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
      throw new Error('API Key não fornecida. Passe via Authorization: Bearer <key> ou na URL com ?apiKey=<sua_chave>');
    }

    return await authenticateApiKey(clientApiKey);
  }



  // Handler principal de SSE do MCP
  async function handleMcpSse(req, res) {
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
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Headers', '*');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');

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

    // Se NÃO tem sessionId: é um cliente MCP usando transporte HTTP Direto (Streamable HTTP / Claude Desktop)
    const body = req.body || {};
    const method = body.method;
    const msgId = body.id ?? 1;

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

    if (method === 'notifications/initialized') {
      return res.status(200).json({ jsonrpc: '2.0', result: {} });
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
                description: 'Executa uma tarefa ou raciocínio complexo delegando para um subagente na API do Painel',
                inputSchema: {
                  type: 'object',
                  properties: {
                    prompt: { type: 'string', description: 'Instrução clara para o subagente executar' },
                    modelo: { type: 'string', description: `Modelo da API. Ativos: ${listStr}` },
                    system_prompt: { type: 'string', description: 'Instruções de sistema opcionais' }
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

      if (toolName === 'executar_subagente') {
        let rawModel = args.modelo;
        if (!rawModel) {
          try {
            const [activeM] = await pool.query("SELECT model_key FROM models WHERE status = 'active' ORDER BY id ASC LIMIT 1");
            if (activeM && activeM.length > 0) rawModel = activeM[0].model_key;
          } catch (e) {}
        }
        if (!rawModel) rawModel = 'gemini-3.8-flash-medium';

        const prompt = args.prompt || '';
        const incomingContents = [{ role: 'user', parts: [{ text: prompt }] }];
        const { targetEngine, customPrompt, providerId, multiplier: modelMultiplier } = await resolveModelConfig(rawModel, incomingContents);

        let systemParts = [];
        if (customPrompt) systemParts.push(customPrompt);
        if (args.system_prompt) systemParts.push(args.system_prompt);
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

        const inputTokens = Math.ceil((prompt.length + (finalSystemInstruction ? finalSystemInstruction.length : 0)) / 4);
        const outputTokens = Math.ceil(replyText.length / 4);
        logUsage(userKey, rawModel, inputTokens, outputTokens, account?.id || null, modelMultiplier);

        return res.status(200).json({
          jsonrpc: '2.0',
          id: msgId,
          result: {
            content: [{ type: 'text', text: replyText }]
          }
        });
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
