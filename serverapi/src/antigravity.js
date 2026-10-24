import https from 'https';

const DAILY_ENDPOINT = 'https://daily-cloudcode-pa.googleapis.com';
const CLOUD_CODE_ENDPOINT = 'https://cloudcode-pa.googleapis.com';

const HEADERS = {
  'User-Agent': 'antigravity/2.11.0 windows/amd64',
  'X-Client-Name': 'antigravity',
  'x-goog-api-client': 'gl-node/18.18.2 fire/0.8.6 grpc/1.10.x',
  'Content-Type': 'application/json'
};

const CLIENT_METADATA = {
  ideType: 9,
  platform: 5,
  pluginType: 2
};

function postRequest(endpointUrl, bodyData, accessToken, timeoutMs = 300000) {
  return new Promise((resolve, reject) => {
    const parsed = new URL(endpointUrl);
    const postBody = JSON.stringify(bodyData);

    const req = https.request({
      hostname: parsed.hostname,
      port: 443,
      path: parsed.pathname + parsed.search,
      method: 'POST',
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
    const res = await postRequest(`${CLOUD_CODE_ENDPOINT}/v1internal:fetchAvailableModels`, body, accessToken);
    return res;
  } catch (err) {
    console.warn('[Antigravity] Erro ao buscar cotas:', err.message || err);
    return null;
  }
}

/**
 * Executa generateContent enviando direto ao DAILY_ENDPOINT oficial com payload correto
 */
export async function generateContent(accessToken, projectId, model, contents, systemInstruction, customConfig = null) {
  const finalProject = projectId || 'aicode-consumers';
  const targetModel = model;

  const generationConfig = {
    temperature: customConfig?.temperature !== undefined ? customConfig.temperature : 0.7,
    maxOutputTokens: customConfig?.maxOutputTokens || 8192
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
      maxOutputTokens: customConfig?.maxOutputTokens || 8192
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

    const req = https.request({
      hostname: parsed.hostname,
      port: 443,
      path: parsed.pathname + parsed.search,
      method: 'POST',
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
          let parsed = null;
          try { parsed = JSON.parse(errData); } catch (e) {}
          const err = new Error(`CloudCode API Error (${res.statusCode}): ${errData}`);
          err.status = res.statusCode;
          err.statusCode = res.statusCode;
          err.data = parsed || { raw: errData };
          reject(err);
        });
        return;
      }

      let buffer = '';
      let fullReplyText = '';
      let fullCandidates = [];
      let capturedToolCalls = [];

      res.on('data', chunk => {
        req.setTimeout(300000); // Reseta o timeout de 5 minutos sempre que novos dados chegam
        if (abortSignal?.aborted) {
          req.destroy();
          return;
        }

        buffer += chunk.toString();
        const lines = buffer.split('\n');
        buffer = lines.pop() || '';

        for (const line of lines) {
          const trimmed = line.trim();
          if (!trimmed.startsWith('data:')) continue;
          const jsonStr = trimmed.slice(5).trim();
          if (!jsonStr || jsonStr === '[DONE]') continue;

          try {
            const parsedChunk = JSON.parse(jsonStr);
            const actualResp = parsedChunk.response || parsedChunk;
            const candidate = actualResp?.candidates?.[0];
            let chunkSig = candidate?.thoughtSignature || candidate?.thought_signature;

            if (candidate?.content?.parts) {
              for (const p of candidate.content.parts) {
                if (p.thoughtSignature || p.thought_signature) {
                  chunkSig = p.thoughtSignature || p.thought_signature;
                }
                if (p.thought) continue;
                if (p.text) {
                  fullReplyText += p.text;
                  if (typeof onChunk === 'function') {
                    onChunk(p.text, null);
                  }
                }
                if (p.functionCall) {
                  const sig = p.thoughtSignature || p.thought_signature || p.functionCall?.thoughtSignature || p.functionCall?.thought_signature || chunkSig;
                  const callObj = {
                    name: p.functionCall.name,
                    args: p.functionCall.args || {},
                    id: p.functionCall.id || ('call_' + Math.random().toString(36).substring(2, 10)),
                    thoughtSignature: sig
                  };
                  capturedToolCalls.push(callObj);
                  if (typeof onChunk === 'function') {
                    onChunk(null, callObj);
                  }
                }
              }
            }
            if (candidate) {
              fullCandidates = [candidate];
            }
          } catch (e) { }
        }
      });

      res.on('end', () => {
        resolve({
          replyText: fullReplyText,
          toolCalls: capturedToolCalls,
          candidates: fullCandidates,
          response: { candidates: fullCandidates }
        });
      });
    });

    if (abortSignal) {
      abortSignal.addEventListener('abort', () => {
        req.destroy();
        resolve({ replyText: '', toolCalls: [], aborted: true });
      });
    }

    req.on('error', err => {
      if (abortSignal?.aborted) {
        resolve({ replyText: '', toolCalls: [], aborted: true });
      } else {
        reject(err);
      }
    });

    req.setTimeout(300000, () => req.destroy(new Error('Timeout de stream com a Google CloudCode API (300s)')));
    req.write(postBody);
    req.end();
  });
}

