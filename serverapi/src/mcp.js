import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { SSEServerTransport } from '@modelcontextprotocol/sdk/server/sse.js';
import { z } from 'zod';
import crypto from 'crypto';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Raiz padrão (fallback) do projeto — usada quando nenhum caminho é configurado.
const defaultProjectRoot = process.env.WORKSPACE_DIR
  ? path.resolve(process.env.WORKSPACE_DIR)
  : path.resolve(__dirname, '../../');

// -------------------------------------------------------------
// MODO DE EXECUÇÃO: LOCAL vs REMOTO (VPS)
// No gateway LOCAL, as tools de disco (ler_codigo_arquivo, arquivos, etc.) leem o
// projeto do usuário. No gateway REMOTO/VPS elas só enxergam o disco da VPS, NÃO o
// projeto do cliente — então o cliente deve enviar o código via "conteudos".
//
// O modo é controlado pelo painel SuperAdmin (tabela `settings`):
//   - mcp_local_mode   = '1' para ligar a leitura de disco (toggle)
//   - mcp_workspace_dir= caminho do projeto a ser lido (ex: C:\xampp\htdocs\meuprojeto)
// Fallback: variáveis de ambiente MCP_LOCAL e WORKSPACE_DIR.
//
// SEGURANÇA/ROBUSTEZ: o modo local só é ativado se o caminho configurado EXISTIR
// nesta máquina. Assim, mesmo que o toggle esteja ligado num banco compartilhado,
// a VPS (que não tem a pasta local) permanece em modo REMOTO e não tenta ler disco à toa.
// -------------------------------------------------------------
const ENV_LOCAL_FALLBACK = /^(1|true|local|yes|on)$/i.test(String(process.env.MCP_LOCAL || '').trim());

// Estado de execução inicial: se MCP_LOCAL=true no .env e o caminho existir, já inicia ativo imediatamente!
let localModeEnabled = Boolean(ENV_LOCAL_FALLBACK && fs.existsSync(defaultProjectRoot) && fs.statSync(defaultProjectRoot).isDirectory());
let projectRoot = defaultProjectRoot;

// Descrições neutras — sempre corretas independente do modo (a IA cliente decide sozinha).
const DESC_ARQUIVOS = 'Caminhos para o subagente LER DO DISCO DO GATEWAY. Só funciona se o gateway estiver em modo LOCAL (ativado no painel SuperAdmin) e os arquivos existirem nessa máquina. Em gateway remoto/VPS NÃO use isto — envie o código pelo parâmetro "conteudos". Ex: ["revenda.php", "admin/accounts.php"]';

const DESC_TOOL_DISCO = ' (lê o disco do gateway; ativo só em modo LOCAL definido no SuperAdmin — em gateway VPS/remoto use "conteudos" no executar_subagente)';

// Mensagem padrão quando uma tool de disco é chamada em gateway remoto/VPS.
const DISK_OFF_MSG = 'Leitura de disco DESLIGADA neste gateway (modo remoto/VPS). Esta ferramenta só funciona em modo LOCAL — ative no painel SuperAdmin (toggle "Modo Local do MCP" + caminho do projeto). Para analisar código agora, use a ferramenta executar_subagente enviando o código no parâmetro "conteudos" (a IA cliente lê o arquivo localmente e envia o conteúdo).';

const IGNORED_DIRS = new Set([
  'node_modules',
  '.git',
  'auth_info_baileys',
  'logs',
  '.claude',
  '.idea',
  '.vscode',
  'vendor'
]);

const BLOCKED_FILES = new Set([
  '.env',
  'database.php',
  'install.lock',
  'id_rsa',
  'id_rsa.pub'
]);

/**
 * Valida e resolve caminho seguro dentro do projeto (impede Path Traversal e vazamento de chaves)
 */
function resolveSafePath(userPath, baseDir = projectRoot) {
  if (!userPath || typeof userPath !== 'string') {
    throw new Error('Caminho de arquivo inválido ou não informado.');
  }

  const target = path.isAbsolute(userPath) ? path.normalize(userPath) : path.resolve(baseDir, userPath);
  const rel = path.relative(baseDir, target);

  if (rel.startsWith('..') && !target.startsWith(baseDir)) {
    throw new Error(`Acesso negado: O caminho "${userPath}" está fora do diretório do projeto.`);
  }

  const basename = path.basename(target).toLowerCase();
  if (BLOCKED_FILES.has(basename) || basename.endsWith('.pem') || basename.endsWith('.key')) {
    throw new Error(`Acesso negado: O arquivo "${basename}" contém dados confidenciais e foi protegido.`);
  }

  return target;
}

/**
 * Lê arquivo de código com numeração de linhas e suporte a paginação
 */
function readCodeFile(caminho, linhaInicio = 1, linhaFim = null) {
  const safePath = resolveSafePath(caminho);
  if (!fs.existsSync(safePath) || !fs.statSync(safePath).isFile()) {
    throw new Error(`Arquivo não encontrado no projeto: ${caminho}`);
  }

  const raw = fs.readFileSync(safePath, 'utf8');
  const allLines = raw.split(/\r?\n/);
  const total = allLines.length;

  const start = Math.max(1, parseInt(linhaInicio, 10) || 1);
  const end = linhaFim ? Math.min(total, parseInt(linhaFim, 10)) : Math.min(total, start + 499);

  const selected = [];
  for (let i = start; i <= end; i++) {
    selected.push(`${i} | ${allLines[i - 1]}`);
  }

  let header = `[Arquivo: ${caminho} | Exibindo linhas ${start} a ${end} de ${total}]`;
  if (end < total) {
    header += `\n(Dica: Para ler as próximas linhas, use linha_inicio=${end + 1})`;
  }

  return `${header}\n\n${selected.join('\n')}`;
}

/**
 * Varre e lista os arquivos do projeto com filtros
 */
function listProjectFiles(subPasta = '', extensao = '', recursivo = null) {
  const targetDir = subPasta ? resolveSafePath(subPasta) : projectRoot;
  if (!fs.existsSync(targetDir) || !fs.statSync(targetDir).isDirectory()) {
    throw new Error(`Diretório não encontrado: ${subPasta || 'raiz'}`);
  }

  const isRoot = path.normalize(targetDir) === path.normalize(projectRoot);
  const shouldRecurse = recursivo !== null ? Boolean(recursivo) : !isRoot;
  const extFilter = extensao ? extensao.toLowerCase() : '';

  const results = [];

  function scan(dir, depth = 0) {
    if (results.length >= 250) return;
    if (depth > 8) return;

    let entries = [];
    try {
      entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch (e) {
      return;
    }

    for (const ent of entries) {
      if (results.length >= 250) break;
      const fullPath = path.join(dir, ent.name);
      const name = ent.name;

      if (ent.isDirectory()) {
        if (IGNORED_DIRS.has(name) || name.startsWith('.')) continue;
        const relDir = path.relative(projectRoot, fullPath).replace(/\\/g, '/');
        results.push(`📁 ${relDir}/`);
        if (shouldRecurse) {
          scan(fullPath, depth + 1);
        }
      } else if (ent.isFile()) {
        if (BLOCKED_FILES.has(name.toLowerCase()) || name.endsWith('.pem') || name.endsWith('.key')) continue;
        if (extFilter && !name.toLowerCase().endsWith(extFilter)) continue;
        const relFile = path.relative(projectRoot, fullPath).replace(/\\/g, '/');
        results.push(`📄 ${relFile}`);
      }
    }
  }

  scan(targetDir);
  return results;
}

/**
 * Busca por termo em arquivos de código (grep)
 */
function searchInCode(termo, subPasta = '', extensao = '.php') {
  if (!termo || typeof termo !== 'string' || termo.trim().length === 0) {
    throw new Error('Informe um termo de busca válido.');
  }

  const targetDir = subPasta ? resolveSafePath(subPasta) : projectRoot;
  if (!fs.existsSync(targetDir) || !fs.statSync(targetDir).isDirectory()) {
    throw new Error(`Diretório de busca não encontrado: ${subPasta || 'raiz'}`);
  }

  const extFilter = extensao ? extensao.toLowerCase() : '';
  const termLower = termo.toLowerCase();
  const results = [];

  function scanSearch(dir, depth = 0) {
    if (results.length >= 60) return;
    if (depth > 8) return;

    let entries = [];
    try {
      entries = fs.readdirSync(dir, { withFileTypes: true });
    } catch (e) {
      return;
    }

    for (const ent of entries) {
      if (results.length >= 60) break;
      const fullPath = path.join(dir, ent.name);
      const name = ent.name;

      if (ent.isDirectory()) {
        if (IGNORED_DIRS.has(name) || name.startsWith('.')) continue;
        scanSearch(fullPath, depth + 1);
      } else if (ent.isFile()) {
        if (BLOCKED_FILES.has(name.toLowerCase()) || name.endsWith('.pem') || name.endsWith('.key')) continue;
        if (extFilter && !name.toLowerCase().endsWith(extFilter)) continue;

        try {
          const content = fs.readFileSync(fullPath, 'utf8');
          if (!content.toLowerCase().includes(termLower)) continue;

          const lines = content.split(/\r?\n/);
          const relFile = path.relative(projectRoot, fullPath).replace(/\\/g, '/');

          for (let i = 0; i < lines.length; i++) {
            if (results.length >= 60) break;
            if (lines[i].toLowerCase().includes(termLower)) {
              results.push(`- ${relFile}:${i + 1} -> ${lines[i].trim().slice(0, 160)}`);
            }
          }
        } catch (readErr) {}
      }
    }
  }

  scanSearch(targetDir);
  return results;
}

/**
 * Executa uma função assíncrona com re-tentativas e backoff exponencial.
 * Só retenta erros transitórios (rede, 429/5xx, timeout, provider sobrecarregado).
 * Erros definitivos (saldo, autenticação, validação) falham na hora.
 */
async function withRetry(fn, { retries = 2, baseDelay = 800, label = 'operação' } = {}) {
  let lastErr;
  for (let attempt = 0; attempt <= retries; attempt++) {
    try {
      return await fn();
    } catch (err) {
      lastErr = err;
      const msg = (err && err.message) ? err.message : String(err);

      // Erros definitivos: não adianta retentar
      if (/saldo|autentic|api key|inválid|unauthorized|forbidden|quota/i.test(msg)) break;

      // Erros transitórios: vale retentar
      const transient = /429|5\d\d|timeout|etimedout|econnreset|enotfound|socket hang up|overloaded|rate.?limit|temporar|unavailable|fetch failed/i.test(msg);
      if (!transient || attempt === retries) break;

      const delay = baseDelay * Math.pow(2, attempt);
      console.warn(`[MCP retry] ${label}: tentativa ${attempt + 1}/${retries + 1} falhou (${msg}). Retentando em ${delay}ms...`);
      await new Promise(r => setTimeout(r, delay));
    }
  }
  throw lastErr;
}

/**
 * Módulo MCP (Model Context Protocol) Nativo com Transporte SSE
 * Permite que o Claude Code (em sua conta Pro ou qualquer cliente MCP)
 * utilize a API do Painel como um orquestrador de subagentes.
 */
export function initMcp(app, gateway) {
  const {
    authenticateApiKey,
    executeWithFallback,
    executeWithFallbackStream,
    executeExternalProvider,
    resolveModelConfig,
    logUsage,
    pool
  } = gateway;

  // -------------------------------------------------------------
  // Resolve o modo LOCAL/REMOTO a partir do painel SuperAdmin (tabela `settings`),
  // com fallback para variáveis de ambiente. Só liga a leitura de disco se o
  // caminho configurado existir NESTA máquina (a VPS continua remota automaticamente).
  // -------------------------------------------------------------
  async function refreshMcpMode() {
    let toggle = ENV_LOCAL_FALLBACK;
    let confPath = defaultProjectRoot;
    try {
      const [rows] = await pool.query(
        "SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('mcp_local_mode', 'mcp_workspace_dir')"
      );
      for (const r of rows) {
        if (r.setting_key === 'mcp_local_mode') {
          toggle = /^(1|true|on|yes)$/i.test(String(r.setting_value || '').trim());
        } else if (r.setting_key === 'mcp_workspace_dir' && String(r.setting_value || '').trim()) {
          confPath = path.resolve(String(r.setting_value).trim());
        }
      }
    } catch (e) {
      // settings indisponível → mantém fallback de ambiente
    }

    let pathOk = false;
    try {
      pathOk = fs.existsSync(confPath) && fs.statSync(confPath).isDirectory();
    } catch (e) {
      pathOk = false;
    }

    const prev = localModeEnabled;
    const prevRoot = projectRoot;
    localModeEnabled = Boolean(toggle && pathOk);
    projectRoot = localModeEnabled ? confPath : defaultProjectRoot;

    if (prev !== localModeEnabled || prevRoot !== projectRoot) {
      if (localModeEnabled) {
        console.log(`📂 MCP Modo: LOCAL — leitura de disco ATIVA em ${projectRoot}`);
      } else if (toggle && !pathOk) {
        console.log(`📂 MCP Modo: REMOTO/VPS — toggle local ligado, mas o caminho "${confPath}" não existe nesta máquina. Clientes devem usar "conteudos".`);
      } else {
        console.log(`📂 MCP Modo: REMOTO/VPS — leitura de disco desligada. Clientes devem enviar código via "conteudos".`);
      }
    }
  }

  // Resolve agora e revalida a cada 30s (toggle no SuperAdmin passa a valer sem reiniciar).
  refreshMcpMode();
  setInterval(refreshMcpMode, 30 * 1000);

  // Mapa de sessões ativas: sessionId -> { transport, server, userKey, keepAliveTimer }
  const activeSessions = new Map();

  // Helper para extrair API Key de headers ou query param
  async function extractAndAuth(req) {
    const authHeader = req.headers['authorization'] || '';
    const xApiKey = req.headers['x-api-key'] || req.headers['x-api-token'] || '';
    const queryKey = req.query['apiKey'] || req.query['api_key'] || req.query['key'] || req.query['token'] || '';

    let clientApiKey = '';
    if (queryKey) {
      clientApiKey = String(queryKey).trim();
    } else if (xApiKey) {
      clientApiKey = String(xApiKey).trim();
    } else if (authHeader) {
      clientApiKey = authHeader.replace(/^Bearer\s+/i, '').trim();
    } else {
      // Procura por headers flexíveis enviados por ferramentas como Codex ou proxies (ex: BRLOVE_API_KEY, CODEX_API_KEY, etc.)
      for (const [headerName, headerVal] of Object.entries(req.headers || {})) {
        if (/api[-_]?key|auth[-_]?token|mcp[-_]?key/i.test(headerName)) {
          if (headerVal && typeof headerVal === 'string' && headerVal.trim().length > 10) {
            clientApiKey = headerVal.replace(/^Bearer\s+/i, '').trim();
            break;
          }
        }
      }
    }

    if (!clientApiKey) {
      throw new Error('API Key não fornecida. Passe via Authorization: Bearer <key>, x-api-key ou na URL com ?apiKey=<sua_chave>');
    }

    return await authenticateApiKey(clientApiKey);
  }

  /**
   * Executa a chamada do subagente com suporte a streaming contínuo e múltiplos fallbacks
   */
  async function runSubagent({ prompt, modelo, system_prompt, arquivos, conteudos, max_tokens, temperature, userKey, progressCallback, abortSignal }) {
    // Revalida o modo MCP (painel SuperAdmin / .env) na hora para garantir sincronia imediata
    await refreshMcpMode().catch(() => {});

    let rawModel = modelo;
    if (!rawModel) {
      try {
        const [activeM] = await pool.query("SELECT model_key FROM models WHERE status = 'active' ORDER BY id ASC LIMIT 1");
        if (activeM && activeM.length > 0) {
          rawModel = activeM[0].model_key;
        }
      } catch (e) {}
    }
    if (!rawModel) rawModel = 'gemini-3.8-flash-medium';

    // Monta o contexto de código a partir de DUAS fontes:
    let fileContentsContext = '';

    // FONTE 1 (produto / qualquer cliente): conteúdo enviado DIRETO pela IA cliente.
    // Funciona para qualquer projeto remoto, sem a VPS precisar acessar o disco do cliente.
    // Aceita [{ caminho, conteudo }] ou objetos equivalentes.
    if (Array.isArray(conteudos) && conteudos.length > 0) {
      for (const item of conteudos) {
        if (!item) continue;
        const caminho = item.caminho || item.path || item.nome || 'arquivo';
        const conteudo = typeof item === 'string' ? item : (item.conteudo || item.content || '');
        if (!conteudo) continue;
        const truncated = conteudo.length > 150000
          ? conteudo.slice(0, 150000) + '\n... [conteúdo truncado para manter performance]'
          : conteudo;
        fileContentsContext += `\n\n=== ARQUIVO ENVIADO PELO CLIENTE: ${caminho} ===\n${truncated}\n=== FIM DO ARQUIVO ===`;
      }
    }

    // FONTE 2 (disco): só lê se o gateway estiver em modo LOCAL (SuperAdmin).
    // Em modo REMOTO/VPS não tenta ler à toa — orienta o cliente a usar "conteudos".
    if (Array.isArray(arquivos) && arquivos.length > 0 && !localModeEnabled) {
      fileContentsContext += `\n\n=== AVISO: leitura de disco está DESLIGADA neste gateway (modo remoto/VPS). Os ${arquivos.length} caminho(s) em "arquivos" foram ignorados. Para o subagente ver o código, reenvie pelo parâmetro "conteudos" (a IA cliente lê o arquivo localmente e manda o conteúdo). ===`;
    } else if (Array.isArray(arquivos) && arquivos.length > 0) {
      for (const filePath of arquivos) {
        try {
          const safePath = resolveSafePath(filePath);
          if (fs.existsSync(safePath) && fs.statSync(safePath).isFile()) {
            const content = fs.readFileSync(safePath, 'utf8');
            const truncated = content.length > 150000
              ? content.slice(0, 150000) + '\n... [conteúdo truncado para manter performance]'
              : content;
            fileContentsContext += `\n\n=== ARQUIVO DO PROJETO (disco): ${filePath} ===\n${truncated}\n=== FIM DO ARQUIVO ===`;
          } else {
            fileContentsContext += `\n\n=== AVISO: Arquivo "${filePath}" não encontrado no disco do servidor. (Se o gateway roda na VPS, envie o conteúdo via parâmetro "conteudos".) ===`;
          }
        } catch (fErr) {
          fileContentsContext += `\n\n=== ERRO AO CARREGAR ARQUIVO "${filePath}": ${fErr.message} ===`;
        }
      }
    }

    const finalPrompt = fileContentsContext
      ? `${prompt}\n\n[ARQUIVOS DO PROJETO CARREGADOS DO DISCO PARA ANÁLISE]:${fileContentsContext}`
      : prompt;

    const incomingContents = [
      {
        role: 'user',
        parts: [{ text: finalPrompt }]
      }
    ];

    const { targetEngine, customPrompt, providerId, multiplier: modelMultiplier } = await resolveModelConfig(rawModel, incomingContents);

    let systemParts = [];
    if (customPrompt) systemParts.push(customPrompt);
    if (system_prompt) systemParts.push(system_prompt);
    const finalSystemInstruction = systemParts.join('\n\n');

    // Configuração de geração parametrizável (com limites seguros)
    const genConfig = {
      temperature: (typeof temperature === 'number' && temperature >= 0 && temperature <= 2) ? temperature : 0.7,
      maxOutputTokens: (typeof max_tokens === 'number' && max_tokens > 0) ? Math.min(max_tokens, 65536) : 16384,
      tools: []
    };

    let replyText = '';
    let account = null;

    if (providerId && providerId !== 2) {
      const incomingMsgs = [
        ...(finalSystemInstruction ? [{ role: 'system', content: finalSystemInstruction }] : []),
        { role: 'user', content: finalPrompt }
      ];
      const extResult = await withRetry(
        () => executeExternalProvider(providerId, targetEngine, incomingMsgs, finalSystemInstruction, []),
        { label: `provider externo (${rawModel})` }
      );
      replyText = extResult.replyText;
    } else {
      // 1. Tenta executar via streaming contínuo para manter os dados fluindo em gerações longas
      let streamSucceeded = false;
      if (typeof executeWithFallbackStream === 'function') {
        try {
          let streamedText = '';
          const streamRes = await executeWithFallbackStream(
            targetEngine,
            incomingContents,
            finalSystemInstruction,
            rawModel,
            genConfig,
            (tokenChunk, toolCall, meta) => {
              if (tokenChunk) {
                streamedText += tokenChunk;
                if (typeof progressCallback === 'function') {
                  progressCallback({ type: 'text', length: streamedText.length, chunk: tokenChunk });
                }
              } else if (meta?.thinking) {
                if (typeof progressCallback === 'function') {
                  progressCallback({ type: 'thinking', chunk: meta.thought });
                }
              }
            },
            abortSignal
          );
          account = streamRes.account;
          replyText = streamRes.googleResp?.replyText || streamedText;
          streamSucceeded = true;
        } catch (streamErr) {
          console.warn('[MCP Subagente] Fallback de streaming para execução direta:', streamErr.message);
        }
      }

      // 2. Se o streaming não foi usado ou falhou, usa a execução padrão (com timeout estendido de 300s)
      if (!streamSucceeded) {
        const { googleResp, account: acc } = await withRetry(
          () => executeWithFallback(
            targetEngine,
            incomingContents,
            finalSystemInstruction,
            rawModel,
            genConfig
          ),
          { label: `execução direta (${rawModel})` }
        );
        account = acc;

        const actualResp = googleResp?.response || googleResp;
        const candidates = actualResp?.candidates || [];
        if (candidates.length > 0 && candidates[0].content?.parts) {
          for (const p of candidates[0].content.parts) {
            if (p.thought) continue;
            if (p.text) replyText += p.text;
          }
        }
      }
    }

    if (!replyText) {
      replyText = '[Aviso do Subagente: Não foi gerada resposta textual pelo modelo selecionado.]';
    }

    // Debitar cota
    const inputTokens = Math.ceil((finalPrompt.length + (finalSystemInstruction ? finalSystemInstruction.length : 0)) / 4);
    const outputTokens = Math.ceil(replyText.length / 4);
    logUsage(userKey, rawModel, inputTokens, outputTokens, account?.id || null, modelMultiplier);

    return replyText;
  }

  // Handler principal de SSE do MCP
  async function handleMcpSse(req, res) {
    // Timeout estendido de 10 minutos (600.000 ms) para subagentes e gerações longas com thinking profundo
    req.setTimeout(600000);
    res.setTimeout(600000);
    if (req.socket) {
      req.socket.setTimeout(600000);
      req.socket.setKeepAlive(true, 5000);
      req.socket.setNoDelay(true);
    }

    // Garantir CORS total e flags anti-buffering para Cloudflare, Nginx e Apache
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Headers', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('X-Accel-Buffering', 'no');
    res.setHeader('Cache-Control', 'no-cache, no-transform');

    let userKey;
    try {
      userKey = await extractAndAuth(req);
    } catch (err) {
      const isBalance = err.message.includes('Saldo');
      return res.status(isBalance ? 402 : 401).json({
        error: {
          type: isBalance ? 'insufficient_quota' : 'authentication_error',
          message: err.message
        }
      });
    }

    // Criar o transporte SSE apontando para a rota de mensagens POST
    const transport = new SSEServerTransport('/mcp/messages', res, {
      enableDnsRebindingProtection: false
    });

    const sessionId = transport.sessionId;

    // Criar uma instância dedicada do McpServer para esta sessão do usuário
    const server = new McpServer({
      name: `painel-subagentes-u${userKey.user_id}`,
      version: '1.0.0'
    });

    // -------------------------------------------------------------
    // TOOL 1: Executar Subagente
    // -------------------------------------------------------------
    server.tool(
      'executar_subagente',
      'Delega uma tarefa/raciocínio complexo a um subagente na API do Painel (Claude Opus Thinking, Sonnet, Gemini, etc.). Para o subagente analisar código, envie os arquivos pelo parâmetro "conteudos" (você lê localmente e manda o conteúdo — funciona em qualquer projeto). O parâmetro "arquivos" só lê o disco do próprio servidor.',
      {
        prompt: z.string().describe('Instrução clara e detalhada para o subagente executar'),
        modelo: z.string().optional().describe('Modelo da API para executar o subagente. Exemplos: claude-opus-5.5, gemini-3.7-flash-medium. Padrão: modelo padrão ativo'),
        system_prompt: z.string().optional().describe('Instrução de persona ou diretrizes de sistema para guiar o subagente'),
        arquivos: z.array(z.string()).optional().describe(DESC_ARQUIVOS),
        conteudos: z.array(z.object({
          caminho: z.string().describe('Caminho/nome do arquivo'),
          conteudo: z.string().describe('Conteúdo do arquivo')
        })).optional().describe('RECOMENDADO para projetos remotos: o conteúdo dos arquivos locais já lido pela IA cliente. Funciona para qualquer projeto sem a VPS acessar seu disco. Ex: [{caminho:"revenda.php", conteudo:"<?php ..."}]'),
        max_tokens: z.number().optional().describe('Limite de tokens de saída (padrão 16384, máx 65536)'),
        temperature: z.number().optional().describe('Criatividade de 0 a 2 (padrão 0.7)')
      },
      async ({ prompt, modelo, system_prompt, arquivos, conteudos, max_tokens, temperature }, extra) => {
        let progressHeartbeatTimer = null;
        try {
          const progressToken =
            extra?._meta?.progressToken ??
            extra?.progressToken ??
            extra?.params?._meta?.progressToken ??
            null;

          let charCount = 0;
          let thinkingCount = 0;
          let heartbeatCount = 0;
          let lastReported = 0;

          const emitProgress = (progressVal, totalVal = 100) => {
            if (progressToken !== null && progressToken !== undefined) {
              try {
                server.server.notification({
                  method: 'notifications/progress',
                  params: {
                    progressToken: progressToken,
                    progress: progressVal,
                    total: totalVal
                  }
                }).catch(() => {});
              } catch (e) {}
            }
            // Envia keepalive SSE na conexão HTTP para evitar timeout em proxies/gateways intermediários
            if (res && !res.writableEnded && !res.destroyed) {
              try {
                res.write(': keepalive-progress\n\n');
              } catch (e) {}
            }
          };

          // 1. Notifica o início da execução imediatamente
          emitProgress(1, 100);

          // 2. Heartbeat de progresso periódico a cada 2.5 segundos
          // Impede timeout de 60s em clientes MCP (Claude Code, Cline, Cursor, SDK) mesmo durante
          // raciocínio interno prolongado (Claude Opus Thinking, Gemini Thinking, DeepSeek R1)
          progressHeartbeatTimer = setInterval(() => {
            heartbeatCount++;
            const virtualProgress = charCount > 0
              ? Math.max(charCount, Math.min(95, 5 + heartbeatCount * 2))
              : Math.min(95, 2 + Math.floor(heartbeatCount * 1.5));
            emitProgress(virtualProgress, 100);
          }, 2500);

          const progressCallback = (info) => {
            const now = Date.now();
            if (typeof info === 'number') {
              charCount = info;
            } else if (info && typeof info === 'object') {
              if (info.length) charCount = info.length;
              if (info.type === 'thinking') thinkingCount++;
            }
            if (now - lastReported > 2000) {
              lastReported = now;
              emitProgress(charCount || (5 + heartbeatCount * 2), 100);
            }
          };

          const replyText = await runSubagent({
            prompt,
            modelo,
            system_prompt,
            arquivos,
            conteudos,
            max_tokens,
            temperature,
            userKey,
            progressCallback,
            abortSignal: extra?.signal
          });

          // Notifica 100% concluído
          emitProgress(100, 100);

          return {
            content: [
              {
                type: 'text',
                text: replyText
              }
            ]
          };
        } catch (subErr) {
          console.error('[MCP Subagente Error]:', subErr.message);
          return {
            isError: true,
            content: [
              {
                type: 'text',
                text: `Erro ao executar subagente na API: ${subErr.message}`
              }
            ]
          };
        } finally {
          if (progressHeartbeatTimer) {
            clearInterval(progressHeartbeatTimer);
          }
        }
      }
    );

    // -------------------------------------------------------------
    // TOOL 2: Listar Modelos Disponíveis na API
    // -------------------------------------------------------------
    server.tool(
      'listar_modelos_api',
      'Lista os modelos de IA disponíveis no painel para você escolher qual usar no subagente',
      {},
      async () => {
        try {
          const [models] = await pool.query(
            "SELECT model_key, name, multiplier, is_smart_routing FROM models WHERE status = 'active' ORDER BY name ASC"
          );

          const listStr = models.map(m => `- ${m.name} (ID: ${m.model_key}) [Multiplicador: ${m.multiplier}x]`).join('\n');

          return {
            content: [
              {
                type: 'text',
                text: `Modelos disponíveis na API:\n\n${listStr || 'Nenhum modelo cadastrado ativo no momento.'}`
              }
            ]
          };
        } catch (err) {
          return {
            isError: true,
            content: [{ type: 'text', text: `Erro ao listar modelos: ${err.message}` }]
          };
        }
      }
    );

    // -------------------------------------------------------------
    // TOOL 3: Consultar Saldo da Conta
    // -------------------------------------------------------------
    server.tool(
      'consultar_saldo_api',
      'Consulta o saldo de tokens e cotas disponíveis na sua conta do painel',
      {},
      async () => {
        try {
          const [rows] = await pool.query('SELECT balance_tokens, tokens_used FROM users WHERE id = ?', [userKey.user_id]);
          if (rows.length === 0) {
            return { content: [{ type: 'text', text: 'Usuário não encontrado.' }] };
          }

          const u = rows[0];
          return {
            content: [
              {
                type: 'text',
                text: `Saldo de Tokens: ${Number(u.balance_tokens).toLocaleString('pt-BR')}\nTokens Utilizados: ${Number(u.tokens_used).toLocaleString('pt-BR')}`
              }
            ]
          };
        } catch (err) {
          return {
            isError: true,
            content: [{ type: 'text', text: `Erro ao consultar saldo: ${err.message}` }]
          };
        }
      }
    );

    // -------------------------------------------------------------
    // TOOL 4: Ler Código do Arquivo (Com numeração de linhas)
    // -------------------------------------------------------------
    server.tool(
      'ler_codigo_arquivo',
      'Lê o código-fonte de um arquivo do projeto no disco com numeração de linhas. Use para inspecionar classes, models, migrations ou controllers.' + DESC_TOOL_DISCO,
      {
        caminho: z.string().describe('Caminho relativo do arquivo no projeto (ex: "app/Models/Fidelidade.php", "admin/accounts.php", "database.sql")'),
        linha_inicio: z.number().optional().describe('Linha inicial (padrão: 1)'),
        linha_fim: z.number().optional().describe('Linha final (opcional, padrão: exibe até 500 linhas)')
      },
      async ({ caminho, linha_inicio, linha_fim }) => {
        await refreshMcpMode().catch(() => {});
        if (!localModeEnabled) {
          return { isError: true, content: [{ type: 'text', text: DISK_OFF_MSG }] };
        }
        try {
          const content = readCodeFile(caminho, linha_inicio, linha_fim);
          return {
            content: [{ type: 'text', text: content }]
          };
        } catch (err) {
          return {
            isError: true,
            content: [{ type: 'text', text: `Erro ao ler arquivo "${caminho}": ${err.message}` }]
          };
        }
      }
    );

    // -------------------------------------------------------------
    // TOOL 5: Listar Estrutura de Arquivos do Projeto
    // -------------------------------------------------------------
    server.tool(
      'listar_arquivos_projeto',
      'Lista pastas e arquivos do projeto para descobrir onde estão localizados os módulos, migrations e classes.' + DESC_TOOL_DISCO,
      {
        pasta: z.string().optional().describe('Subpasta para listar (ex: "app/Models", "database", "admin"). Vazio lista a raiz.'),
        extensao: z.string().optional().describe('Filtrar por extensão (ex: ".php", ".js", ".sql")'),
        recursivo: z.boolean().optional().describe('Se deve listar recursivamente (padrão: true para subpastas, false para raiz)')
      },
      async ({ pasta, extensao, recursivo }) => {
        await refreshMcpMode().catch(() => {});
        if (!localModeEnabled) {
          return { isError: true, content: [{ type: 'text', text: DISK_OFF_MSG }] };
        }
        try {
          const files = listProjectFiles(pasta, extensao, recursivo);
          return {
            content: [{
              type: 'text',
              text: `Arquivos encontrados (${files.length} itens):\n\n${files.join('\n') || 'Nenhum arquivo encontrado com esses filtros.'}`
            }]
          };
        } catch (err) {
          return {
            isError: true,
            content: [{ type: 'text', text: `Erro ao listar arquivos: ${err.message}` }]
          };
        }
      }
    );

    // -------------------------------------------------------------
    // TOOL 6: Buscar no Código (Grep)
    // -------------------------------------------------------------
    server.tool(
      'buscar_no_codigo',
      'Pesquisa termos, nomes de tabelas, classes ou métodos em todo o código-fonte do projeto.' + DESC_TOOL_DISCO,
      {
        termo: z.string().describe('Termo, tabela ou nome de classe a buscar (ex: "Fidelidade", "ordens_servico", "calculatePoints")'),
        pasta: z.string().optional().describe('Subpasta onde buscar (ex: "app", "admin"). Vazio busca no projeto todo.'),
        extensao: z.string().optional().describe('Extensão dos arquivos (padrão: ".php")')
      },
      async ({ termo, pasta, extensao }) => {
        await refreshMcpMode().catch(() => {});
        if (!localModeEnabled) {
          return { isError: true, content: [{ type: 'text', text: DISK_OFF_MSG }] };
        }
        try {
          const results = searchInCode(termo, pasta, extensao);
          return {
            content: [{
              type: 'text',
              text: results.length > 0
                ? `Ocorrências de "${termo}" (${results.length} resultados encontrados):\n\n${results.join('\n')}`
                : `Nenhuma ocorrência encontrada para o termo "${termo}".`
            }]
          };
        } catch (err) {
          return {
            isError: true,
            content: [{ type: 'text', text: `Erro ao buscar no código: ${err.message}` }]
          };
        }
      }
    );

    // Heartbeat periódico (a cada 8 segundos) via comentário SSE ": keepalive\n\n"
    // Isso mantém proxies intermediários, Cloudflare (100s) e clientes Claude conectados durante raciocínios longos
    const keepAliveTimer = setInterval(() => {
      if (res.writableEnded || res.destroyed) {
        clearInterval(keepAliveTimer);
        return;
      }
      try {
        res.write(': keepalive\n\n');
      } catch (e) {
        clearInterval(keepAliveTimer);
      }
    }, 8000);

    // Armazenar sessão
    activeSessions.set(sessionId, { transport, server, userKey, keepAliveTimer });

    transport.onclose = () => {
      clearInterval(keepAliveTimer);
      console.log(`[MCP] Sessão SSE encerrada: ${sessionId} (Usuário ID: ${userKey.user_id})`);
      activeSessions.delete(sessionId);
    };

    res.on('close', () => {
      clearInterval(keepAliveTimer);
      activeSessions.delete(sessionId);
    });

    console.log(`[MCP] Nova conexão SSE estabelecida: ${sessionId} (Usuário ID: ${userKey.user_id})`);

    // Conectar o servidor ao transporte (connect() já chama transport.start() automaticamente)
    await server.connect(transport);
  }

  // Rota SSE explícita
  app.get(['/mcp/sse', '/v1/mcp/sse'], handleMcpSse);

  // Rota /mcp padrão direta: conecta SEMPRE ao SSE para o conector do Claude Desktop
  app.get(['/mcp', '/v1/mcp'], (req, res) => {
    const acceptsHtml = (req.headers['accept'] || '').includes('text/html');
    if (acceptsHtml && !req.query['apiKey'] && !req.headers['authorization']) {
      const host = req.headers['x-forwarded-host'] || req.get('host') || 'localhost:3000';
      const protocol = req.headers['x-forwarded-proto'] || req.protocol || 'https';
      const baseUrl = `${protocol}://${host}`;

      return res.json({
        status: 'online',
        service: 'Antigravity Gateway MCP Server',
        version: '1.0.0',
        transport: 'SSE (Server-Sent Events)',
        endpoints: {
          mcp: `${baseUrl}/mcp`,
          sse: `${baseUrl}/mcp/sse`,
          messages: `${baseUrl}/mcp/messages`
        }
      });
    }

    return handleMcpSse(req, res);
  });

  // -------------------------------------------------------------
  // ROTA POST: MENSAGENS E PROTOCOLO MCP COMPLETO (HTTP + SSE)
  // -------------------------------------------------------------
  app.post(['/mcp', '/v1/mcp', '/mcp/messages', '/v1/mcp/messages', '/mcp/sse', '/v1/mcp/sse'], async (req, res) => {
    // Timeout estendido de 10 minutos (600.000 ms) para permitir requisições de longa duração e thinking models
    req.setTimeout(600000);
    res.setTimeout(600000);
    if (req.socket) {
      req.socket.setTimeout(600000);
      req.socket.setKeepAlive(true, 5000);
      req.socket.setNoDelay(true);
    }

    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Headers', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.setHeader('X-Accel-Buffering', 'no');

    const sessionId = req.query.sessionId;
    if (sessionId) {
      const session = activeSessions.get(sessionId);
      if (!session) {
        // Sessão expirou (ex: o gateway reiniciou). Responde em JSON-RPC pedindo reconexão,
        // em vez de um 404 seco que faz o cliente MCP travar/derrubar a conexão.
        const bodyId = req.body?.id ?? null;
        return res.status(404).json({
          jsonrpc: '2.0',
          id: bodyId,
          error: {
            code: -32001,
            message: 'Sessão MCP expirada ou servidor reiniciado. Reconecte ao endpoint /mcp/sse para abrir uma nova sessão.'
          }
        });
      }

      try {
        await session.transport.handlePostMessage(req, res, req.body);
      } catch (err) {
        console.error('[MCP PostMessage Error]:', err.message);
        if (!res.headersSent) {
          res.status(500).send(`MCP message handling failed: ${err.message}`);
        }
      }
      return;
    }

    // Se NÃO tem sessionId: é um cliente MCP usando transporte HTTP Direto (Streamable HTTP / Codex / Claude Desktop)
    const body = req.body || {};
    const method = body.method;
    const msgId = body.id;

    // Pela especificação oficial do MCP (Streamable HTTP Transports):
    // Notificações JSON-RPC (como 'notifications/initialized' ou qualquer requisição sem 'id')
    // DEVEM responder estritamente com HTTP 202 Accepted e sem corpo (empty body).
    // Responder com HTTP 200 ou corpo JSON-RPC faz o Codex / clientes estritos fecharem o canal.
    if (msgId === undefined || msgId === null || (typeof method === 'string' && method.startsWith('notifications/'))) {
      return res.status(202).send();
    }

    // 1. Handshake Initialize
    if (method === 'initialize') {
      return res.status(200).json({
        jsonrpc: '2.0',
        id: msgId,
        result: {
          protocolVersion: body.params?.protocolVersion || '2024-11-05',
          capabilities: {
            tools: { listChanged: true }
          },
          serverInfo: {
            name: 'painel-subagentes',
            version: '1.0.0'
          }
        }
      });
    }

    if (method === 'ping') {
      return res.status(200).json({ jsonrpc: '2.0', id: msgId, result: {} });
    }

    // 2. Tools List
    if (method === 'tools/list') {
      try {
        const [models] = await pool.query(
          "SELECT model_key, name, multiplier FROM models WHERE status = 'active' ORDER BY name ASC"
        );
        const listStr = models.map(m => `- ${m.name} (ID: ${m.model_key})`).join(', ');

        return res.status(200).json({
          jsonrpc: '2.0',
          id: msgId,
          result: {
            tools: [
              {
                name: 'executar_subagente',
                description: 'Delega uma tarefa/raciocínio a um subagente da API. Para analisar código envie o conteúdo via "conteudos" (lido localmente pela IA cliente — funciona em qualquer projeto); "arquivos" só lê o disco do servidor.',
                inputSchema: {
                  type: 'object',
                  properties: {
                    prompt: { type: 'string', description: 'Instrução clara para o subagente executar' },
                    modelo: { type: 'string', description: `Modelo da API. Ativos: ${listStr}` },
                    system_prompt: { type: 'string', description: 'Instruções de sistema opcionais' },
                    arquivos: {
                      type: 'array',
                      items: { type: 'string' },
                      description: DESC_ARQUIVOS
                    },
                    conteudos: {
                      type: 'array',
                      items: {
                        type: 'object',
                        properties: {
                          caminho: { type: 'string' },
                          conteudo: { type: 'string' }
                        },
                        required: ['conteudo']
                      },
                      description: 'RECOMENDADO para projetos remotos: conteúdo dos arquivos locais já lido pela IA cliente. Funciona em qualquer projeto sem a VPS acessar o disco do cliente.'
                    },
                    max_tokens: { type: 'number', description: 'Limite de tokens de saída (padrão 16384, máx 65536)' },
                    temperature: { type: 'number', description: 'Criatividade de 0 a 2 (padrão 0.7)' }
                  },
                  required: ['prompt']
                }
              },
              {
                name: 'listar_modelos_api',
                description: 'Lista os modelos de IA disponíveis no painel',
                inputSchema: { type: 'object', properties: {} }
              },
              {
                name: 'consultar_saldo_api',
                description: 'Consulta o saldo de tokens e cotas disponíveis na sua conta do painel',
                inputSchema: { type: 'object', properties: {} }
              },
              {
                name: 'ler_codigo_arquivo',
                description: 'Lê o código-fonte de um arquivo do projeto no disco com numeração de linhas' + DESC_TOOL_DISCO,
                inputSchema: {
                  type: 'object',
                  properties: {
                    caminho: { type: 'string', description: 'Caminho do arquivo (ex: "app/Models/Fidelidade.php")' },
                    linha_inicio: { type: 'number', description: 'Linha inicial (padrão: 1)' },
                    linha_fim: { type: 'number', description: 'Linha final (opcional)' }
                  },
                  required: ['caminho']
                }
              },
              {
                name: 'listar_arquivos_projeto',
                description: 'Lista arquivos e pastas do projeto para descobrir onde estão as classes e migrações' + DESC_TOOL_DISCO,
                inputSchema: {
                  type: 'object',
                  properties: {
                    pasta: { type: 'string', description: 'Subpasta para listar (ex: "app/Models")' },
                    extensao: { type: 'string', description: 'Filtrar por extensão (ex: ".php")' },
                    recursivo: { type: 'boolean', description: 'Listar recursivamente' }
                  }
                }
              },
              {
                name: 'buscar_no_codigo',
                description: 'Busca textual rápida (grep) por termos, tabelas, métodos ou classes no projeto' + DESC_TOOL_DISCO,
                inputSchema: {
                  type: 'object',
                  properties: {
                    termo: { type: 'string', description: 'Texto ou nome da classe/tabela a buscar' },
                    pasta: { type: 'string', description: 'Subpasta onde buscar' },
                    extensao: { type: 'string', description: 'Extensão dos arquivos (padrão: ".php")' }
                  },
                  required: ['termo']
                }
              }
            ]
          }
        });
      } catch (err) {
        return res.status(500).json({ jsonrpc: '2.0', id: msgId, error: { code: -32603, message: err.message } });
      }
    }

    // 3. Tools Call
    if (method === 'tools/call') {
      await refreshMcpMode().catch(() => {});
      let userKey;
      try {
        userKey = await extractAndAuth(req);
      } catch (err) {
        return res.status(401).json({ jsonrpc: '2.0', id: msgId, error: { code: -32000, message: err.message } });
      }

      const toolName = body.params?.name;
      const args = body.params?.arguments || {};

      if (toolName === 'consultar_saldo_api') {
        const [rows] = await pool.query('SELECT balance_tokens, tokens_used FROM users WHERE id = ?', [userKey.user_id]);
        const u = rows[0] || {};
        return res.status(200).json({
          jsonrpc: '2.0',
          id: msgId,
          result: {
            content: [{
              type: 'text',
              text: `Saldo de Tokens: ${Number(u.balance_tokens || 0).toLocaleString('pt-BR')} | Tokens Utilizados: ${Number(u.tokens_used || 0).toLocaleString('pt-BR')}`
            }]
          }
        });
      }

      if (toolName === 'listar_modelos_api') {
        const [models] = await pool.query("SELECT model_key, name, multiplier FROM models WHERE status = 'active' ORDER BY name ASC");
        const listStr = models.map(m => `- ${m.name} (ID: ${m.model_key}) [Multiplicador: ${m.multiplier}x]`).join('\n');
        return res.status(200).json({
          jsonrpc: '2.0',
          id: msgId,
          result: {
            content: [{ type: 'text', text: `Modelos disponíveis na API:\n\n${listStr || 'Nenhum modelo cadastrado ativo no momento.'}` }]
          }
        });
      }

      if (toolName === 'ler_codigo_arquivo') {
        if (!localModeEnabled) {
          return res.status(200).json({
            jsonrpc: '2.0', id: msgId,
            result: { isError: true, content: [{ type: 'text', text: DISK_OFF_MSG }] }
          });
        }
        try {
          const content = readCodeFile(args.caminho, args.linha_inicio, args.linha_fim);
          return res.status(200).json({
            jsonrpc: '2.0',
            id: msgId,
            result: {
              content: [{ type: 'text', text: content }]
            }
          });
        } catch (err) {
          return res.status(200).json({
            jsonrpc: '2.0',
            id: msgId,
            result: {
              isError: true,
              content: [{ type: 'text', text: `Erro ao ler arquivo: ${err.message}` }]
            }
          });
        }
      }

      if (toolName === 'listar_arquivos_projeto') {
        if (!localModeEnabled) {
          return res.status(200).json({
            jsonrpc: '2.0', id: msgId,
            result: { isError: true, content: [{ type: 'text', text: DISK_OFF_MSG }] }
          });
        }
        try {
          const files = listProjectFiles(args.pasta, args.extensao, args.recursivo);
          return res.status(200).json({
            jsonrpc: '2.0',
            id: msgId,
            result: {
              content: [{
                type: 'text',
                text: `Arquivos encontrados (${files.length} itens):\n\n${files.join('\n') || 'Nenhum arquivo encontrado.'}`
              }]
            }
          });
        } catch (err) {
          return res.status(200).json({
            jsonrpc: '2.0',
            id: msgId,
            result: {
              isError: true,
              content: [{ type: 'text', text: `Erro ao listar arquivos: ${err.message}` }]
            }
          });
        }
      }

      if (toolName === 'buscar_no_codigo') {
        if (!localModeEnabled) {
          return res.status(200).json({
            jsonrpc: '2.0', id: msgId,
            result: { isError: true, content: [{ type: 'text', text: DISK_OFF_MSG }] }
          });
        }
        try {
          const results = searchInCode(args.termo, args.pasta, args.extensao);
          return res.status(200).json({
            jsonrpc: '2.0',
            id: msgId,
            result: {
              content: [{
                type: 'text',
                text: results.length > 0
                  ? `Ocorrências de "${args.termo}" (${results.length} resultados):\n\n${results.join('\n')}`
                  : `Nenhuma ocorrência encontrada para o termo "${args.termo}".`
              }]
            }
          });
        } catch (err) {
          return res.status(200).json({
            jsonrpc: '2.0',
            id: msgId,
            result: {
              isError: true,
              content: [{ type: 'text', text: `Erro ao buscar no código: ${err.message}` }]
            }
          });
        }
      }

      if (toolName === 'executar_subagente') {
        const progressToken = body?.params?._meta?.progressToken ?? null;
        const acceptsSse = String(req.headers['accept'] || '').includes('text/event-stream');

        // Caminho SSE (Streamable HTTP): mantém a conexão alimentada com heartbeat durante
        // gerações longas (thinking profundo / max_tokens grande). Sem isto, o cliente MCP
        // (Claude Desktop/Code) aborta por volta de 60s de silêncio — a resposta única em JSON
        // só chega no fim. Espelha o heartbeat do caminho SSE legado (server.tool acima).
        if (acceptsSse) {
          res.status(200);
          res.setHeader('Content-Type', 'text/event-stream; charset=utf-8');
          res.setHeader('Cache-Control', 'no-cache, no-transform');
          res.setHeader('X-Accel-Buffering', 'no');
          if (typeof res.flushHeaders === 'function') res.flushHeaders();

          const sendEvent = (obj) => {
            if (res.writableEnded || res.destroyed) return;
            try { res.write(`event: message\ndata: ${JSON.stringify(obj)}\n\n`); } catch (e) {}
          };
          const sendKeepalive = (progressVal) => {
            if (res.writableEnded || res.destroyed) return;
            try { res.write(': keepalive\n\n'); } catch (e) {}
            if (progressToken !== null && progressToken !== undefined) {
              sendEvent({
                jsonrpc: '2.0',
                method: 'notifications/progress',
                params: { progressToken, progress: progressVal, total: 100 }
              });
            }
          };

          // Aborta a geração se o cliente desconectar (não desperdiça cota/tokens).
          const ac = new AbortController();
          req.on('close', () => { try { ac.abort(); } catch (e) {} });

          let charCount = 0;
          let heartbeatCount = 0;
          let lastReported = 0;
          sendKeepalive(1);
          const heartbeatTimer = setInterval(() => {
            heartbeatCount++;
            const virtualProgress = charCount > 0
              ? Math.max(charCount, Math.min(95, 5 + heartbeatCount * 2))
              : Math.min(95, 2 + Math.floor(heartbeatCount * 1.5));
            sendKeepalive(virtualProgress);
          }, 2500);

          const progressCallback = (info) => {
            const now = Date.now();
            if (typeof info === 'number') charCount = info;
            else if (info && typeof info === 'object' && info.length) charCount = info.length;
            if (now - lastReported > 2000) {
              lastReported = now;
              sendKeepalive(charCount || (5 + heartbeatCount * 2));
            }
          };

          try {
            const replyText = await runSubagent({
              prompt: args.prompt || '',
              modelo: args.modelo,
              system_prompt: args.system_prompt,
              arquivos: args.arquivos,
              conteudos: args.conteudos,
              max_tokens: args.max_tokens,
              temperature: args.temperature,
              userKey,
              progressCallback,
              abortSignal: ac.signal
            });
            sendEvent({
              jsonrpc: '2.0',
              id: msgId,
              result: { content: [{ type: 'text', text: replyText }] }
            });
          } catch (subErr) {
            console.error('[MCP Direct HTTP Error]:', subErr.message);
            sendEvent({
              jsonrpc: '2.0',
              id: msgId,
              result: {
                isError: true,
                content: [{ type: 'text', text: `Erro ao executar subagente: ${subErr.message}` }]
              }
            });
          } finally {
            clearInterval(heartbeatTimer);
            if (!res.writableEnded) { try { res.end(); } catch (e) {} }
          }
          return;
        }

        // Fallback: cliente não aceita SSE → resposta JSON única (sem heartbeat; só serve p/ geração curta).
        try {
          const replyText = await runSubagent({
            prompt: args.prompt || '',
            modelo: args.modelo,
            system_prompt: args.system_prompt,
            arquivos: args.arquivos,
            conteudos: args.conteudos,
            max_tokens: args.max_tokens,
            temperature: args.temperature,
            userKey
          });

          return res.status(200).json({
            jsonrpc: '2.0',
            id: msgId,
            result: {
              content: [{ type: 'text', text: replyText }]
            }
          });
        } catch (subErr) {
          console.error('[MCP Direct HTTP Error]:', subErr.message);
          return res.status(200).json({
            jsonrpc: '2.0',
            id: msgId,
            result: {
              isError: true,
              content: [{ type: 'text', text: `Erro ao executar subagente: ${subErr.message}` }]
            }
          });
        }
      }

      return res.status(404).json({ jsonrpc: '2.0', id: msgId, error: { code: -32601, message: `Tool not found: ${toolName}` } });
    }

    // Default fallback
    return res.status(200).json({ jsonrpc: '2.0', id: msgId, result: { status: 'ready', service: 'mcp' } });
  });

  // -------------------------------------------------------------
  // SUPORTE OAUTH AUTOMÁTICO PARA O CONECTOR DO CLAUDE DESKTOP
  // -------------------------------------------------------------
  app.get('/.well-known/oauth-authorization-server', (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    const host = req.headers['x-forwarded-host'] || req.get('host') || 'api.brlove.online';
    const protocol = req.headers['x-forwarded-proto'] || req.protocol || 'https';
    const baseUrl = `${protocol}://${host}`;

    res.json({
      issuer: baseUrl,
      authorization_endpoint: `${baseUrl}/oauth/authorize`,
      token_endpoint: `${baseUrl}/oauth/token`,
      registration_endpoint: `${baseUrl}/oauth/register`,
      response_types_supported: ['code'],
      response_modes_supported: ['query'],
      grant_types_supported: ['authorization_code'],
      code_challenge_methods_supported: ['S256', 'plain'],
      token_endpoint_auth_methods_supported: ['none', 'client_secret_post', 'client_secret_basic']
    });
  });

  app.get('/.well-known/oauth-protected-resource', (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    const host = req.headers['x-forwarded-host'] || req.get('host') || 'api.brlove.online';
    const protocol = req.headers['x-forwarded-proto'] || req.protocol || 'https';
    const baseUrl = `${protocol}://${host}`;

    res.json({
      resource: `${baseUrl}/mcp`,
      authorization_servers: [baseUrl],
      scopes_supported: ['mcp']
    });
  });

  // Registro dinâmico de cliente OAuth (RFC 7591)
  app.post('/oauth/register', (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Headers', '*');
    res.status(201).json({
      client_id: 'claude-desktop-client',
      client_secret: 'claude-desktop-secret',
      client_id_issued_at: Math.floor(Date.now() / 1000),
      client_name: 'Claude Desktop',
      redirect_uris: req.body?.redirect_uris || ['https://claude.ai/api/mcp/auth_callback'],
      grant_types: ['authorization_code'],
      response_types: ['code'],
      token_endpoint_auth_method: 'none'
    });
  });

  // Autorização automática sem tela de login (auto-aprovação instantânea)
  app.get('/oauth/authorize', (req, res) => {
    const redirectUri = req.query.redirect_uri;
    const state = req.query.state || '';
    const code = 'brlove_oauth_code_' + Date.now();

    if (redirectUri) {
      try {
        const targetUrl = new URL(redirectUri);
        targetUrl.searchParams.set('code', code);
        if (state) targetUrl.searchParams.set('state', state);
        return res.redirect(targetUrl.toString());
      } catch (e) {}
    }

    res.json({ status: 'authorized', code });
  });

  app.post('/oauth/token', async (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Headers', '*');

    const token = req.query?.apiKey || req.body?.apiKey || req.body?.client_secret || req.body?.code || '';
    res.json({
      access_token: token,
      token_type: 'Bearer',
      expires_in: 31536000,
      scope: 'mcp'
    });
  });

  // Suporte a OPTIONS para pre-flight CORS em clientes web/remotos
  app.options([
    '/mcp/sse', '/v1/mcp/sse', '/mcp/messages', '/v1/mcp/messages', '/mcp',
    '/.well-known/oauth-authorization-server', '/.well-known/oauth-protected-resource',
    '/oauth/register', '/oauth/authorize', '/oauth/token'
  ], (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Headers', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.sendStatus(204);
  });

  console.log(`🤖 MCP Server (SSE + OAuth Auto-Handshake) inicializado e disponível em /mcp`);
  console.log(`📂 MCP Modo: ${localModeEnabled ? 'LOCAL — lendo disco em ' + projectRoot : 'REMOTO/VPS — clientes usam "conteudos" (ligue no SuperAdmin: toggle + caminho, ou MCP_LOCAL=true)'} [revalidado a cada 30s]`);
}
