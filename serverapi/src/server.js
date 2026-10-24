import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
// Carrega o .env localizado na pasta serverapi independentemente do CWD
dotenv.config({ path: path.resolve(__dirname, '../.env') });
dotenv.config(); // fallback para CWD se existir

import express from 'express';
import { createServer } from 'http';
import cors from 'cors';
import mysql from 'mysql2/promise';
import crypto from 'crypto';
import { startOAuthFlow, refreshAccessToken, exchangeOAuthCode, closeActiveAuthServer } from './oauth.js';
import { onboardUser, fetchUserQuotas, generateContent, streamGenerateContent } from './antigravity.js';
import { initRealtime, AVAILABLE_VOICES } from './realtime.js';
import { initMcp } from './mcp.js';
import {
  initWhatsAppBaileys,
  connectToWhatsApp,
  disconnectWhatsApp,
  sendWhatsAppMessage,
  baileysState
} from './whatsapp_baileys.js';
import AntigravityAccountPool from './services/account_pool.js';

import fs from 'fs';

const app = express();
app.use(cors());

// app.use((req, res, next) => { console.log(`[GATEWAY] ${req.method} ${req.url}`); next(); });

app.use(express.json({ limit: '50mb' }));

// Middleware para evitar que JSON malformado derrube o servidor
app.use((err, req, res, next) => {
  if (err instanceof SyntaxError && err.status === 400 && 'body' in err) {
    console.error('[Gateway] Requisição com JSON malformado ignorada:', err.message);
    return res.status(400).json({ error: { message: 'Invalid JSON payload received.', type: 'invalid_request_error' } });
  }
  next();
});

process.on('uncaughtException', (err) => {
  console.error('[Gateway UncaughtException]:', err.message || err);
});

process.on('unhandledRejection', (reason) => {
  console.error('[Gateway UnhandledRejection]:', reason);
});

let dbConfig = {
  host: process.env.DB_HOST || '127.0.0.1',
  port: parseInt(process.env.DB_PORT || '3306'),
  user: process.env.DB_USER || 'root',
  password: process.env.DB_PASSWORD || '',
  database: process.env.DB_NAME || 'api_gateway',
  waitForConnections: true,
  connectionLimit: 20
};

// Fallback inteligente: se não houver senha no .env, tenta ler do config/database.php gerado pelo instalador
try {
  const phpDbPath = path.resolve(__dirname, '../../config/database.php');
  if (fs.existsSync(phpDbPath)) {
    const phpContent = fs.readFileSync(phpDbPath, 'utf8');
    const hostMatch = phpContent.match(/\$db_host\s*=\s*['"]([^'"]+)['"]/);
    const portMatch = phpContent.match(/\$db_port\s*=\s*['"]([^'"]+)['"]/);
    const nameMatch = phpContent.match(/\$db_name\s*=\s*['"]([^'"]+)['"]/);
    const userMatch = phpContent.match(/\$db_user\s*=\s*['"]([^'"]+)['"]/);
    const passMatch = phpContent.match(/\$db_pass\s*=\s*['"]([^'"]*)['"]/);

    if (userMatch) {
      if (hostMatch) dbConfig.host = hostMatch[1];
      if (portMatch) dbConfig.port = parseInt(portMatch[1]);
      if (nameMatch) dbConfig.database = nameMatch[1];
      if (userMatch) dbConfig.user = userMatch[1];
      if (passMatch) dbConfig.password = passMatch[1];
      console.log(`[Database] Credenciais carregadas com sucesso de config/database.php (Usuário: ${dbConfig.user}, Banco: ${dbConfig.database})`);
    }
  }
} catch (e) {
  console.warn('[Database] Aviso ao ler fallback de database.php:', e.message);
}

app.get('/mcp/debug-env', (req, res) => {
  res.json({
    dbConfig_user: dbConfig.user,
    dbConfig_db: dbConfig.database,
    has_password: Boolean(dbConfig.password),
    phpDbPath: path.resolve(__dirname, '../../config/database.php'),
    phpDbExists: fs.existsSync(path.resolve(__dirname, '../../config/database.php')),
    env_user: process.env.DB_USER || null
  });
});

const pool = mysql.createPool(dbConfig);
const accountPool = new AntigravityAccountPool(pool);

// Garantir colunas de multiplicador no banco
(async () => {
  try {
    await pool.query('ALTER TABLE models ADD COLUMN multiplier DECIMAL(5,2) DEFAULT 1.00');
  } catch (e) {}
  try {
    await pool.query('ALTER TABLE models ADD COLUMN routing_fast_multiplier DECIMAL(5,2) DEFAULT 1.00');
  } catch (e) {}
  try {
    await pool.query('ALTER TABLE models ADD COLUMN routing_medium_multiplier DECIMAL(5,2) DEFAULT 1.50');
  } catch (e) {}
  try {
    await pool.query('ALTER TABLE models ADD COLUMN routing_heavy_multiplier DECIMAL(5,2) DEFAULT 3.00');
  } catch (e) {}
  try {
    await pool.query('ALTER TABLE usage_logs ADD COLUMN multiplier DECIMAL(5,2) DEFAULT 1.00');
  } catch (e) {}
})();

async function getSetting(key, defaultValue = '') {
  try {
    const [rows] = await pool.query('SELECT setting_value FROM settings WHERE setting_key = ?', [key]);
    return rows.length > 0 ? rows[0].setting_value : defaultValue;
  } catch (err) {
    return defaultValue;
  }
}

// -------------------------------------------------------------
// ROTA OAUTH PARA CONEXÃO DE CONTAS GOOGLE ANTIGRAVITY
// -------------------------------------------------------------
app.get('/auth/antigravity/login', (req, res) => {
  try {
    const clientRedirect = req.query.redirect || null;
    const authUrl = startOAuthFlow(async (accountData) => {
      console.log(`[OAuth Antigravity] Sucesso! Vinculando conta: ${accountData.email}`);

      let projectId = 'aicode-consumers';
      try {
        projectId = await onboardUser(accountData.accessToken);
        console.log(`[OAuth Antigravity] Project ID resolvido: ${projectId}`);
      } catch (e) {
        console.warn(`[OAuth Antigravity] Aviso ao resolver Project ID:`, e.message);
      }

      let quotasJson = null;
      try {
        const quotas = await fetchUserQuotas(accountData.accessToken, projectId);
        if (quotas) quotasJson = JSON.stringify(quotas);
      } catch (e) { }

      const expiresAt = new Date(Date.now() + (accountData.expiresIn * 1000));

      await pool.query(`
        INSERT INTO antigravity_accounts 
        (email, name, picture, refresh_token, access_token, token_expires_at, project_id, quotas_json, last_sync_at, is_active, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1, 'active')
        ON DUPLICATE KEY UPDATE
          name = VALUES(name),
          picture = VALUES(picture),
          refresh_token = VALUES(refresh_token),
          access_token = VALUES(access_token),
          token_expires_at = VALUES(token_expires_at),
          project_id = VALUES(project_id),
          quotas_json = VALUES(quotas_json),
          last_sync_at = NOW(),
          is_active = 1,
          status = 'active'
      `, [
        accountData.email,
        accountData.name,
        accountData.picture,
        accountData.refreshToken,
        accountData.accessToken,
        expiresAt,
        projectId,
        quotasJson
      ]);

      console.log(`[OAuth Antigravity] Conta ${accountData.email} salva no banco com sucesso!`);
    }, (err) => {
      console.error('[OAuth Antigravity] Erro no fluxo OAuth:', err);
    }, clientRedirect);

    res.redirect(authUrl);
  } catch (err) {
    res.status(500).send(`Erro ao iniciar OAuth Antigravity: ${err.message}`);
  }
});

// ROTA PARA CONEXÃO MANUAL VIA CÓDIGO/URL (PARA USO EM VPS REMOTA)
// -------------------------------------------------------------
app.all('/auth/antigravity/manual-callback', async (req, res) => {
  try {
    let rawCode = req.body?.code || req.query?.code || req.body?.codeUrl || req.query?.codeUrl;
    if (!rawCode) {
      return res.status(400).json({ error: 'Nenhum código ou URL de callback fornecido.' });
    }

    let code = rawCode;
    let state = null;
    // Se colou a URL inteira (ex: http://localhost:51121/oauth-callback?code=4/0A...&state=...)
    if (rawCode.includes('code=')) {
      const match = rawCode.match(/[?&]code=([^&]+)/);
      if (match) code = decodeURIComponent(match[1]);
    }
    if (rawCode.includes('state=')) {
      const matchState = rawCode.match(/[?&]state=([^&]+)/);
      if (matchState) state = decodeURIComponent(matchState[1]);
    }

    console.log(`[OAuth Antigravity Manual/VPS] Trocando código por token...`);
    const accountData = await exchangeOAuthCode(code, null, state);
    closeActiveAuthServer();

    let projectId = 'aicode-consumers';
    try {
      projectId = await onboardUser(accountData.accessToken);
    } catch (e) { }

    let quotasJson = null;
    try {
      const quotas = await fetchUserQuotas(accountData.accessToken, projectId);
      if (quotas) quotasJson = JSON.stringify(quotas);
    } catch (e) { }

    const expiresAt = new Date(Date.now() + (accountData.expiresIn * 1000));

    await pool.query(`
      INSERT INTO antigravity_accounts 
      (email, name, picture, refresh_token, access_token, token_expires_at, project_id, quotas_json, last_sync_at, is_active, status)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 1, 'active')
      ON DUPLICATE KEY UPDATE
        name = VALUES(name),
        picture = VALUES(picture),
        refresh_token = VALUES(refresh_token),
        access_token = VALUES(access_token),
        token_expires_at = VALUES(token_expires_at),
        project_id = VALUES(project_id),
        quotas_json = VALUES(quotas_json),
        last_sync_at = NOW(),
        is_active = 1,
        status = 'active'
    `, [
      accountData.email,
      accountData.name,
      accountData.picture,
      accountData.refreshToken,
      accountData.accessToken,
      expiresAt,
      projectId,
      quotasJson
    ]);

    console.log(`[OAuth Antigravity Manual/VPS] Conta ${accountData.email} conectada com sucesso!`);

    if (req.query?.redirect) {
      return res.redirect(req.query.redirect);
    }

    res.json({ success: true, email: accountData.email, name: accountData.name });
  } catch (err) {
    console.error('[OAuth Antigravity Manual/VPS] Erro:', err.message);
    res.status(500).json({ error: `Falha ao processar OAuth: ${err.message}` });
  }
});

// -------------------------------------------------------------
// VERIFICADOR REAL DE COTA DO CLAUDE OPUS (PROBE NA GOOGLE API)
// -------------------------------------------------------------
async function verifySingleAccountOpus(acc) {
  let token = acc.access_token;
  try {
    const refreshed = await refreshAccessToken(acc.refresh_token);
    if (refreshed && refreshed.accessToken) {
      token = refreshed.accessToken;
      const newExpiresAt = new Date(Date.now() + 3600 * 1000);
      await pool.query('UPDATE antigravity_accounts SET access_token = ?, token_expires_at = ? WHERE id = ?', [token, newExpiresAt, acc.id]);
    }
  } catch (e) {
    console.warn(`[Opus Verify] Falha ao renovar token de ${acc.email}:`, e.message);
  }

  const modelKey = 'claude-opus-4-6-thinking';
  let hasOpus = false;
  let remainingFraction = 0.0;
  let message = '';
  let resetTime = null;
  let rawError = '';

  try {
    // Probe ultra-leve de 1 token para testar a cota real do Claude Opus na Google API
    await generateContent(
      token,
      acc.project_id || 'aicode-consumers',
      modelKey,
      [{ role: 'user', parts: [{ text: '1' }] }],
      '',
      { maxOutputTokens: 1, thinkingBudget: 0 }
    );
    hasOpus = true;
    remainingFraction = 1.0;
    message = 'Conta confirmada com cota ativa de Claude Opus!';
  } catch (err) {
    hasOpus = false;
    remainingFraction = 0.0;
    rawError = err.message || '';
    const errData = err.data || {};
    const meta = errData.error?.details?.[0]?.metadata || {};
    const reason = errData.error?.details?.[0]?.reason || '';
    resetTime = meta.quotaResetTimeStamp || null;

    if (err.status === 429 || reason === 'QUOTA_EXHAUSTED' || rawError.includes('quota') || rawError.includes('exhausted')) {
      message = 'Cota do Claude Opus esgotada no Google (429 QUOTA_EXHAUSTED).';
    } else if (err.status === 403 || rawError.includes('subscription') || rawError.includes('permission')) {
      message = 'Conta sem assinatura/permissão para Claude Opus (requer Google One AI Premium).';
    } else {
      message = `Opus indisponível: ${reason || err.message || 'Cota não liberada'}`;
    }
  }

  // Atualizar quotas_json da conta no MySQL com quantidade exata de tokens
  let maxTokens = 1048576;
  let availableTokens = 0;
  let consumedTokens = maxTokens;

  try {
    const [rows] = await pool.query('SELECT quotas_json FROM antigravity_accounts WHERE id = ?', [acc.id]);
    let quotas = {};
    if (rows.length > 0 && rows[0].quotas_json) {
      try { quotas = JSON.parse(rows[0].quotas_json); } catch (e) { quotas = {}; }
    }
    if (!quotas.models) quotas.models = {};
    if (!quotas.models[modelKey]) {
      quotas.models[modelKey] = {
        displayName: 'Claude Opus 4.6 (Thinking)',
        maxTokens: 1048576,
        modelProvider: 'Anthropic',
        quotaInfo: {}
      };
    }
    maxTokens = parseInt(quotas.models[modelKey].maxTokens || 1048576);
    availableTokens = hasOpus ? Math.round(maxTokens * remainingFraction) : 0;
    consumedTokens = Math.max(0, maxTokens - availableTokens);

    quotas.models[modelKey].maxTokens = maxTokens;
    quotas.models[modelKey].availableTokens = availableTokens;
    quotas.models[modelKey].consumedTokens = consumedTokens;
    quotas.models[modelKey].quotaInfo = quotas.models[modelKey].quotaInfo || {};
    quotas.models[modelKey].quotaInfo.remainingFraction = remainingFraction;
    quotas.models[modelKey].quotaInfo.hasOpusQuota = hasOpus;
    quotas.models[modelKey].quotaInfo.availableTokens = availableTokens;
    quotas.models[modelKey].quotaInfo.maxTokens = maxTokens;
    quotas.models[modelKey].quotaInfo.checkedAt = new Date().toISOString();
    quotas.models[modelKey].quotaInfo.statusMessage = message;
    if (resetTime) quotas.models[modelKey].quotaInfo.resetTime = resetTime;

    quotas.hasOpusQuota = hasOpus;
    quotas.opusTokensAvailable = availableTokens;
    quotas.opusTokensMax = maxTokens;
    quotas.opusTokensConsumed = consumedTokens;
    quotas.opusCheckedAt = new Date().toISOString();
    quotas.opusMessage = message;

    await pool.query('UPDATE antigravity_accounts SET quotas_json = ?, last_sync_at = NOW() WHERE id = ?', [
      JSON.stringify(quotas),
      acc.id
    ]);

    // Atualiza antigravity_model_quotas se existir
    try {
      await pool.query(`
        INSERT INTO antigravity_model_quotas (account_id, model_name, percentage, reset_time, raw_quota_info, updated_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE percentage = VALUES(percentage), reset_time = VALUES(reset_time), raw_quota_info = VALUES(raw_quota_info), updated_at = NOW()
      `, [
        acc.id,
        modelKey,
        hasOpus ? (remainingFraction * 100) : 0.00,
        resetTime ? new Date(resetTime) : null,
        JSON.stringify({ verified: true, hasOpus, availableTokens, maxTokens, message, checkedAt: new Date().toISOString() })
      ]);
    } catch (dbErr) {}

  } catch (dbErr) {
    console.error(`[Opus Verify] Erro ao salvar cota no banco para ${acc.email}:`, dbErr.message);
  }

  // Notifica o pool de contas em memória
  if (accountPool) {
    const stat = accountPool.getAccountStats(acc.id);
    if (!stat.modelCooldowns) stat.modelCooldowns = new Map();
    if (!hasOpus) {
      stat.modelCooldowns.set(modelKey, Date.now() + 86400000); // Cooldown prolongado para Opus
    } else {
      stat.modelCooldowns.delete(modelKey);
    }
  }

  return {
    id: acc.id,
    email: acc.email,
    name: acc.name,
    hasOpus,
    availableTokens,
    maxTokens,
    consumedTokens,
    percentage: hasOpus ? Math.round(remainingFraction * 100) : 0,
    tokensFormatted: `${availableTokens.toLocaleString('pt-BR')} / ${maxTokens.toLocaleString('pt-BR')} tokens`,
    message,
    resetTime
  };
}

// -------------------------------------------------------------
// SINCRONIZADOR DE COTA DE CONTA (CONSULTA DIRETA SEM GASTAR COTA)
// -------------------------------------------------------------
async function syncSingleAccount(acc, shouldProbeOpus = false) {
  let token = acc.access_token;
  try {
    const refreshed = await refreshAccessToken(acc.refresh_token);
    if (refreshed && refreshed.accessToken) {
      token = refreshed.accessToken;
      const newExpiresAt = new Date(Date.now() + 3600 * 1000);
      await pool.query('UPDATE antigravity_accounts SET access_token = ?, token_expires_at = ? WHERE id = ?', [token, newExpiresAt, acc.id]);
    }
  } catch (e) {
    console.warn(`[Sync] Falha ao renovar token de ${acc.email}:`, e.message);
  }

  // Consulta oficial das cotas sem gerar prompt (NAO GASTA TOKENS NEM COTA)
  const quotas = await fetchUserQuotas(token, acc.project_id);
  if (quotas && quotas.models) {
    // Preservar a validação real do Opus se a conta já tiver sido testada e identificada como sem cota
    let existingQuotas = null;
    try {
      if (acc.quotas_json) {
        existingQuotas = typeof acc.quotas_json === 'string' ? JSON.parse(acc.quotas_json) : acc.quotas_json;
      }
    } catch (e) {}

    const opusModelKey = 'claude-opus-4-6-thinking';
    const existingOpus = existingQuotas?.models?.[opusModelKey]?.quotaInfo;
    const hadOpusProbe = existingOpus && (existingOpus.hasOpusQuota !== undefined || existingQuotas?.hasOpusQuota !== undefined);

    if (shouldProbeOpus) {
      // Se solicitado probe explícito de opus
      await verifySingleAccountOpus({ ...acc, access_token: token });
    } else if (hadOpusProbe) {
      // Mantém o estado real do probe do Opus (não deixa o catálogo do Google enganar e sobrescrever para 1.0)
      if (quotas.models[opusModelKey]) {
        quotas.models[opusModelKey].quotaInfo = quotas.models[opusModelKey].quotaInfo || {};
        quotas.models[opusModelKey].quotaInfo.remainingFraction = existingOpus.remainingFraction ?? (existingQuotas.hasOpusQuota ? 1.0 : 0.0);
        quotas.models[opusModelKey].quotaInfo.hasOpusQuota = existingOpus.hasOpusQuota ?? existingQuotas.hasOpusQuota;
        quotas.models[opusModelKey].quotaInfo.checkedAt = existingOpus.checkedAt ?? existingQuotas.opusCheckedAt;
        if (existingOpus.resetTime) quotas.models[opusModelKey].quotaInfo.resetTime = existingOpus.resetTime;
        if (existingOpus.statusMessage) quotas.models[opusModelKey].quotaInfo.statusMessage = existingOpus.statusMessage;
      }
      quotas.hasOpusQuota = existingQuotas.hasOpusQuota;
      quotas.opusCheckedAt = existingQuotas.opusCheckedAt;
      quotas.opusMessage = existingQuotas.opusMessage;
    }

    // Modelos ativos suportados (Gemini 3.8 Flash Medium / High)
    const geminiModel = quotas.models['gemini-3.8-flash-medium'] || quotas.models['gemini-3.8-flash-high'];
    const remaining = geminiModel?.quotaInfo?.remainingFraction ?? 1.0;
    const newStatus = remaining > 0.05 ? 'active' : 'quota_exhausted';

    await pool.query(
      'UPDATE antigravity_accounts SET quotas_json = ?, status = ?, last_sync_at = NOW() WHERE id = ?',
      [JSON.stringify(quotas), newStatus, acc.id]
    );
    return { email: acc.email, success: true, count: Object.keys(quotas.models).length, status: newStatus, remaining: (remaining * 100).toFixed(1) + '%' };
  } else {
    return { email: acc.email, success: false };
  }
}

// -------------------------------------------------------------
// ROTA PARA SINCRONIZACAO DE COTAS COM O GOOGLE
// -------------------------------------------------------------
app.all('/auth/antigravity/sync', async (req, res) => {
  // Protecao: Apenas requisicoes locais (PHP/Apache) ou com cabecalho interno
  const clientIp = req.socket?.remoteAddress || req.ip || '';
  const isLocal = clientIp === '127.0.0.1' || clientIp === '::1' || clientIp === '::ffff:127.0.0.1';
  if (!isLocal && !req.headers['x-internal-secret'] && !req.query.token) {
    return res.status(403).json({ error: 'Acesso negado: rota restrita ao painel local.' });
  }

  try {
    const accountId = req.query.id || req.body?.account_id;
    const isAsync = req.query.async === '1' || req.query.background === '1' || req.body?.background;
    const shouldProbeOpus = req.query.probe_opus === '1' || req.body?.probe_opus;

    let query = 'SELECT * FROM antigravity_accounts WHERE is_active = 1';
    let params = [];
    if (accountId) {
      query = 'SELECT * FROM antigravity_accounts WHERE id = ?';
      params = [accountId];
    }
    const [accounts] = await pool.query(query, params);

    const runSync = async () => {
      const settled = await Promise.allSettled(accounts.map(acc => syncSingleAccount(acc, shouldProbeOpus)));
      return settled.map(s => s.status === 'fulfilled' ? s.value : { success: false, error: s.reason?.message });
    };

    if (isAsync) {
      runSync().catch(err => console.error('[Background Sync Error]:', err));
      return res.json({ success: true, background: true, count: accounts.length, message: `Sincronizacao em segundo plano iniciada para ${accounts.length} conta(s).` });
    }

    const results = await runSync();

    if (req.query.redirect) {
      return res.redirect(req.query.redirect);
    }

    res.json({ success: true, results });
  } catch (err) {
    res.status(500).json({ error: err.message });
  }
});

// -------------------------------------------------------------
// ROTA PARA VERIFICACAO REAL DE COTA DO CLAUDE OPUS
// -------------------------------------------------------------
app.all('/auth/antigravity/verify-opus', async (req, res) => {
  const clientIp = req.socket?.remoteAddress || req.ip || '';
  const isLocal = clientIp === '127.0.0.1' || clientIp === '::1' || clientIp === '::ffff:127.0.0.1';
  if (!isLocal && !req.headers['x-internal-secret'] && !req.query.token) {
    return res.status(403).json({ error: 'Acesso negado: rota restrita ao painel local.' });
  }

  try {
    const accountId = req.query.id || req.body?.account_id;
    let query = 'SELECT * FROM antigravity_accounts WHERE is_active = 1';
    let params = [];
    if (accountId) {
      query = 'SELECT * FROM antigravity_accounts WHERE id = ?';
      params = [accountId];
    }
    const [accounts] = await pool.query(query, params);

    const results = [];
    for (const acc of accounts) {
      const resItem = await verifySingleAccountOpus(acc);
      results.push(resItem);
    }

    const totalAvailableTokens = results.reduce((sum, r) => sum + (r.availableTokens || 0), 0);
    const totalMaxTokens = results.reduce((sum, r) => sum + (r.maxTokens || 0), 0);
    const opusAvailableCount = results.filter(r => r.hasOpus).length;

    res.json({
      success: true,
      totalChecked: accounts.length,
      opusAvailableCount,
      opusTotalAvailableTokens: totalAvailableTokens,
      opusTotalMaxTokens: totalMaxTokens,
      opusPercentage: totalMaxTokens > 0 ? Math.round((totalAvailableTokens / totalMaxTokens) * 100) : 0,
      results
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// ROTA PARA CONSULTA DE MÉTRICAS EM TEMPO REAL DO POOL (RPM e Concorrência por Conta)
app.get('/auth/antigravity/pool-metrics', async (req, res) => {
  const clientIp = req.socket?.remoteAddress || req.ip || '';
  const isLocal = clientIp === '127.0.0.1' || clientIp === '::1' || clientIp === '::ffff:127.0.0.1';
  if (!isLocal && !req.headers['x-internal-secret']) {
    return res.status(403).json({ success: false, error: 'Acesso negado: rota de métricas restrita ao painel local.' });
  }

  try {
    const metrics = await accountPool.getPoolMetrics();
    res.json({ success: true, metrics });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

let roundRobinIndex = 0;

async function getAllActiveAccounts() {
  return await accountPool.getAllActiveAccounts();
}

async function ensureTokenFresh(account) {
  return await accountPool.ensureTokenFresh(account);
}

async function getAvailableAccount() {
  return await accountPool.acquireAccount('default', 4000);
}

// Executa a requisição com fila inteligente, controle de RPM e failover automático entre contas
async function executeWithFallback(targetEngine, contents, systemInstruction, rawModel, customConfig = null) {
  return await accountPool.executeWithFallback(targetEngine, contents, systemInstruction, rawModel, customConfig);
}

/**
 * Executa requisição com fallback usando streaming direto do Google CloudCode (SSE) com controle de RPM
 */
async function executeWithFallbackStream(targetEngine, contents, systemInstruction, requestedModel, customConfig, onChunk, abortSignal) {
  return await accountPool.executeWithFallbackStream(targetEngine, contents, systemInstruction, requestedModel, customConfig, onChunk, abortSignal);
}


// -------------------------------------------------------------
// EXECUÇÃO EM PROVEDORES EXTERNOS (ex: api.b.ai, OpenAI, etc)
// -------------------------------------------------------------
async function executeExternalProvider(providerId, targetEngine, incomingMessages, systemInstruction, rawTools = null) {
  const [providers] = await pool.query('SELECT * FROM providers WHERE id = ?', [providerId]);
  if (providers.length === 0 || providers[0].status !== 'active') {
    throw new Error(`Provedor de IA (ID ${providerId}) não encontrado ou inativo.`);
  }
  const provider = providers[0];

  const [keys] = await pool.query(`
    SELECT * FROM provider_keys 
    WHERE provider_id = ? AND status = 'active' 
    ORDER BY error_count ASC, last_used_at ASC 
    LIMIT 1
  `, [providerId]);

  if (keys.length === 0) {
    throw new Error(`Nenhuma chave de API ativa encontrada para o provedor ${provider.name}.`);
  }
  const pKey = keys[0];

  const messages = [];
  if (systemInstruction) {
    messages.push({ role: 'system', content: systemInstruction });
  }

  const normalized = normalizeMessagesForOpenAI(incomingMessages);
  messages.push(...normalized);

  const endpoint = provider.base_url.replace(/\/+$/, '') + '/chat/completions';

  const reqBody = {
    model: targetEngine,
    messages: messages,
    temperature: 0.7
  };

  if (rawTools && Array.isArray(rawTools) && rawTools.length > 0) {
    reqBody.tools = sanitizeToolsForProvider(rawTools);
  }

  const res = await fetch(endpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${pKey.api_key}`
    },
    body: JSON.stringify(reqBody),
    signal: AbortSignal.timeout(60000)
  });

  const resText = await res.text();
  let json;
  try {
    json = JSON.parse(resText);
  } catch (e) {
    throw new Error(`${provider.name} retornou resposta inválida: ${resText.slice(0, 200)}`);
  }

  if (!res.ok) {
    const errMsg = json?.error?.message || json?.message || `HTTP ${res.status}`;
    await pool.query('UPDATE provider_keys SET error_count = error_count + 1 WHERE id = ?', [pKey.id]).catch(() => { });
    throw new Error(`${provider.name}: ${errMsg}`);
  }

  await pool.query('UPDATE provider_keys SET last_used_at = NOW(), error_count = 0 WHERE id = ?', [pKey.id]).catch(() => { });

  const replyMessage = json.choices?.[0]?.message || {};
  const replyText = replyMessage.content || json.response || '';
  const extToolCalls = [];

  if (replyMessage.tool_calls && Array.isArray(replyMessage.tool_calls)) {
    for (const tc of replyMessage.tool_calls) {
      let args = {};
      try {
        args = typeof tc.function.arguments === 'string' ? JSON.parse(tc.function.arguments) : (tc.function.arguments || {});
      } catch (e) {
        args = { raw: tc.function.arguments };
      }
      extToolCalls.push({
        id: tc.id || ('call_' + crypto.randomBytes(8).toString('hex')),
        name: tc.function.name,
        args: args
      });
    }
  }

  return { replyText, toolCalls: extToolCalls, provider, account: null };
}

// Autenticação pura (sem req/res) para uso no WebSocket Realtime
async function authenticateApiKey(apiKey) {
  if (!apiKey) throw new Error('API Key não fornecida.');

  const [keys] = await pool.query(`
    SELECT k.id as key_id, k.tokens_used as key_tokens, u.id as user_id, u.role as user_role, u.balance_tokens, u.tokens_used as user_tokens, u.status as user_status, k.status as key_status
    FROM api_keys k
    JOIN users u ON k.user_id = u.id
    WHERE k.api_key = ?
  `, [apiKey]);

  if (keys.length === 0 || keys[0].key_status !== 'active' || keys[0].user_status !== 'active') {
    throw new Error('API Key inválida ou conta suspensa.');
  }

  const userKey = keys[0];

  if (userKey.balance_tokens <= 0) {
    throw new Error('Saldo de cotas/tokens esgotado. Recarregue no painel.');
  }

  return userKey;
}

async function authenticateClient(req, res) {
  const authHeader = req.headers['authorization'] || '';
  const xApiKey = req.headers['x-api-key'] || '';
  const azureApiKey = req.headers['api-key'] || req.query['api-key'] || req.query['api_key'] || '';
  let clientApiKey = '';

  if (azureApiKey) {
    clientApiKey = azureApiKey.toString().trim();
  } else if (xApiKey) {
    clientApiKey = xApiKey.trim();
  } else if (authHeader.startsWith('Bearer ')) {
    clientApiKey = authHeader.replace(/^Bearer\s+/i, '').trim();
  }

  if (!clientApiKey) {
    res.status(401).json({
      type: 'error',
      error: { type: 'authentication_error', message: 'API Key não fornecida.' }
    });
    return null;
  }

  try {
    return await authenticateApiKey(clientApiKey);
  } catch (err) {
    const isBalance = err.message.includes('Saldo');
    res.status(isBalance ? 402 : 401).json({
      type: 'error',
      error: { type: isBalance ? 'invalid_request_error' : 'authentication_error', message: err.message }
    });
    return null;
  }
}

// Classificador inteligente de complexidade da mensagem para economizar cota
function classifyRequestComplexity(textSample = '', totalChars = 0) {
  const lower = (textSample || '').toLowerCase();

  // 1. Padrões de alta complexidade (Código, Arquitetura, Raciocínio pesado, Debugging longo)
  const heavyKeywords = [
    '```', 'refactor', 'arquitetura', 'architecture', 'debug', 'stack trace', 'exception',
    'dockerfile', 'kubernetes', 'algorithm', 'complexidade', 'sql migration', 'benchmark',
    'thread safety', 'concurrency', 'deadlock', 'ast ', 'compiler', 'analise detalhada',
    'step-by-step reasoning', 'pense com calma', 'demonstre matematicamente', 'proof'
  ];

  // Se tem blocos de código grandes ou mais de 2500 caracteres, ou palavras-chave pesadas
  if (totalChars > 2500 || lower.includes('```') || heavyKeywords.some(kw => lower.includes(kw))) {
    return 'heavy';
  }

  // 2. Padrões de complexidade média (perguntas explicativas, dúvidas gerais, resumos moderados)
  if (totalChars > 500 || lower.length > 250 || lower.includes('como fazer') || lower.includes('explique') || lower.includes('resuma')) {
    return 'medium';
  }

  // 3. Padrões leves / básicos (saudações, comandos diretos, perguntas curtas)
  return 'fast';
}

export function normalizeAntigravityModel(model) {
  if (!model) return 'gemini-3.8-flash-medium';
  const m = String(model).toLowerCase().trim();
  // Se for Claude
  if (m.startsWith('claude-')) {
    if (m.includes('opus')) return 'claude-opus-4-6-thinking';
    return 'claude-sonnet-4-6';
  }
  // Modelos descontinuados ou abaixo do 3.8 (2.5, 3.5, 3.6, 3.7) -> converter para 3.8 oficial ativo
  if (m.includes('2.5') || m.includes('3.5') || m.includes('3.6') || m.includes('3.7') || m.includes('realtime')) {
    return 'gemini-3.8-flash-medium';
  }
  if (m.includes('3.8')) {
    if (m.includes('high')) return 'gemini-3.8-flash-high';
    return 'gemini-3.8-flash-medium';
  }
  if (m.startsWith('gpt-')) {
    if (m.includes('oss')) return 'gpt-oss-120b-medium';
    return 'gemini-3.8-flash-medium';
  }
  return model;
}

async function resolveModelConfig(requestedModel, incomingContents = []) {
  const cleanModel = (requestedModel || '').replace(/[^\w\.\-]/g, '');

  let [rows] = await pool.query(`
    SELECT id, model_key, name, provider_id, upstream_model, multiplier, pre_prompt, is_admin_only, is_realtime, is_smart_routing, routing_fast_model, routing_fast_multiplier, routing_medium_model, routing_medium_multiplier, routing_heavy_model, routing_heavy_multiplier
    FROM models 
    WHERE (model_key = ? OR name = ?) AND status = 'active'
    LIMIT 1
  `, [cleanModel, requestedModel]);

  if (rows.length === 0) {
    const baseKey = cleanModel.split('-202')[0].replace('-latest', '');
    [rows] = await pool.query(`
      SELECT id, model_key, name, provider_id, upstream_model, multiplier, pre_prompt, is_admin_only, is_realtime, is_smart_routing, routing_fast_model, routing_fast_multiplier, routing_medium_model, routing_medium_multiplier, routing_heavy_model, routing_heavy_multiplier
      FROM models 
      WHERE (model_key LIKE ? OR name LIKE ?) AND status = 'active'
      LIMIT 1
    `, [`%${baseKey}%`, `%${baseKey}%`]);
  }

  const globalPrePrompt = await getSetting('pre_prompt', '');

  if (rows.length > 0) {
    const row = rows[0];
    const modelPrompt = row.pre_prompt ? row.pre_prompt.trim() : '';
    const combinedPrompt = [globalPrePrompt, modelPrompt].filter(Boolean).join('\n\n');

    let chosenEngine = row.upstream_model || cleanModel;
    let chosenMultiplier = parseFloat(row.multiplier) || 1.0;

    // ROTEAMENTO INTELIGENTE (SE HABILITADO NESTE MODELO)
    if (row.is_smart_routing == 1) {
      // Pega especificamente a ÚLTIMA mensagem do usuário para não deixar as instruções internas do sistema/CLI distorcerem a classificação
      let userPrompt = '';
      if (Array.isArray(incomingContents)) {
        for (let i = incomingContents.length - 1; i >= 0; i--) {
          const item = incomingContents[i];
          if (item.role === 'user' && item.parts) {
            userPrompt = item.parts.map(p => p.text || '').join(' ').trim();
            if (userPrompt) break;
          }
        }
      }

      // Se não achou usuário isolado, usa texto geral
      if (!userPrompt && Array.isArray(incomingContents)) {
        for (const item of incomingContents) {
          if (item.parts) userPrompt += (item.parts.map(p => p.text || '').join(' ')) + ' ';
        }
      }

      const complexity = classifyRequestComplexity(userPrompt, userPrompt.length);

      if (complexity === 'fast' && row.routing_fast_model) {
        chosenEngine = row.routing_fast_model;
        chosenMultiplier = parseFloat(row.routing_fast_multiplier) || 1.0;
      } else if (complexity === 'medium' && row.routing_medium_model) {
        chosenEngine = row.routing_medium_model;
        chosenMultiplier = parseFloat(row.routing_medium_multiplier) || 1.5;
      } else if (complexity === 'heavy' && row.routing_heavy_model) {
        chosenEngine = row.routing_heavy_model;
        chosenMultiplier = parseFloat(row.routing_heavy_multiplier) || 3.0;
      }
    }

    const providerId = row ? row.provider_id : 2;
    if (providerId === 2 && !row.upstream_model) {
      chosenEngine = normalizeAntigravityModel(chosenEngine);
    }

    return {
      targetEngine: chosenEngine,
      customPrompt: combinedPrompt,
      providerId: providerId,
      isAdminOnly: row ? (row.is_admin_only == 1) : false,
      isRealtime: row ? (row.is_realtime == 1) : true,
      multiplier: chosenMultiplier
    };
  }

  // Se nao encontrou em models, buscar em provider_models (modelos sincronizados de provedores)
  try {
    const [pmRows] = await pool.query(`
      SELECT upstream_model, name, provider_id FROM provider_models
      WHERE upstream_model = ? OR name = ?
      LIMIT 1
    `, [cleanModel, requestedModel]);

    if (pmRows.length > 0) {
      let pmEngine = pmRows[0].upstream_model || cleanModel;
      if (pmRows[0].provider_id === 2) {
        pmEngine = normalizeAntigravityModel(pmEngine);
      }
      return {
        targetEngine: pmEngine,
        customPrompt: globalPrePrompt,
        providerId: pmRows[0].provider_id,
        multiplier: 1.0
      };
    }
  } catch (err) { }

  // Fallback dinamico: se for modelo comum de APIs externas (glm, deepseek, qwen, minimax, kimi, etc.), usa provider 1, senão provider 2
  const isExternalStyle = /^(glm|deepseek|qwen|minimax|kimi|mimo|hy3|hy4)/i.test(cleanModel);
  const defaultProvId = isExternalStyle ? 1 : 2;

  let targetEngine = cleanModel;
  let defaultMultiplier = 1.0;
  if (defaultProvId === 2) {
    targetEngine = normalizeAntigravityModel(cleanModel);
    if (targetEngine.includes('opus')) {
      defaultMultiplier = 3.0;
    } else if (targetEngine.includes('claude')) {
      defaultMultiplier = 2.0;
    } else {
      defaultMultiplier = 1.0;
    }
  }

  return {
    targetEngine: targetEngine,
    customPrompt: globalPrePrompt,
    providerId: defaultProvId,
    multiplier: defaultMultiplier
  };
}

async function logUsage(userKey, model, promptTokens, completionTokens, accountId, customMultiplier = 1.0) {
  const rawTotal = promptTokens + completionTokens;
  const multiplier = Math.max(0.01, parseFloat(customMultiplier) || 1.0);
  const billedTokens = Math.round(rawTotal * multiplier);

  try {
    await pool.query(`
      INSERT INTO usage_logs (user_id, api_key_id, model, prompt_tokens, completion_tokens, total_tokens, multiplier)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    `, [userKey.user_id, userKey.key_id, model, promptTokens, completionTokens, billedTokens, multiplier]);

    await pool.query(`
      UPDATE users SET balance_tokens = GREATEST(0, balance_tokens - ?), tokens_used = tokens_used + ? WHERE id = ?
    `, [billedTokens, billedTokens, userKey.user_id]);

    await pool.query(`
      UPDATE api_keys SET tokens_used = tokens_used + ? WHERE id = ?
    `, [billedTokens, userKey.key_id]);

    if (accountId) {
      await pool.query(`
        UPDATE antigravity_accounts 
        SET total_requests = total_requests + 1, total_tokens = total_tokens + ? 
        WHERE id = ?
      `, [rawTotal, accountId]);

      // Sincronização inteligente em segundo plano:
      // A cada 10 requisições ou se última sync foi há mais de 2 minutos, atualiza as cotas reais do Google
      setTimeout(async () => {
        try {
          const [accRows] = await pool.query(
            'SELECT * FROM antigravity_accounts WHERE id = ?',
            [accountId]
          );
          if (accRows.length > 0) {
            const lastSync = accRows[0].last_sync_at ? new Date(accRows[0].last_sync_at).getTime() : 0;
            if (Date.now() - lastSync > 120000) {
              await syncSingleAccount(accRows[0]);
              console.log(`[Auto-Sync] Cotas sincronizadas em segundo plano para conta ${accRows[0].email}`);
            }
          }
        } catch (e) {
          // Ignora silenciosamente para não travar a resposta
        }
      }, 500);
    }
  } catch (err) {
    console.error('Erro ao debitar cota:', err.message);
  }
}

// =============================================================
// FUNÇÕES AUXILIARES DE TOOL CALLING / FUNCTION CALLING
// =============================================================

// Palavras-chave proibidas em schemas de ferramentas (violam JSON Schema 2020-12 ou causam rejeição em provedores)
const SCHEMA_BLACKLIST_KEYS = new Set([
  '$schema', '$id', '$ref', '$defs', 'definitions', '$comment',
  'nullable', 'additionalProperties', 'patternProperties',
  'unevaluatedProperties', 'unevaluatedItems',
  'if', 'then', 'else', 'not',
  'dependentSchemas', 'dependentRequired',
  'contentMediaType', 'contentEncoding', 'contentSchema',
  'deprecated', 'readOnly', 'writeOnly',
  'examples', 'example', '$anchor', '$dynamicRef', '$dynamicAnchor',
  'discriminator', 'xml', 'externalDocs',
  'prefixItems', 'contains', 'minContains', 'maxContains',
  'propertyNames'
]);

const VALID_PRIMITIVES = new Set(['string', 'number', 'integer', 'boolean', 'array', 'object']);

/**
 * Sanitizador universal de JSON Schema para tool calling.
 * Garante conformidade com JSON Schema draft 2020-12.
 * Funciona para: Gemini, Anthropic Claude, OpenAI, Hermes, Claude Code, Codex, etc.
 */
function sanitizeToolSchema(schema, isRoot = false) {
  if (!schema || typeof schema !== 'object' || Array.isArray(schema)) {
    return isRoot ? { type: 'object', properties: {} } : { type: 'string' };
  }

  // Proteção contra referências circulares (max profundidade)
  const MAX_DEPTH = 15;
  
  function cleanNode(node, depth, forceRoot) {
    if (depth > MAX_DEPTH) return { type: 'string' };
    if (!node || typeof node !== 'object' || Array.isArray(node)) {
      return { type: 'string' };
    }

    // 1. Resolver anyOf / oneOf / allOf → achatar para a primeira opção não-null
    let effectiveNode = {};
    // Copiar apenas campos seguros
    for (const [k, v] of Object.entries(node)) {
      if (!SCHEMA_BLACKLIST_KEYS.has(k) && k !== 'anyOf' && k !== 'oneOf' && k !== 'allOf') {
        effectiveNode[k] = v;
      }
    }

    const unionList = node.anyOf || node.oneOf || node.allOf;
    if (Array.isArray(unionList) && unionList.length > 0) {
      // Pegar a primeira opção que não seja { type: 'null' }
      const nonNullOption = unionList.find(opt =>
        opt && typeof opt === 'object' && opt.type !== 'null'
      ) || unionList[0];

      if (nonNullOption && typeof nonNullOption === 'object') {
        // Mesclar campos da opção na node, sem sobrescrever campos já existentes
        for (const [k, v] of Object.entries(nonNullOption)) {
          if (!SCHEMA_BLACKLIST_KEYS.has(k) && k !== 'anyOf' && k !== 'oneOf' && k !== 'allOf') {
            if (effectiveNode[k] === undefined) {
              effectiveNode[k] = v;
            }
          }
        }
        // Se a node não tem type mas a opção tem, usar
        if (!effectiveNode.type && nonNullOption.type) {
          effectiveNode.type = nonNullOption.type;
        }
      }
    }

    const cleaned = {};

    // 2. Resolver tipo
    let rawType = effectiveNode.type;
    let type = null;

    if (typeof rawType === 'string') {
      const lower = rawType.toLowerCase();
      if (VALID_PRIMITIVES.has(lower)) {
        type = lower;
      } else {
        type = 'string'; // fallback seguro
      }
    } else if (Array.isArray(rawType)) {
      // type: ["string", "null"] → pegar o primeiro não-null
      const firstValid = rawType.find(t =>
        typeof t === 'string' && VALID_PRIMITIVES.has(t.toLowerCase()) && t.toLowerCase() !== 'null'
      );
      type = firstValid ? firstValid.toLowerCase() : 'string';
    }

    // Forçar root como object
    if (forceRoot) {
      cleaned.type = 'object';
    } else if (type) {
      cleaned.type = type;
    } else if (effectiveNode.properties) {
      cleaned.type = 'object';
    } else if (effectiveNode.items) {
      cleaned.type = 'array';
    } else if (effectiveNode.enum) {
      cleaned.type = 'string';
    } else {
      cleaned.type = 'string';
    }

    // 3. description (string limpo)
    if (effectiveNode.description && typeof effectiveNode.description === 'string') {
      cleaned.description = effectiveNode.description.slice(0, 1024);
    }

    // 4. enum (converter tudo para string se tipo é string)
    if (effectiveNode.enum && Array.isArray(effectiveNode.enum) && effectiveNode.enum.length > 0) {
      if (cleaned.type === 'string') {
        cleaned.enum = effectiveNode.enum.filter(e => e !== null && e !== undefined).map(e => String(e));
      } else {
        cleaned.enum = effectiveNode.enum.filter(e => e !== null && e !== undefined);
      }
      if (cleaned.enum.length === 0) delete cleaned.enum;
    }

    // 5. const → converter para enum com 1 elemento
    if (effectiveNode.const !== undefined && !cleaned.enum) {
      cleaned.enum = [effectiveNode.const];
    }

    // 6. default → preservar apenas se é do tipo correto (alguns provedores aceitam)
    // NÃO incluir default — muitos provedores rejeitam
    
    // 7. Object com properties
    if (cleaned.type === 'object') {
      cleaned.properties = {};
      if (effectiveNode.properties && typeof effectiveNode.properties === 'object' && !Array.isArray(effectiveNode.properties)) {
        for (const [key, val] of Object.entries(effectiveNode.properties)) {
          if (typeof key === 'string' && key.trim() && !key.startsWith('$')) {
            cleaned.properties[key] = cleanNode(val, depth + 1, false);
          }
        }
      }
      // required — validar que cada required existe em properties
      if (Array.isArray(effectiveNode.required) && effectiveNode.required.length > 0) {
        const validRequired = effectiveNode.required.filter(r =>
          typeof r === 'string' && cleaned.properties[r]
        );
        if (validRequired.length > 0) {
          cleaned.required = validRequired;
        }
      }
    }

    // 8. Array com items
    if (cleaned.type === 'array') {
      if (effectiveNode.items && typeof effectiveNode.items === 'object' && !Array.isArray(effectiveNode.items)) {
        cleaned.items = cleanNode(effectiveNode.items, depth + 1, false);
      } else if (Array.isArray(effectiveNode.items) && effectiveNode.items.length > 0) {
        // tuple items → pegar o primeiro
        cleaned.items = cleanNode(effectiveNode.items[0], depth + 1, false);
      } else {
        cleaned.items = { type: 'string' };
      }

      // minItems / maxItems — são válidos em JSON Schema 2020-12
      if (typeof effectiveNode.minItems === 'number') cleaned.minItems = effectiveNode.minItems;
      if (typeof effectiveNode.maxItems === 'number') cleaned.maxItems = effectiveNode.maxItems;
    }

    // 9. String constraints válidas
    if (cleaned.type === 'string') {
      if (typeof effectiveNode.minLength === 'number') cleaned.minLength = effectiveNode.minLength;
      if (typeof effectiveNode.maxLength === 'number') cleaned.maxLength = effectiveNode.maxLength;
      // pattern é válido mas pode causar problemas em alguns provedores → incluir
      if (typeof effectiveNode.pattern === 'string') cleaned.pattern = effectiveNode.pattern;
    }

    // 10. Number constraints válidas
    if (cleaned.type === 'number' || cleaned.type === 'integer') {
      if (typeof effectiveNode.minimum === 'number') cleaned.minimum = effectiveNode.minimum;
      if (typeof effectiveNode.maximum === 'number') cleaned.maximum = effectiveNode.maximum;
    }

    return cleaned;
  }

  return cleanNode(schema, 0, isRoot);
}

/**
 * Sanitiza array de tools no formato OpenAI para envio a qualquer provedor externo.
 * Garante que cada tool tenha schema válido e limpo.
 */
function sanitizeToolsForProvider(tools) {
  if (!tools || !Array.isArray(tools) || tools.length === 0) return undefined;

  const sanitized = [];

  for (const t of tools) {
    if (!t) continue;

    // Formato OpenAI: { type: 'function', function: { name, description, parameters } }
    if (t.type === 'function' && t.function) {
      const fn = t.function;
      if (!fn.name) continue;

      sanitized.push({
        type: 'function',
        function: {
          name: fn.name.replace(/[^a-zA-Z0-9_:-]/g, '_').slice(0, 64),
          description: (fn.description || '').slice(0, 1024),
          parameters: sanitizeToolSchema(fn.parameters, true)
        }
      });
    }
    // Formato Anthropic: { name, description, input_schema }
    else if (t.name) {
      sanitized.push({
        type: 'function',
        function: {
          name: t.name.replace(/[^a-zA-Z0-9_:-]/g, '_').slice(0, 64),
          description: (t.description || '').slice(0, 1024),
          parameters: sanitizeToolSchema(t.input_schema || t.parameters, true)
        }
      });
    }
    // Formato custom/computer_use/bash etc → Anthropic custom tool types
    else if (t.type && !t.function) {
      // Tipos especiais (computer_20241022, bash_20241022, text_editor_20241022)
      // Estes não são function tools — pular (não são suportados no nosso gateway)
      continue;
    }
  }

  return sanitized.length > 0 ? sanitized : undefined;
}

function convertToolsToGemini(tools) {
  if (!tools || !Array.isArray(tools) || tools.length === 0) return null;

  const declarations = [];

  for (const t of tools) {
    if (!t) continue;
    let name = '';
    let description = '';
    let params = null;

    if (t.type === 'function' && t.function) {
      name = t.function.name;
      description = t.function.description || '';
      params = t.function.parameters;
    } else if (t.name) {
      name = t.name;
      description = t.description || '';
      params = t.input_schema || t.parameters;
    }

    if (!name) continue;

    declarations.push({
      name: name.replace(/[^a-zA-Z0-9_:-]/g, '_').slice(0, 64),
      description: (description || '').slice(0, 1024),
      parameters: sanitizeToolSchema(params, true)
    });
  }

  if (declarations.length === 0) return null;
  return [{ functionDeclarations: declarations }];
}

// Cache em memória de thoughtSignature para garantir compatibilidade com tool calling em Gemini 2.5 / 3 / thinking
const thoughtSignatureCache = new Map();

function saveThoughtSignature(callId, fnName, signature) {
  if (!signature) return;
  if (callId) thoughtSignatureCache.set(String(callId), signature);
  if (fnName) {
    const rawName = String(fnName);
    thoughtSignatureCache.set(rawName, signature);
    const sanitized = rawName.replace(/[^a-zA-Z0-9_:-]/g, '_').slice(0, 64);
    thoughtSignatureCache.set(sanitized, signature);
    const sanitizedNoColon = rawName.replace(/[^a-zA-Z0-9_-]/g, '_').slice(0, 64);
    thoughtSignatureCache.set(sanitizedNoColon, signature);
  }
  if (thoughtSignatureCache.size > 5000) {
    const toDelete = Array.from(thoughtSignatureCache.keys()).slice(0, 1000);
    for (const k of toDelete) thoughtSignatureCache.delete(k);
  }
}

function getThoughtSignature(callId, fnName) {
  if (callId && thoughtSignatureCache.has(String(callId))) {
    return thoughtSignatureCache.get(String(callId));
  }
  if (fnName) {
    const rawName = String(fnName);
    if (thoughtSignatureCache.has(rawName)) return thoughtSignatureCache.get(rawName);
    const sanitized = rawName.replace(/[^a-zA-Z0-9_:-]/g, '_').slice(0, 64);
    if (thoughtSignatureCache.has(sanitized)) return thoughtSignatureCache.get(sanitized);
    const sanitizedNoColon = rawName.replace(/[^a-zA-Z0-9_-]/g, '_').slice(0, 64);
    if (thoughtSignatureCache.has(sanitizedNoColon)) return thoughtSignatureCache.get(sanitizedNoColon);
  }
  return null;
}

function extractGeminiResponse(googleResp) {
  const actualResp = googleResp?.response || googleResp;
  const candidates = actualResp?.candidates || [];
  let replyText = '';
  const toolCalls = [];

  if (candidates.length > 0 && candidates[0].content?.parts) {
    let candidateSig = candidates[0].thoughtSignature || candidates[0].thought_signature;
    for (const p of candidates[0].content.parts) {
      if (p.thoughtSignature || p.thought_signature) {
        candidateSig = p.thoughtSignature || p.thought_signature;
      }
      if (p.thought) continue;
      if (p.text) {
        replyText += p.text;
      }
      if (p.functionCall) {
        const callId = p.functionCall.id || ('call_' + crypto.randomBytes(8).toString('hex'));
        const fnName = p.functionCall.name;
        const sig = p.thoughtSignature || p.thought_signature || p.functionCall?.thoughtSignature || p.functionCall?.thought_signature || candidateSig;
        if (sig) {
          saveThoughtSignature(callId, fnName, sig);
        }
        toolCalls.push({
          id: callId,
          name: fnName,
          args: p.functionCall.args || {},
          thoughtSignature: sig
        });
      }
    }
  }

  replyText = replyText.replace(/<\/?thought>/gi, '').replace(/\bthought\b/gi, '').trim();
  return { replyText, toolCalls };
}

/**
 * Normaliza qualquer formato de mensagens (Anthropic Claude ou OpenAI) para o formato padrão OpenAI.
 * Converte tool_use/tool_result para role 'assistant' com tool_calls e role 'tool'.
 */
function normalizeMessagesForOpenAI(incomingMessages) {
  const normalized = [];

  for (const m of incomingMessages) {
    if (!m) continue;

    if (typeof m.content === 'string') {
      normalized.push({
        role: m.role === 'assistant' ? 'assistant' : (m.role === 'tool' ? 'tool' : (m.role === 'system' ? 'system' : 'user')),
        content: m.content || '',
        tool_calls: m.tool_calls || undefined,
        tool_call_id: m.tool_call_id || undefined,
        name: m.name || undefined
      });
      continue;
    }

    if (Array.isArray(m.content)) {
      let textContent = '';
      const toolCalls = [];
      const toolResults = [];

      for (const b of m.content) {
        if (!b) continue;
        if (b.type === 'text') {
          textContent += (b.text || '') + '\n';
        } else if (b.type === 'tool_use') {
          toolCalls.push({
            id: b.id || ('call_' + crypto.randomBytes(8).toString('hex')),
            type: 'function',
            function: {
              name: b.name,
              arguments: typeof b.input === 'string' ? b.input : JSON.stringify(b.input || {})
            }
          });
        } else if (b.type === 'tool_result') {
          let resStr = '';
          if (typeof b.content === 'string') {
            resStr = b.content;
          } else if (Array.isArray(b.content)) {
            resStr = b.content.map(c => c.text || JSON.stringify(c)).join('\n');
          } else {
            resStr = JSON.stringify(b.content || '');
          }
          toolResults.push({
            role: 'tool',
            tool_call_id: b.tool_use_id,
            content: resStr
          });
        }
      }

      if (toolResults.length > 0) {
        for (const tr of toolResults) {
          normalized.push(tr);
        }
        if (textContent.trim()) {
          normalized.push({ role: 'user', content: textContent.trim() });
        }
      } else {
        const role = m.role === 'assistant' ? 'assistant' : 'user';
        const msgObj = {
          role,
          content: textContent.trim() || (toolCalls.length > 0 ? null : ' ')
        };
        if (toolCalls.length > 0) {
          msgObj.tool_calls = toolCalls;
        }
        normalized.push(msgObj);
      }
    }
  }

  return normalized;
}

/**
 * Converte mensagens no formato Anthropic (/v1/messages) para o formato nativo do Gemini (contents).
 * Garante que tool_use vire functionCall, tool_result vire functionResponse (com o nome correto da função),
 * e que turnos consecutivos do mesmo role sejam unidos para respeitar as regras do Gemini.
 */
function convertAnthropicMessagesToGemini(incomingMessages) {
  const contents = [];
  const toolUseIdToName = {};

  // Mapear IDs de ferramentas para seus nomes
  for (const m of incomingMessages) {
    if (Array.isArray(m.content)) {
      for (const b of m.content) {
        if (b && b.type === 'tool_use' && b.id && b.name) {
          toolUseIdToName[b.id] = b.name;
        }
      }
    }
  }

  for (const m of incomingMessages) {
    const role = m.role === 'assistant' ? 'model' : 'user';
    const parts = [];

    if (typeof m.content === 'string') {
      if (m.content.trim()) {
        parts.push({ text: m.content });
      }
    } else if (Array.isArray(m.content)) {
      for (const b of m.content) {
        if (!b) continue;

        if (b.type === 'text' && b.text) {
          parts.push({ text: b.text });
        } else if (b.type === 'tool_use') {
          let inputArgs = {};
          if (typeof b.input === 'object' && b.input !== null) {
            inputArgs = b.input;
          } else if (typeof b.input === 'string') {
            try { inputArgs = JSON.parse(b.input); } catch (e) { inputArgs = { raw: b.input }; }
          }
          const callId = b.id || ('toolu_' + crypto.randomBytes(8).toString('hex'));
          const rawFnName = b.name || 'unknown';
          const fnName = rawFnName.replace(/[^a-zA-Z0-9_:-]/g, '_').slice(0, 64);
          const clientSig = b.thoughtSignature || b.thought_signature;
          const sig = clientSig || getThoughtSignature(callId, rawFnName) || getThoughtSignature(callId, fnName) || 'skip_thought_signature_validator';
          const partObj = {
            functionCall: {
              id: callId,
              name: fnName,
              args: inputArgs
            },
            thoughtSignature: sig
          };
          parts.push(partObj);
        } else if (b.type === 'tool_result') {
          const rawName = toolUseIdToName[b.tool_use_id] || 'tool';
          const fnName = rawName.replace(/[^a-zA-Z0-9_:-]/g, '_').slice(0, 64);
          let responseObj;
          if (typeof b.content === 'string') {
            try {
              responseObj = JSON.parse(b.content);
            } catch (e) {
              responseObj = { output: b.content };
            }
          } else if (typeof b.content === 'object' && b.content !== null) {
            responseObj = b.content;
          } else {
            responseObj = { output: String(b.content || '') };
          }

          if (typeof responseObj !== 'object' || responseObj === null || Array.isArray(responseObj)) {
            responseObj = { output: responseObj };
          }

          parts.push({
            functionResponse: {
              id: b.tool_use_id,
              name: fnName,
              response: responseObj
            }
          });
        } else if (b.type === 'image' && b.source?.data) {
          parts.push({
            inlineData: {
              mimeType: b.source.media_type || 'image/png',
              data: b.source.data
            }
          });
        }
      }
    }

    if (parts.length > 0) {
      if (contents.length > 0 && contents[contents.length - 1].role === role) {
        contents[contents.length - 1].parts.push(...parts);
      } else {
        contents.push({ role, parts });
      }
    }
  }

  if (contents.length === 0) {
    contents.push({ role: 'user', parts: [{ text: ' ' }] });
  }

  if (contents[0].role === 'model') {
    contents.unshift({ role: 'user', parts: [{ text: 'Início' }] });
  }

  return contents;
}

/**
 * Converte mensagens no formato OpenAI (/v1/chat/completions) para o formato nativo do Gemini (contents).
 * Garante que tool_calls vire functionCall, role 'tool' vire functionResponse (resolvendo o nome real da função via tool_call_id),
 * e que turnos consecutivos do mesmo role sejam unidos.
 */
function convertOpenAiMessagesToGemini(incomingMessages) {
  const contents = [];
  let userSystemPrompt = '';
  const toolCallIdToName = {};

  for (const m of incomingMessages) {
    if (m.tool_calls && Array.isArray(m.tool_calls)) {
      for (const tc of m.tool_calls) {
        if (tc.id && tc.function?.name) {
          toolCallIdToName[tc.id] = tc.function.name;
        }
      }
    }
  }

  for (const m of incomingMessages) {
    if (m.role === 'system') {
      if (typeof m.content === 'string') {
        userSystemPrompt += (m.content || '') + '\n';
      } else if (Array.isArray(m.content)) {
        userSystemPrompt += m.content.map(p => p.text || '').join('\n') + '\n';
      }
      continue;
    }

    if (m.role === 'tool') {
      const rawName = m.name || toolCallIdToName[m.tool_call_id] || 'tool';
      const fnName = rawName.replace(/[^a-zA-Z0-9_:-]/g, '_').slice(0, 64);
      let responseObj;
      try {
        responseObj = typeof m.content === 'string' ? JSON.parse(m.content) : (m.content || {});
      } catch (e) {
        responseObj = { output: m.content || '' };
      }

      if (typeof responseObj !== 'object' || responseObj === null || Array.isArray(responseObj)) {
        responseObj = { output: responseObj };
      }

      const part = {
        functionResponse: {
          id: m.tool_call_id || undefined,
          name: fnName,
          response: responseObj
        }
      };

      if (contents.length > 0 && contents[contents.length - 1].role === 'user') {
        contents[contents.length - 1].parts.push(part);
      } else {
        contents.push({ role: 'user', parts: [part] });
      }
      continue;
    }

    const role = m.role === 'assistant' ? 'model' : 'user';
    const parts = [];

    if (typeof m.content === 'string' && m.content.trim()) {
      parts.push({ text: m.content });
    } else if (Array.isArray(m.content)) {
      for (const item of m.content) {
        if (item.type === 'text') {
          parts.push({ text: item.text || '' });
        } else if (item.type === 'image_url' && item.image_url?.url) {
          const urlStr = item.image_url.url;
          if (urlStr.startsWith('data:')) {
            const matches = urlStr.match(/^data:([^;]+);base64,(.+)$/);
            if (matches) {
              parts.push({
                inlineData: { mimeType: matches[1], data: matches[2] }
              });
            }
          }
        }
      }
    }

    if (m.tool_calls && Array.isArray(m.tool_calls)) {
      for (const tc of m.tool_calls) {
        let args = {};
        try {
          args = typeof tc.function?.arguments === 'string' ? JSON.parse(tc.function.arguments) : (tc.function?.arguments || {});
        } catch (e) {
          args = { raw: tc.function?.arguments || '' };
        }
        const callId = tc.id || ('call_' + crypto.randomBytes(8).toString('hex'));
        const rawFnName = tc.function?.name || 'unknown';
        const fnName = rawFnName.replace(/[^a-zA-Z0-9_:-]/g, '_').slice(0, 64);
        const clientSig = tc.thoughtSignature || tc.thought_signature || tc.function?.thoughtSignature || tc.function?.thought_signature;
        const sig = clientSig || getThoughtSignature(callId, rawFnName) || getThoughtSignature(callId, fnName) || 'skip_thought_signature_validator';
        const partObj = {
          functionCall: {
            id: callId,
            name: fnName,
            args: args
          },
          thoughtSignature: sig
        };
        parts.push(partObj);
      }
    }

    if (parts.length > 0) {
      if (contents.length > 0 && contents[contents.length - 1].role === role) {
        contents[contents.length - 1].parts.push(...parts);
      } else {
        contents.push({ role, parts });
      }
    }
  }

  if (contents.length === 0) {
    contents.push({
      role: 'user',
      parts: [{ text: userSystemPrompt.trim() || 'Olá' }]
    });
  }

  if (contents[0].role === 'model') {
    contents.unshift({ role: 'user', parts: [{ text: 'Início da conversa' }] });
  }

  return { contents, userSystemPrompt };
}

// -------------------------------------------------------------
// ENDPOINT ANTHROPIC CLAUDE (/v1/messages)
// -------------------------------------------------------------
app.post(['/v1/messages', '/messages'], async (req, res) => {
  const userKey = await authenticateClient(req, res);
  if (!userKey) return;

  const rawModel = req.body.model || 'claude-3-5-sonnet';
  const incomingMessages = req.body.messages || [];
  const contents = convertAnthropicMessagesToGemini(incomingMessages);

  const { targetEngine, customPrompt, providerId, multiplier: modelMultiplier } = await resolveModelConfig(rawModel, contents);

  let systemParts = [];
  if (customPrompt) {
    systemParts.push(customPrompt);
  }
  if (req.body.system) {
    if (typeof req.body.system === 'string') {
      systemParts.push(req.body.system);
    } else if (Array.isArray(req.body.system)) {
      systemParts.push(req.body.system.map(s => s.text || '').join('\n'));
    }
  }

  const finalSystemInstruction = systemParts.join('\n\n');
  const geminiTools = convertToolsToGemini(req.body.tools);
  const customConfig = {
    temperature: req.body.temperature !== undefined ? req.body.temperature : 0.7,
    maxOutputTokens: req.body.max_tokens || 8192,
    tools: geminiTools
  };

  try {
    let replyText = '';
    let toolCalls = [];
    let account = null;

    if (req.body.stream) {
      res.setHeader('Content-Type', 'text/event-stream; charset=utf-8');
      res.setHeader('Cache-Control', 'no-cache');
      res.setHeader('Connection', 'keep-alive');

      const msgId = 'msg_' + crypto.randomBytes(12).toString('hex');
      const inputTokens = Math.ceil(JSON.stringify(contents).length / 4);

      res.write(`event: message_start\ndata: ${JSON.stringify({
        type: 'message_start',
        message: {
          id: msgId,
          type: 'message',
          role: 'assistant',
          model: rawModel,
          content: [],
          stop_reason: null,
          stop_sequence: null,
          usage: { input_tokens: inputTokens, output_tokens: 1 }
        }
      })}\n\n`);

      let blockIndex = 0;
      let textBlockStarted = false;
      const abortController = new AbortController();
      req.on('close', () => abortController.abort());

      if (providerId && providerId != 2) {
        const extResult = await executeExternalProvider(providerId, targetEngine, incomingMessages, finalSystemInstruction, req.body.tools);
        replyText = extResult.replyText;
        toolCalls = extResult.toolCalls || [];
        if (replyText) {
          res.write(`event: content_block_start\ndata: ${JSON.stringify({
            type: 'content_block_start',
            index: blockIndex,
            content_block: { type: 'text', text: '' }
          })}\n\n`);
          res.write(`event: content_block_delta\ndata: ${JSON.stringify({
            type: 'content_block_delta',
            index: blockIndex,
            delta: { type: 'text_delta', text: replyText }
          })}\n\n`);
          res.write(`event: content_block_stop\ndata: ${JSON.stringify({ type: 'content_block_stop', index: blockIndex })}\n\n`);
          blockIndex++;
        }
      } else {
        const { googleResp, account: acc } = await executeWithFallbackStream(
          targetEngine,
          contents,
          finalSystemInstruction,
          rawModel,
          customConfig,
          (textChunk) => {
            if (textChunk) {
              if (!textBlockStarted) {
                textBlockStarted = true;
                res.write(`event: content_block_start\ndata: ${JSON.stringify({
                  type: 'content_block_start',
                  index: blockIndex,
                  content_block: { type: 'text', text: '' }
                })}\n\n`);
              }
              res.write(`event: content_block_delta\ndata: ${JSON.stringify({
                type: 'content_block_delta',
                index: blockIndex,
                delta: { type: 'text_delta', text: textChunk }
              })}\n\n`);
            }
          },
          abortController.signal
        );
        account = acc;
        replyText = googleResp?.replyText || '';
        toolCalls = googleResp?.toolCalls || [];
        for (const tc of toolCalls) {
          if (tc.thoughtSignature) {
            saveThoughtSignature(tc.id, tc.name, tc.thoughtSignature);
          }
        }

        if (textBlockStarted) {
          res.write(`event: content_block_stop\ndata: ${JSON.stringify({ type: 'content_block_stop', index: blockIndex })}\n\n`);
          blockIndex++;
        }
      }

      for (const tc of toolCalls) {
        res.write(`event: content_block_start\ndata: ${JSON.stringify({
          type: 'content_block_start',
          index: blockIndex,
          content_block: { type: 'tool_use', id: tc.id, name: tc.name, input: {} }
        })}\n\n`);
        res.write(`event: content_block_delta\ndata: ${JSON.stringify({
          type: 'content_block_delta',
          index: blockIndex,
          delta: { type: 'input_json_delta', partial_json: JSON.stringify(tc.args || {}) }
        })}\n\n`);
        res.write(`event: content_block_stop\ndata: ${JSON.stringify({ type: 'content_block_stop', index: blockIndex })}\n\n`);
        blockIndex++;
      }

      const outputTokens = Math.ceil((replyText.length + JSON.stringify(toolCalls).length) / 4);
      const stopReason = toolCalls.length > 0 ? 'tool_use' : 'end_turn';

      res.write(`event: message_delta\ndata: ${JSON.stringify({
        type: 'message_delta',
        delta: { stop_reason: stopReason, stop_sequence: null },
        usage: { output_tokens: outputTokens }
      })}\n\n`);
      res.write(`event: message_stop\ndata: {"type":"message_stop"}\n\n`);
      res.end();

      logUsage(userKey, rawModel, inputTokens, outputTokens, account?.id || null, modelMultiplier);
      return;
    }

    if (providerId && providerId != 2) {
      // PROVEDOR EXTERNO (ex: api.b.ai)
      const extResult = await executeExternalProvider(providerId, targetEngine, incomingMessages, finalSystemInstruction, req.body.tools);
      replyText = extResult.replyText;
      toolCalls = extResult.toolCalls || [];
    } else {
      // GOOGLE ANTIGRAVITY POOL COM SUPORTE A TOOLS
      const { googleResp, account: acc } = await executeWithFallback(targetEngine, contents, finalSystemInstruction, rawModel, customConfig);
      account = acc;
      const parsed = extractGeminiResponse(googleResp);
      replyText = parsed.replyText;
      toolCalls = parsed.toolCalls;
    }

    const inputTokens = Math.ceil(JSON.stringify(contents).length / 4);
    const outputTokens = Math.ceil((replyText.length + JSON.stringify(toolCalls).length) / 4);
    const msgId = 'msg_' + crypto.randomBytes(12).toString('hex');
    const hasToolCalls = toolCalls.length > 0;
    const stopReason = hasToolCalls ? 'tool_use' : 'end_turn';

    // Montar content blocks
    const contentBlocks = [];
    if (replyText) {
      contentBlocks.push({ type: 'text', text: replyText });
    }
    for (const tc of toolCalls) {
      contentBlocks.push({
        type: 'tool_use',
        id: tc.id,
        name: tc.name,
        input: tc.args
      });
    }

    res.json({
      id: msgId,
      type: 'message',
      role: 'assistant',
      model: rawModel,
      content: contentBlocks,
      stop_reason: stopReason,
      stop_sequence: null,
      usage: {
        input_tokens: inputTokens,
        output_tokens: outputTokens
      }
    });

    logUsage(userKey, rawModel, inputTokens, outputTokens, account?.id || null, modelMultiplier);
  } catch (err) {
    console.error(`[Anthropic /v1/messages] Erro ao processar modelo "${rawModel}" -> Upstream: "${targetEngine}" (Provider: ${providerId}):`, err.message || err);
    const isQuotaError = (err.message || '').toLowerCase().includes('cota esgotada');
    res.status(isQuotaError ? 429 : 500).json({
      type: 'error',
      error: { type: isQuotaError ? 'rate_limit_error' : 'api_error', message: err.message || 'Erro ao processar na conta Antigravity.' }
    });
  }
});

// -------------------------------------------------------------
// ENDPOINT OPENAI E AZURE COMPATÍVEL (/v1/chat/completions)
// -------------------------------------------------------------
app.post(['/openai/deployments/:deployment/chat/completions', '/v1/chat/completions', '/chat/completions'], async (req, res) => {
  const userKey = await authenticateClient(req, res);
  if (!userKey) return;

  const rawModel = req.body.model || req.params.deployment || 'Realtime';
  const incomingMessages = req.body.messages || [];
  const { contents, userSystemPrompt } = convertOpenAiMessagesToGemini(incomingMessages);

  const { targetEngine, customPrompt, providerId, multiplier: modelMultiplier } = await resolveModelConfig(rawModel, contents);
  const finalSystemInstruction = [customPrompt, userSystemPrompt].filter(Boolean).join('\n\n');

  // Suporte a tools e functions padrão OpenAI
  const rawTools = req.body.tools || (req.body.functions ? req.body.functions.map(f => ({ type: 'function', function: f })) : null);
  const geminiTools = convertToolsToGemini(rawTools);
  const customConfig = {
    temperature: req.body.temperature !== undefined ? req.body.temperature : 0.7,
    maxOutputTokens: req.body.max_tokens || 8192,
    tools: geminiTools
  };

  try {
    let replyText = '';
    let toolCalls = [];
    let account = null;

    if (req.body.stream) {
      res.setHeader('Content-Type', 'text/event-stream; charset=utf-8');
      res.setHeader('Cache-Control', 'no-cache');
      res.setHeader('Connection', 'keep-alive');

      const compId = 'chatcmpl-' + crypto.randomBytes(12).toString('hex');
      const abortController = new AbortController();
      req.on('close', () => abortController.abort());

      // Chunk inicial com role
      const startChunk = {
        id: compId,
        object: 'chat.completion.chunk',
        created: Math.floor(Date.now() / 1000),
        model: rawModel,
        choices: [{
          index: 0,
          delta: { role: 'assistant', content: '' },
          finish_reason: null
        }]
      };
      res.write(`data: ${JSON.stringify(startChunk)}\n\n`);

      if (providerId && providerId != 2) {
        const extResult = await executeExternalProvider(providerId, targetEngine, incomingMessages, finalSystemInstruction, rawTools);
        replyText = extResult.replyText;
        toolCalls = extResult.toolCalls || [];
        if (replyText) {
          res.write(`data: ${JSON.stringify({
            id: compId,
            object: 'chat.completion.chunk',
            created: Math.floor(Date.now() / 1000),
            model: rawModel,
            choices: [{ index: 0, delta: { content: replyText }, finish_reason: null }]
          })}\n\n`);
        }
      } else {
        const { googleResp, account: acc } = await executeWithFallbackStream(
          targetEngine,
          contents,
          finalSystemInstruction,
          rawModel,
          customConfig,
          (textChunk) => {
            if (textChunk) {
              res.write(`data: ${JSON.stringify({
                id: compId,
                object: 'chat.completion.chunk',
                created: Math.floor(Date.now() / 1000),
                model: rawModel,
                choices: [{ index: 0, delta: { content: textChunk }, finish_reason: null }]
              })}\n\n`);
            }
          },
          abortController.signal
        );
        account = acc;
        replyText = googleResp?.replyText || '';
        toolCalls = googleResp?.toolCalls || [];
        for (const tc of toolCalls) {
          if (tc.thoughtSignature) {
            saveThoughtSignature(tc.id, tc.name, tc.thoughtSignature);
          }
        }
      }

      const hasToolCalls = toolCalls.length > 0;
      if (hasToolCalls) {
        const streamOpenAiToolCalls = toolCalls.map((tc, idx) => ({
          index: idx,
          id: tc.id,
          type: 'function',
          function: {
            name: tc.name,
            arguments: JSON.stringify(tc.args)
          }
        }));

        res.write(`data: ${JSON.stringify({
          id: compId,
          object: 'chat.completion.chunk',
          created: Math.floor(Date.now() / 1000),
          model: rawModel,
          choices: [{
            index: 0,
            delta: { tool_calls: streamOpenAiToolCalls },
            finish_reason: null
          }]
        })}\n\n`);
      }

      const endChunk = {
        id: compId,
        object: 'chat.completion.chunk',
        created: Math.floor(Date.now() / 1000),
        model: rawModel,
        choices: [{
          index: 0,
          delta: {},
          finish_reason: hasToolCalls ? 'tool_calls' : 'stop'
        }]
      };
      res.write(`data: ${JSON.stringify(endChunk)}\n\n`);
      res.write(`data: [DONE]\n\n`);
      res.end();

      const inputTokens = Math.ceil(JSON.stringify(contents).length / 4);
      const outputTokens = Math.ceil((replyText.length + JSON.stringify(toolCalls).length) / 4);
      logUsage(userKey, rawModel, inputTokens, outputTokens, account?.id || null, modelMultiplier);
      return;
    }

    if (providerId && providerId != 2) {
      // PROVEDOR EXTERNO (ex: api.b.ai)
      const extResult = await executeExternalProvider(providerId, targetEngine, incomingMessages, finalSystemInstruction, rawTools);
      replyText = extResult.replyText;
      toolCalls = extResult.toolCalls || [];
    } else {
      // GOOGLE ANTIGRAVITY POOL COM SUPORTE A TOOLS
      const { googleResp, account: acc } = await executeWithFallback(targetEngine, contents, finalSystemInstruction, rawModel, customConfig);
      account = acc;
      const parsed = extractGeminiResponse(googleResp);
      replyText = parsed.replyText;
      toolCalls = parsed.toolCalls;
    }

    const inputTokens = Math.ceil(JSON.stringify(contents).length / 4);
    const outputTokens = Math.ceil((replyText.length + JSON.stringify(toolCalls).length) / 4);
    const compId = 'chatcmpl-' + crypto.randomBytes(12).toString('hex');
    const hasToolCalls = toolCalls.length > 0;
    const finishReason = hasToolCalls ? 'tool_calls' : 'stop';

    const nonStreamOpenAiToolCalls = hasToolCalls ? toolCalls.map(tc => ({
      id: tc.id,
      type: 'function',
      function: {
        name: tc.name,
        arguments: JSON.stringify(tc.args)
      }
    })) : undefined;

    res.json({
      id: compId,
      object: 'chat.completion',
      created: Math.floor(Date.now() / 1000),
      model: rawModel,
      choices: [{
        index: 0,
        message: {
          role: 'assistant',
          content: replyText || null,
          tool_calls: nonStreamOpenAiToolCalls
        },
        finish_reason: finishReason
      }],
      usage: {
        prompt_tokens: inputTokens,
        completion_tokens: outputTokens,
        total_tokens: inputTokens + outputTokens
      }
    });

    logUsage(userKey, rawModel, inputTokens, outputTokens, account?.id || null, modelMultiplier);
  } catch (err) {
    console.error(`[OpenAI /v1/chat/completions] Erro ao processar modelo "${rawModel}" -> Upstream: "${targetEngine}" (Provider: ${providerId}):`, err.message || err);
    if (rawTools && rawTools.length > 0) {
      console.error(`[OpenAI Debug] Total tools recebidos: ${rawTools.length}`);
    }
    if (res.headersSent) return;
    const isQuotaError = (err.message || '').toLowerCase().includes('cota esgotada');
    res.status(isQuotaError ? 429 : 500).json({
      error: { message: err.message || 'Erro ao processar requisição.', type: isQuotaError ? 'rate_limit_error' : 'api_error' }
    });
  }
});

app.get(['/v1/models', '/models'], async (req, res) => {
  try {
    // Retorna os modelos criados pelo admin na tabela models (100% mascarados para os clientes)
    const [rows] = await pool.query('SELECT model_key, name FROM models WHERE status = "active" ORDER BY id ASC');
    const data = rows.map(r => ({
      id: r.model_key,
      object: 'model',
      type: 'model',
      display_name: r.name,
      name: r.name,
      created: 1700000000,
      created_at: '2024-10-22T00:00:00Z',
      owned_by: 'antigravity-gateway'
    }));

    // Modelo de transcrição de áudio oficial compatível com OpenAI Whisper
    data.push({
      id: 'whisper-1',
      object: 'model',
      type: 'audio',
      display_name: 'Whisper 1 (Transcrição de Áudio Multimodal)',
      name: 'Whisper 1 (Áudio ➔ Texto)',
      created: 1700000000,
      created_at: '2024-10-22T00:00:00Z',
      owned_by: 'openai'
    });

    res.json({
      object: 'list',
      data,
      has_more: false,
      first_id: rows[0]?.model_key || 'whisper-1',
      last_id: 'whisper-1'
    });
  } catch (e) {
    res.json({ object: 'list', data: [], has_more: false });
  }
});

app.get(['/v1/models/:id', '/models/:id'], async (req, res) => {
  try {
    const modelId = req.params.id;
    const [rows] = await pool.query(
      'SELECT model_key, name FROM models WHERE (model_key = ? OR name = ?) AND status = "active" LIMIT 1',
      [modelId, modelId]
    );
    if (rows.length > 0) {
      const r = rows[0];
      return res.json({
        id: r.model_key,
        object: 'model',
        type: 'model',
        display_name: r.name,
        name: r.name,
        created: 1700000000,
        created_at: '2024-10-22T00:00:00Z',
        owned_by: 'antigravity-gateway'
      });
    }
    // Fallback permissivo para que checagens de modelos no Claude Code / CLIs não deem 404
    return res.json({
      id: modelId,
      object: 'model',
      type: 'model',
      display_name: modelId,
      name: modelId,
      created: 1700000000,
      created_at: '2024-10-22T00:00:00Z',
      owned_by: 'antigravity-gateway'
    });
  } catch (e) {
    res.status(500).json({ error: e.message });
  }
});

// -------------------------------------------------------------
// ROTA DE TRANSCRIÇÃO DE ÁUDIO COM IA MULTIMODAL (/v1/audio/transcriptions)
// -------------------------------------------------------------
app.post(['/v1/audio/transcriptions', '/audio/transcriptions'], async (req, res) => {
  const userKey = await authenticateClient(req, res);
  if (!userKey) return;

  try {
    let audioBase64 = req.body?.audio || req.body?.file || '';
    let mimeType = req.body?.mimeType || 'audio/webm';

    if (!audioBase64 && typeof req.body === 'string') {
      try {
        const parsed = JSON.parse(req.body);
        audioBase64 = parsed.audio || parsed.file || '';
        mimeType = parsed.mimeType || mimeType;
      } catch (e) { }
    }

    if (!audioBase64) {
      return res.status(400).json({ error: 'Nenhum áudio fornecido em base64' });
    }

    // Limpar prefixo data:audio/...;base64,
    if (audioBase64.includes(';base64,')) {
      const parts = audioBase64.split(';base64,');
      mimeType = parts[0].replace('data:', '');
      audioBase64 = parts[1];
    }

    const cleanMime = (mimeType || 'audio/webm').split(';')[0];

    const contents = [
      {
        role: 'user',
        parts: [
          {
            inlineData: {
              mimeType: cleanMime,
              data: audioBase64
            }
          },
          {
            text: 'Você é um transcrevedor de áudio em português de altíssima precisão. Transcreva exatamente o que foi dito neste áudio. Retorne APENAS o texto falado puro, sem aspas, sem introdução e sem explicações.'
          }
        ]
      }
    ];

    const { googleResp, account } = await executeWithFallback('gemini-2.5-flash', contents, '', 'gemini-2.5-flash');
    const candidate = googleResp?.candidates?.[0];
    let text = candidate?.content?.parts?.map(p => p.text || '').join(' ').trim() || '';

    // Limpar aspas externas se houver
    text = text.replace(/^["']|["']$/g, '').trim();

    const inputTokens = Math.ceil(audioBase64.length / 8);
    const outputTokens = Math.ceil(text.length / 4);
    logUsage(userKey, 'whisper-1', inputTokens, outputTokens, account?.id || null, 1.0);

    return res.json({ text });
  } catch (err) {
    console.error('[Audio Transcription Error]:', err.message);
    return res.status(500).json({ error: err.message || 'Erro ao transcrever áudio' });
  }
});

app.get('/status', async (req, res) => {
  try {
    const [accounts] = await pool.query('SELECT id, email, is_active, status, total_requests, last_sync_at FROM antigravity_accounts');
    res.json({
      online: true,
      total_accounts: accounts.length,
      accounts: accounts
    });
  } catch (e) {
    res.status(500).json({ online: false, error: e.message });
  }
});

// ==========================================
// ROTAS DE INTEGRAÇÃO WHATSAPP BAILEYS & AGENTE IA
// ==========================================

initWhatsAppBaileys(pool, executeWithFallback).catch(err => {
  console.error('[Baileys Init Error]:', err);
});

app.get('/instances', async (req, res) => {
  try {
    const [rows] = await pool.query('SELECT instance_key, instance_name as name, status, qr_code as qrcode FROM whatsapp_instances');
    if (rows.length > 0) {
      return res.json(rows.map(r => ({
        ...r,
        qrcode: baileysState.status === 'connected' ? null : (baileysState.qrRaw || r.qrcode),
        qrDataUrl: baileysState.qrDataUrl,
        phone: baileysState.phone
      })));
    }
  } catch (err) { }

  res.json([{
    instance_key: baileysState.instanceKey,
    name: 'WhatsApp Principal',
    status: baileysState.status,
    qrcode: baileysState.qrRaw,
    qrDataUrl: baileysState.qrDataUrl,
    phone: baileysState.phone
  }]);
});

app.get('/instances/:instanceKey/status', async (req, res) => {
  const { instanceKey } = req.params;
  res.json({
    instance_key: instanceKey,
    name: 'WhatsApp Principal',
    status: baileysState.status,
    qrcode: baileysState.qrRaw,
    qrDataUrl: baileysState.qrDataUrl,
    phone: baileysState.phone
  });
});

app.post(['/instances/:instanceKey/connect', '/instances/:instanceKey/qrcode'], async (req, res) => {
  const { instanceKey } = req.params;
  try {
    if (baileysState.status === 'connected') {
      return res.json({
        success: true,
        instance_key: instanceKey,
        status: 'connected',
        phone: baileysState.phone,
        message: 'WhatsApp já está conectado no seu celular!'
      });
    }

    connectToWhatsApp().catch(err => console.error('[Baileys Connect]:', err));

    for (let i = 0; i < 10; i++) {
      await new Promise(r => setTimeout(r, 500));
      if (baileysState.qrRaw || baileysState.status === 'connected') break;
    }

    res.json({
      success: true,
      instance_key: instanceKey,
      status: baileysState.status,
      qrcode: baileysState.qrRaw,
      qrDataUrl: baileysState.qrDataUrl,
      phone: baileysState.phone,
      message: baileysState.qrRaw ? 'QR Code gerado para leitura no WhatsApp' : 'Iniciando WhatsApp Web...'
    });
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.post('/instances/:instanceKey/disconnect', async (req, res) => {
  try {
    const result = await disconnectWhatsApp();
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

app.post('/instances/:instanceKey/send', async (req, res) => {
  const { to, message } = req.body;
  if (!to || !message) {
    return res.status(400).json({ success: false, error: 'Parâmetros "to" e "message" são obrigatórios.' });
  }

  try {
    const result = await sendWhatsAppMessage(to, message);
    res.json(result);
  } catch (err) {
    res.status(500).json({ success: false, error: err.message });
  }
});

// Endpoint para listar vozes TTS disponíveis (usado pelo frontend)
app.get('/v1/realtime/voices', (req, res) => {
  res.json({ voices: AVAILABLE_VOICES });
});

const PORT = process.env.PORT || 3000;
const httpServer = createServer(app);

// Inicializar WebSocket Realtime
initRealtime(httpServer, {
  authenticateApiKey,
  executeWithFallback,
  executeWithFallbackStream,
  executeExternalProvider,
  resolveModelConfig,
  logUsage,
  getSetting,
  pool,
  convertToolsToGemini,
  sanitizeToolsForProvider
});

// Inicializar MCP Server Nativo (Model Context Protocol para Claude Code & Subagentes)
initMcp(app, {
  authenticateApiKey,
  executeWithFallback,
  executeWithFallbackStream,
  executeExternalProvider,
  resolveModelConfig,
  logUsage,
  pool
});

// Rotina em segundo plano: Sincronizacao periodica de cotas com o Google a cada 5 minutos
setInterval(async () => {
  try {
    const [accounts] = await pool.query('SELECT * FROM antigravity_accounts WHERE is_active = 1');
    await Promise.allSettled(accounts.map(acc => syncSingleAccount(acc, false)));
  } catch (err) {
    console.warn('[Periodic Quota Sync] Erro:', err.message);
  }
}, 5 * 60 * 1000);

httpServer.listen(PORT, () => {
  console.log(`\n=============================================================`);
  console.log(`🚀 ANTIGRAVITY GATEWAY & REVENDA ATIVO NA PORTA ${PORT}`);
  console.log(`🔑 Login Google OAuth: http://localhost:${PORT}/auth/antigravity/login`);
  console.log(`⚡ Claude Code CLI:   ANTHROPIC_BASE_URL=http://localhost:${PORT}`);
  console.log(`💻 VS Code / Cline:    baseURL=http://localhost:${PORT}/v1`);
  console.log(`🎙️  Realtime WebSocket:  ws://localhost:${PORT}/v1/realtime`);
  console.log(`🤖 Claude Code MCP:   claude mcp add --transport sse painel-subagente http://localhost:${PORT}/mcp/sse --header "Authorization: Bearer SUA_API_KEY"`);
  console.log(`=============================================================\n`);
});

