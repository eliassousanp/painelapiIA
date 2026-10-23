import { WebSocketServer } from 'ws';
import crypto from 'crypto';
import fs from 'fs';
import path from 'path';
import os from 'os';
import { fileURLToPath } from 'url';
import { EdgeTTS } from 'node-edge-tts';
import { searchWeb, shouldSearchWeb, formatSearchResultsForPrompt } from './services/webSearch.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

/**
 * Módulo Realtime WebSocket para o Antigravity Gateway
 * Protocolo compatível com OpenAI Realtime API
 * Pipeline: STT (Gemini) → LLM (Pool/Providers) → TTS (Edge Neural Voices)
 */

// Vozes disponíveis do Edge TTS (neural, gratuitas, realistas)
const AVAILABLE_VOICES = {
  // Português do Brasil (Vozes Neurais Microsoft Azure HD)
  'francisca': { id: 'pt-BR-FranciscaNeural', name: 'Francisca (Feminina Natural)', lang: 'pt-BR', gender: 'female' },
  'antonio': { id: 'pt-BR-AntonioNeural', name: 'Antônio (Masculina Profissional)', lang: 'pt-BR', gender: 'male' },
  'thalita': { id: 'pt-BR-ThalitaNeural', name: 'Thalita (Feminina Jovem)', lang: 'pt-BR', gender: 'female' },
  'fabio': { id: 'pt-BR-FabioNeural', name: 'Fábio (Masculina Jovem)', lang: 'pt-BR', gender: 'male' },
  'giovanna': { id: 'pt-BR-GiovannaNeural', name: 'Giovanna (Feminina Suave)', lang: 'pt-BR', gender: 'female' },
  'humberto': { id: 'pt-BR-HumbertoNeural', name: 'Humberto (Masculina Firme)', lang: 'pt-BR', gender: 'male' },
  'julio': { id: 'pt-BR-JulioNeural', name: 'Júlio (Masculina Narrador)', lang: 'pt-BR', gender: 'male' },
  'nicolau': { id: 'pt-BR-NicolauNeural', name: 'Nicolau (Masculina Amigável)', lang: 'pt-BR', gender: 'male' },
  'valerio': { id: 'pt-BR-ValerioNeural', name: 'Valério (Masculina Grave)', lang: 'pt-BR', gender: 'male' },
  'yara': { id: 'pt-BR-YaraNeural', name: 'Yara (Feminina Elegante)', lang: 'pt-BR', gender: 'female' },

  // Inglês (EUA)
  'aria': { id: 'en-US-AriaNeural', name: 'Aria (EN)', lang: 'en-US', gender: 'female' },
  'guy': { id: 'en-US-GuyNeural', name: 'Guy (EN)', lang: 'en-US', gender: 'male' },
  'jenny': { id: 'en-US-JennyNeural', name: 'Jenny (EN)', lang: 'en-US', gender: 'female' },
  'andrew': { id: 'en-US-AndrewNeural', name: 'Andrew (EN)', lang: 'en-US', gender: 'male' },
};

const DEFAULT_VOICE = 'francisca';
const DEFAULT_MODEL = 'Realtime';

export function initRealtime(httpServer, gateway) {
  const wss = new WebSocketServer({
    server: httpServer,
    path: '/v1/realtime',
    maxPayload: 10 * 1024 * 1024 // 10MB para chunks de áudio
  });

  console.log(`🎙️  Realtime WebSocket ativo em /v1/realtime`);

  // Intervalo de Heartbeat a cada 30s
  const pingInterval = setInterval(() => {
    wss.clients.forEach((ws) => {
      if (ws.isAlive === false) return ws.terminate();
      ws.isAlive = false;
      ws.ping();
    });
  }, 30000);

  wss.on('close', () => {
    clearInterval(pingInterval);
  });

  wss.on('connection', async (ws, req) => {
    // Heartbeat Ping-Pong para manter WebSocket ativo em proxies/Cloudflare
    ws.isAlive = true;
    ws.on('pong', () => { ws.isAlive = true; });

    // --- AUTENTICAÇÃO ---
    const url = new URL(req.url, 'http://localhost');
    const apiKey = url.searchParams.get('api_key')
      || url.searchParams.get('apiKey')
      || url.searchParams.get('api-key')
      || req.headers['api-key']
      || req.headers['x-api-key']
      || '';
    const modelParam = url.searchParams.get('model') || '';

    // Taxa de amostragem configurável via query string (?sample_rate=16000 ou ?rate=16000)
    const sampleRateQuery = parseInt(
      url.searchParams.get('sample_rate')
      || url.searchParams.get('sampleRate')
      || url.searchParams.get('rate')
      || ''
    );
    // Padrão 16000Hz (16kHz PCM16, ideal para áudios do WhatsApp e STT direto no Gemini)
    const initialSampleRate = (!isNaN(sampleRateQuery) && sampleRateQuery > 0) ? sampleRateQuery : 16000;

    if (!apiKey) {
      sendEvent(ws, 'error', { type: 'auth_error', message: 'API Key não fornecida. Use ?api_key=sk-xxx ou ?api-key=sk-xxx' });
      ws.close(4001, 'API Key não fornecida');
      return;
    }

    let userKey;
    try {
      userKey = await gateway.authenticateApiKey(apiKey);
    } catch (err) {
      sendEvent(ws, 'error', { type: 'auth_error', message: err.message });
      ws.close(4001, err.message);
      return;
    }

    if (!userKey) {
      sendEvent(ws, 'error', { type: 'auth_error', message: 'API Key inválida ou conta suspensa.' });
      ws.close(4001, 'API Key inválida');
      return;
    }

    // --- VERIFICAÇÃO DE DISPONIBILIDADE GLOBAL ---
    const isAdmin = userKey.user_role === 'admin';
    try {
      if (typeof gateway.getSetting === 'function') {
        const realtimeAccess = await gateway.getSetting('realtime_access', 'all');
        if (realtimeAccess === 'admin' && !isAdmin) {
          sendEvent(ws, 'error', {
            type: 'permission_denied',
            message: 'O console Realtime está configurado para acesso exclusivo de administradores.'
          });
          ws.close(4003, 'Acesso Realtime restrito para administradores');
          return;
        }
      }
    } catch (e) {
      console.warn('[Realtime] Aviso ao verificar realtime_access:', e.message);
    }

    // --- SESSÃO ---
    const session = {
      id: 'sess_' + crypto.randomBytes(12).toString('hex'),
      model: modelParam || DEFAULT_MODEL,
      voice: DEFAULT_VOICE,
      voice_rate: '+5%',
      sample_rate: initialSampleRate,
      input_audio_format: 'pcm16',
      output_audio_format: 'mp3',
      instructions: '',
      temperature: 0.7,
      turn_detection: { type: 'server_vad', threshold: 0.5, silence_duration_ms: 600 },
      web_search: true, // Pesquisa na Web em tempo real ativada por padrão
      tools: [],        // Ferramentas / Function Calling
      conversation: [], // histórico de mensagens {role, content}
      audioBuffer: [],  // chunks de áudio base64
      vadSilenceTimer: null,
      vadStarted: false,
      isSpeaking: false,
      lastSpeechTime: 0,
      isProcessing: false,
      activeResponse: null, // AbortController da resposta em andamento
      userKey: userKey,
      noiseFloor: 50,
      maxConversationHistory: 16,
      lastSttLatencyMs: 0
    };

    // Enviar session.created
    sendEvent(ws, 'session.created', {
      session: {
        id: session.id,
        model: session.model,
        voice: session.voice,
        sample_rate: session.sample_rate,
        input_audio_format: session.input_audio_format,
        output_audio_format: session.output_audio_format,
        instructions: session.instructions,
        temperature: session.temperature,
        turn_detection: session.turn_detection,
        web_search: session.web_search,
        tools: session.tools
      }
    });

    // --- HANDLER DE MENSAGENS ---
    ws.on('message', async (data) => {
      let event;
      try {
        event = JSON.parse(data.toString());
      } catch (e) {
        sendEvent(ws, 'error', { type: 'invalid_request', message: 'JSON inválido.' });
        return;
      }

      const type = event.type;

      try {
        switch (type) {
          case 'session.update':
            handleSessionUpdate(ws, session, event);
            break;

          case 'input_audio_buffer.append':
            handleAudioAppend(ws, session, gateway, event);
            break;

          case 'input_audio_buffer.commit':
            await handleAudioCommit(ws, session, gateway);
            break;

          case 'input_audio_buffer.clear':
            session.audioBuffer = [];
            sendEvent(ws, 'input_audio_buffer.cleared', {});
            break;

          case 'conversation.item.create':
            handleConversationItemCreate(ws, session, event);
            break;

          case 'conversation.item.delete':
            handleConversationItemDelete(ws, session, event);
            break;

          case 'response.create':
            await handleResponseCreate(ws, session, gateway, event);
            break;

          case 'response.cancel':
            if (session.activeResponse) {
              session.activeResponse.abort();
              session.activeResponse = null;
              sendEvent(ws, 'response.cancelled', {});
            }
            break;

          default:
            sendEvent(ws, 'error', { type: 'invalid_request', message: `Evento desconhecido: ${type}` });
        }
      } catch (err) {
        console.error(`[Realtime] Erro ao processar evento ${type}:`, err.message);
        sendEvent(ws, 'error', { type: 'server_error', message: err.message });
      }
    });

    ws.on('close', () => {
      if (session.activeResponse) {
        session.activeResponse.abort();
      }
    });

    ws.on('error', (err) => {
      console.error(`[Realtime] WebSocket error:`, err.message);
    });
  });

  // --- ENDPOINT REST: TTS (compatível OpenAI /v1/audio/speech) ---
  return wss;
}

// =============================================
// HANDLERS DE EVENTOS
// =============================================

function pcmToWav(pcmBuffer, sampleRate = 16000, numChannels = 1, bitsPerSample = 16) {
  const byteRate = sampleRate * numChannels * (bitsPerSample / 8);
  const blockAlign = numChannels * (bitsPerSample / 8);
  const dataSize = pcmBuffer.length;
  const header = Buffer.alloc(44);

  header.write('RIFF', 0);
  header.writeUInt32LE(dataSize + 36, 4);
  header.write('WAVE', 8);
  header.write('fmt ', 12);
  header.writeUInt32LE(16, 16);
  header.writeUInt16LE(1, 20);
  header.writeUInt16LE(numChannels, 22);
  header.writeUInt32LE(sampleRate, 24);
  header.writeUInt32LE(byteRate, 28);
  header.writeUInt16LE(blockAlign, 32);
  header.writeUInt16LE(bitsPerSample, 34);
  header.write('data', 36);
  header.writeUInt32LE(dataSize, 40);

  return Buffer.concat([header, pcmBuffer]);
}

function handleSessionUpdate(ws, session, event) {
  const s = event.session || {};

  if (s.model) session.model = s.model;
  if (s.voice) session.voice = s.voice;
  if (s.voice_rate) session.voice_rate = s.voice_rate;
  if (s.instructions !== undefined) session.instructions = s.instructions;
  if (s.temperature !== undefined) session.temperature = s.temperature;
  // Suporte flexível a taxa de amostragem (16000, 24000, etc.)
  const rateCandidate = s.sample_rate || s.sampleRate || s.input_audio_sample_rate || s.audio_sample_rate || s.rate;
  if (rateCandidate) {
    const parsedRate = parseInt(rateCandidate);
    if (!isNaN(parsedRate) && parsedRate > 0) {
      session.sample_rate = parsedRate;
    }
  }

  // Suporte a formatos de áudio (string ou objeto estruturado)
  if (s.input_audio_format) {
    if (typeof s.input_audio_format === 'string') {
      session.input_audio_format = s.input_audio_format;
      if (s.input_audio_format.includes('16000') || s.input_audio_format.includes('16k')) {
        session.sample_rate = 16000;
      } else if (s.input_audio_format.includes('24000') || s.input_audio_format.includes('24k')) {
        session.sample_rate = 24000;
      } else if (s.input_audio_format.includes('8000') || s.input_audio_format.includes('8k')) {
        session.sample_rate = 8000;
      }
    } else if (typeof s.input_audio_format === 'object') {
      if (s.input_audio_format.sample_rate) {
        const parsedObjRate = parseInt(s.input_audio_format.sample_rate);
        if (!isNaN(parsedObjRate) && parsedObjRate > 0) session.sample_rate = parsedObjRate;
      }
      if (s.input_audio_format.type) {
        session.input_audio_format = s.input_audio_format.type;
      }
    }
  }
  if (s.turn_detection) session.turn_detection = { ...session.turn_detection, ...s.turn_detection };
  if (s.web_search !== undefined) session.web_search = !!s.web_search;
  if (s.tools) session.tools = s.tools;

  sendEvent(ws, 'session.updated', {
    session: {
      id: session.id,
      model: session.model,
      voice: session.voice,
      sample_rate: session.sample_rate,
      input_audio_format: session.input_audio_format,
      output_audio_format: session.output_audio_format,
      instructions: session.instructions,
      temperature: session.temperature,
      turn_detection: session.turn_detection,
      web_search: session.web_search,
      tools: session.tools
    }
  });
}

function calculateAudioRMS(chunkBuffer) {
  if (!chunkBuffer || chunkBuffer.length < 2) return 0;
  let sum = 0;
  const numSamples = Math.floor(chunkBuffer.length / 2);
  for (let i = 0; i < chunkBuffer.length - 1; i += 2) {
    const val = chunkBuffer.readInt16LE(i);
    sum += val * val;
  }
  return Math.sqrt(sum / numSamples);
}

function handleAudioAppend(ws, session, gateway, event) {
  const audio = event.audio;
  if (!audio) {
    sendEvent(ws, 'error', { type: 'invalid_request', message: 'Campo "audio" ausente em input_audio_buffer.append' });
    return;
  }

  const chunkBuf = Buffer.from(audio, 'base64');
  const rms = calculateAudioRMS(chunkBuf);

  // Server VAD adaptativo por piso de ruído acústico (PCM 16-bit)
  if (session.turn_detection && session.turn_detection.type === 'server_vad') {
    // Ajuste dinâmico do piso de ruído ambiente (calibração rápida inicial nos primeiros 300ms)
    if (!session.isSpeaking) {
      if (!session.noiseCalibrationSamples) session.noiseCalibrationSamples = 0;
      session.noiseCalibrationSamples++;
      const alpha = session.noiseCalibrationSamples < 15 ? 0.25 : 0.04;
      session.noiseFloor = (session.noiseFloor * (1 - alpha)) + (rms * alpha);
    }

    // Limiar adaptativo dinâmico: piso de ruído + margem de fala
    const dynamicThreshold = Math.max(90, Math.min(420, (session.noiseFloor * 2.2) + 35));

    if (rms > dynamicThreshold) {
      session.lastSpeechTime = Date.now();
      if (!session.isSpeaking) {
        session.isSpeaking = true;
        sendEvent(ws, 'input_audio_buffer.speech_started', {});

        // Barge-in (Interrupção de Fala): Apenas cancela a IA se o usuário DE FATO começou a falar com energia de voz
        if (session.activeResponse) {
          console.log(`[Realtime Barge-in] Usuário começou a falar (RMS: ${Math.round(rms)}). Cancelando áudio anterior da IA...`);
          session.activeResponse.abort();
          session.activeResponse = null;
          sendEvent(ws, 'response.cancelled', {});
        }
      }
    }

    // Se a IA ainda estiver processando a resposta anterior e o usuário não estiver falando, não acumula ruído
    if (session.isProcessing && !session.isSpeaking) {
      return;
    }

    session.audioBuffer.push(audio);

    // Espera ~1200ms de silêncio real contínuo antes de fechar a frase, permitindo pausas naturais para respirar
    const silenceTimeout = session.turn_detection?.silence_duration_ms || 600;
    if (session.isSpeaking && (Date.now() - session.lastSpeechTime >= silenceTimeout)) {
      session.isSpeaking = false;
      session.isProcessing = true;
      sendEvent(ws, 'input_audio_buffer.speech_stopped', {});

      setImmediate(async () => {
        try {
          await handleAudioCommit(ws, session, gateway);
        } catch (err) {
          console.error('[Realtime VAD Commit] Erro:', err.message);
        } finally {
          session.isProcessing = false;
        }
      });
    }
  } else {
    session.audioBuffer.push(audio);
  }
}

async function handleAudioCommit(ws, session, gateway) {
  if (session.audioBuffer.length === 0) {
    sendEvent(ws, 'error', { type: 'invalid_request', message: 'Buffer de áudio vazio.' });
    return;
  }

  const itemId = 'item_' + crypto.randomBytes(8).toString('hex');

  sendEvent(ws, 'input_audio_buffer.committed', { item_id: itemId });

  // Decodifica cada chunk e concatena os buffers binários (evita corrupção do Base64 pelo padding '=')
  const rawBuffer = Buffer.concat(session.audioBuffer.map(chunk => Buffer.from(chunk, 'base64')));
  session.audioBuffer = [];

  let mimeType = 'audio/webm';
  let audioBase64ToSend = '';

  const isWebM = rawBuffer.length >= 4 && rawBuffer[0] === 0x1a && rawBuffer[1] === 0x45 && rawBuffer[2] === 0xdf && rawBuffer[3] === 0xa3;
  const isWav = rawBuffer.length >= 12 && rawBuffer.subarray(0, 4).toString('ascii') === 'RIFF' && rawBuffer.subarray(8, 12).toString('ascii') === 'WAVE';
  const isOgg = rawBuffer.length >= 4 && rawBuffer.subarray(0, 4).toString('ascii') === 'OggS';
  const isMp3 = rawBuffer.length >= 3 && (rawBuffer.subarray(0, 3).toString('ascii') === 'ID3' || (rawBuffer[0] === 0xff && (rawBuffer[1] & 0xe0) === 0xe0));

  if (isWebM) {
    mimeType = 'audio/webm';
    audioBase64ToSend = rawBuffer.toString('base64');
  } else if (isWav) {
    mimeType = 'audio/wav';
    audioBase64ToSend = rawBuffer.toString('base64');
  } else if (isOgg) {
    mimeType = 'audio/ogg';
    audioBase64ToSend = rawBuffer.toString('base64');
  } else if (isMp3) {
    mimeType = 'audio/mp3';
    audioBase64ToSend = rawBuffer.toString('base64');
  } else {
    const sampleRate = session.sample_rate || 16000;
    const wavBuf = pcmToWav(rawBuffer, sampleRate, 1, 16);
    audioBase64ToSend = wavBuf.toString('base64');
    mimeType = 'audio/wav';
    const durationSec = (rawBuffer.length / (sampleRate * 2)).toFixed(2);
    console.log(`[Realtime STT] Convertido PCM (${rawBuffer.length} bytes / ${durationSec}s) para WAV ${sampleRate}Hz`);
  }

  // Transcrever via Gemini (STT)
  try {
    sendEvent(ws, 'input_audio_buffer.speech_started', { item_id: itemId });

    const contents = [
      {
        role: 'user',
        parts: [
          {
            inlineData: {
              mimeType: mimeType,
              data: audioBase64ToSend
            }
          },
          {
            text: 'Transcreva com máxima precisão o que foi falado neste áudio em português do Brasil. Retorne APENAS o texto exato pronunciado pelo usuário, sem aspas, sem explicações e sem adicionar palavras que não foram faladas. Se for apenas ruído ou silêncio, responda vazio.'
          }
        ]
      }
    ];

    console.log(`[Realtime STT] Iniciando transcrição rápida (${Math.round(audioBase64ToSend.length / 1024)} KB)...`);
    const sttStart = Date.now();
    const { googleResp } = await gateway.executeWithFallback(
      'gemini-2.5-flash',
      contents,
      '',
      'gemini-2.5-flash',
      { temperature: 0.0, maxOutputTokens: 250 }
    );
    session.lastSttLatencyMs = Date.now() - sttStart;

    const actualResp = googleResp?.response || googleResp;
    const candidate = actualResp?.candidates?.[0];
    let transcript = candidate?.content?.parts?.map(p => p.text || '').join(' ').trim() || '';
    transcript = transcript.replace(/^["']|["']$/g, '').trim();

    // Se for ruído, silêncio ou tag do modelo, descarta
    if (/^<noise>|^<silence>|^ruído/i.test(transcript) || transcript.length < 2) {
      transcript = '';
    }

    console.log(`[Realtime STT] Transcrição concluída em ${session.lastSttLatencyMs}ms: "${transcript}"`);

    if (!transcript) {
      console.warn('[Realtime STT] Nenhuma fala identificada no áudio recebido.');
      sendEvent(ws, 'error', { type: 'transcription_error', message: 'Nenhuma fala foi detectada no áudio. Fale um pouco mais perto do microfone.' });
      return;
    }

    sendEvent(ws, 'input_audio_buffer.speech_stopped', { item_id: itemId });

    // Adicionar transcrição ao histórico com sliding window
    session.conversation.push({ role: 'user', content: transcript });
    if (session.conversation.length > 24) {
      session.conversation = session.conversation.slice(-16);
    }

    sendEvent(ws, 'conversation.item.created', {
      item: {
        id: itemId,
        type: 'message',
        role: 'user',
        content: [{ type: 'input_audio', transcript: transcript }]
      }
    });

    sendEvent(ws, 'conversation.item.input_audio_transcription.completed', {
      item_id: itemId,
      transcript: transcript
    });

    // Auto-gerar resposta após transcrição
    await handleResponseCreate(ws, session, gateway, { generate_audio: true });

  } catch (err) {
    console.error('[Realtime STT] Erro:', err.message);
    sendEvent(ws, 'error', { type: 'transcription_error', message: err.message });
  }
}

function handleConversationItemCreate(ws, session, event) {
  const item = event.item || {};
  const itemId = item.id || 'item_' + crypto.randomBytes(8).toString('hex');

  // Suporte a function_call_output (retorno de ferramenta executada no cliente)
  if (item.type === 'function_call_output') {
    session.conversation.push({
      role: 'user',
      content: `[Resultado da ação executada pelo sistema]: ${item.output || ''}`
    });
    sendEvent(ws, 'conversation.item.created', { item });
    return;
  }

  let content = '';
  if (typeof item.content === 'string') {
    content = item.content;
  } else if (Array.isArray(item.content)) {
    content = item.content.map(c => c.text || c.transcript || '').join('\n');
  } else if (item.content?.text) {
    content = item.content.text;
  }

  const role = item.role || 'user';
  session.conversation.push({ role, content });

  sendEvent(ws, 'conversation.item.created', {
    item: {
      id: itemId,
      type: 'message',
      role: role,
      content: [{ type: 'text', text: content }]
    }
  });
}

function handleConversationItemDelete(ws, session, event) {
  const itemId = event.item_id;
  // Remove o último item ou por índice se quiser
  if (session.conversation.length > 0) {
    session.conversation.pop();
  }
  sendEvent(ws, 'conversation.item.deleted', { item_id: itemId });
}

async function handleResponseCreate(ws, session, gateway, event = {}) {
  const responseId = 'resp_' + crypto.randomBytes(8).toString('hex');
  const outputItemId = 'item_' + crypto.randomBytes(8).toString('hex');
  const generateAudio = event.generate_audio !== false; // default true
  const startTimeMs = Date.now();

  const abortController = new AbortController();
  session.activeResponse = abortController;

  sendEvent(ws, 'response.created', {
    response: {
      id: responseId,
      status: 'in_progress',
      output: []
    }
  });

  try {
    // Sliding Window para manter histórico ágil e latência constante
    const maxHistory = session.maxConversationHistory || 16;
    const recentMessages = session.conversation.length > maxHistory
      ? session.conversation.slice(-maxHistory)
      : session.conversation;

    // Montar contents para o LLM
    const contents = [];
    for (const msg of recentMessages) {
      contents.push({
        role: msg.role === 'assistant' ? 'model' : 'user',
        parts: [{ text: msg.content || ' ' }]
      });
    }

    if (contents.length === 0) {
      contents.push({ role: 'user', parts: [{ text: 'Olá' }] });
    }

    // Resolver modelo
    const { targetEngine, customPrompt, providerId, isAdminOnly, isRealtime, multiplier } = await gateway.resolveModelConfig(session.model, contents);
    session.modelMultiplier = multiplier || 1.0;

    if (isRealtime === false) {
      sendEvent(ws, 'error', {
        type: 'invalid_model',
        message: `O modelo "${session.model}" não está habilitado para uso no Realtime.`
      });
      return;
    }

    const isClientAdmin = session.userKey && session.userKey.user_role === 'admin';
    if (isAdminOnly && !isClientAdmin) {
      sendEvent(ws, 'error', {
        type: 'permission_denied',
        message: `O modelo "${session.model}" é exclusivo para administradores.`
      });
      return;
    }

    // --- PESQUISA NA WEB EM TEMPO REAL ---
    const lastUserMsg = session.conversation.filter(m => m.role === 'user').slice(-1)[0]?.content || '';
    let searchContext = '';

    if (session.web_search !== false && shouldSearchWeb(lastUserMsg)) {
      console.log(`[Realtime WebSearch] Buscando na web: "${lastUserMsg}"...`);
      sendEvent(ws, 'response.web_search.started', { query: lastUserMsg });
      try {
        const searchResults = await searchWeb(lastUserMsg, 4);
        if (searchResults && searchResults.length > 0) {
          searchContext = formatSearchResultsForPrompt(searchResults, lastUserMsg);
          console.log(`[Realtime WebSearch] ${searchResults.length} resultados encontrados com sucesso.`);
          sendEvent(ws, 'response.web_search.completed', {
            query: lastUserMsg,
            count: searchResults.length,
            results: searchResults.map(r => ({ title: r.title, url: r.url }))
          });
        } else {
          sendEvent(ws, 'response.web_search.completed', { query: lastUserMsg, count: 0, results: [] });
        }
      } catch (searchErr) {
        console.warn(`[Realtime WebSearch] Erro ao buscar:`, searchErr.message);
        sendEvent(ws, 'response.web_search.failed', { query: lastUserMsg, error: searchErr.message });
      }
    }

    const now = new Date();
    const currentDateStr = now.toLocaleDateString('pt-BR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric', timeZone: 'America/Sao_Paulo' });
    const currentTimeStr = now.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', timeZone: 'America/Sao_Paulo' });

    const realtimeConversationalInstruction = `MODO DE VOZ EM TEMPO REAL: Você está em uma chamada de voz ao vivo. Suas respostas serão lidas por voz neural. Responda SEMPRE em português do Brasil de forma concisa, direta, empática e natural (idealmente de 1 a 3 frases curtas por resposta). NUNCA inclua pensamentos internos ou a palavra "thought". NUNCA use formatações markdown pesadas (como tabelas, múltiplos asteriscos ou listas longas com marcadores) que soem estranhas faladas em voz alta.\nData e hora atual de referência no Brasil: ${currentDateStr}, às ${currentTimeStr}. Se houver dados de pesquisa web fornecidos nas instruções, utilize-os para responder de forma atualizada e precisa. IMPORTANTE: Conclua sempre todas as frases iniciadas de forma completa.`;

    let toolsInstruction = '';
    if (session.tools && session.tools.length > 0) {
      const toolsList = session.tools.map(t => {
        const fn = t.function || t;
        return `- ${fn.name}: ${fn.description || ''}. Parâmetros: ${JSON.stringify(fn.parameters?.properties || {})}`;
      }).join('\n');

      toolsInstruction = `FERRAMENTAS DO SISTEMA:
Se o pedido ou intenção do usuário pedir para executar uma das ferramentas abaixo, você DEVE responder EXCLUSIVAMENTE com um JSON puro no formato:
{"function_call": {"name": "nome_da_ferramenta", "arguments": { ...parâmetros preenchidos... }}}
Não coloque nenhuma palavra antes ou depois do JSON ao chamar uma ferramenta.
Ferramentas disponíveis:
${toolsList}`;
    }

    const finalSystemInstruction = [
      realtimeConversationalInstruction,
      searchContext,
      customPrompt,
      toolsInstruction,
      session.instructions
    ].filter(Boolean).join('\n\n');

    let replyText = '';
    let account = null;
    let fnCall = null;

    const geminiTools = (gateway.convertToolsToGemini && session.tools?.length > 0)
      ? gateway.convertToolsToGemini(session.tools)
      : null;

    if (abortController.signal.aborted || ws.readyState !== 1) return;

    const voiceConfig = AVAILABLE_VOICES[session.voice] || AVAILABLE_VOICES[DEFAULT_VOICE];
    const isBrowserVoice = session.voice === 'browser_fast';
    const voiceRate = session.voice_rate || '+5%';

    // Pipeline de streaming: Frase -> TTS em tempo real
    let sentenceBuffer = '';
    let sentenceIndex = 0;
    const pendingTtsPromises = [];
    let nextAudioToEmit = 0;
    const readyAudioMap = new Map();

    function emitOrderedAudioDeltas() {
      while (readyAudioMap.has(nextAudioToEmit)) {
        if (abortController.signal.aborted || ws.readyState !== 1) break;
        const item = readyAudioMap.get(nextAudioToEmit);
        readyAudioMap.delete(nextAudioToEmit);
        if (item && item.audioBase64) {
          sendEvent(ws, 'response.audio.delta', {
            response_id: responseId,
            item_id: outputItemId,
            sentence_index: item.sIdx,
            delta: item.audioBase64,
            transcript: item.sentence
          });
        }
        nextAudioToEmit++;
      }
    }

    function dispatchSentenceForTTS(sentence) {
      if (!generateAudio || !sentence || sentence.length < 2 || isBrowserVoice) return;
      const sIdx = sentenceIndex++;
      const p = generateTTS(sentence, voiceConfig.id, voiceRate, abortController.signal)
        .then(audioBase64 => {
          if (abortController.signal.aborted || ws.readyState !== 1) return;
          readyAudioMap.set(sIdx, { sIdx, sentence, audioBase64 });
          emitOrderedAudioDeltas();
        })
        .catch(err => {
          readyAudioMap.set(sIdx, null);
          emitOrderedAudioDeltas();
        });
      pendingTtsPromises.push(p);
    }

    function onStreamToken(deltaText, capturedFc) {
      if (abortController.signal.aborted || ws.readyState !== 1) return;

      if (capturedFc && !fnCall) {
        fnCall = { name: capturedFc.name, arguments: capturedFc.args || {} };
        return;
      }

      if (!deltaText) return;

      replyText += deltaText;
      sentenceBuffer += deltaText;

      // Emite delta de texto em tempo real para a interface
      sendEvent(ws, 'response.text.delta', {
        response_id: responseId,
        item_id: outputItemId,
        delta: deltaText
      });
      sendEvent(ws, 'response.audio_transcript.delta', {
        response_id: responseId,
        item_id: outputItemId,
        delta: deltaText
      });

      // Fatiamento contínuo em frases para disparo imediato do Edge-TTS
      const sentenceDelimMatch = sentenceBuffer.match(/^(.*?[\.\!\?\n\:])\s*(.*)$/s);
      if (sentenceDelimMatch && sentenceDelimMatch[1].trim().length >= 15) {
        const sentence = sentenceDelimMatch[1].trim();
        sentenceBuffer = sentenceDelimMatch[2] || '';
        dispatchSentenceForTTS(sentence);
      } else if (sentenceBuffer.length > 80) {
        const commaMatch = sentenceBuffer.match(/^(.*?[,;])\s*(.*)$/s);
        if (commaMatch && commaMatch[1].trim().length >= 25) {
          const sentence = commaMatch[1].trim();
          sentenceBuffer = commaMatch[2] || '';
          dispatchSentenceForTTS(sentence);
        }
      }
    }

    if (providerId && providerId != 2) {
      // Provedor externo com fallback automático para Google Pool
      try {
        const incomingMessages = session.conversation.map(m => ({
          role: m.role,
          content: m.content
        }));
        const extResult = await gateway.executeExternalProvider(providerId, targetEngine, incomingMessages, finalSystemInstruction, session.tools);
        replyText = extResult.replyText;
        if (extResult.toolCalls && extResult.toolCalls.length > 0) {
          fnCall = {
            name: extResult.toolCalls[0].name,
            arguments: extResult.toolCalls[0].args || {}
          };
        }
      } catch (extErr) {
        console.warn(`[Realtime] Provedor externo (${providerId}) falhou (${extErr.message}). Acionando fallback automático para Google Antigravity Pool...`);
        const { googleResp, account: acc } = await gateway.executeWithFallback(
          targetEngine || 'gemini-2.5-flash',
          contents,
          finalSystemInstruction,
          session.model,
          { temperature: session.temperature || 0.7, maxOutputTokens: 480, tools: geminiTools }
        );
        account = acc;
        const actualResp = googleResp.response || googleResp;
        const candidates = actualResp.candidates || [];
        if (candidates.length > 0 && candidates[0].content?.parts) {
          for (const p of candidates[0].content.parts) {
            if (p.text) replyText += p.text;
            if (p.functionCall && !fnCall) {
              fnCall = { name: p.functionCall.name, arguments: p.functionCall.args || {} };
            }
          }
        }
      }
    } else {
      // Google Antigravity Pool com streaming nativo (TTFB < 400ms!)
      const engine = targetEngine || 'gemini-2.5-flash';
      if (gateway.executeWithFallbackStream) {
        const { googleResp, account: acc } = await gateway.executeWithFallbackStream(
          engine,
          contents,
          finalSystemInstruction,
          session.model,
          { temperature: session.temperature || 0.7, maxOutputTokens: 480, tools: geminiTools },
          (chunkText, chunkFc) => onStreamToken(chunkText, chunkFc),
          abortController.signal
        );
        account = acc;
      } else {
        const { googleResp, account: acc } = await gateway.executeWithFallback(
          engine,
          contents,
          finalSystemInstruction,
          session.model,
          { temperature: session.temperature || 0.7, maxOutputTokens: 480, tools: geminiTools }
        );
        account = acc;
        const actualResp = googleResp.response || googleResp;
        const candidates = actualResp.candidates || [];
        if (candidates.length > 0 && candidates[0].content?.parts) {
          for (const p of candidates[0].content.parts) {
            if (p.text) replyText += p.text;
            if (p.functionCall && !fnCall) {
              fnCall = { name: p.functionCall.name, arguments: p.functionCall.args || {} };
            }
          }
        }
      }
    }

    if (abortController.signal.aborted || ws.readyState !== 1) return;

    // Remover qualquer resquício ou marcador de "thought" que o modelo possa ter gerado
    replyText = replyText.replace(/<\/?thought>/gi, '').replace(/\bthought\b/gi, '').replace(/\s{2,}/g, ' ').trim();

    if (abortController.signal.aborted || ws.readyState !== 1) return;

    // Fallback: se não veio functionCall nativo, detectar JSON no texto
    if (!fnCall) {
      try {
        const cleanJson = replyText.trim().replace(/^```json\s*|```$/g, '').trim();
        if (cleanJson.includes('"function_call"')) {
          const parsed = JSON.parse(cleanJson);
          if (parsed.function_call && parsed.function_call.name) {
            fnCall = parsed.function_call;
          }
        }
      } catch (e) { }
    }

    if (fnCall) {
      console.log(`[Realtime Function Call] Executando ferramenta: "${fnCall.name}"`);
      const callId = 'call_' + crypto.randomBytes(8).toString('hex');
      const argsStr = typeof fnCall.arguments === 'string' ? fnCall.arguments : JSON.stringify(fnCall.arguments || {});

      sendEvent(ws, 'response.function_call_arguments.done', {
        response_id: responseId,
        item_id: outputItemId,
        call_id: callId,
        name: fnCall.name,
        arguments: argsStr
      });

      sendEvent(ws, 'response.done', {
        response: {
          id: responseId,
          status: 'completed',
          output: [{
            id: outputItemId,
            type: 'function_call',
            call_id: callId,
            name: fnCall.name,
            arguments: argsStr
          }],
          usage: { input_tokens: 20, output_tokens: 20, total_tokens: 40 }
        }
      });
      return;
    }

    // Se sobrou texto na ponta do buffer de streaming, despacha a última frase
    if (sentenceBuffer.trim().length > 0) {
      dispatchSentenceForTTS(sentenceBuffer.trim());
      sentenceBuffer = '';
    }

    // Se o cliente escolheu voz nativa do navegador, emite evento imediato
    if (isBrowserVoice) {
      sendEvent(ws, 'response.audio.delta', {
        response_id: responseId,
        item_id: outputItemId,
        sentence_index: 0,
        total_sentences: 1,
        delta: null,
        transcript: replyText
      });
      sendEvent(ws, 'response.audio.done', {
        response_id: responseId,
        item_id: outputItemId
      });
    } else if (pendingTtsPromises.length === 0 && replyText.length > 0 && generateAudio) {
      // Se não havia entrado pelo streaming (ex: fallback sem stream), divide e gera
      const sentences = splitIntoSentences(replyText);
      for (const s of sentences) {
        dispatchSentenceForTTS(s);
      }
    }

    // Aguarda todas as frases de áudio pendentes concluírem
    if (pendingTtsPromises.length > 0) {
      await Promise.all(pendingTtsPromises);
      emitOrderedAudioDeltas();
      if (!abortController.signal.aborted && ws.readyState === 1) {
        sendEvent(ws, 'response.audio.done', {
          response_id: responseId,
          item_id: outputItemId
        });
      }
    }

    if (abortController.signal.aborted || ws.readyState !== 1) return;

    // Finalizar eventos de texto
    sendEvent(ws, 'response.text.done', {
      response_id: responseId,
      item_id: outputItemId,
      output_index: 0,
      content_index: 0,
      text: replyText
    });
    sendEvent(ws, 'response.audio_transcript.done', {
      response_id: responseId,
      item_id: outputItemId,
      transcript: replyText
    });

    // Adicionar resposta ao histórico com sliding window
    session.conversation.push({ role: 'assistant', content: replyText });
    if (session.conversation.length > 24) {
      session.conversation = session.conversation.slice(-16);
    }

    // Contabilizar uso
    const inputTokens = Math.ceil(JSON.stringify(contents).length / 4);
    const outputTokens = Math.ceil(replyText.length / 4);
    const totalLatency = Date.now() - startTimeMs;

    sendEvent(ws, 'response.done', {
      response: {
        id: responseId,
        status: 'completed',
        latency_ms: totalLatency,
        metrics: {
          stt_latency_ms: session.lastSttLatencyMs || 0,
          total_latency_ms: totalLatency
        },
        output: [{
          id: outputItemId,
          type: 'message',
          role: 'assistant',
          content: [{ type: 'text', text: replyText }]
        }],
        usage: {
          input_tokens: inputTokens,
          output_tokens: outputTokens,
          total_tokens: inputTokens + outputTokens
        }
      }
    });

    // Log de uso com multiplicador do modelo
    gateway.logUsage(session.userKey, session.model, inputTokens, outputTokens, account?.id || null, session.modelMultiplier || 1.0);

  } catch (err) {
    if (abortController.signal.aborted) return;
    console.error('[Realtime Response] Erro:', err.message);
    sendEvent(ws, 'error', { type: 'response_error', message: err.message });
    sendEvent(ws, 'response.done', {
      response: {
        id: responseId,
        status: 'failed',
        output: [],
        usage: { input_tokens: 0, output_tokens: 0, total_tokens: 0 }
      }
    });
  } finally {
    session.activeResponse = null;
  }
}

// =============================================
// TTS COM NODE-EDGE-TTS (GRATUITO E REALISTA)
// =============================================

// Cache em memória de respostas TTS (entrega instantânea de 0ms para frases repetidas)
const ttsMemoryCache = new Map();
const MAX_TTS_CACHE = 500;

async function generateTTS(text, voiceId, rate = '+5%', abortSignal = null) {
  if (abortSignal?.aborted) return null;

  const cleanText = cleanTextForSpeech(text);
  if (!cleanText || cleanText.length < 2) return null;

  const cacheKey = `${voiceId}_${rate}_${cleanText}`;

  if (ttsMemoryCache.has(cacheKey)) {
    return ttsMemoryCache.get(cacheKey);
  }

  let tmpFile = null;
  try {
    if (abortSignal?.aborted) return null;

    const voiceLang = (voiceId && voiceId.startsWith('pt')) ? 'pt-BR' : 'en-US';
    const tts = new EdgeTTS({ voice: voiceId, lang: voiceLang, rate: rate });
    tmpFile = path.join(os.tmpdir(), `tts_${crypto.randomBytes(8).toString('hex')}.mp3`);

    // Race com abortSignal para interromper a síntese no Barge-in instantaneamente
    await Promise.race([
      tts.ttsPromise(cleanText, tmpFile),
      new Promise((_, reject) => {
        if (abortSignal?.aborted) return reject(new Error('TTS_ABORTED'));
        abortSignal?.addEventListener('abort', () => reject(new Error('TTS_ABORTED')), { once: true });
      })
    ]);

    if (abortSignal?.aborted) return null;

    const buffer = await fs.promises.readFile(tmpFile);
    if (abortSignal?.aborted) return null;

    const base64Audio = buffer.toString('base64');

    if (ttsMemoryCache.size >= MAX_TTS_CACHE) {
      const firstKey = ttsMemoryCache.keys().next().value;
      ttsMemoryCache.delete(firstKey);
    }
    ttsMemoryCache.set(cacheKey, base64Audio);

    return base64Audio;
  } catch (err) {
    if (err.message !== 'TTS_ABORTED' && !abortSignal?.aborted) {
      console.error('[Edge TTS] Erro ao gerar áudio:', err.message);
    }
    return null;
  } finally {
    if (tmpFile) {
      fs.promises.unlink(tmpFile).catch(() => { });
    }
  }
}

// =============================================
// UTILS
// =============================================

function sendEvent(ws, type, data) {
  if (ws && ws.readyState === 1) { // OPEN
    try {
      ws.send(JSON.stringify({
        type: type,
        event_id: 'evt_' + crypto.randomBytes(6).toString('hex'),
        ...data
      }));
    } catch (e) {
      console.warn(`[Realtime] Falha ao enviar evento ${type}:`, e.message);
    }
  }
}


function cleanTextForSpeech(text) {
  if (!text) return '';
  return text
    .replace(/\[([^\]]+)\]\([^\)]+\)/g, '$1') // remove links markdown [texto](url) -> texto
    .replace(/https?:\/\/\S+/g, '')             // remove URLs puras
    .replace(/[*_~`#>\-+]/g, ' ')                  // remove caracteres de formatação markdown (*, _, #, `, etc.)
    .replace(/\s+/g, ' ')                          // normaliza espaços múltiplos
    .trim();
}

function splitIntoSentences(text) {
  if (!text) return [];
  // Divide por pontuação mantendo trechos naturais para áudio fluído
  const rawParts = text.split(/(?<=[.!?\n])\s+/);
  const sentences = [];
  let current = '';

  for (const part of rawParts) {
    const trimmed = part.trim();
    if (!trimmed) continue;
    // Agrupar frases muito curtas (ex: 'Sim.', 'Não.') para não gerar áudios de 0.2s
    if (current.length + trimmed.length < 90 && !/[.!?\n]$/.test(current)) {
      current = current ? current + ' ' + trimmed : trimmed;
    } else {
      if (current) sentences.push(current);
      current = trimmed;
    }
  }
  if (current) sentences.push(current);
  return sentences.length > 0 ? sentences : [text.trim()];
}

function sleep(ms) {
  return new Promise(r => setTimeout(r, ms));
}

export { AVAILABLE_VOICES };
