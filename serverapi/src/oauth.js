import http from 'http';
import https from 'https';
import crypto from 'crypto';
import url from 'url';

export const OAUTH_CONFIG = {
  clientId: '1071006060591-tmhssin2h21lcre235vtolojh4g403ep.apps.googleusercontent.com',
  clientSecret: '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',
  redirectUri: 'http://localhost:51121/oauth-callback',
  authUri: 'https://accounts.google.com/o/oauth2/v2/auth',
  tokenUri: 'https://oauth2.googleapis.com/token',
  scopes: [
    'https://www.googleapis.com/auth/cloud-platform',
    'https://www.googleapis.com/auth/userinfo.email',
    'https://www.googleapis.com/auth/userinfo.profile',
    'https://www.googleapis.com/auth/cclog',
    'https://www.googleapis.com/auth/experimentsandconfigs'
  ]
};

function generateCodeVerifier() {
  return crypto.randomBytes(32).toString('base64url');
}

function generateCodeChallenge(verifier) {
  return crypto.createHash('sha256').update(verifier).digest('base64url');
}

function requestPost(endpointUrl, postData, headers = {}) {
  return new Promise((resolve, reject) => {
    const parsed = new URL(endpointUrl);
    const body = typeof postData === 'string' ? postData : JSON.stringify(postData);
    const req = https.request({
      hostname: parsed.hostname,
      port: 443,
      path: parsed.pathname + parsed.search,
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Content-Length': Buffer.byteLength(body),
        ...headers
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
            reject(new Error(`OAuth Error (${res.statusCode}): ${JSON.stringify(json)}`));
          }
        } catch (e) {
          reject(new Error(`OAuth Response parse error: ${data}`));
        }
      });
    });
    req.on('error', reject);
    req.write(body);
    req.end();
  });
}

function fetchUserInfo(accessToken) {
  return new Promise((resolve, reject) => {
    https.get('https://www.googleapis.com/oauth2/v2/userinfo', {
      headers: { 'Authorization': `Bearer ${accessToken}` }
    }, (res) => {
      let data = '';
      res.on('data', chunk => data += chunk);
      res.on('end', () => {
        try {
          resolve(JSON.parse(data));
        } catch (e) {
          reject(e);
        }
      });
    }).on('error', reject);
  });
}

export async function refreshAccessToken(refreshToken) {
  const params = new URLSearchParams({
    client_id: OAUTH_CONFIG.clientId,
    client_secret: OAUTH_CONFIG.clientSecret,
    refresh_token: refreshToken,
    grant_type: 'refresh_token'
  });

  const res = await requestPost(OAUTH_CONFIG.tokenUri, params.toString());
  return {
    accessToken: res.access_token,
    expiresIn: res.expires_in || 3600
  };
}

let activeAuthServer = null;
export const pendingVerifiers = new Map();

export function closeActiveAuthServer() {
  if (activeAuthServer) {
    try { activeAuthServer.close(); } catch (e) {}
    activeAuthServer = null;
  }
}

export async function exchangeOAuthCode(code, codeVerifier = null, state = null) {
  let verifier = codeVerifier;
  if (!verifier && state && pendingVerifiers.has(state)) {
    verifier = pendingVerifiers.get(state);
    pendingVerifiers.delete(state);
  }
  if (!verifier) {
    verifier = Array.from(pendingVerifiers.values()).pop();
  }
  if (!verifier) {
    throw new Error('Nenhum code_verifier pendente encontrado. Inicie o fluxo de login no painel primeiro.');
  }

  const tokenParams = new URLSearchParams({
    client_id: OAUTH_CONFIG.clientId,
    client_secret: OAUTH_CONFIG.clientSecret,
    code: code,
    code_verifier: verifier,
    grant_type: 'authorization_code',
    redirect_uri: OAUTH_CONFIG.redirectUri
  });

  const tokenData = await requestPost(OAUTH_CONFIG.tokenUri, tokenParams.toString());
  const userInfo = await fetchUserInfo(tokenData.access_token);

  return {
    email: userInfo.email,
    name: userInfo.name || userInfo.email.split('@')[0],
    picture: userInfo.picture || '',
    accessToken: tokenData.access_token,
    refreshToken: tokenData.refresh_token,
    expiresIn: tokenData.expires_in || 3600
  };
}

export function startOAuthFlow(onSuccess, onError, clientRedirectUrl = null) {
  if (activeAuthServer) {
    try { activeAuthServer.close(); } catch (e) {}
    activeAuthServer = null;
  }

  const codeVerifier = generateCodeVerifier();
  const codeChallenge = generateCodeChallenge(codeVerifier);
  const state = crypto.randomBytes(16).toString('hex');
  pendingVerifiers.set(state, codeVerifier);

  // Limpa verifiers antigos após 10 minutos
  setTimeout(() => pendingVerifiers.delete(state), 10 * 60 * 1000);

  const authUrl = `${OAUTH_CONFIG.authUri}?` + new URLSearchParams({
    client_id: OAUTH_CONFIG.clientId,
    redirect_uri: OAUTH_CONFIG.redirectUri,
    response_type: 'code',
    scope: OAUTH_CONFIG.scopes.join(' '),
    code_challenge: codeChallenge,
    code_challenge_method: 'S256',
    state: state,
    access_type: 'offline',
    prompt: 'consent'
  }).toString();

  const server = http.createServer(async (req, res) => {
    const reqUrl = url.parse(req.url, true);
    if (reqUrl.pathname === '/oauth-callback') {
      const code = reqUrl.query.code;
      const returnedState = reqUrl.query.state;
      const error = reqUrl.query.error;

      if (error) {
        res.writeHead(400, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(`<h2>Erro na autenticação: ${error}</h2><p>Você pode fechar esta janela.</p>`);
        server.close();
        activeAuthServer = null;
        if (onError) onError(new Error(error));
        return;
      }

      if (!code) {
        res.writeHead(400, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(`<h2>Código não recebido.</h2>`);
        return;
      }

      try {
        const tokenParams = new URLSearchParams({
          client_id: OAUTH_CONFIG.clientId,
          client_secret: OAUTH_CONFIG.clientSecret,
          code: code,
          code_verifier: codeVerifier,
          grant_type: 'authorization_code',
          redirect_uri: OAUTH_CONFIG.redirectUri
        });

        const tokenData = await requestPost(OAUTH_CONFIG.tokenUri, tokenParams.toString());
        const userInfo = await fetchUserInfo(tokenData.access_token);

        const targetRedirect = clientRedirectUrl || '/admin/accounts.php?msg=connected';

        res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(`
          <html>
          <body style="font-family: sans-serif; background: #0f172a; color: #f8fafc; display: flex; flex-direction: column; align-items: center; justify-content: center; height: 100vh; margin: 0;">
            <div style="background: #1e293b; padding: 40px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); text-align: center; border: 1px solid #334155;">
              <h2 style="color: #38bdf8; margin-top: 0;">Conta Conectada com Sucesso!</h2>
              <p style="color: #94a3b8;">A conta <b>${userInfo.email}</b> foi vinculada ao pool oficial Antigravity.</p>
              <p style="margin-top: 25px;"><a href="${targetRedirect}" style="background: #38bdf8; color: #0f172a; padding: 10px 20px; text-decoration: none; border-radius: 6px; font-weight: bold;">Voltar para o Painel</a></p>
            </div>
            <script>
              setTimeout(() => {
                window.location.href = '${targetRedirect}';
              }, 1200);
            </script>
          </body>
          </html>
        `);

        server.close();
        activeAuthServer = null;

        if (onSuccess) {
          onSuccess({
            email: userInfo.email,
            name: userInfo.name || userInfo.email.split('@')[0],
            picture: userInfo.picture || '',
            accessToken: tokenData.access_token,
            refreshToken: tokenData.refresh_token,
            expiresIn: tokenData.expires_in || 3600
          });
        }
      } catch (err) {
        console.error('[OAuth] Erro ao trocar token:', err);
        res.writeHead(500, { 'Content-Type': 'text/html; charset=utf-8' });
        res.end(`<h2>Falha ao autenticar token: ${err.message}</h2>`);
        server.close();
        activeAuthServer = null;
        if (onError) onError(err);
      }
    }
  });

  server.listen(51121, '127.0.0.1', () => {
    console.log('[OAuth] Listener aberto em http://localhost:51121/oauth-callback');
  });

  server.on('error', (err) => {
    console.error('[OAuth] Erro no servidor de callback:', err);
    if (onError) onError(err);
  });

  activeAuthServer = server;
  return authUrl;
}
