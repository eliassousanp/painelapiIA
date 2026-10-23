import { refreshAccessToken } from '../oauth.js';
import { generateContent, streamGenerateContent } from '../antigravity.js';

/**
 * Gerenciador Avançado de Pool de Contas Google Antigravity
 * - Controle inteligente de RPM (Requests Per Minute) por janela deslizante de 60s
 * - Semáforo de concorrência por conta (evita 429 por burst simultâneo)
 * - Fila suave assíncrona (micro-buffering com retry)
 * - Failover automático com resfriamento inteligente de 429
 * - Suporta escala ilimitada de contas conectadas
 */

class AntigravityAccountPool {
  constructor(pool) {
    this.pool = pool;
    // Map<accountId, AccountRuntimeStats>
    this.stats = new Map();
    // Limites de segurança por categoria de modelo
    this.limits = {
      heavy: {
        maxConcurrent: 2, // Max requisições simultâneas por conta
        maxRpm: 8         // Max requisições por minuto por conta
      },
      light: {
        maxConcurrent: 4,
        maxRpm: 18
      }
    };
  }

  isHeavyModel(modelKey = '') {
    const m = (modelKey || '').toLowerCase();
    return m.includes('claude') || m.includes('pro') || m.includes('opus') || m.includes('sonnet') || m.includes('thinking');
  }

  getModelLimits(modelKey = '') {
    return this.isHeavyModel(modelKey) ? this.limits.heavy : this.limits.light;
  }

  getAccountStats(accId) {
    if (!this.stats.has(accId)) {
      this.stats.set(accId, {
        activeRequests: 0,
        requestTimestamps: [],
        cooldownUntil: 0,
        modelCooldowns: new Map(),
        totalCalls: 0,
        failedCalls: 0,
        lastUsedAt: null,
        lastError: null
      });
    }
    const stat = this.stats.get(accId);
    // Limpar timestamps com mais de 60 segundos
    const now = Date.now();
    stat.requestTimestamps = stat.requestTimestamps.filter(t => (now - t) < 60000);
    return stat;
  }

  getAccountRpm(accId) {
    const stat = this.getAccountStats(accId);
    return stat.requestTimestamps.length;
  }

  async getAllActiveAccounts() {
    let [accounts] = await this.pool.query(`
      SELECT * FROM antigravity_accounts 
      WHERE is_active = 1 AND status = 'active'
      ORDER BY total_requests ASC
    `);

    if (accounts.length === 0) {
      // Auto-recuperação: se todas estiverem como quota_exhausted, reativa para tentar novamente
      const [allActive] = await this.pool.query(`
        SELECT * FROM antigravity_accounts 
        WHERE is_active = 1
        ORDER BY total_requests ASC
      `);

      if (allActive.length > 0) {
        await this.pool.query(`UPDATE antigravity_accounts SET status = 'active' WHERE is_active = 1`);
        accounts = allActive;
      } else {
        throw new Error('Nenhuma conta Google Antigravity conectada no pool.');
      }
    }

    return accounts;
  }

  async ensureTokenFresh(account) {
    const now = new Date();
    const tokenExpiresAt = account.token_expires_at ? new Date(account.token_expires_at) : new Date(0);

    if (tokenExpiresAt.getTime() - now.getTime() < 120000) {
      try {
        console.log(`[Token Refresh] Renovando token da conta ${account.email}...`);
        const refreshed = await refreshAccessToken(account.refresh_token);
        const newExpiresAt = new Date(Date.now() + 3600 * 1000);

        await this.pool.query(`
          UPDATE antigravity_accounts 
          SET access_token = ?, token_expires_at = ?
          WHERE id = ?
        `, [refreshed.accessToken, newExpiresAt, account.id]);

        account.access_token = refreshed.accessToken;
        account.token_expires_at = newExpiresAt;
      } catch (err) {
        console.error(`[Token Refresh] Falha ao renovar token para ${account.email}:`, err.message);
        return false;
      }
    }
    return true;
  }

  /**
   * Ordena contas ativas pela melhor candidata:
   * 1. Não estar em cooldown de burst
   * 2. Menor número de requisições simultâneas em andamento
   * 3. Menor RPM nos últimos 60 segundos
   * 4. Cota restante no modelo (se disponível)
   */
  rankAccounts(accounts, modelKey) {
    const now = Date.now();
    const limits = this.getModelLimits(modelKey);

    return [...accounts].map(acc => {
      const stat = this.getAccountStats(acc.id);
      const rpm = stat.requestTimestamps.length;
      const isModelCooldown = Boolean(stat.modelCooldowns?.get(modelKey) && stat.modelCooldowns.get(modelKey) > now);
      const inCooldown = (stat.cooldownUntil > now) || isModelCooldown;

      // Extrair cota se houver quotas_json
      let remainingFraction = 1.0;
      if (acc.quotas_json) {
        try {
          const q = typeof acc.quotas_json === 'string' ? JSON.parse(acc.quotas_json) : acc.quotas_json;
          if (q?.models) {
            const mKeyLower = (modelKey || '').toLowerCase();
            let matchedModel = q.models[modelKey];
            if (!matchedModel && mKeyLower) {
              const foundKey = Object.keys(q.models).find(k => {
                const kLower = k.toLowerCase();
                return kLower === mKeyLower ||
                  (mKeyLower.includes('opus') && kLower.includes('opus')) ||
                  (mKeyLower.includes('sonnet') && kLower.includes('sonnet')) ||
                  (mKeyLower.includes('claude') && kLower.includes('claude')) ||
                  (mKeyLower.includes('gemini') && kLower.includes('gemini'));
              });
              if (foundKey) matchedModel = q.models[foundKey];
            }

            if (matchedModel?.quotaInfo) {
              if (matchedModel.quotaInfo.remainingFraction !== undefined) {
                remainingFraction = matchedModel.quotaInfo.remainingFraction;
              }
              // Se for Claude Opus e não tiver cota real confirmada (marcada como false)
              if (mKeyLower.includes('opus') && (matchedModel.quotaInfo.hasOpusQuota === false || q.hasOpusQuota === false)) {
                remainingFraction = 0.0;
              }
              // Se tiver data de reset no futuro e a cota for baixa, trata como esgotada
              if (matchedModel.quotaInfo.resetTime && new Date(matchedModel.quotaInfo.resetTime) > new Date()) {
                if (remainingFraction <= 0.05) remainingFraction = 0.0;
              }
            }
          }
        } catch (e) {}
      }

      // Cálculo de pontuação de carga (menor é melhor)
      let loadScore = (stat.activeRequests * 100) + (rpm * 10);
      if (inCooldown) loadScore += 10000;
      if (remainingFraction <= 0.02) loadScore += 50000;

      return {
        account: acc,
        stat,
        rpm,
        inCooldown,
        remainingFraction,
        loadScore
      };
    }).sort((a, b) => a.loadScore - b.loadScore);
  }

  /**
   * Tenta adquirir um slot de execução respeitando RPM e concorrência máxima
   * Se todas as contas estiverem cheias no mesmo milissegundo, aguarda em micro-fila
   */
  async acquireAccount(modelKey, maxWaitMs = 6000) {
    const startTime = Date.now();
    const limits = this.getModelLimits(modelKey);

    while (Date.now() - startTime < maxWaitMs) {
      const accounts = await this.getAllActiveAccounts();
      const ranked = this.rankAccounts(accounts, modelKey);

      for (const item of ranked) {
        const { account, stat, rpm, inCooldown, remainingFraction } = item;

        // Se está em cooldown ou sem cota real, pula para a próxima
        if (inCooldown || remainingFraction <= 0.02) continue;

        // Se a conta tem espaço de concorrência e não estourou o teto de RPM
        if (stat.activeRequests < limits.maxConcurrent && rpm < limits.maxRpm) {
          // Token válido
          const tokenOk = await this.ensureTokenFresh(account);
          if (!tokenOk) continue;

          // Reserva o slot
          stat.activeRequests++;
          stat.requestTimestamps.push(Date.now());
          stat.totalCalls++;
          stat.lastUsedAt = new Date();

          return account;
        }
      }

      // Se todas as contas estão momentaneamente cheias, aguarda 250ms antes de tentar de novo
      await new Promise(res => setTimeout(res, 250));
    }

    // Fallback: se passou o tempo de espera, pega a conta com menor concorrência atual
    const accounts = await this.getAllActiveAccounts();
    const ranked = this.rankAccounts(accounts, modelKey);
    const chosen = ranked[0].account;
    const stat = this.getAccountStats(chosen.id);
    stat.activeRequests++;
    stat.requestTimestamps.push(Date.now());
    stat.totalCalls++;
    await this.ensureTokenFresh(chosen);
    return chosen;
  }

  releaseAccount(accId) {
    if (!accId) return;
    const stat = this.getAccountStats(accId);
    stat.activeRequests = Math.max(0, stat.activeRequests - 1);
  }

  async handleAccountError(accId, err, modelKey = '') {
    if (!accId) return;
    const stat = this.getAccountStats(accId);
    stat.failedCalls++;
    stat.lastError = err.message || String(err);

    const status = err.status || err.statusCode || 0;
    const msg = (err.message || '').toLowerCase();
    let errData = err.data || {};
    if (!errData.error && typeof err.message === 'string') {
      try {
        const jsonMatch = err.message.match(/(\{[\s\S]*\})/);
        if (jsonMatch) errData = JSON.parse(jsonMatch[1]);
      } catch (e) {}
    }

    const details = Array.isArray(errData.error?.details) ? errData.error.details : [];
    const errorInfo = details.find(d => d['@type']?.includes('ErrorInfo')) || details[0] || {};
    const reason = errorInfo.reason || '';
    const meta = errorInfo.metadata || {};

    // Modelo reportado no erro pelo Google ou passado como parâmetro
    const actualModel = meta.model || modelKey || '';

    const isDailyQuotaExhausted = reason === 'QUOTA_EXHAUSTED' ||
      msg.includes('individual quota reached') ||
      msg.includes('upgrade your subscription') ||
      msg.includes('quota_exhausted');

    if (isDailyQuotaExhausted && actualModel) {
      console.warn(`[Pool Manager] Cota do modelo "${actualModel}" ESGOTADA no Google para conta #${accId}. Reset previsto: ${meta.quotaResetTimeStamp || meta.quotaResetDelay || 'N/A'}. Registrando no banco...`);
      try {
        const [rows] = await this.pool.query('SELECT quotas_json FROM antigravity_accounts WHERE id = ?', [accId]);
        if (rows.length > 0) {
          let q = rows[0].quotas_json ? JSON.parse(rows[0].quotas_json) : { models: {} };
          if (!q.models) q.models = {};
          if (!q.models[actualModel]) q.models[actualModel] = { displayName: actualModel, maxTokens: 250000, quotaInfo: {} };
          if (!q.models[actualModel].quotaInfo) q.models[actualModel].quotaInfo = {};
          q.models[actualModel].quotaInfo.remainingFraction = 0.0;
          if (meta.quotaResetTimeStamp) {
            q.models[actualModel].quotaInfo.resetTime = meta.quotaResetTimeStamp;
          }
          await this.pool.query('UPDATE antigravity_accounts SET quotas_json = ? WHERE id = ?', [JSON.stringify(q), accId]);
        }

        // Também grava na tabela relacional antigravity_model_quotas se existir
        try {
          await this.pool.query(`
            INSERT INTO antigravity_model_quotas (account_id, model_name, percentage, reset_time, updated_at)
            VALUES (?, ?, 0.00, ?, NOW())
            ON DUPLICATE KEY UPDATE percentage = 0.00, reset_time = VALUES(reset_time), updated_at = NOW()
          `, [accId, actualModel, meta.quotaResetTimeStamp ? new Date(meta.quotaResetTimeStamp) : null]);
        } catch (e) {}

      } catch (dbErr) {
        console.error('[Pool Manager] Erro ao gravar cota esgotada:', dbErr.message);
      }

      // Aplica cooldown prolongado em memória especificamente para o modelo esgotado (não trava a conta inteira para outros modelos como Gemini Flash)
      let cooldownMs = 3600000; // 1 hora padrão
      if (meta.quotaResetTimeStamp) {
        const resetDiff = new Date(meta.quotaResetTimeStamp).getTime() - Date.now();
        if (resetDiff > 0) {
          cooldownMs = Math.min(resetDiff, 7 * 24 * 3600 * 1000);
        }
      }
      if (!stat.modelCooldowns) stat.modelCooldowns = new Map();
      stat.modelCooldowns.set(actualModel, Date.now() + cooldownMs);
      if (actualModel.includes('claude')) {
        stat.modelCooldowns.set('claude', Date.now() + cooldownMs);
        stat.modelCooldowns.set('claude-sonnet-4-6', Date.now() + cooldownMs);
        if (actualModel.includes('opus')) {
          stat.modelCooldowns.set('claude-opus-4-6-thinking', Date.now() + cooldownMs);
        }
      }
    } else if (status === 429 || status === 503 || msg.includes('quota') || msg.includes('rate') || msg.includes('resource_exhausted')) {
      // Cooldown curto de 5 segundos em memória para desobstruir a janela de burst
      stat.cooldownUntil = Date.now() + 5000;
      console.warn(`[Pool Manager] Conta #${accId} atingiu limite de taxa temporário (429/RPM). Cooldown de 5s aplicado.`);
    }
  }

  /**
   * Executa chamada com Fallback automático em caso de 429/503
   */
  async executeWithFallback(targetEngine, contents, systemInstruction, rawModel, customConfig = null) {
    if (targetEngine.includes('3.6') || targetEngine.includes('3.7')) {
      targetEngine = 'gemini-3.8-flash-medium';
    }

    const accounts = await this.getAllActiveAccounts();
    let lastError = null;

    // Tenta até passar pelas contas disponíveis
    for (let attempt = 0; attempt < Math.min(accounts.length, 5); attempt++) {
      let account = null;
      try {
        account = await this.acquireAccount(targetEngine, 4000);
        const googleResp = await generateContent(
          account.access_token,
          account.project_id || 'aicode-consumers',
          targetEngine,
          contents,
          systemInstruction,
          customConfig
        );
        this.releaseAccount(account.id);
        return { googleResp, account };
      } catch (err) {
        if (account) {
          this.releaseAccount(account.id);
          this.handleAccountError(account.id, err, targetEngine || rawModel);
        }
        lastError = err;
        const status = err.status || err.statusCode || 0;
        const msg = (err.message || '').toLowerCase();

        // Se o modelo retornou 404 NOT FOUND no Google Cloud Code, faz fallback seguro para gemini-3.8-flash-medium
        if ((status === 404 || msg.includes('not found')) && targetEngine !== 'gemini-3.8-flash-medium') {
          console.warn(`[Pool Fallback] Modelo "${targetEngine}" retornou 404 NOT FOUND no Google. Redirecionando com segurança para "gemini-3.8-flash-medium"...`);
          return await this.executeWithFallback('gemini-3.8-flash-medium', contents, systemInstruction, rawModel, customConfig);
        }

        // Se for 429/503 ou rate limit, tenta a próxima conta imediatamente
        if (status === 429 || status === 503 || msg.includes('quota') || msg.includes('rate') || msg.includes('resource_exhausted')) {
          console.warn(`[Pool Fallback] Tentativa ${attempt + 1} falhou com 429 na conta ${account?.email || 'N/A'}. Tentando próxima conta livre...`);
          continue;
        }

        // Se for outro erro (ex: validação de payload), propaga
        throw err;
      }
    }

    // Fallback Inteligente entre Modelos: Se o modelo for Claude e todas as contas estiverem com cota esgotada (429)
    if (targetEngine.includes('claude')) {
      const fallbackEngine = 'gemini-3.8-flash-medium';
      console.warn(`[Model Auto-Fallback] Todas as contas esgotaram cota de "${targetEngine}". Redirecionando requisição com sucesso para "${fallbackEngine}"...`);
      return await this.executeWithFallback(fallbackEngine, contents, systemInstruction, rawModel, customConfig);
    }

    throw lastError || new Error('Todas as contas do pool estão ocupadas ou com limite de taxa no momento. Tente novamente em instantes.');
  }

  /**
   * Executa chamada via Streaming com Fallback inteligente
   */
  async executeWithFallbackStream(targetEngine, contents, systemInstruction, requestedModel, customConfig, onChunk, abortSignal) {
    if (targetEngine.includes('3.6') || targetEngine.includes('3.7')) {
      targetEngine = 'gemini-3.8-flash-medium';
    }

    const accounts = await this.getAllActiveAccounts();
    let lastError = null;

    for (let attempt = 0; attempt < Math.min(accounts.length, 5); attempt++) {
      let account = null;
      try {
        account = await this.acquireAccount(targetEngine, 4000);
        const streamRes = await streamGenerateContent(
          account.access_token,
          account.project_id || 'aicode-consumers',
          targetEngine,
          contents,
          systemInstruction,
          customConfig,
          onChunk,
          abortSignal
        );
        this.releaseAccount(account.id);
        return { googleResp: streamRes, account };
      } catch (err) {
        if (account) {
          this.releaseAccount(account.id);
          this.handleAccountError(account.id, err, targetEngine || requestedModel);
        }
        lastError = err;
        const status = err.status || err.statusCode || 0;
        const msg = (err.message || '').toLowerCase();

        // Se o modelo retornou 404 NOT FOUND no Google Cloud Code durante streaming, faz fallback seguro para gemini-3.8-flash-medium
        if ((status === 404 || msg.includes('not found')) && targetEngine !== 'gemini-3.8-flash-medium') {
          console.warn(`[Pool Stream Fallback] Modelo "${targetEngine}" retornou 404 NOT FOUND no Google. Redirecionando streaming com segurança para "gemini-3.8-flash-medium"...`);
          return await this.executeWithFallbackStream('gemini-3.8-flash-medium', contents, systemInstruction, requestedModel, customConfig, onChunk, abortSignal);
        }

        if (status === 429 || status === 503 || msg.includes('quota') || msg.includes('rate') || msg.includes('resource_exhausted')) {
          console.warn(`[Pool Stream Fallback] Tentativa ${attempt + 1} falhou com 429 na conta ${account?.email || 'N/A'}. Tentando próxima conta livre...`);
          continue;
        }

        throw err;
      }
    }

    // Fallback Inteligente entre Modelos no Streaming
    if (targetEngine.includes('claude')) {
      const fallbackEngine = 'gemini-3.8-flash-medium';
      console.warn(`[Model Stream Auto-Fallback] Todas as contas esgotaram cota de "${targetEngine}". Redirecionando requisição com sucesso para "${fallbackEngine}"...`);
      return await this.executeWithFallbackStream(fallbackEngine, contents, systemInstruction, requestedModel, customConfig, onChunk, abortSignal);
    }

    throw lastError || new Error('Todas as contas do pool estão com limite de taxa no momento.');
  }

  /**
   * Retorna estatísticas em tempo real para o painel administrativo
   */
  async getPoolMetrics() {
    const accounts = await this.getAllActiveAccounts().catch(() => []);
    const now = Date.now();
    let totalRpm = 0;
    let totalActiveReqs = 0;

    const accountMetrics = accounts.map(acc => {
      const stat = this.getAccountStats(acc.id);
      const rpm = stat.requestTimestamps.length;
      totalRpm += rpm;
      totalActiveReqs += stat.activeRequests;

      return {
        id: acc.id,
        email: acc.email,
        name: acc.name,
        picture: acc.picture,
        status: acc.status,
        activeRequests: stat.activeRequests,
        currentRpm: rpm,
        inCooldown: stat.cooldownUntil > now,
        cooldownSecondsRemaining: Math.max(0, Math.ceil((stat.cooldownUntil - now) / 1000)),
        totalCalls: stat.totalCalls,
        failedCalls: stat.failedCalls,
        lastUsedAt: stat.lastUsedAt
      };
    });

    return {
      totalAccounts: accounts.length,
      poolActiveRequests: totalActiveReqs,
      poolCurrentRpm: totalRpm,
      accounts: accountMetrics
    };
  }
}

export default AntigravityAccountPool;
