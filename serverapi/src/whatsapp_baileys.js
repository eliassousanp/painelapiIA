import makeWASocketPkg, { DisconnectReason, useMultiFileAuthState } from '@whiskeysockets/baileys';
import pino from 'pino';
import qrcode from 'qrcode';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const makeWASocket = makeWASocketPkg.default || makeWASocketPkg;

// Garante que a pasta auth_info_baileys fique sempre dentro de serverapi/ independente do diretório de execução
const SESSION_DIR = path.resolve(__dirname, '../auth_info_baileys');

export function normalizeBrazilianPhone(rawPhone) {
  let clean = String(rawPhone || '').replace(/\D/g, '');
  if (!clean) return '';
  if (clean.startsWith('0')) clean = clean.substring(1);
  if (clean.startsWith('55') && clean.length > 11) {
    clean = clean.substring(2);
  }

  if (clean.length === 10 || clean.length === 11) {
    const ddd = parseInt(clean.substring(0, 2), 10);
    const rest = clean.substring(2);

    // DDDs 11 a 28 (SP, RJ, ES) exigem estritamente o 9º dígito
    if (ddd >= 11 && ddd <= 28) {
      if (rest.length === 8) {
        clean = `${ddd}9${rest}`;
      }
    }
  }

  return '55' + clean;
}

// Estado global da sessão em memória
export const baileysState = {
  sock: null,
  status: 'disconnected', // 'disconnected', 'connecting', 'connected'
  qrRaw: null,
  qrDataUrl: null,
  phone: '',
  instanceKey: 'inst_default'
};

let dbPool = null;
let aiFallbackHandler = null;

export async function initWhatsAppBaileys(pool, executeWithFallback) {
  dbPool = pool;
  aiFallbackHandler = executeWithFallback;

  // Se já existe sessão salva no disco, tenta conectar automaticamente
  if (fs.existsSync(SESSION_DIR) && fs.readdirSync(SESSION_DIR).length > 0) {
    console.log('[Baileys] Sessão anterior encontrada. Reconectando...');
    connectToWhatsApp().catch(err => console.error('[Baileys] Erro ao reconectar:', err));
  } else {
    console.log('[Baileys] Nenhuma sessão ativa. Aguardando comando para gerar QR Code.');
  }
}

export async function connectToWhatsApp() {
  try {
    if (!fs.existsSync(SESSION_DIR)) {
      fs.mkdirSync(SESSION_DIR, { recursive: true });
    }

    const { state, saveCreds } = await useMultiFileAuthState(SESSION_DIR);

    baileysState.status = 'connecting';
    baileysState.qrRaw = null;
    baileysState.qrDataUrl = null;

    const sock = makeWASocket({
      auth: state,
      printQRInTerminal: false,
      logger: pino({ level: 'silent' }),
      browser: ['BRLove API Gateway', 'Chrome', '1.0.0']
    });

    baileysState.sock = sock;

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async (update) => {
      const { connection, lastDisconnect, qr } = update;

      if (qr) {
        baileysState.qrRaw = qr;
        try {
          baileysState.qrDataUrl = await qrcode.toDataURL(qr);
        } catch (e) {
          baileysState.qrDataUrl = null;
        }
        baileysState.status = 'connecting';

        console.log('[Baileys] Novo QR Code real gerado! Pronto para leitura no WhatsApp Web.');

        if (dbPool) {
          try {
            await dbPool.query(
              'INSERT INTO whatsapp_instances (instance_key, instance_name, status, qr_code) VALUES (?, ?, "connecting", ?) ON DUPLICATE KEY UPDATE status = "connecting", qr_code = ?',
              [baileysState.instanceKey, 'WhatsApp Principal', qr, qr]
            );
          } catch (e) {}
        }
      }

      if (connection === 'open') {
        baileysState.status = 'connected';
        baileysState.qrRaw = null;
        baileysState.qrDataUrl = null;

        const rawUser = sock.user?.id || '';
        const phone = rawUser.split(':')[0] || rawUser.split('@')[0];
        baileysState.phone = phone;

        console.log(`\n=============================================================`);
        console.log(`✅ [Baileys] WHATSAPP REAL CONECTADO COM SUCESSO!`);
        console.log(`📱 Número: +${phone}`);
        console.log(`=============================================================\n`);

        if (dbPool) {
          try {
            await dbPool.query(
              'UPDATE whatsapp_instances SET status = "connected", qr_code = NULL WHERE instance_key = ?',
              [baileysState.instanceKey]
            );
          } catch (e) {}
        }
      }

      if (connection === 'close') {
        const statusCode = lastDisconnect?.error?.output?.statusCode;
        const shouldReconnect = statusCode !== DisconnectReason.loggedOut;

        console.log(`[Baileys] Conexão fechada (Código: ${statusCode}). Reconectar: ${shouldReconnect}`);

        if (statusCode === DisconnectReason.loggedOut) {
          baileysState.status = 'disconnected';
          baileysState.sock = null;
          baileysState.phone = '';
          baileysState.qrRaw = null;
          baileysState.qrDataUrl = null;

          try {
            fs.rmSync(SESSION_DIR, { recursive: true, force: true });
          } catch (e) {}

          if (dbPool) {
            try {
              await dbPool.query(
                'UPDATE whatsapp_instances SET status = "disconnected", qr_code = NULL WHERE instance_key = ?',
                [baileysState.instanceKey]
              );
            } catch (e) {}
          }
        } else if (shouldReconnect) {
          setTimeout(() => connectToWhatsApp(), 3000);
        }
      }
    });

    // AUTO-RESPONDER COM AGENTE IA AO RECEBER MENSAGEM
    sock.ev.on('messages.upsert', async ({ messages, type }) => {
      if (type !== 'notify') return;

      for (const msg of messages) {
        // Ignora mensagens enviadas pelo próprio bot, grupos, canais e stories/status
        if (msg.key.fromMe) continue;
        const remoteJid = msg.key.remoteJid;
        if (!remoteJid || remoteJid.endsWith('@g.us') || remoteJid.endsWith('@newsletter') || remoteJid === 'status@broadcast') continue;

        const text = msg.message?.conversation || msg.message?.extendedTextMessage?.text || '';
        if (!text || text.trim() === '') continue;

        const senderPhone = remoteJid.split('@')[0];
        console.log(`[Baileys] Mensagem recebida de +${senderPhone}: "${text}"`);

        // Salvar mensagem recebida no histórico
        if (dbPool) {
          try {
            await dbPool.query(
              'INSERT INTO whatsapp_messages (instance_key, destination, message, status, sent_at) VALUES (?, ?, ?, "received", NOW())',
              [baileysState.instanceKey, senderPhone, text]
            );
          } catch (e) {}
        }

        // Verificar se o Agente IA está ativo
        if (dbPool && aiFallbackHandler) {
          try {
            const [rows] = await dbPool.query(
              "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('whatsapp_bot_active', 'whatsapp_bot_model', 'whatsapp_bot_prompt')"
            );
            const settingsMap = {};
            rows.forEach(r => { settingsMap[r.setting_key] = r.setting_value; });

            const isBotActive = settingsMap['whatsapp_bot_active'] === '1';
            if (isBotActive) {
              const botModel = settingsMap['whatsapp_bot_model'] || 'gemini-3.8-flash-medium';
              const basePrompt = settingsMap['whatsapp_bot_prompt'] || 'Você é a atendente virtual da BRLove API.';

              // Busca planos ativos
              let plansText = 'Nenhum plano cadastrado no momento.';
              try {
                const [plans] = await dbPool.query(
                  "SELECT name, price, reseller_price, tokens_amount, target_audience, features FROM plans WHERE status = 'active' ORDER BY price ASC"
                );
                if (plans.length > 0) {
                  plansText = plans.map(p => {
                    const tokens = Number(p.tokens_amount).toLocaleString('pt-BR');
                    const desc = p.features ? ` (${p.features.replace(/\n/g, ', ')})` : '';
                    return `- Plano ${p.name}: R$ ${p.price} (${tokens} tokens)${desc} [Público: ${p.target_audience === 'reseller' ? 'Revenda' : 'Cliente'}]`;
                  }).join('\n');
                }
              } catch (e) {}

              // Busca modelos ativos criados pelo administrador
              let modelsText = 'Nenhum modelo cadastrado no momento.';
              try {
                const [models] = await dbPool.query(
                  "SELECT model_key, name FROM models WHERE status = 'active' ORDER BY id ASC"
                );
                if (models.length > 0) {
                  modelsText = models.map(m => `- ${m.name} (Chave do modelo: ${m.model_key})`).join('\n');
                }
              } catch (e) {}

              const fullSystemPrompt = `${basePrompt}

[INFORMAÇÕES OFICIAIS DO SISTEMA EM TEMPO REAL]
Aqui estão os dados exatos e atualizados do catálogo de planos e modelos que você oferece aos clientes. Use essas informações para responder com total precisão quando perguntarem sobre valores, planos ou quais modelos estão disponíveis:

--- MODELOS DE INTELIGÊNCIA ARTIFICIAL DISPONÍVEIS NA PLATAFORMA ---
${modelsText}

--- TABELA OFICIAL DE PLANOS & RECARGAS DE TOKENS ---
${plansText}
`;

              console.log(`[Baileys Agente IA] Respondendo +${senderPhone} com ${botModel}...`);

              const contents = [{
                role: 'user',
                parts: [{ text }]
              }];

              const { googleResp } = await aiFallbackHandler(botModel, contents, fullSystemPrompt, botModel);
              const actualResp = googleResp.response || googleResp;
              let replyText = '';
              if (actualResp.candidates && actualResp.candidates[0]?.content?.parts) {
                replyText = actualResp.candidates[0].content.parts.map(p => p.text || '').join('');
              }

              if (replyText) {
                // Envia a resposta de volta ao WhatsApp real
                await sock.sendMessage(remoteJid, { text: replyText });
                console.log(`[Baileys Agente IA] Resposta entregue para +${senderPhone}!`);

                await dbPool.query(
                  'INSERT INTO whatsapp_messages (instance_key, destination, message, status, sent_at) VALUES (?, ?, ?, "bot_reply", NOW())',
                  [baileysState.instanceKey, senderPhone, replyText]
                );
              }
            }
          } catch (botErr) {
            console.error('[Baileys Agente IA] Erro ao gerar resposta automática:', botErr.message || botErr);
          }
        }
      }
    });

    return baileysState;
  } catch (err) {
    console.error('[Baileys] Erro fatal ao iniciar socket:', err);
    baileysState.status = 'disconnected';
    throw err;
  }
}

export async function disconnectWhatsApp() {
  if (baileysState.sock) {
    try {
      await baileysState.sock.logout();
    } catch (e) {}
  }
  baileysState.sock = null;
  baileysState.status = 'disconnected';
  baileysState.phone = '';
  baileysState.qrRaw = null;
  baileysState.qrDataUrl = null;

  try {
    fs.rmSync(SESSION_DIR, { recursive: true, force: true });
  } catch (e) {}

  if (dbPool) {
    try {
      await dbPool.query(
        'UPDATE whatsapp_instances SET status = "disconnected", qr_code = NULL WHERE instance_key = ?',
        [baileysState.instanceKey]
      );
    } catch (e) {}
  }

  return { success: true, message: 'WhatsApp desconectado com sucesso.' };
}

export async function sendWhatsAppMessage(to, message) {
  const cleanPhone = normalizeBrazilianPhone(to);
  if (!cleanPhone) {
    return { success: false, error: 'Número de WhatsApp inválido.' };
  }

  const remoteJid = cleanPhone + '@s.whatsapp.net';

  // Se o Baileys estiver conectado de verdade:
  if (baileysState.sock && baileysState.status === 'connected') {
    try {
      await baileysState.sock.sendMessage(remoteJid, { text: message });
      console.log(`[Baileys Real] Mensagem enviada para ${cleanPhone}: "${message.substring(0, 50)}..."`);

      if (dbPool) {
        try {
          await dbPool.query(
            'INSERT INTO whatsapp_messages (instance_key, destination, message, status, sent_at) VALUES (?, ?, ?, "sent", NOW())',
            [baileysState.instanceKey, cleanPhone, message]
          );
        } catch (e) {}
      }

      return {
        success: true,
        message: 'Mensagem entregue com sucesso via WhatsApp Web.',
        to: cleanPhone,
        remote_jid: remoteJid,
        timestamp: new Date().toISOString()
      };
    } catch (err) {
      console.error(`[Baileys Real] Erro ao enviar mensagem para ${cleanPhone}:`, err.message || err);
      return { success: false, error: 'Erro ao enviar via WhatsApp: ' + err.message };
    }
  }

  // Se não estiver conectado, simula registro para teste ou avisa
  console.log(`[Baileys] Socket não conectado. Registrando envio simulado para ${cleanPhone}: "${message.substring(0, 50)}..."`);
  if (dbPool) {
    try {
      await dbPool.query(
        'INSERT INTO whatsapp_messages (instance_key, destination, message, status, sent_at) VALUES (?, ?, ?, "sent_simulated", NOW())',
        [baileysState.instanceKey, cleanPhone, message]
      );
    } catch (e) {}
  }

  return {
    success: true,
    simulated: true,
    message: 'Mensagem registrada (WhatsApp desconectado no momento). Conecte pelo QR Code para envio real.',
    to: cleanPhone,
    remote_jid: remoteJid,
    timestamp: new Date().toISOString()
  };
}
