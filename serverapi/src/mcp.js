import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { SSEServerTransport } from '@modelcontextprotocol/sdk/server/sse.js';
import { z } from 'zod';
import crypto from 'crypto';

/**
 * Módulo MCP (Model Context Protocol) Nativo com Transporte SSE
 * Permite que o Claude Code (em sua conta Pro ou qualquer cliente MCP)
 * utilize a API do Painel como um orquestrador de subagentes.
 */
export function initMcp(app, gateway) {
  const {
    authenticateApiKey,
    executeWithFallback,
    executeExternalProvider,
    resolveModelConfig,
    logUsage,
    pool
  } = gateway;

  // Mapa de sessões ativas: sessionId -> { transport, server, userKey }
  const activeSessions = new Map();

  // Helper para extrair API Key de headers ou query param
  async function extractAndAuth(req) {
    const authHeader = req.headers['authorization'] || '';
    const xApiKey = req.headers['x-api-key'] || '';
    const queryKey = req.query['apiKey'] || req.query['api_key'] || req.query['key'] || req.query['token'] || '';

    let clientApiKey = '';
    if (queryKey) {
      clientApiKey = String(queryKey).trim();
    } else if (xApiKey) {
      clientApiKey = String(xApiKey).trim();
    } else if (authHeader.startsWith('Bearer ')) {
      clientApiKey = authHeader.replace(/^Bearer\s+/i, '').trim();
    }

    if (!clientApiKey) {
      throw new Error('API Key não fornecida. Passe via Authorization: Bearer <key> ou ?apiKey=<key>');
    }

    return await authenticateApiKey(clientApiKey);
  }

  // -------------------------------------------------------------
  // ROTA INFORMATIVA / STATUS
  // -------------------------------------------------------------
  app.get(['/mcp', '/v1/mcp'], (req, res) => {
    const host = req.headers['x-forwarded-host'] || req.get('host') || 'localhost:3000';
    const protocol = req.headers['x-forwarded-proto'] || req.protocol || 'http';
    const baseUrl = `${protocol}://${host}`;

    res.json({
      status: 'online',
      service: 'Antigravity Gateway MCP Server',
      version: '1.0.0',
      transport: 'SSE (Server-Sent Events)',
      endpoints: {
        sse: `${baseUrl}/mcp/sse`,
        messages: `${baseUrl}/mcp/messages`
      },
      instructions: {
        claude_code_setup: `claude mcp add --transport sse painel-subagente ${baseUrl}/mcp/sse --header "Authorization: Bearer SUA_API_KEY"`
      }
    });
  });

  // -------------------------------------------------------------
  // ROTA SSE: CONEXÃO DO CLIENTE MCP (ex: Claude Code)
  // -------------------------------------------------------------
  app.get(['/mcp/sse', '/v1/mcp/sse'], async (req, res) => {
    // Garantir CORS total
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Headers', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');

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
      'Executa uma tarefa ou raciocínio complexo delegando para um subagente na API do Painel (ex: Claude Opus Thinking, Claude Sonnet, Gemini 3.8, etc.)',
      {
        prompt: z.string().describe('Instrução clara e detalhada para o subagente executar'),
        modelo: z.string().optional().describe('Modelo da API para executar o subagente. Exemplos: claude-opus-4-6-thinking, claude-sonnet-4-6, gemini-3.8-flash-high, gemini-3.8-flash-medium. Padrão: claude-opus-4-6-thinking'),
        system_prompt: z.string().optional().describe('Instrução de persona ou diretrizes de sistema para guiar o subagente')
      },
      async ({ prompt, modelo, system_prompt }) => {
        try {
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
          const incomingContents = [
            {
              role: 'user',
              parts: [{ text: prompt }]
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
              { role: 'user', content: prompt }
            ];
            const extResult = await executeExternalProvider(providerId, targetEngine, incomingMsgs, finalSystemInstruction, []);
            replyText = extResult.replyText;
          } else {
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

          if (!replyText) {
            replyText = '[Aviso do Subagente: Não foi gerada resposta textual pelo modelo selecionado.]';
          }

          // Debitar cota
          const inputTokens = Math.ceil((prompt.length + (finalSystemInstruction ? finalSystemInstruction.length : 0)) / 4);
          const outputTokens = Math.ceil(replyText.length / 4);
          logUsage(userKey, rawModel, inputTokens, outputTokens, account?.id || null, modelMultiplier);

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

    // Armazenar sessão
    activeSessions.set(sessionId, { transport, server, userKey });

    transport.onclose = () => {
      console.log(`[MCP] Sessão SSE encerrada: ${sessionId} (Usuário ID: ${userKey.user_id})`);
      activeSessions.delete(sessionId);
    };

    console.log(`[MCP] Nova conexão SSE estabelecida: ${sessionId} (Usuário ID: ${userKey.user_id})`);

    // Conectar o servidor ao transporte e iniciar o streaming
    await server.connect(transport);
    await transport.start();
  });

  // -------------------------------------------------------------
  // ROTA POST: MENSAGENS DO CLIENTE MCP (JSON-RPC)
  // -------------------------------------------------------------
  app.post(['/mcp/messages', '/v1/mcp/messages'], async (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Headers', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');

    const sessionId = req.query.sessionId;
    if (!sessionId) {
      return res.status(400).send('Query parameter sessionId is required');
    }

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
  });

  // Suporte a OPTIONS para pre-flight CORS em clientes web/remotos
  app.options(['/mcp/sse', '/v1/mcp/sse', '/mcp/messages', '/v1/mcp/messages', '/mcp'], (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Headers', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
    res.sendStatus(204);
  });

  console.log(`🤖 MCP Server (SSE) inicializado e disponível em /mcp/sse`);
}
