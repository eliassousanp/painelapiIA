import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { SSEServerTransport } from '@modelcontextprotocol/sdk/server/sse.js';
import { z } from 'zod';
import crypto from 'crypto';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Diretório raiz do projeto (suporta WORKSPACE_DIR no .env ou relativo a serverapi/src)
const defaultProjectRoot = process.env.WORKSPACE_DIR 
  ? path.resolve(process.env.WORKSPACE_DIR) 
  : path.resolve(__dirname, '../../');

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
function resolveSafePath(userPath, baseDir = defaultProjectRoot) {
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
  const targetDir = subPasta ? resolveSafePath(subPasta) : defaultProjectRoot;
  if (!fs.existsSync(targetDir) || !fs.statSync(targetDir).isDirectory()) {
    throw new Error(`Diretório não encontrado: ${subPasta || 'raiz'}`);
  }

  const isRoot = path.normalize(targetDir) === path.normalize(defaultProjectRoot);
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
        const relDir = path.relative(defaultProjectRoot, fullPath).replace(/\\/g, '/');
        results.push(`📁 ${relDir}/`);
        if (shouldRecurse) {
          scan(fullPath, depth + 1);
        }
      } else if (ent.isFile()) {
        if (BLOCKED_FILES.has(name.toLowerCase()) || name.endsWith('.pem') || name.endsWith('.key')) continue;
        if (extFilter && !name.toLowerCase().endsWith(extFilter)) continue;
        const relFile = path.relative(defaultProjectRoot, fullPath).replace(/\\/g, '/');
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

  const targetDir = subPasta ? resolveSafePath(subPasta) : defaultProjectRoot;
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
          const relFile = path.relative(defaultProjectRoot, fullPath).replace(/\\/g, '/');

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
  async function runSubagent({ prompt, modelo, system_prompt, arquivos, userKey, progressCallback, abortSignal }) {
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

    // Se foram informados arquivos do projeto para o subagente analisar:
    let fileContentsContext = '';
    if (Array.isArray(arquivos) && arquivos.length > 0) {
      for (const filePath of arquivos) {
        try {
          const safePath = resolveSafePath(filePath);
          if (fs.existsSync(safePath) && fs.statSync(safePath).isFile()) {
            const content = fs.readFileSync(safePath, 'utf8');
            const truncated = content.length > 150000
              ? content.slice(0, 150000) + '\n... [conteúdo truncado para manter performance]'
              : content;
            fileContentsContext += `\n\n=== ARQUIVO DO PROJETO: ${filePath} ===\n${truncated}\n=== FIM DO ARQUIVO ===`;
          } else {
            fileContentsContext += `\n\n=== AVISO: Arquivo "${filePath}" não encontrado no projeto. ===`;
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

    let replyText = '';
    let account = null;

    if (providerId && providerId !== 2) {
      const incomingMsgs = [
        ...(finalSystemInstruction ? [{ role: 'system', content: finalSystemInstruction }] : []),
        { role: 'user', content: finalPrompt }
      ];
      const extResult = await executeExternalProvider(providerId, targetEngine, incomingMsgs, finalSystemInstruction, []);
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
            { temperature: 0.7, maxOutputTokens: 16384, tools: [] },
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
        const { googleResp, account: acc } = await executeWithFallback(
          targetEngine,
          incomingContents,
          finalSystemInstruction,
          rawModel,
          { temperature: 0.7, maxOutputTokens: 16384, tools: [] }
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
      'Executa uma tarefa ou raciocínio complexo delegando para um subagente na API do Painel (ex: Claude Opus Thinking, Claude Sonnet, Gemini 3.8, etc.). Aceita uma lista de arquivos do projeto para ler e entender o código antes de responder!',
      {
        prompt: z.string().describe('Instrução clara e detalhada para o subagente executar'),
        modelo: z.string().optional().describe('Modelo da API para executar o subagente. Exemplos: claude-opus-5.5, gemini-3.7-flash-medium. Padrão: modelo padrão ativo'),
        system_prompt: z.string().optional().describe('Instrução de persona ou diretrizes de sistema para guiar o subagente'),
        arquivos: z.array(z.string()).optional().describe('Lista de arquivos do projeto que o subagente deve carregar e analisar do disco (ex: ["app/Models/Fidelidade.php", "database/migrations/2026_create_fidelidade.php"])')
      },
      async ({ prompt, modelo, system_prompt, arquivos }, extra) => {
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
      'Lê o código-fonte de um arquivo do projeto no disco com numeração de linhas. Use para inspecionar classes, models, migrations ou controllers.',
      {
        caminho: z.string().describe('Caminho relativo do arquivo no projeto (ex: "app/Models/Fidelidade.php", "admin/accounts.php", "database.sql")'),
        linha_inicio: z.number().optional().describe('Linha inicial (padrão: 1)'),
        linha_fim: z.number().optional().describe('Linha final (opcional, padrão: exibe até 500 linhas)')
      },
      async ({ caminho, linha_inicio, linha_fim }) => {
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
      'Lista pastas e arquivos do projeto para descobrir onde estão localizados os módulos, migrations e classes.',
      {
        pasta: z.string().optional().describe('Subpasta para listar (ex: "app/Models", "database", "admin"). Vazio lista a raiz.'),
        extensao: z.string().optional().describe('Filtrar por extensão (ex: ".php", ".js", ".sql")'),
        recursivo: z.boolean().optional().describe('Se deve listar recursivamente (padrão: true para subpastas, false para raiz)')
      },
      async ({ pasta, extensao, recursivo }) => {
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
      'Pesquisa termos, nomes de tabelas, classes ou métodos em todo o código-fonte do projeto.',
      {
        termo: z.string().describe('Termo, tabela ou nome de classe a buscar (ex: "Fidelidade", "ordens_servico", "calculatePoints")'),
        pasta: z.string().optional().describe('Subpasta onde buscar (ex: "app", "admin"). Vazio busca no projeto todo.'),
        extensao: z.string().optional().describe('Extensão dos arquivos (padrão: ".php")')
      },
      async ({ termo, pasta, extensao }) => {
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
        return res.status(404).send('Session not found or expired');
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
                description: 'Executa uma tarefa ou raciocínio complexo delegando para um subagente na API do Painel (aceita lista de arquivos do projeto para analisar)',
                inputSchema: {
                  type: 'object',
                  properties: {
                    prompt: { type: 'string', description: 'Instrução clara para o subagente executar' },
                    modelo: { type: 'string', description: `Modelo da API. Ativos: ${listStr}` },
                    system_prompt: { type: 'string', description: 'Instruções de sistema opcionais' },
                    arquivos: {
                      type: 'array',
                      items: { type: 'string' },
                      description: 'Lista de arquivos do projeto para o subagente carregar do disco e entender (ex: ["app/Models/Fidelidade.php"])'
                    }
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
                description: 'Lê o código-fonte de um arquivo do projeto no disco com numeração de linhas',
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
                description: 'Lista arquivos e pastas do projeto para descobrir onde estão as classes e migrações',
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
                description: 'Busca textual rápida (grep) por termos, tabelas, métodos ou classes no projeto',
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
        try {
          const replyText = await runSubagent({
            prompt: args.prompt || '',
            modelo: args.modelo,
            system_prompt: args.system_prompt,
            arquivos: args.arquivos,
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
}
