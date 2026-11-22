import https from 'https';
import crypto from 'crypto';
import {StringDecoder} from 'node:string_decoder';

// Pool de sockets TLS isolados por conta (impede que 20 contas diferentes multiplexem na mesma sessão TLS)
const accountAgents = new Map();
function getAgentForAccount(accountKey = '') {
  const key = accountKey || 'default';
  if (accountAgents.has(key)) {
    return accountAgents.get(key);
  }
  const agent = new https.Agent({
    keepAlive: true,
    maxSockets: 6,
    maxFreeSockets: 2,
    timeout: 600000,
    keepAliveMsecs: 30000
  });
  accountAgents.set(key, agent);
  return agent;
}

const DAILY_ENDPOINT = 'https://daily-cloudcode-pa.googleapis.com';
const CLOUD_CODE_ENDPOINT = 'https://cloudcode-pa.googleapis.com';

const CLIENT_VERSION = '2.7.0';
const BASE_HEADERS = {
  'User-Agent': `antigravity/${CLIENT_VERSION} windows/amd64`,
  'X-Client-Name': 'antigravity',
  'X-Client-Version': CLIENT_VERSION,
  'x-goog-api-client': 'gl-node/20.11.0 fire/0.8.6 grpc/1.10.x ide/antigravity',
  'Content-Type': 'application/json'
};

const CLIENT_METADATA = {
  ideType: 9,
  platform: 5,
  pluginType: 2,
  ideVersion: CLIENT_VERSION
};

// Deriva identificadores determinísticos de máquina/sessão por conta (evita que 20 contas pareçam rodar na mesma instância física)
const accountFingerprintCache = new Map();
export function getAccountFingerprint(accessToken, accountKey = '') {
  const seed = accountKey || accessToken.slice(-24);
  if (accountFingerprintCache.has(seed)) {
    const fp = accountFingerprintCache.get(seed);
    // Rotaciona sessionId a cada 4 horas como um usuário real abrindo/fechando o IDE
    if (Date.now() - fp.sessionCreatedAt > 4 * 3600 * 1000) {
      fp.sessionId = crypto.randomUUID();
      fp.sessionCreatedAt = Date.now();
    }
    return fp;
  }
  const hash = crypto.createHash('sha256').update(`agy-fp-${seed}`).digest('hex');
  const machineId = [
    hash.slice(0, 8),
    hash.slice(8, 12),
    '4' + hash.slice(13, 16),
    'a' + hash.slice(17, 20),
    hash.slice(20, 32)
  ].join('-');
  const fp = {
    machineId,
    sessionId: crypto.randomUUID(),
    sessionCreatedAt: Date.now()
  };
  accountFingerprintCache.set(seed, fp);
  return fp;
}

// Sanitizador central de Camada 7 (Payload): remove assinaturas de CLIs concorrentes
// (Claude Code, Cline, Roo Code, Kilo Code, Cursor, OpenCode, Aider) de systemInstruction,
// contents (mensagens de user/model) e descriptions de tools antes de sair para o Google Cloud Code.
export function sanitizePayloadText(text = '') {
  if (!text || typeof text !== 'string') return text;
  return text
    .replace(/You are Claude Code,\s*Anthropic'?s official CLI for Claude\.?/gi, 'You are an expert AI coding assistant running inside the IDE.')
    .replace(/Anthropic'?s official CLI for Claude/gi, 'the IDE coding assistant')
    .replace(/Claude Code is an agentic coding tool/gi, 'The IDE assistant is an agentic coding tool')
    .replace(/\bClaude Code\b/gi, 'IDE Assistant')
    .replace(/\bclaude-code\/[\d.a-z-]+/gi, 'antigravity/2.7.0')
    .replace(/https?:\/\/docs\.anthropic\.com\/[^\s)\]"']+/gi, 'https://antigravity.google/docs')
    .replace(/https?:\/\/github\.com\/anthropics\/claude-code[^\s)\]"']*/gi, 'https://antigravity.google/docs')
    .replace(/\bCLAUDE\.md\b/g, 'AGENTS.md')
    .replace(/\.claude\//g, '.agent/')
    .replace(/You are Cline,\s*a highly skilled software engineer/gi, 'You are a highly skilled software engineer')
    .replace(/You are Roo(?:\s*Code)?,\s*a/gi, 'You are a')
    .replace(/You are Kilo(?:\s*Code)?,\s*a/gi, 'You are a');
}

function sanitizeContentsForAntigravity(contents) {
  if (!Array.isArray(contents)) return contents;
  return contents.map(item => {
    if (!item || !Array.isArray(item.parts)) return item;
    return {
      ...item,
      parts: item.parts.map(p => {
        if (!p || typeof p !== 'object') return p;
        if (typeof p.text === 'string') {
          return { ...p, text: sanitizePayloadText(p.text) };
        }
        return p;
      })
    };
  });
}

function sanitizeToolsForAntigravity(tools) {
  if (!Array.isArray(tools)) return tools;
  return tools.map(toolGroup => {
    if (!toolGroup || !Array.isArray(toolGroup.functionDeclarations)) return toolGroup;
    return {
      ...toolGroup,
      functionDeclarations: toolGroup.functionDeclarations.map(fn => ({
        ...fn,
        description: typeof fn.description === 'string' ? sanitizePayloadText(fn.description) : fn.description
      }))
    };
  });
}

function buildRequestHeaders(accessToken, accountKey = '', extraHeaders = {}) {
  const fp = getAccountFingerprint(accessToken, accountKey);
  return {
    ...BASE_HEADERS,
    'Authorization': `Bearer ${accessToken}`,
    'x-goog-request-params': `ide_type=9&platform=5&plugin_type=2`,
    'x-client-session-id': fp.sessionId,
    'x-client-machine-id': fp.machineId,
    ...extraHeaders
  };
}

function postRequest(endpointUrl, bodyData, accessToken, timeoutMs = 600000, accountKey = '') {
  return new Promise((resolve, reject) => {
    const parsed = new URL(endpointUrl);
    const postBody = JSON.stringify(bodyData);
    const headers = buildRequestHeaders(accessToken, accountKey, {
      'Content-Length': Buffer.byteLength(postBody)
    });

    const req = https.request({
      hostname: parsed.hostname,
      port: 443,
      path: parsed.pathname + parsed.search,
      method: 'POST',
      agent: getAgentForAccount(accountKey || accessToken.slice(-16)),
      headers
    }, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        try {
          const json = JSON.parse(data);
          if (res.statusCode >= 200 && res.statusCode < 300) {
            resolve(json);
          } else {
            reject({
              status: res.statusCode,
              data: json,
              message: `CloudCode API Error (${res.statusCode}): ${JSON.stringify(json)}`
            });
          }
        } catch (e) {
          reject({
            status: res.statusCode,
            data: data,
            message: `CloudCode API non-JSON response: ${data}`
          });
        }
      });
    });

    req.setTimeout(timeoutMs, () => req.destroy(new Error(`Timeout de requisicao com a Google CloudCode API (${Math.round(timeoutMs / 1000)}s)`)));
    req.on('error', reject);
    req.write(postBody);
    req.end();
  });
}

export async function onboardUser(accessToken, accountKey = '') {
  try {
    const fp = getAccountFingerprint(accessToken, accountKey);
    const res = await postRequest(
      `${CLOUD_CODE_ENDPOINT}/v1internal:loadCodeAssist`,
      { metadata: { ...CLIENT_METADATA, sessionId: fp.sessionId } },
      accessToken,
      600000,
      accountKey
    );
    if (res.cloudaicompanionProject) {
      return res.cloudaicompanionProject;
    }
    if (res.currentTier?.projectNumber) {
      return res.currentTier.projectNumber;
    }
  } catch (err) {
    console.warn('[Antigravity] loadCodeAssist falhou:', err.message || err);
  }
  return 'aicode-consumers';
}

export async function fetchUserQuotas(accessToken, projectId, accountKey = '') {
  try {
    const fp = getAccountFingerprint(accessToken, accountKey);
    const body = {
      project: projectId || 'aicode-consumers',
      metadata: { ...CLIENT_METADATA, sessionId: fp.sessionId }
    };
    const res = await postRequest(`${CLOUD_CODE_ENDPOINT}/v1internal:fetchAvailableModels`, body, accessToken, 20000, accountKey);
    return res;
  } catch (err) {
    console.warn('[Antigravity] Erro ao buscar cotas:', err.message || err);
    return null;
  }
}

// Mesmo método usado pelo cliente Gemini para consultar as janelas da conta.
export async function fetchUserQuotaBuckets(accessToken, projectId, accountKey = '') {
  const fp = getAccountFingerprint(accessToken, accountKey);
  const body = {
    project: projectId || 'aicode-consumers',
    metadata: { ...CLIENT_METADATA, sessionId: fp.sessionId }
  };
  let last;
  for(const host of [DAILY_ENDPOINT,CLOUD_CODE_ENDPOINT]) {
    try {
      const data=await postRequest(`${host}/v1internal:retrieveUserQuota`,body,accessToken,20000,accountKey);
      if(!Array.isArray(data?.buckets) || !data.buckets.length)throw new Error('Resposta sem janelas de cota.');
      return data;
    } catch(error) {last=error;}
  }
  throw new Error(`Não foi possível consultar as janelas de cota do Google: ${String(last?.message || 'erro desconhecido').slice(0,200)}`);
}

/**
 * Executa generateContent enviando direto ao DAILY_ENDPOINT oficial com payload correto
 */
export async function generateContent(accessToken, projectId, model, contents, systemInstruction, customConfig = null, accountKey = '') {
  const finalProject = projectId || 'aicode-consumers';
  const targetModel = model;

  const generationConfig = {
    temperature: customConfig?.temperature !== undefined ? customConfig.temperature : 0.7,
    maxOutputTokens: Math.min(customConfig?.maxOutputTokens || 8192, 16384)
  };

  if (customConfig?.thinkingBudget !== undefined) {
    generationConfig.thinkingConfig = {
      thinkingBudget: customConfig.thinkingBudget
    };
  } else if (customConfig?.thinkingConfig) {
    generationConfig.thinkingConfig = customConfig.thinkingConfig;
  }

  const payload = {
    project: finalProject,
    model: targetModel,
    request: {
      contents: sanitizeContentsForAntigravity(contents),
      generationConfig: generationConfig
    }
  };

  if (systemInstruction) {
    payload.request.systemInstruction = {
      parts: [{ text: sanitizePayloadText(systemInstruction) }]
    };
  }

  if (customConfig?.tools && Array.isArray(customConfig.tools) && customConfig.tools.length > 0) {
    payload.request.tools = sanitizeToolsForAntigravity(customConfig.tools);
  }

  if (customConfig?.toolConfig) {
    payload.request.toolConfig = customConfig.toolConfig;
  }

  // Chamada oficial direta ao endpoint daily do Cloud Code Antigravity
  return await postRequest(`${DAILY_ENDPOINT}/v1internal:generateContent`, payload, accessToken, 600000, accountKey);
}

/**
 * Executa generateContent via streaming (SSE) no DAILY_ENDPOINT com suporte a callbacks de token/tool e AbortSignal
 */
export function streamGenerateContent(accessToken, projectId, model, contents, systemInstruction, customConfig = null, onChunk = null, abortSignal = null, accountKey = '') {
  return new Promise((resolve, reject) => {
    const finalProject = projectId || 'aicode-consumers';
    const targetModel = model;

    const generationConfig = {
      temperature: customConfig?.temperature !== undefined ? customConfig.temperature : 0.7,
      maxOutputTokens: Math.min(customConfig?.maxOutputTokens || 8192, 16384)
    };

    if (customConfig?.thinkingBudget !== undefined) {
      generationConfig.thinkingConfig = {
        thinkingBudget: customConfig.thinkingBudget
      };
    } else if (customConfig?.thinkingConfig) {
      generationConfig.thinkingConfig = customConfig.thinkingConfig;
    }

    const payload = {
      project: finalProject,
      model: targetModel,
      request: {
        contents: sanitizeContentsForAntigravity(contents),
        generationConfig: generationConfig
      }
    };

    if (systemInstruction) {
      payload.request.systemInstruction = {
        parts: [{ text: sanitizePayloadText(systemInstruction) }]
      };
    }

    if (customConfig?.tools && Array.isArray(customConfig.tools) && customConfig.tools.length > 0) {
      payload.request.tools = sanitizeToolsForAntigravity(customConfig.tools);
    }

    if (customConfig?.toolConfig) {
      payload.request.toolConfig = customConfig.toolConfig;
    }

    const postBody = JSON.stringify(payload);
    const parsed = new URL(`${DAILY_ENDPOINT}/v1internal:streamGenerateContent?alt=sse`);

    let completed=false, emitted=false;
    const rejectStream=error=>{
      if(completed)return;completed=true;
      const err=error instanceof Error ? error : new Error(String(error));
      if(emitted)err.partialOutput=true;reject(err);
    };
    const headers = buildRequestHeaders(accessToken, accountKey, {
      'Accept': 'text/event-stream',
      'Content-Length': Buffer.byteLength(postBody)
    });
    const req = https.request({
      hostname: parsed.hostname,
      port: 443,
      path: parsed.pathname + parsed.search,
      method: 'POST',
      agent: getAgentForAccount(accountKey || accessToken.slice(-16)),
      headers
    }, (res) => {
      if (res.statusCode < 200 || res.statusCode >= 300) {
        let errData = '';
        res.on('data', d => errData += d);
        res.on('end', () => {
          if(completed)return;let parsed = null;
          try { parsed = JSON.parse(errData); } catch (e) {}
          const err = new Error(`CloudCode API Error (${res.statusCode}): ${errData}`);
          err.status = res.statusCode;
          err.statusCode = res.statusCode;
          err.data = parsed || { raw: errData };
          rejectStream(err);
        });
        res.on('error',rejectStream);
        res.on('aborted',()=>rejectStream(new Error('Resposta de erro CloudCode interrompida.')));
        return;
      }

      let buffer = '';
      let fullReplyText = '';
      let fullCandidates = [];
      let usageMetadata;
      const capturedToolCalls = [];
      const decoder = new StringDecoder('utf8');
      let failed = false;
      const fail = error => {
        if (failed) return;
        failed = true;
        const err = error instanceof Error ? error : new Error(String(error));
        if (fullReplyText || capturedToolCalls.length) err.partialOutput = true;
        rejectStream(err);
        req.destroy();
      };
      const processLine = line => {
        const trimmed=line.trim();
        if(!trimmed.startsWith('data:'))return;
        const jsonStr=trimmed.slice(5).trim();
        if(!jsonStr || jsonStr==='[DONE]')return;
        let parsedChunk;
        try {parsedChunk=JSON.parse(jsonStr);} catch {throw new Error('CloudCode enviou evento de transmissão JSON inválido.');}
        const actualResp=parsedChunk.response || parsedChunk;
        if(actualResp.usageMetadata)usageMetadata=actualResp.usageMetadata;
        const candidate=actualResp?.candidates?.[0];
        let chunkSig=candidate?.thoughtSignature || candidate?.thought_signature;
        if(candidate?.content?.parts) for(const p of candidate.content.parts) {
          if(p.thoughtSignature || p.thought_signature)chunkSig=p.thoughtSignature || p.thought_signature;
          if(p.thought) {if(typeof onChunk==='function')onChunk(null,null,{thinking:true,thought:p.text || ''});continue;}
          if(p.text) {fullReplyText+=p.text;emitted=true;if(typeof onChunk==='function')onChunk(p.text,null);}
          if(p.functionCall) {
            const sig=p.thoughtSignature || p.thought_signature || p.functionCall?.thoughtSignature || p.functionCall?.thought_signature || chunkSig;
            const callObj={name:p.functionCall.name,args:p.functionCall.args || {},id:p.functionCall.id || ('call_'+Math.random().toString(36).substring(2,10)),thoughtSignature:sig};
            capturedToolCalls.push(callObj);emitted=true;if(typeof onChunk==='function')onChunk(null,callObj);
          }
        }
        if(candidate)fullCandidates=[candidate];
      };
      res.on('data', chunk => {
        if(failed)return;
        req.setTimeout(600000);
        if(abortSignal?.aborted) {req.destroy();return;}
        try {
          buffer+=decoder.write(Buffer.isBuffer(chunk)?chunk:Buffer.from(chunk));
          const lines=buffer.split('\n');buffer=lines.pop() || '';
          for(const line of lines)processLine(line);
        } catch(error) {fail(error);}
      });
      res.on('end', () => {
        if(failed)return;
        try {
          buffer+=decoder.end();
          if(buffer)processLine(buffer);
          completed=true;resolve({replyText:fullReplyText,toolCalls:capturedToolCalls,candidates:fullCandidates,usageMetadata,response:{candidates:fullCandidates,usageMetadata}});
        } catch(error) {fail(error);}
      });
      res.on('error',fail);
      res.on('aborted',()=>fail(new Error('Transmissão CloudCode interrompida antes do fim.')));
    });

    if (abortSignal) {
      abortSignal.addEventListener('abort', () => {
        req.destroy();
        if(!completed){completed=true;resolve({ replyText: '', toolCalls: [], aborted: true });}
      });
    }

    req.on('error', err => {
      if (abortSignal?.aborted) {
        if(!completed){completed=true;resolve({ replyText: '', toolCalls: [], aborted: true });}
      } else {
        rejectStream(err);
      }
    });

    if (abortSignal?.aborted) {req.destroy();if(!completed){completed=true;resolve({replyText:'',toolCalls:[],aborted:true});}return;}
    req.setTimeout(600000, () => req.destroy(new Error('Timeout de stream com a Google CloudCode API (600s)')));
    req.write(postBody);
    req.end();
  });
}

