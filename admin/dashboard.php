<?php
require_once __DIR__ . '/../config/helpers.php';
requireAdmin();

$db = getDB();
$pageTitle = 'Dashboard';
$pageHeaderIcon = '<i class="fa-solid fa-chart-line text-purple-400 mr-2.5"></i>';

// KPIs do Dashboard
$totalUsers = (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeKeys = (int)$db->query("SELECT COUNT(*) FROM api_keys WHERE status = 'active'")->fetchColumn();
$totalTokensUsed = (int)($db->query("SELECT SUM(tokens_used) FROM users")->fetchColumn() ?: 0);
$totalRevenue = (float)($db->query("SELECT SUM(amount) FROM payments WHERE status = 'paid'")->fetchColumn() ?: 0);

// Modelos Ativos
$stmtModels = $db->query("SELECT m.*, p.name as provider_name FROM models m LEFT JOIN providers p ON m.provider_id = p.id ORDER BY m.id ASC");
$modelsList = $stmtModels->fetchAll();

// Logs Recentes
$stmtLogs = $db->query("SELECT l.*, u.name as user_name, k.key_name FROM usage_logs l JOIN users u ON l.user_id = u.id JOIN api_keys k ON l.api_key_id = k.id ORDER BY l.id DESC LIMIT 6");
$logs = $stmtLogs->fetchAll();

// Métricas do Pool de Contas Google Antigravity
$accounts = $db->query("SELECT id, email, name, picture, is_active, status, total_requests, total_tokens FROM antigravity_accounts ORDER BY id DESC")->fetchAll();
$totalAccounts = count($accounts);
$activeAccounts = count(array_filter($accounts, fn($a) => $a['is_active'] == 1));

// Consultar métricas dinâmicas de RPM do Gateway
$poolMetrics = null;
$accountMetricsMap = [];
try {
    $resMetrics = gateway_request('auth/antigravity/pool-metrics', 'GET', null, 2);
    if ($resMetrics['success'] && !empty($resMetrics['data']['metrics'])) {
        $poolMetrics = $resMetrics['data']['metrics'];
        foreach (($poolMetrics['accounts'] ?? []) as $aM) {
            $accountMetricsMap[$aM['id']] = $aM;
        }
    }
} catch (Exception $e) {}

$maxSafeRpm = max(30, $activeAccounts * 15);
$currentRpm = $poolMetrics ? (int)$poolMetrics['poolCurrentRpm'] : 0;
$activeReqs = $poolMetrics ? (int)$poolMetrics['poolActiveRequests'] : 0;
$rpmPercentage = min(100, round(($currentRpm / max(1, $maxSafeRpm)) * 100));

// Capacidade simultânea estimada
$simultaneousDevs = $activeAccounts * 8; // ex: 6 * 8 = 48 devs
$simultaneousChat = $activeAccounts * 16; // ex: 6 * 16 = 96 chats

require_once __DIR__ . '/includes/header.php';
?>

<!-- KPI Metrics Cards Grid com Visual Moderno & Glow -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
    <div class="glass-card p-6 rounded-3xl border flex flex-col justify-between group hover:scale-[1.01] transition-all">
        <div class="flex items-center justify-between mb-4">
            <span class="text-xs font-bold uppercase tracking-wider" style="color: var(--text-muted);">Total de Usuários</span>
            <div class="w-10 h-10 rounded-2xl flex items-center justify-center text-sm shadow-sm" style="background: rgba(139, 92, 246, 0.15); color: #8b5cf6; border: 1px solid rgba(139, 92, 246, 0.25);">
                <i class="fa-solid fa-users"></i>
            </div>
        </div>
        <div class="text-3xl font-extrabold font-mono" style="color: var(--text-heading);"><?= number_format($totalUsers, 0, ',', '.') ?></div>
        <p class="text-[11px] mt-2.5 flex items-center font-medium" style="color: var(--text-muted);">
            <i class="fa-solid fa-circle-check text-purple-400 mr-1.5"></i> Desenvolvedores e revendedores
        </p>
    </div>

    <div class="glass-card p-6 rounded-3xl border flex flex-col justify-between group hover:scale-[1.01] transition-all">
        <div class="flex items-center justify-between mb-4">
            <span class="text-xs font-bold uppercase tracking-wider" style="color: var(--text-muted);">API Keys Ativas</span>
            <div class="w-10 h-10 rounded-2xl flex items-center justify-center text-sm shadow-sm" style="background: rgba(99, 102, 241, 0.15); color: #6366f1; border: 1px solid rgba(99, 102, 241, 0.25);">
                <i class="fa-solid fa-key"></i>
            </div>
        </div>
        <div class="text-3xl font-extrabold font-mono" style="color: var(--accent-primary);"><?= number_format($activeKeys, 0, ',', '.') ?></div>
        <p class="text-[11px] mt-2.5 flex items-center font-medium" style="color: var(--text-muted);">
            <i class="fa-solid fa-shield-halved mr-1.5" style="color: var(--accent-primary);"></i> Chaves com acesso liberado
        </p>
    </div>

    <div class="glass-card p-6 rounded-3xl border flex flex-col justify-between group hover:scale-[1.01] transition-all">
        <div class="flex items-center justify-between mb-4">
            <span class="text-xs font-bold uppercase tracking-wider" style="color: var(--text-muted);">Tokens Consumidos</span>
            <div class="w-10 h-10 rounded-2xl flex items-center justify-center text-sm shadow-sm" style="background: rgba(6, 182, 212, 0.15); color: #06b6d4; border: 1px solid rgba(6, 182, 212, 0.25);">
                <i class="fa-solid fa-microchip"></i>
            </div>
        </div>
        <div class="text-3xl font-extrabold font-mono text-cyan-500"><?= number_format($totalTokensUsed, 0, ',', '.') ?></div>
        <p class="text-[11px] mt-2.5 flex items-center font-medium" style="color: var(--text-muted);">
            <i class="fa-solid fa-chart-pie text-cyan-400 mr-1.5"></i> Volume total de computação
        </p>
    </div>

    <div class="glass-card p-6 rounded-3xl border flex flex-col justify-between group hover:scale-[1.01] transition-all">
        <div class="flex items-center justify-between mb-4">
            <span class="text-xs font-bold uppercase tracking-wider" style="color: var(--text-muted);">Receita PIX</span>
            <div class="w-10 h-10 rounded-2xl flex items-center justify-center text-sm shadow-sm" style="background: rgba(16, 185, 129, 0.15); color: #10b981; border: 1px solid rgba(16, 185, 129, 0.25);">
                <i class="fa-solid fa-wallet"></i>
            </div>
        </div>
        <div class="text-3xl font-extrabold font-mono text-emerald-500">R$ <?= number_format($totalRevenue, 2, ',', '.') ?></div>
        <p class="text-[11px] mt-2.5 flex items-center font-medium" style="color: var(--text-muted);">
            <i class="fa-solid fa-bolt text-emerald-400 mr-1.5"></i> Vendas confirmadas no PIX
        </p>
    </div>
</div>

<!-- PAINEL DE INFRAESTRUTURA: POOL DE CONTAS & CAPACIDADE DE USUÁRIOS EM TEMPO REAL -->
<div class="glass-card p-6 rounded-3xl border border-gray-800 space-y-5 bg-gradient-to-b from-gray-900/90 to-gray-950/90 shadow-2xl">
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 border-b border-gray-800/80 pb-4">
        <div class="flex items-center space-x-3.5">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-cyan-600 to-blue-600 text-white flex items-center justify-center text-xl shadow-lg shadow-cyan-500/20">
                <i class="fa-brands fa-google"></i>
            </div>
            <div>
                <div class="flex items-center space-x-2.5">
                    <h2 class="text-base font-extrabold text-white tracking-wide">Pool de Contas Google Antigravity</h2>
                    <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-bold flex items-center gap-1.5">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                        <?= $activeAccounts ?> CONTAS ATIVAS
                    </span>
                </div>
                <p class="text-xs text-gray-400 mt-0.5">Balanceamento inteligente com proteção de RPM e concorrência máxima por conta</p>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <a href="accounts.php" class="px-3.5 py-2 rounded-xl bg-cyan-600/20 hover:bg-cyan-600/30 text-cyan-300 text-xs font-bold border border-cyan-500/30 transition flex items-center space-x-1.5 shadow-lg shadow-cyan-500/10">
                <i class="fa-solid fa-gear text-[11px]"></i>
                <span>Gerenciar Contas & Cotas</span>
            </a>
        </div>
    </div>

    <!-- Indicadores de Capacidade Simultânea e RPM -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="p-4 rounded-2xl bg-gray-900/50 border border-gray-800">
            <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider flex items-center justify-between">
                <span>RPM Atual do Pool</span>
                <span class="inline-flex items-center gap-1 text-[9px] text-cyan-400 font-mono"><span class="w-1.5 h-1.5 rounded-full bg-cyan-400 animate-pulse"></span> AO VIVO</span>
            </div>
            <div class="text-2xl font-black text-cyan-300 font-mono mt-1 flex items-baseline gap-1">
                <span id="live-pool-rpm"><?= $currentRpm ?></span> <span class="text-xs text-gray-500 font-normal">/ ~<span id="live-pool-max-rpm"><?= $maxSafeRpm ?></span> RPM</span>
            </div>
            <div class="w-full h-1.5 rounded-full bg-gray-950 border border-gray-800 mt-2 overflow-hidden">
                <div id="live-pool-bar" class="h-full bg-gradient-to-r from-cyan-500 to-blue-500 transition-all duration-500" style="width: <?= max(5, $rpmPercentage) ?>%;"></div>
            </div>
            <div class="text-[10px] text-gray-500 mt-1"><span id="live-pool-percent"><?= $rpmPercentage ?></span>% da capacidade de rajada utilizada</div>
        </div>

        <div class="p-4 rounded-2xl bg-gray-900/50 border border-gray-800">
            <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider">Chamadas em Andamento</div>
            <div class="text-2xl font-black text-indigo-400 font-mono mt-1 flex items-baseline gap-1.5">
                <span id="live-pool-active-reqs"><?= $activeReqs ?></span> <span class="text-xs text-gray-500 font-normal">ativas agora</span>
            </div>
            <p class="text-[10px] text-gray-400 mt-2">Semáforo de concorrência anti-burst</p>
        </div>

        <div class="p-4 rounded-2xl bg-gray-900/50 border border-gray-800">
            <div class="text-[10px] font-bold text-purple-400 uppercase tracking-wider flex items-center gap-1">
                <i class="fa-solid fa-code"></i> Devs Concorrentes (Claude/Pro)
            </div>
            <div class="text-2xl font-black text-purple-300 font-mono mt-1">
                ~<span id="live-simultaneous-devs"><?= $simultaneousDevs ?></span> <span class="text-xs text-gray-500 font-normal">ao mesmo tempo</span>
            </div>
            <p class="text-[10px] text-gray-400 mt-2">Cursor, Cline, VS Code rodando juntos</p>
        </div>

        <div class="p-4 rounded-2xl bg-gray-900/50 border border-gray-800">
            <div class="text-[10px] font-bold text-emerald-400 uppercase tracking-wider flex items-center gap-1">
                <i class="fa-solid fa-comments"></i> Usuários Chat (Gemini Flash)
            </div>
            <div class="text-2xl font-black text-emerald-300 font-mono mt-1">
                ~<span id="live-simultaneous-chat"><?= $simultaneousChat ?></span> <span class="text-xs text-gray-500 font-normal">ao mesmo tempo</span>
            </div>
            <p class="text-[10px] text-gray-400 mt-2">Base suportada: <span id="live-supported-base"><?= ($activeAccounts * 50) ?> a <?= ($activeAccounts * 100) ?></span> cadastrados</p>
        </div>
    </div>

    <!-- Lista Rápida das Contas Conectadas -->
    <div class="pt-1">
        <div class="text-[11px] font-bold text-gray-400 uppercase tracking-wider mb-2.5 flex items-center justify-between">
            <span>Distribuição de Contas Conectadas</span>
            <span class="text-gray-500 font-mono"><span id="live-active-accs-count"><?= $activeAccounts ?></span> de <?= $totalAccounts ?> ativas</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2.5" id="live-accounts-container">
            <?php foreach ($accounts as $acc): 
                $aMetric = $accountMetricsMap[$acc['id']] ?? null;
                $accRpm = $aMetric ? $aMetric['currentRpm'] : 0;
                $accActive = $aMetric ? $aMetric['activeRequests'] : 0;
                $inCd = !empty($aMetric['inCooldown']);
            ?>
                <div class="p-3 rounded-2xl bg-gray-950/70 border border-gray-800/80 flex items-center justify-between gap-3 text-xs" id="acc-card-<?= $acc['id'] ?>">
                    <div class="flex items-center space-x-2.5 min-w-0">
                        <?php if (!empty($acc['picture'])): ?>
                            <img src="<?= htmlspecialchars($acc['picture']) ?>" referrerpolicy="no-referrer" loading="lazy" class="w-8 h-8 rounded-xl border border-gray-700 flex-shrink-0 object-cover" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?= urlencode($acc['name'] ?: $acc['email']) ?>&background=d97736&color=fff&rounded=true&bold=true';">
                        <?php else: ?>
                            <div class="w-8 h-8 rounded-xl bg-gradient-to-tr from-amber-600 to-orange-600 text-white flex items-center justify-center font-bold text-xs flex-shrink-0">
                                <?= strtoupper(substr($acc['email'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                        <div class="min-w-0">
                            <div class="font-bold text-white truncate text-[11px]"><?= htmlspecialchars($acc['name'] ?: $acc['email']) ?></div>
                            <div class="text-[10px] text-gray-500 font-mono truncate"><?= htmlspecialchars($acc['email']) ?></div>
                        </div>
                    </div>
                    <div class="flex flex-col items-end gap-1 flex-shrink-0" id="acc-badges-<?= $acc['id'] ?>">
                        <?php if ($acc['is_active']): ?>
                            <span class="px-2 py-0.5 rounded-full border text-[9px] font-mono font-bold <?= $accRpm > 5 ? 'bg-amber-500/10 text-amber-400 border-amber-500/20' : 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20' ?>">
                                <?= $accRpm ?> RPM
                            </span>
                            <?php if ($accActive > 0): ?>
                                <span class="text-[9px] font-bold text-indigo-400 animate-pulse flex items-center gap-1">
                                    <span class="w-1 h-1 rounded-full bg-indigo-400"></span> <?= $accActive ?> ativa
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="px-2 py-0.5 rounded-full bg-red-500/10 text-red-400 border border-red-500/20 text-[9px] font-bold">PAUSADA</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Recent Activity Overview Grid -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Modelos Ativos e Provedores -->
    <div class="glass-card p-6 rounded-3xl border border-gray-800 space-y-4">
        <div class="flex items-center justify-between border-b border-gray-800 pb-3">
            <h3 class="text-sm font-bold text-white flex items-center">
                <i class="fa-solid fa-microchip text-purple-400 mr-2"></i> Modelos Disponíveis
            </h3>
            <a href="models.php" class="text-xs text-purple-400 hover:text-purple-300 font-semibold">Gerenciar Modelos</a>
        </div>
        <div class="space-y-2.5">
            <?php foreach ($modelsList as $m): ?>
                <div class="p-3 rounded-xl dark:bg-gray-900/60 bg-slate-50 border dark:border-gray-800/80 border-slate-200 flex items-center justify-between text-xs font-mono shadow-sm">
                    <div class="flex items-center space-x-2.5">
                        <span class="font-bold dark:text-cyan-300 text-sky-700"><?= htmlspecialchars($m['model_key']) ?></span>
                        <span class="dark:text-gray-400 text-slate-500 font-sans text-xs">(<?= htmlspecialchars($m['name']) ?>)</span>
                    </div>
                    <span class="px-2.5 py-0.5 rounded dark:bg-emerald-500/10 dark:text-emerald-400 dark:border-emerald-500/20 bg-emerald-50 text-emerald-700 border border-emerald-200 text-[11px] font-semibold">
                        <?= htmlspecialchars($m['upstream_model']) ?>
                    </span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Logs Recentes -->
    <div class="glass-card p-6 rounded-3xl border border-gray-800 space-y-4">
        <div class="flex items-center justify-between border-b border-gray-800 pb-3">
            <h3 class="text-sm font-bold text-white flex items-center">
                <i class="fa-solid fa-list-check text-cyan-400 mr-2"></i> Últimas Requisições
            </h3>
            <a href="logs.php" class="text-xs text-cyan-400 hover:text-cyan-300 font-semibold">Ver Todos os Logs</a>
        </div>
        <div class="space-y-2.5">
            <?php if (empty($logs)): ?>
                <p class="text-xs text-gray-500 py-4 text-center">Nenhum log registrado ainda.</p>
            <?php else: ?>
                <?php foreach ($logs as $l): ?>
                    <div class="p-3 rounded-xl dark:bg-gray-900/60 bg-slate-50 border dark:border-gray-800/80 border-slate-200 flex items-center justify-between text-xs font-mono shadow-sm">
                        <div>
                            <span class="font-bold dark:text-white text-slate-900 font-sans"><?= htmlspecialchars($l['user_name']) ?></span>
                            <span class="dark:text-purple-300 text-purple-700 ml-2 font-medium">(<?= htmlspecialchars($l['model']) ?>)</span>
                        </div>
                        <span class="dark:text-cyan-400 text-sky-700 font-bold"><?= number_format($l['total_tokens'], 0, ',', '.') ?> tokens</span>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Script de Atualização Contínua em Tempo Real (Sem F5) -->
<script>
(function() {
    async function updateDashboardLivePool() {
        try {
            const res = await fetch('api_pool.php');
            if (!res.ok) return;
            const data = await res.json();
            if (!data || !data.success || !data.metrics) return;

            const m = data.metrics;
            const currentRpm = parseInt(m.poolCurrentRpm || 0);
            const activeReqs = parseInt(m.poolActiveRequests || 0);
            const totalAccounts = (m.accounts && m.accounts.length) || 1;
            const maxSafeRpm = Math.max(30, totalAccounts * 15);
            const pct = Math.min(100, Math.round((currentRpm / maxSafeRpm) * 100));

            // Atualizar valores do pool
            const elRpm = document.getElementById('live-pool-rpm');
            if (elRpm) elRpm.innerText = currentRpm;

            const elActiveReqs = document.getElementById('live-pool-active-reqs');
            if (elActiveReqs) elActiveReqs.innerText = activeReqs;

            const elPercent = document.getElementById('live-pool-percent');
            if (elPercent) elPercent.innerText = pct;

            const elBar = document.getElementById('live-pool-bar');
            if (elBar) elBar.style.width = Math.max(5, pct) + '%';

            // Atualizar contadores de Devs e Chats simultâneos dinamicamente
            const elDevs = document.getElementById('live-simultaneous-devs');
            if (elDevs) elDevs.innerText = totalAccounts * 8;

            const elChats = document.getElementById('live-simultaneous-chat');
            if (elChats) elChats.innerText = totalAccounts * 16;

            const elBase = document.getElementById('live-supported-base');
            if (elBase) elBase.innerText = `${totalAccounts * 50} a ${totalAccounts * 100}`;

            // Atualizar badges das contas individuais
            if (m.accounts && Array.isArray(m.accounts)) {
                m.accounts.forEach(acc => {
                    const badgeContainer = document.getElementById('acc-badges-' + acc.id);
                    if (badgeContainer) {
                        const rpm = acc.currentRpm || 0;
                        const active = acc.activeRequests || 0;
                        const inCd = acc.inCooldown;
                        const rpmClass = rpm > 5 ? 'bg-amber-500/10 text-amber-400 border-amber-500/20' : 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20';

                        let html = '';
                        if (acc.status === 'active') {
                            html += `<span class="px-2 py-0.5 rounded-full border text-[9px] font-mono font-bold ${rpmClass}">${rpm} RPM</span>`;
                            if (active > 0) {
                                html += `<span class="text-[9px] font-bold text-indigo-400 animate-pulse flex items-center gap-1"><span class="w-1 h-1 rounded-full bg-indigo-400"></span> ${active} ativa</span>`;
                            }
                            if (inCd) {
                                html += `<span class="text-[9px] font-bold text-rose-400">Cooldown (${acc.cooldownSecondsRemaining || 0}s)</span>`;
                            }
                        } else {
                            html += `<span class="px-2 py-0.5 rounded-full bg-red-500/10 text-red-400 border border-red-500/20 text-[9px] font-bold">PAUSADA</span>`;
                        }
                        badgeContainer.innerHTML = html;
                    }
                });
            }
        } catch (e) {
            // Silencioso em caso de falha de rede temporária
        }
    }

    // Executa a cada 2.5 segundos de forma silenciosa
    setInterval(updateDashboardLivePool, 2500);
})();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
