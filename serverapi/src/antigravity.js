import https from 'https';
import {StringDecoder} from 'node:string_decoder';

// Agent HTTPS com Keep-Alive de alta performance para reutilização de conexões e latência ultrabaixa
const keepAliveAgent = new https.Agent({
  keepAlive: true,
  maxSockets: 100,
  maxFreeSockets: 25,
  timeout: 600000,
  keepAliveMsecs: 30000
});

const DAILY_ENDPOINT = 'https://daily-cloudcode-pa.googleapis.com';
const CLOUD_CODE_ENDPOINT = 'https://cloudcode-pa.googleapis.com';

const HEADERS = {
  'User-Agent': 'antigravity/2.18.0 windows/amd64',
  'X-Client-Name': 'antigravity',
  'X-Client-Version': '2.18.0',
  'x-goog-api-client': 'gl-node/20.11.0 fire/0.8.6 grpc/1.10.x ide/antigravity',
  'X-Requested-With': 'XMLHttpRequest',
  'Content-Type': 'application/json'
};

const CLIENT_METADATA = {
  ideType: 9,
  platform: 5,
  pluginType: 2
};

function postRequest(endpointUrl, bodyData, accessToken, timeoutMs = 600000) {
  return new Promise((resolve, reject) => {
    const parsed = new URL(endpointUrl);
    const postBody = JSON.stringify(bodyData);

    const req = https.request({
      hostname: parsed.hostname,
      port: 443,
      path: parsed.pathname + parsed.search,
      method: 'POST',
      agent: keepAliveAgent,
      headers: {
        ...HEADERS,
        'Authorization': `Bearer ${accessToken}`,
        'Content-Length': Buffer.byteLength(postBody)
      }
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

export async function onboardUser(accessToken) {
  try {
    const res = await postRequest(
      `${CLOUD_CODE_ENDPOINT}/v1internal:loadCodeAssist`,
      { metadata: CLIENT_METADATA },
      accessToken
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

export async function fetchUserQuotas(accessToken, projectId) {
  try {
    const body = { project: projectId || 'aicode-consumers' };
    const res = await postRequest(`${CLOUD_CODE_ENDPOINT}/v1internal:fetchAvailableModels`, body, accessToken, 20000);
    return res;
  } catch (err) {
    console.warn('[Antigravity] Erro ao buscar cotas:', err.message || err);
    return null;
  }
}

// Mesmo método usado pelo cliente Gemini para consultar as janelas da conta.
export async function fetchUserQuotaBuckets(accessToken, projectId) {
  const body={project:projectId || 'aicode-consumers'};
  let last;
  for(const host of [DAILY_ENDPOINT,CLOUD_CODE_ENDPOINT]) {
    try {
      const data=await postRequest(`${host}/v1internal:retrieveUserQuota`,body,accessToken,20000);
      if(!Array.isArray(data?.buckets) || !data.buckets.length)throw new Error('Resposta sem janelas de cota.');
      return data;
    } catch(error) {last=error;}
  }
  throw new Error(`Não foi possível consultar as janelas de cota do Google: ${String(last?.message || 'erro desconhecido').slice(0,200)}`);
}

/**
 * Executa generateContent enviando direto ao DAILY_ENDPOINT oficial com payload correto
 */
export async function generateContent(accessToken, projectId, model, contents, systemInstruction, customConfig = null) {
  const finalProject = projectId || 'aicode-consumers';
  const targetModel = model;

  const generationConfig = {
    temperature: customConfig?.temperature !== undefined ? customConfig.temperature : 0.7,
    maxOutputTokens: customConfig?.maxOutputTokens || 32768
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
      contents: contents,
      generationConfig: generationConfig
    }
  };

  if (systemInstruction) {
    payload.request.systemInstruction = {
      parts: [{ text: systemInstruction }]
    };
  }

  if (customConfig?.tools && Array.isArray(customConfig.tools) && customConfig.tools.length > 0) {
    payload.request.tools = customConfig.tools;
  }

  if (customConfig?.toolConfig) {
    payload.request.toolConfig = customConfig.toolConfig;
  }

  // Chamada oficial direta ao endpoint daily do Cloud Code Antigravity
  return await postRequest(`${DAILY_ENDPOINT}/v1internal:generateContent`, payload, accessToken);
}

/**
 * Executa generateContent via streaming (SSE) no DAILY_ENDPOINT com suporte a callbacks de token/tool e AbortSignal
 */
export function streamGenerateContent(accessToken, projectId, model, contents, systemInstruction, customConfig = null, onChunk = null, abortSignal = null) {
  return new Promise((resolve, reject) => {
    const finalProject = projectId || 'aicode-consumers';
    const targetModel = model;

    const generationConfig = {
      temperature: customConfig?.temperature !== undefined ? customConfig.temperature : 0.7,
      maxOutputTokens: customConfig?.maxOutputTokens || 32768
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
        contents: contents,
        generationConfig: generationConfig
      }
    };

    if (systemInstruction) {
      payload.request.systemInstruction = {
        parts: [{ text: systemInstruction }]
      };
    }

    if (customConfig?.tools && Array.isArray(customConfig.tools) && customConfig.tools.length > 0) {
      payload.request.tools = customConfig.tools;
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
    const req = https.request({
      hostname: parsed.hostname,
      port: 443,
      path: parsed.pathname + parsed.search,
      method: 'POST',
      agent: keepAliveAgent,
      headers: {
        ...HEADERS,
        'Authorization': `Bearer ${accessToken}`,
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(postBody)
      }
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

