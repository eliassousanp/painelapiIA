<?php
require_once __DIR__ . '/../config/helpers.php';
requireAdmin();

$db = getDB();
$pageTitle = 'Pool de Contas & Cotas Google Antigravity';
$pageHeaderIcon = '<i class="fa-brands fa-google text-cyan-400 mr-2.5"></i>';
$msg = '';
$error = '';

// -------------------------------------------------------------
// ENDPOINT AJAX UNIFICADO: VERIFICAÇÃO AUTOMÁTICA DE TODAS AS COTAS
// Executa a checagem oficial de Gemini, Sonnet, Opus e GPT em um único fluxo
// -------------------------------------------------------------
if (isset($_GET['ajax_verify_quotas']) || isset($_GET['ajax_sync']) || isset($_GET['ajax_verify_opus']) || (isset($_POST['action']) && in_array($_POST['action'], ['ajax_verify_quotas', 'ajax_sync', 'ajax_verify_opus']))) {
    header('Content-Type: application/json; charset=utf-8');
    $accId = !empty($_REQUEST['account_id']) ? (int)$_REQUEST['account_id'] : 0;
    $syncEndpoint = $accId > 0 ? "auth/antigravity/sync?id={$accId}" : "auth/antigravity/sync";

    $res = gateway_request($syncEndpoint, 'GET', null, 50);
    if ($res['success']) {
        echo json_encode([
            'success' => true,
            'message' => $accId > 0 ? 'Cotas da conta verificadas e atualizadas com sucesso!' : 'Todas as cotas do pool foram verificadas com sucesso!',
            'results' => $res['data']['results'] ?? []
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => "Erro ao verificar cotas no Gateway Node: " . ($res['error'] ?? 'Falha de comunicação')
        ]);
    }
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'export_accounts') {
    $stmt = $db->query("SELECT email, name, picture, refresh_token, access_token, token_expires_at, project_id, is_active, status, quotas_json FROM antigravity_accounts");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="contas_google_antigravity_' . date('Y-m-d_His') . '.json"');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'connected') {
        $msg = 'Conta Google vinculada com sucesso ao ecossistema Antigravity!';
    } elseif ($_GET['msg'] === 'synced') {
        $msg = 'Todas as cotas foram verificadas e atualizadas em tempo real!';
    }
}

// Processamento de Ações POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validate_csrf()) {
        $error = 'Sessão expirada ou token CSRF inválido. Por favor, recarregue a página.';
    } elseif ($_POST['action'] === 'toggle_account') {
        $accId = (int)$_POST['account_id'];
        $current = (int)$_POST['current_active'];
        $newVal = $current === 1 ? 0 : 1;
        $stmt = $db->prepare("UPDATE antigravity_accounts SET is_active = ? WHERE id = ?");
        $stmt->execute([$newVal, $accId]);
        $msg = 'Status da conta alterado com sucesso!';
    } elseif ($_POST['action'] === 'delete_account') {
        $accId = (int)$_POST['account_id'];
        $stmt = $db->prepare("DELETE FROM antigravity_accounts WHERE id = ?");
        $stmt->execute([$accId]);
        $msg = 'Conta removida do pool.';
    } elseif (in_array($_POST['action'], ['verify_quotas', 'sync_quotas', 'verify_opus'])) {
        $accId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : 0;
        $endpoint = $accId > 0 ? "auth/antigravity/sync?id={$accId}" : "auth/antigravity/sync";
        $res = gateway_request($endpoint, 'GET', null, 50);
        if ($res['success']) {
            $msg = $accId > 0 ? 'Cotas da conta verificadas com sucesso na API Google!' : 'Todas as cotas do pool foram verificadas e atualizadas com sucesso!';
        } else {
            $error = "Erro ao verificar cotas: " . ($res['error'] ?? 'Verifique se o serviço Node.js está rodando.');
        }
    } elseif ($_POST['action'] === 'manual_vps_connect') {
        $codeUrl = trim($_POST['code_url'] ?? '');
        if (!empty($codeUrl)) {
            $res = gateway_request("auth/antigravity/manual-callback", 'POST', ['codeUrl' => $codeUrl], 15);
            if ($res['success']) {
                $msg = "Conta " . ($res['data']['email'] ?? '') . " vinculada com sucesso ao pool!";
            } else {
                $error = "Erro ao conectar conta: " . ($res['error'] ?? 'Verifique se a URL ou código foi copiado corretamente.');
            }
        } else {
            $error = 'Por favor, informe o código ou a URL de redirecionamento.';
        }
    } elseif ($_POST['action'] === 'import_accounts') {
        $jsonStr = '';
        if (!empty($_FILES['accounts_file']['tmp_name'])) {
            $jsonStr = file_get_contents($_FILES['accounts_file']['tmp_name']);
        } elseif (!empty($_POST['accounts_json'])) {
            $jsonStr = trim($_POST['accounts_json']);
        }

        $imported = !empty($jsonStr) ? json_decode($jsonStr, true) : null;
        if (is_array($imported) && count($imported) > 0) {
            $stmt = $db->prepare("INSERT INTO antigravity_accounts 
                (email, name, picture, refresh_token, access_token, token_expires_at, project_id, is_active, status, quotas_json) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                name = VALUES(name),
                picture = VALUES(picture),
                refresh_token = VALUES(refresh_token),
                access_token = VALUES(access_token),
                token_expires_at = VALUES(token_expires_at),
                project_id = VALUES(project_id),
                is_active = VALUES(is_active),
                status = VALUES(status),
                quotas_json = VALUES(quotas_json)");
            $count = 0;
            foreach ($imported as $acc) {
                if (!empty($acc['email']) && !empty($acc['refresh_token'])) {
                    $stmt->execute([
                        $acc['email'],
                        $acc['name'] ?? '',
                        $acc['picture'] ?? '',
                        $acc['refresh_token'],
                        $acc['access_token'] ?? null,
                        $acc['token_expires_at'] ?? null,
                        $acc['project_id'] ?? null,
                        isset($acc['is_active']) ? (int)$acc['is_active'] : 1,
                        $acc['status'] ?? 'active',
                        $acc['quotas_json'] ?? null
                    ]);
                    $count++;
                }
            }
            if ($count > 0) {
                $msg = "Sucesso! {$count} conta(s) Google importada(s)/atualizada(s) no pool!";
            } else {
                $error = 'Nenhuma conta válida (com e-mail e refresh_token) foi encontrada no arquivo.';
            }
        } else {
            $error = 'Arquivo JSON inválido ou vazio.';
        }
    }
}

// Resolução dinâmica de URLs do gateway para o cliente
$currentHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$currentScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$currentAppUrl = $currentScheme . '://' . $currentHost . getAppBaseUrl() . '/admin/accounts.php';
$gatewayExternalUrl = getGatewayPublicUrl();

$accounts = $db->query("SELECT * FROM antigravity_accounts ORDER BY id DESC")->fetchAll();
$totalAccounts = count($accounts);
$bannedAccounts = count(array_filter($accounts, fn($a) => $a['status'] === 'banned'));
$activeAccounts = count(array_filter($accounts, fn($a) => $a['is_active'] == 1 && $a['status'] !== 'banned'));
$pausedAccounts = count(array_filter($accounts, fn($a) => !$a['is_active'] && $a['status'] !== 'banned'));
$totalRequests = array_sum(array_column($accounts, 'total_requests'));
$totalTokens = array_sum(array_column($accounts, 'total_tokens'));

// Função para diagnosticar se a conta realmente possui cota de Claude Opus e calcular quantidade exata de tokens
function getAccountOpusQuotaStatus($acc) {
    $q = !empty($acc['quotas_json']) ? json_decode($acc['quotas_json'], true) : null;
    $opusKey = 'claude-opus-4-6-thinking';
    $mInfo = $q['models'][$opusKey] ?? null;

    $hasOpusQuota = $mInfo['quotaInfo']['hasOpusQuota'] ?? ($q['hasOpusQuota'] ?? null);
    $fraction = isset($mInfo['quotaInfo']['remainingFraction']) ? (float)$mInfo['quotaInfo']['remainingFraction'] : null;
    $checkedAt = $mInfo['quotaInfo']['checkedAt'] ?? ($q['opusCheckedAt'] ?? null);
    $message = $mInfo['quotaInfo']['statusMessage'] ?? ($q['opusMessage'] ?? '');
    $resetTime = $mInfo['quotaInfo']['resetTime'] ?? null;
    $maxTokens = (int)($mInfo['maxTokens'] ?? ($q['opusTokensMax'] ?? 1048576));

    if (!$q || $hasOpusQuota === null) {
        return [
            'status' => 'unverified',
            'has_quota' => false,
            'max_tokens' => $maxTokens,
            'available_tokens' => 0,
            'consumed_tokens' => $maxTokens,
            'percentage' => 0,
            'tokens_formatted' => '0 / ' . number_format($maxTokens, 0, ',', '.') . ' tk',
            'label' => 'Opus: Não Testado (Clique em Testar)',
            'badge_class' => 'bg-amber-500/10 border-amber-500/20 text-amber-400',
            'icon' => 'fa-solid fa-circle-question',
            'checked_at' => null,
            'reset_time' => null,
            'message' => 'Cota do Claude Opus ainda não foi testada na API do Google'
        ];
    }

    if ($hasOpusQuota === true && ($fraction === null || $fraction > 0.05)) {
        $effectiveFraction = $fraction ?? 1.0;
        $availableTokens = (int)($mInfo['availableTokens'] ?? ($q['opusTokensAvailable'] ?? round($maxTokens * $effectiveFraction)));
        $consumedTokens = max(0, $maxTokens - $availableTokens);
        $percentage = round(($availableTokens / max(1, $maxTokens)) * 100);

        return [
            'status' => 'available',
            'has_quota' => true,
            'max_tokens' => $maxTokens,
            'available_tokens' => $availableTokens,
            'consumed_tokens' => $consumedTokens,
            'percentage' => $percentage,
            'tokens_formatted' => number_format($availableTokens, 0, ',', '.') . ' / ' . number_format($maxTokens, 0, ',', '.') . ' tk',
            'label' => '💎 Opus: ' . number_format($availableTokens, 0, ',', '.') . ' / ' . number_format($maxTokens, 0, ',', '.') . ' tk (' . $percentage . '%)',
            'badge_class' => 'bg-gradient-to-r from-pink-500/20 to-purple-500/20 border-pink-500/40 text-pink-300 shadow-md shadow-pink-500/10',
            'icon' => 'fa-solid fa-gem text-pink-400',
            'checked_at' => $checkedAt,
            'reset_time' => $resetTime,
            'message' => $message ?: 'Cota de Claude Opus confirmada e ativa na API Google'
        ];
    }

    // Se $hasOpusQuota === false ou fração zerada
    $consumedTokens = $maxTokens;
    return [
        'status' => 'no_quota',
        'has_quota' => false,
        'max_tokens' => $maxTokens,
        'available_tokens' => 0,
        'consumed_tokens' => $consumedTokens,
        'percentage' => 0,
        'tokens_formatted' => '0 / ' . number_format($maxTokens, 0, ',', '.') . ' tk',
        'label' => '🚫 Opus: 0 / ' . number_format($maxTokens, 0, ',', '.') . ' tk (0% - Sem cota)',
        'badge_class' => 'bg-rose-500/10 border-rose-500/20 text-rose-400',
        'icon' => 'fa-solid fa-ban text-rose-400',
        'checked_at' => $checkedAt,
        'reset_time' => $resetTime,
        'message' => $message ?: 'Esta conta Google não possui cota para Claude Opus (requer Google One AI Premium)'
    ];
}

// Estatísticas globais do Claude Opus
$opusActiveCount = 0;
$opusNoQuotaCount = 0;
$opusUnverifiedCount = 0;
$opusPoolAvailableTokens = 0;
$opusPoolMaxTokens = 0;

foreach ($accounts as $acc) {
    if (!$acc['is_active'] || $acc['status'] === 'banned') continue;
    $opStat = getAccountOpusQuotaStatus($acc);
    $opusPoolMaxTokens += $opStat['max_tokens'];
    if ($opStat['status'] === 'available') {
        $opusActiveCount++;
        $opusPoolAvailableTokens += $opStat['available_tokens'];
    } elseif ($opStat['status'] === 'no_quota') {
        $opusNoQuotaCount++;
    } else {
        $opusUnverifiedCount++;
    }
}
$opusPoolPercentage = $opusPoolMaxTokens > 0 ? round(($opusPoolAvailableTokens / $opusPoolMaxTokens) * 100) : 0;

// Calcular tempo da última sincronização para controle inteligente de background sync
$latestSyncTimestamp = 0;
foreach ($accounts as $acc) {
    if (!empty($acc['last_sync_at'])) {
        $ts = strtotime($acc['last_sync_at']);
        if ($ts > $latestSyncTimestamp) $latestSyncTimestamp = $ts;
    }
}
$secondsSinceLastSync = $latestSyncTimestamp > 0 ? (time() - $latestSyncTimestamp) : 999999;

// Buscar histórico de tokens consumidos por modelo em usage_logs
$modelUsageStats = [];
$stmtUsage = $db->query("SELECT model, SUM(total_tokens) as tokens_spent, COUNT(*) as req_count FROM usage_logs GROUP BY model");
while ($rowU = $stmtUsage->fetch(PDO::FETCH_ASSOC)) {
    $modelUsageStats[$rowU['model']] = [
        'tokens' => (int)$rowU['tokens_spent'],
        'reqs' => (int)$rowU['req_count']
    ];
}

// Buscar métricas em tempo real do pool no Gateway Node (RPM, concorrência e cooldowns)
$poolMetrics = null;
$accountMetricsMap = [];
try {
    $gwUrl = getGatewayInternalUrl() . '/auth/antigravity/pool-metrics';
    $chMetrics = curl_init($gwUrl);
    curl_setopt($chMetrics, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($chMetrics, CURLOPT_TIMEOUT, 2);
    $rawMetrics = curl_exec($chMetrics);
    curl_close($chMetrics);
    if ($rawMetrics) {
        $jMetrics = json_decode($rawMetrics, true);
        if (!empty($jMetrics['success'])) {
            $poolMetrics = $jMetrics['metrics'];
            foreach ($poolMetrics['accounts'] as $aM) {
                $accountMetricsMap[$aM['id']] = $aM;
            }
        }
    }
} catch (Exception $e) {}

// Modelos chave em destaque (Modelos ativos atuais: apenas 3.8 em diante)
$keyModels = [
    'gemini-3.8-flash-medium' => ['name' => 'Gemini 3.8 Flash Medium', 'tag' => 'Google (Fast)', 'color' => 'bg-cyan-500'],
    'gemini-3.8-flash-high' => ['name' => 'Gemini 3.8 Flash High', 'tag' => 'Google (Pro)', 'color' => 'bg-blue-500'],
    'claude-sonnet-4-6' => ['name' => 'Claude Sonnet 4.6 (Thinking)', 'tag' => 'Anthropic', 'color' => 'bg-purple-500'],
    'claude-opus-4-6-thinking' => ['name' => 'Claude Opus 4.6 (Thinking)', 'tag' => 'Anthropic', 'color' => 'bg-pink-500'],
    'gpt-oss-120b-medium' => ['name' => 'GPT-OSS 120B (Medium)', 'tag' => 'OpenAI', 'color' => 'bg-amber-500']
];

// Consolidar a capacidade total de cada modelo somando todas as contas ativas do pool
// (Exclui modelos obsoletos abaixo de 3.8 e contabiliza Opus apenas de contas confirmadas)
$poolModelStats = [];
foreach ($accounts as $acc) {
    if (!$acc['is_active'] || $acc['status'] === 'banned') continue;
    $qData = !empty($acc['quotas_json']) ? json_decode($acc['quotas_json'], true) : null;
    $models = $qData['models'] ?? [];
    $accOpusStat = getAccountOpusQuotaStatus($acc);

    foreach ($models as $mKey => $mInfo) {
        if (!isset($mInfo['quotaInfo'])) continue;
        // Filtra modelos descontinuados abaixo do 3.8
        if (preg_match('/(2\.5|3\.5|3\.6|3\.7)/i', $mKey)) continue;

        if (!isset($poolModelStats[$mKey])) {
            $providerRaw = $mInfo['modelProvider'] ?? $mInfo['apiProvider'] ?? 'Google';
            $provider = stripos($providerRaw, 'anthropic') !== false ? 'Anthropic' : (stripos($providerRaw, 'openai') !== false ? 'OpenAI' : 'Google');

            $poolModelStats[$mKey] = [
                'id' => $mKey,
                'displayName' => $mInfo['displayName'] ?? $mKey,
                'provider' => $provider,
                'totalAvailableTokens' => 0,
                'totalConsumedTokens' => 0,
                'totalMaxTokens' => 0,
                'activeAccountsCount' => 0,
                'nextResetTime' => null
            ];
        }

        $fraction = (float)($mInfo['quotaInfo']['remainingFraction'] ?? 1.0);
        $maxTokens = (int)($mInfo['maxTokens'] ?? 1048576);

        // Se for Opus, só adiciona tokens disponíveis se a conta realmente tiver cota confirmada
        if (stripos($mKey, 'opus') !== false) {
            if (!$accOpusStat['has_quota']) {
                $fraction = 0.0;
            }
        }

        $available = round($maxTokens * $fraction);
        $consumed = max(0, $maxTokens - $available);
        $resetTime = $mInfo['quotaInfo']['resetTime'] ?? null;

        $poolModelStats[$mKey]['totalAvailableTokens'] += $available;
        $poolModelStats[$mKey]['totalConsumedTokens'] += $consumed;
        $poolModelStats[$mKey]['totalMaxTokens'] += $maxTokens;
        if ($fraction > 0.05) {
            $poolModelStats[$mKey]['activeAccountsCount']++;
        }

        if ($resetTime) {
            if (!$poolModelStats[$mKey]['nextResetTime'] || strtotime($resetTime) < strtotime($poolModelStats[$mKey]['nextResetTime'])) {
                $poolModelStats[$mKey]['nextResetTime'] = $resetTime;
            }
        }
    }
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">

    <?php if (!empty($msg)): ?>
        <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs flex items-center shadow-lg shadow-emerald-500/5">
            <i class="fa-solid fa-circle-check mr-2.5 text-base"></i>
            <span><?= htmlspecialchars($msg) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div class="p-4 rounded-2xl bg-red-500/10 border border-red-500/20 text-red-400 text-xs flex items-center shadow-lg shadow-red-500/5">
            <i class="fa-solid fa-triangle-exclamation mr-2.5 text-base"></i>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($bannedAccounts > 0): ?>
        <!-- BANNER DE ALERTA: CONTAS BANIDAS (TOS VIOLATION) -->
        <div class="p-5 rounded-3xl bg-rose-950/40 border border-rose-500/40 text-rose-300 flex flex-col md:flex-row md:items-center justify-between gap-4 shadow-2xl shadow-rose-950/50">
            <div class="flex items-start space-x-3.5">
                <div class="w-11 h-11 rounded-2xl bg-rose-600/20 text-rose-400 flex items-center justify-center shrink-0 border border-rose-500/30 text-lg mt-0.5">
                    <i class="fa-solid fa-skull-crossbones"></i>
                </div>
                <div>
                    <h4 class="font-extrabold text-white text-sm flex items-center gap-2">
                        <span><?= $bannedAccounts ?> Conta(s) Google Bloqueada(s) / Banida(s)</span>
                        <span class="px-2.5 py-0.5 rounded-full bg-rose-600/30 text-rose-300 text-[10px] font-mono font-bold border border-rose-500/30">403 TOS_VIOLATION</span>
                    </h4>
                    <p class="text-xs text-rose-200/80 mt-1 leading-relaxed">
                        O Google desativou o Cloud Code nestas contas por violação de termos de serviço. O gateway isolou essas contas automaticamente para não derrubar as requisições do seu pool. 
                        Você pode tentar <strong>Reautenticar</strong> ou <strong>Colar o link na VPS</strong> para verificar se a conta volta.
                    </p>
                </div>
            </div>
            <div class="flex items-center space-x-2 shrink-0 self-end md:self-center">
                <button type="button" onclick="document.getElementById('vpsModal').classList.remove('hidden')" class="px-3.5 py-2.5 rounded-xl bg-amber-500/10 hover:bg-amber-500/20 text-amber-300 font-bold text-xs border border-amber-500/30 transition flex items-center gap-1.5 cursor-pointer shadow-sm">
                    <i class="fa-solid fa-server text-[11px]"></i>
                    <span>Colar Link VPS</span>
                </button>
                <a href="https://forms.gle/hGzM9MEUv2azZsrb9" target="_blank" class="px-3.5 py-2.5 rounded-xl bg-rose-600 hover:bg-rose-500 text-white font-bold text-xs border border-rose-400/40 transition flex items-center gap-1.5 cursor-pointer shadow-md shadow-rose-600/20">
                    <i class="fa-solid fa-gavel text-[11px]"></i>
                    <span>Formulário de Apelação</span>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <!-- Banner & Estatísticas Topo -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="glass-card p-5 rounded-3xl border border-[var(--border-color)]">
            <div class="text-[11px] font-bold text-[var(--text-muted)] uppercase tracking-wider">Total de Contas</div>
            <div class="text-2xl font-extrabold text-[var(--text-heading)] mt-1"><?= $totalAccounts ?></div>
            <div class="text-[11px] text-[var(--text-muted)] opacity-80 mt-0.5">Pool de Contas Google</div>
        </div>
        <div class="glass-card p-5 rounded-3xl border border-[var(--border-color)]">
            <div class="text-[11px] font-bold text-emerald-400 uppercase tracking-wider">Contas Ativas</div>
            <div class="text-2xl font-extrabold text-emerald-400 mt-1"><?= $activeAccounts ?></div>
            <div class="text-[11px] text-[var(--text-muted)] opacity-80 mt-0.5">Prontas para requisições</div>
        </div>
        <?php if ($bannedAccounts > 0): ?>
            <div class="glass-card p-5 rounded-3xl border border-rose-500/30 bg-rose-950/20">
                <div class="text-[11px] font-bold text-rose-400 uppercase tracking-wider flex items-center gap-1">
                    <i class="fa-solid fa-skull-crossbones text-[10px]"></i> Contas Banidas
                </div>
                <div class="text-2xl font-extrabold text-rose-400 mt-1"><?= $bannedAccounts ?></div>
                <div class="text-[11px] text-rose-300/70 mt-0.5">Bloqueadas pelo Google (403)</div>
            </div>
        <?php else: ?>
            <div class="glass-card p-5 rounded-3xl border border-[var(--border-color)]">
                <div class="text-[11px] font-bold text-[var(--text-muted)] uppercase tracking-wider">Requisições</div>
                <div class="text-2xl font-extrabold text-purple-400 mt-1"><?= number_format($totalRequests, 0, ',', '.') ?></div>
                <div class="text-[11px] text-[var(--text-muted)] opacity-80 mt-0.5">Total de chamadas</div>
            </div>
        <?php endif; ?>
        <div class="glass-card p-5 rounded-3xl border border-[var(--border-color)]">
            <div class="text-[11px] font-bold text-amber-400 uppercase tracking-wider flex items-center gap-1">
                <i class="fa-solid fa-coins text-[10px]"></i> Tokens Consumidos
            </div>
            <div class="text-2xl font-extrabold text-amber-300 font-mono mt-1"><?= number_format($totalTokens, 0, ',', '.') ?></div>
            <div class="text-[11px] text-[var(--text-muted)] opacity-80 mt-0.5">Consumo total do pool</div>
        </div>
        <div class="glass-card p-5 rounded-3xl border border-[var(--border-color)] flex flex-col justify-center">
            <a href="<?= htmlspecialchars($gatewayExternalUrl) ?>/auth/antigravity/login?redirect=<?= urlencode($currentAppUrl . '?msg=connected') ?>" target="_blank" class="w-full py-3 px-4 rounded-2xl bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 text-white font-bold text-xs flex items-center justify-center space-x-2 shadow-lg shadow-amber-600/25 transition">
                <i class="fa-brands fa-google text-sm"></i>
                <span>+ Conectar Conta Google</span>
            </a>
            <div class="flex items-center justify-between text-[10px] text-[var(--text-muted)] mt-1.5 px-0.5">
                <span>OAuth Antigravity</span>
                <button type="button" onclick="document.getElementById('vpsModal').classList.remove('hidden')" class="text-amber-400 hover:text-amber-300 underline font-semibold">
                    <i class="fa-solid fa-server mr-0.5"></i> Usando VPS?
                </button>
            </div>
            <div class="flex items-center justify-between gap-1.5 pt-2 border-t border-[var(--border-color)] mt-2 text-[10px]">
                <a href="?action=export_accounts" class="flex-1 py-1.5 px-2 rounded-xl bg-[var(--bg-surface)] hover:bg-[var(--border-color)] text-cyan-400 font-bold flex items-center justify-center space-x-1 border border-[var(--border-color)] transition" title="Exportar Contas Conectadas em JSON">
                    <i class="fa-solid fa-file-export text-[11px]"></i>
                    <span>Exportar</span>
                </a>
                <button type="button" onclick="document.getElementById('importModal').classList.remove('hidden')" class="flex-1 py-1.5 px-2 rounded-xl bg-[var(--bg-surface)] hover:bg-[var(--border-color)] text-emerald-400 font-bold flex items-center justify-center space-x-1 border border-[var(--border-color)] transition" title="Importar Contas Conectadas">
                    <i class="fa-solid fa-file-import text-[11px]"></i>
                    <span>Importar</span>
                </button>
            </div>
        </div>
    </div>

    <!-- BANNER DE DESTAQUE: STATUS REAL DE COTA E TOKENS DO CLAUDE OPUS 4.6 -->
    <div class="glass-card rounded-3xl border border-pink-500/30 p-5 shadow-2xl flex flex-col lg:flex-row items-center justify-between gap-5" style="background: radial-gradient(circle at top left, rgba(236,72,153,0.12), transparent 70%), var(--bg-surface-elevated);">
        <div class="flex items-start space-x-4">
            <div class="w-12 h-12 rounded-2xl bg-gradient-to-tr from-pink-600 to-purple-600 text-white flex items-center justify-center text-xl shadow-lg shadow-pink-500/25 shrink-0 mt-0.5">
                <i class="fa-solid fa-gem"></i>
            </div>
            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-base font-extrabold text-[var(--text-heading)] flex items-center gap-2">
                        <span>Claude Opus 4.6 (Thinking)</span>
                        <span class="text-xs px-2.5 py-0.5 rounded-full bg-pink-500/15 text-pink-400 border border-pink-500/30 font-mono font-bold">Probe Real Ativo</span>
                    </h3>
                    <?php if ($opusActiveCount > 0): ?>
                        <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/15 text-emerald-400 border border-emerald-500/30 text-[10px] font-bold">
                            <i class="fa-solid fa-circle-check mr-1"></i><?= $opusActiveCount ?> de <?= $activeAccounts ?> CONTAS COM COTA ATIVA
                        </span>
                    <?php else: ?>
                        <span class="px-2.5 py-0.5 rounded-full bg-rose-500/15 text-rose-400 border border-rose-500/30 text-[10px] font-bold">
                            <i class="fa-solid fa-triangle-exclamation mr-1"></i>0 CONTAS COM COTA (<?= $opusNoQuotaCount ?> sem cota<?= $opusUnverifiedCount > 0 ? ", {$opusUnverifiedCount} pendentes" : "" ?>)
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Métricas Claras de Tokens de Opus no Pool -->
                <div class="flex flex-wrap items-center gap-2 pt-0.5 font-mono text-xs">
                    <span class="px-3 py-1.5 rounded-xl border border-pink-500/30 text-pink-300 font-bold flex items-center gap-2 shadow-sm" style="background: rgba(236,72,153,0.08);">
                        <i class="fa-solid fa-battery-half text-emerald-400 text-sm"></i>
                        <span>Cota Opus no Pool:</span>
                        <strong class="text-[var(--text-heading)] text-sm"><?= number_format($opusPoolAvailableTokens, 0, ',', '.') ?></strong>
                        <span class="text-pink-400/90 font-normal">/ <?= number_format($opusPoolMaxTokens, 0, ',', '.') ?> tokens livres (<?= $opusPoolPercentage ?>%)</span>
                    </span>
                    <span class="px-2.5 py-1.5 rounded-xl border border-[var(--border-color)] text-emerald-400 flex items-center gap-1.5 text-[11px] font-semibold" style="background: var(--bg-surface);">
                        <i class="fa-solid fa-circle-check text-emerald-400"></i> <?= $opusActiveCount ?> com cota
                    </span>
                    <span class="px-2.5 py-1.5 rounded-xl border border-[var(--border-color)] text-rose-400 flex items-center gap-1.5 text-[11px] font-semibold" style="background: var(--bg-surface);">
                        <i class="fa-solid fa-circle-xmark text-rose-400"></i> <?= $opusNoQuotaCount ?> sem cota
                    </span>
                    <?php if ($opusUnverifiedCount > 0): ?>
                        <span class="px-2.5 py-1.5 rounded-xl bg-amber-500/10 border border-amber-500/20 text-amber-400 flex items-center gap-1.5 text-[11px] font-semibold">
                            <i class="fa-solid fa-circle-question text-amber-400"></i> <?= $opusUnverifiedCount ?> não testadas
                        </span>
                    <?php endif; ?>
                </div>

                <p class="text-xs text-[var(--text-muted)] leading-relaxed">
                    O catálogo da Google API lista cota teórica para todas as contas. Nossa verificação unificada faz probe real via Cloud Code para assegurar que seus tokens de Opus estão prontos para produção.
                </p>
            </div>
        </div>
        <div class="flex items-center space-x-2 self-stretch lg:self-auto justify-end shrink-0">
            <button type="button" onclick="verifyAllQuotas();" class="btn-verify-all-main w-full lg:w-auto px-5 py-3 rounded-2xl bg-gradient-to-r from-pink-600 via-purple-600 to-indigo-600 hover:from-pink-500 hover:to-indigo-500 text-white text-xs font-bold shadow-lg shadow-pink-600/30 transition flex items-center justify-center space-x-2.5 cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed">
                <i class="fa-solid fa-bolt text-sm"></i>
                <span>Verificar Todas as Cotas</span>
            </button>
        </div>
    </div>

    <!-- PAINEL CONSOLIDADO: CAPACIDADE TOTAL DO POOL (SOMA DE TODAS AS CONTAS) -->
    <?php if (!empty($poolModelStats)): ?>
        <div id="poolConsolidatedCard" data-seconds-since-sync="<?= $secondsSinceLastSync ?>" data-active-accounts="<?= $activeAccounts ?>" class="glass-card rounded-3xl border border-[var(--border-color)] overflow-hidden shadow-2xl p-6 space-y-5" style="background: var(--bg-surface-elevated);">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-3 border-b border-[var(--border-color)] pb-4">
                <div class="flex items-center space-x-3">
                    <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-cyan-500 to-blue-600 text-white flex items-center justify-center text-lg shadow-lg shadow-cyan-500/20">
                        <i class="fa-solid fa-server"></i>
                    </div>
                    <div>
                        <div class="flex items-center space-x-2.5">
                            <h2 class="text-base font-extrabold text-[var(--text-heading)] tracking-wide">Capacidade Total Consolidada do Pool</h2>
                            <span class="px-2.5 py-0.5 rounded-full bg-cyan-500/10 border border-cyan-500/20 text-cyan-400 text-[10px] font-bold">
                                <?= $activeAccounts ?> CONTAS ATIVAS
                            </span>
                        </div>
                        <p class="text-xs text-[var(--text-muted)] mt-0.5">Soma em tempo real dos tokens disponíveis e consumidos de todas as contas combinadas</p>
                    </div>
                </div>

                <div class="flex items-center space-x-2 self-start md:self-auto">
                    <!-- Badge de Status em Segundo Plano -->
                    <div id="bgSyncBadge" class="hidden items-center space-x-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold backdrop-blur-md transition-all duration-300"></div>

                    <button type="button" id="btnSyncAll" onclick="verifyAllQuotas();" class="px-4 py-2.5 rounded-xl bg-cyan-600/20 hover:bg-cyan-600/30 text-cyan-300 text-xs font-bold border border-cyan-500/30 transition flex items-center space-x-2 shadow-lg shadow-cyan-500/10 cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed">
                        <i class="fa-solid fa-arrows-rotate text-[11px]" id="syncAllIcon"></i>
                        <span id="syncAllText">Sincronizar Todas as Cotas</span>
                    </button>
                </div>
            </div>

            <!-- Cards Consolidado dos Modelos Principais do Pool -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <?php 
                $featuredPoolKeys = [
                    'gemini-3.8-flash-medium',
                    'claude-sonnet-4-6',
                    'claude-opus-4-6-thinking'
                ];

                foreach ($featuredPoolKeys as $pKey): 
                    $pStat = $poolModelStats[$pKey] ?? null;
                    if (!$pStat) continue;

                    $poolPct = $pStat['totalMaxTokens'] > 0 ? round(($pStat['totalAvailableTokens'] / $pStat['totalMaxTokens']) * 100) : 0;
                    $poolBarCol = $poolPct > 50 ? 'bg-emerald-500' : ($poolPct > 20 ? 'bg-amber-500' : 'bg-red-500');
                    $poolTextCol = $poolPct > 50 ? 'text-emerald-400' : ($poolPct > 20 ? 'text-amber-400' : 'text-red-400');

                    $pResetLabel = 'Automático';
                    if (!empty($pStat['nextResetTime'])) {
                        $pResetTs = strtotime($pStat['nextResetTime']);
                        $pDiffSec = $pResetTs - time();
                        $pDateStr = date('d/m H:i', $pResetTs);
                        if ($pDiffSec > 0) {
                            $ph = floor($pDiffSec / 3600);
                            $pm = floor(($pDiffSec % 3600) / 60);
                            $pResetLabel = $ph > 0 ? "{$pDateStr} (em {$ph}h {$pm}m)" : "{$pDateStr} (em {$pm}m)";
                        } else {
                            $pResetLabel = "{$pDateStr} (Renovado)";
                        }
                    }
                ?>
                    <div class="p-4 rounded-2xl border border-[var(--border-color)] hover:border-cyan-500/40 transition space-y-3 shadow-lg" style="background: var(--bg-surface);">
                        <div class="flex items-start justify-between">
                            <div>
                                <div class="text-xs font-bold text-[var(--text-heading)] leading-tight"><?= htmlspecialchars($pStat['displayName']) ?></div>
                                <div class="text-[10px] text-[var(--text-muted)] font-mono mt-0.5 flex items-center gap-1.5">
                                    <span><?= htmlspecialchars($pKey) ?></span>
                                    <span>•</span>
                                    <span class="text-cyan-400 font-bold"><?= $pStat['activeAccountsCount'] ?> contas</span>
                                </div>
                            </div>
                            <div class="text-right">
                                <span class="text-sm font-black font-mono <?= $poolTextCol ?>"><?= $poolPct ?>%</span>
                                <div class="text-[9px] text-[var(--text-muted)] uppercase font-semibold">Pool Disponível</div>
                            </div>
                        </div>

                        <!-- Métricas Consolidadas do Pool -->
                        <div class="grid grid-cols-2 gap-2 font-mono text-xs">
                            <div class="px-2.5 py-2 rounded-xl border border-[var(--border-color)] flex flex-col" style="background: var(--bg-surface-elevated);">
                                <span class="text-[var(--text-muted)] text-[9px] flex items-center gap-1">
                                    <i class="fa-solid fa-battery-three-quarters text-emerald-400 text-[9px]"></i> Total Disponível
                                </span>
                                <span class="font-bold <?= $poolTextCol ?> text-xs truncate mt-0.5">
                                    <?= number_format($pStat['totalAvailableTokens'], 0, ',', '.') ?> <span class="text-[9px] text-[var(--text-muted)]">tk</span>
                                </span>
                            </div>
                            <div class="px-2.5 py-2 rounded-xl border border-[var(--border-color)] flex flex-col" style="background: var(--bg-surface-elevated);">
                                <span class="text-[var(--text-muted)] text-[9px] flex items-center gap-1">
                                    <i class="fa-solid fa-fire-flame-curved text-orange-400 text-[9px]"></i> Total Consumido
                                </span>
                                <span class="font-bold text-amber-300 text-xs truncate mt-0.5">
                                    <?= number_format($pStat['totalConsumedTokens'], 0, ',', '.') ?> <span class="text-[9px] text-[var(--text-muted)]">tk</span>
                                </span>
                            </div>
                        </div>

                        <!-- Barra de Progresso Consolidada do Pool -->
                        <div class="w-full h-2 rounded-full border border-[var(--border-color)] overflow-hidden" style="background: var(--bg-surface-elevated);">
                            <div class="h-full <?= $poolBarCol ?> transition-all duration-700" style="width: <?= $poolPct ?>%;"></div>
                        </div>

                        <div class="flex items-center justify-between text-[10px] text-[var(--text-muted)] pt-0.5 font-mono">
                            <span class="flex items-center space-x-1" title="Capacidade máxima combinada de todas as contas para este modelo">
                                <i class="fa-solid fa-layer-group text-[9px]"></i>
                                <span>Capacidade: <?= number_format($pStat['totalMaxTokens'], 0, ',', '.') ?> tk</span>
                            </span>
                            <span class="flex items-center gap-1.5">
                                <span class="text-[9px] text-cyan-400" title="Horário de renovação de cota do Google"><i class="fa-solid fa-clock-rotate-left mr-0.5"></i> <?= $pResetLabel ?></span>
                                <span class="px-2 py-0.5 rounded border border-[var(--border-color)] font-mono text-[9px]" style="background: var(--bg-surface-elevated); color: var(--text-heading);"><?= $pStat['provider'] ?></span>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Gaveta com Todos os Modelos Consolidados do Pool -->
            <details class="group mt-2">
                <summary class="cursor-pointer text-xs text-cyan-400 hover:text-cyan-300 font-bold flex items-center space-x-1.5 select-none py-1.5 px-3 rounded-xl border border-[var(--border-color)] transition w-fit" style="background: var(--bg-surface);">
                    <i class="fa-solid fa-layer-group text-[11px]"></i>
                    <span>Ver todos os <?= count($poolModelStats) ?> modelos consolidados do pool</span>
                    <i class="fa-solid fa-chevron-down text-[10px] group-open:rotate-180 transition-transform ml-1"></i>
                </summary>
                <div class="mt-3 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2.5 p-3 rounded-2xl border border-[var(--border-color)]" style="background: var(--bg-surface);">
                    <?php foreach ($poolModelStats as $allPKey => $allPStat): 
                        $allPct = $allPStat['totalMaxTokens'] > 0 ? round(($allPStat['totalAvailableTokens'] / $allPStat['totalMaxTokens']) * 100) : 0;
                        $allCol = $allPct > 50 ? 'text-emerald-400' : ($allPct > 20 ? 'text-amber-400' : 'text-red-400');
                    ?>
                        <div class="p-2.5 rounded-xl border border-[var(--border-color)] text-xs flex items-center justify-between" style="background: var(--bg-surface-elevated);">
                            <div class="truncate mr-2">
                                <div class="font-medium text-[var(--text-heading)] truncate text-[11px]"><?= htmlspecialchars($allPStat['displayName']) ?></div>
                                <div class="text-[9px] text-[var(--text-muted)] font-mono truncate">
                                    Disp: <?= number_format($allPStat['totalAvailableTokens'], 0, ',', '.') ?> / <?= number_format($allPStat['totalMaxTokens'], 0, ',', '.') ?> tk
                                </div>
                            </div>
                            <span class="font-bold font-mono text-[11px] <?= $allCol ?> whitespace-nowrap"><?= $allPct ?>%</span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        </div>
    <?php endif; ?>

    <!-- Lista de Contas Individuais com Painel de Cotas por Modelo -->
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h3 class="text-sm font-bold text-gray-300 uppercase tracking-wider flex items-center gap-2">
                <i class="fa-brands fa-google text-cyan-400"></i> Detalhamento Individual por Conta Google
            </h3>
            <span class="text-xs text-gray-500 font-mono"><?= $totalAccounts ?> contas registradas</span>
        </div>
        <?php if (empty($accounts)): ?>
            <div class="glass-card rounded-3xl border border-gray-800 p-12 text-center">
                <i class="fa-brands fa-google text-4xl text-gray-600 mb-3"></i>
                <h3 class="text-base font-bold text-white">Nenhuma conta Google conectada no pool</h3>
                <p class="text-xs text-gray-400 mt-1">Clique no botão acima para conectar sua primeira conta via OAuth.</p>
            </div>
        <?php else: ?>
            <?php foreach ($accounts as $acc): 
                $quotasData = !empty($acc['quotas_json']) ? json_decode($acc['quotas_json'], true) : null;
                $modelsQuotas = $quotasData['models'] ?? [];
                $isBanned = ($acc['status'] === 'banned');
                $cardClasses = $isBanned 
                    ? 'border-rose-500/50 bg-gradient-to-b from-rose-950/25 to-gray-900/90 shadow-rose-950/30 ring-1 ring-rose-500/20' 
                    : 'border-gray-800 bg-gray-900/60';
            ?>
                <div class="glass-card rounded-3xl border <?= $cardClasses ?> overflow-hidden shadow-2xl transition">
                    <details class="group">
                        <summary class="p-5 <?= $isBanned ? 'bg-rose-950/20 hover:bg-rose-950/40' : 'bg-gray-900/60 hover:bg-gray-900/90' ?> flex flex-col lg:flex-row lg:items-center justify-between gap-4 cursor-pointer select-none transition list-none">
                        <div class="flex items-center space-x-3.5">
                            <?php if (!empty($acc['picture'])): ?>
                                <img src="<?= htmlspecialchars($acc['picture']) ?>" referrerpolicy="no-referrer" loading="lazy" class="w-12 h-12 rounded-2xl border <?= $isBanned ? 'border-rose-500/40 ring-2 ring-rose-500/20' : 'border-gray-700' ?> shadow-md object-cover" onerror="this.onerror=null; this.src='https://ui-avatars.com/api/?name=<?= urlencode($acc['name'] ?: $acc['email']) ?>&background=d97736&color=fff&rounded=true&bold=true';">
                            <?php else: ?>
                                <div class="w-12 h-12 rounded-2xl <?= $isBanned ? 'bg-gradient-to-tr from-rose-700 to-red-800' : 'bg-gradient-to-tr from-amber-600 to-orange-600' ?> text-white flex items-center justify-center font-bold text-lg shadow-md">
                                    <?= strtoupper(substr($acc['email'], 0, 1)) ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <div class="flex items-center space-x-2.5">
                                    <h3 class="text-base font-bold text-white"><?= htmlspecialchars($acc['name'] ?? 'Conta Google') ?></h3>
                                    <?php if ($acc['status'] === 'banned'): ?>
                                        <span class="px-2.5 py-0.5 rounded-full bg-rose-600/30 border border-rose-500/40 text-rose-300 text-[10px] font-bold flex items-center gap-1 shadow-sm">
                                            <i class="fa-solid fa-skull-crossbones text-[9px] text-rose-400"></i> BANIDO (TOS 403)
                                        </span>
                                    <?php elseif ($acc['is_active']): ?>
                                        <span class="px-2.5 py-0.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[10px] font-bold">ATIVO</span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-0.5 rounded-full bg-red-500/10 border border-red-500/20 text-red-400 text-[10px] font-bold">PAUSADO</span>
                                    <?php endif; ?>

                                    <!-- BADGE DE STATUS E TOKENS DE COTA DO CLAUDE OPUS -->
                                    <?php 
                                    $accOpus = getAccountOpusQuotaStatus($acc);
                                    ?>
                                    <span class="px-3 py-1 rounded-full border text-[11px] font-mono font-bold flex items-center gap-1.5 <?= $accOpus['badge_class'] ?>" title="<?= htmlspecialchars($accOpus['message']) ?>">
                                        <i class="<?= $accOpus['icon'] ?>"></i>
                                        <span><?= $accOpus['label'] ?></span>
                                    </span>

                                    <?php 
                                    $aMetric = $accountMetricsMap[$acc['id']] ?? null;
                                    if ($aMetric): 
                                        $rpmBadgeColor = $aMetric['currentRpm'] > 6 ? 'bg-amber-500/10 text-amber-400 border-amber-500/20' : 'bg-cyan-500/10 text-cyan-400 border-cyan-500/20';
                                    ?>
                                        <span class="px-2.5 py-0.5 rounded-full border text-[10px] font-mono font-bold <?= $rpmBadgeColor ?>" title="Requisições nos últimos 60 segundos">
                                            <i class="fa-solid fa-gauge-high mr-1"></i><?= $aMetric['currentRpm'] ?> RPM
                                        </span>
                                        <?php if ($aMetric['activeRequests'] > 0): ?>
                                            <span class="px-2 py-0.5 rounded-full bg-indigo-500/20 border border-indigo-500/30 text-indigo-300 text-[10px] font-bold flex items-center gap-1">
                                                <span class="w-1.5 h-1.5 rounded-full bg-indigo-400 animate-ping"></span>
                                                <?= $aMetric['activeRequests'] ?> ativa(s)
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($aMetric['inCooldown'])): ?>
                                            <span class="px-2 py-0.5 rounded-full bg-rose-500/20 border border-rose-500/30 text-rose-300 text-[10px] font-bold">
                                                Cooldown 429 (<?= $aMetric['cooldownSecondsRemaining'] ?>s)
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-gray-400 mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span class="<?= $isBanned ? 'text-rose-300' : 'text-gray-300' ?> font-mono"><?= htmlspecialchars($acc['email']) ?></span>
                                    <span>•</span>
                                    <span class="font-mono text-[11px] text-cyan-300">Project: <?= htmlspecialchars($acc['project_id'] ?? 'managed') ?></span>
                                    <span>•</span>
                                    <span class="text-purple-300 font-bold"><?= number_format($acc['total_requests'] ?? 0, 0, ',', '.') ?> reqs</span>
                                    <span>•</span>
                                    <span class="text-amber-300 font-mono font-bold flex items-center gap-1">
                                        <i class="fa-solid fa-coins text-[10px]"></i> <?= number_format($acc['total_tokens'] ?? 0, 0, ',', '.') ?> tk totais
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Botões de Ação da Conta -->
                        <div class="flex items-center space-x-2 self-end lg:self-center flex-wrap gap-y-2">
                            <?php if ($acc['status'] === 'banned'): ?>
                                <!-- 1º Passo: Botão Reautenticar (Abre OAuth) -->
                                <a href="<?= htmlspecialchars($gatewayExternalUrl) ?>/auth/antigravity/login?redirect=<?= urlencode($currentAppUrl . '?msg=connected') ?>" target="_blank" onclick="event.stopPropagation();" class="px-3 py-2 rounded-xl bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 text-white text-xs font-bold border border-amber-500/30 transition flex items-center space-x-1.5 shadow-lg shadow-amber-600/20 cursor-pointer" title="Clique para abrir a tela de login do Google">
                                    <i class="fa-brands fa-google text-[11px]"></i>
                                    <span>1º Reautenticar</span>
                                </a>
                                <!-- 2º Passo: Botão Colar Link VPS (Abre modal de VPS direto aqui) -->
                                <button type="button" onclick="event.stopPropagation(); document.getElementById('vpsModal').classList.remove('hidden');" class="px-3 py-2 rounded-xl bg-cyan-600/20 hover:bg-cyan-600/30 text-cyan-300 text-xs font-bold border border-cyan-500/40 transition flex items-center space-x-1.5 cursor-pointer shadow-md" title="Cole aqui a URL localhost:51121 se estiver usando VPS">
                                    <i class="fa-solid fa-server text-[11px] text-cyan-400"></i>
                                    <span>2º Colar Link VPS</span>
                                </button>
                                <!-- Link para Apelar no Google -->
                                <a href="https://forms.gle/hGzM9MEUv2azZsrb9" target="_blank" onclick="event.stopPropagation();" class="px-3 py-2 rounded-xl bg-rose-600/20 hover:bg-rose-600/30 text-rose-300 text-xs font-semibold border border-rose-500/30 transition flex items-center space-x-1.5" title="Enviar formulário oficial de apelação ao Google">
                                    <i class="fa-solid fa-gavel text-[11px] text-rose-400"></i>
                                    <span>Apelar Ban</span>
                                </a>
                            <?php else: ?>
                                <!-- Botão Único: Verificar Cotas (Consolidado Google + Opus) -->
                                <button type="button" onclick="event.stopPropagation(); verifyAccountQuotas(<?= $acc['id'] ?>, this);" title="Verificar cotas reais de todos os modelos e probe do Claude Opus para esta conta" class="btn-verify-single px-3.5 py-2 rounded-xl bg-cyan-600/20 hover:bg-cyan-600/30 text-cyan-300 text-xs font-bold border border-cyan-500/30 transition flex items-center space-x-1.5 shadow-md shadow-cyan-500/10 cursor-pointer disabled:opacity-60 disabled:cursor-not-allowed">
                                    <i class="fa-solid fa-bolt text-[11px]"></i>
                                    <span>Verificar Cotas</span>
                                </button>
                            <?php endif; ?>

                            <!-- Alternar Status -->
                            <form method="POST" action="" class="inline" onclick="event.stopPropagation();">
                                <input type="hidden" name="action" value="toggle_account">
                                <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                <input type="hidden" name="current_active" value="<?= $acc['is_active'] ?>">
                                <button type="submit" class="px-3 py-2 rounded-xl bg-gray-800 hover:bg-gray-700 text-gray-300 text-xs font-semibold transition">
                                    <?= $acc['is_active'] ? 'Pausar' : 'Ativar' ?>
                                </button>
                            </form>

                            <!-- Excluir -->
                            <form method="POST" action="" onsubmit="return confirm('Deseja realmente remover esta conta do pool?');" class="inline" onclick="event.stopPropagation();">
                                <input type="hidden" name="action" value="delete_account">
                                <input type="hidden" name="account_id" value="<?= $acc['id'] ?>">
                                <button type="submit" class="px-3 py-2 rounded-xl bg-red-600/10 hover:bg-red-600/20 text-red-400 text-xs font-semibold border border-red-500/20 transition">
                                    Excluir
                                </button>
                            </form>

                            <!-- Indicador de Expandir / Minimizar -->
                            <div class="pl-2 border-l border-gray-800 text-gray-400 group-open:text-cyan-400 transition flex items-center gap-1.5 text-xs font-semibold">
                                <span class="group-open:hidden text-[11px]">Ver Cotas</span>
                                <span class="hidden group-open:inline text-[11px]">Ocultar</span>
                                <i class="fa-solid fa-chevron-down text-xs transition-transform duration-300 group-open:rotate-180"></i>
                            </div>
                        </div>
                    </summary>

                    <!-- PAINEL VISUAL DE COTAS DA CONTA (MINIMIZADO POR PADRÃO) -->
                    <div class="p-6 space-y-4 border-t border-gray-800/80 bg-gray-950/40">
                        <div class="flex items-center justify-between border-b border-gray-800/80 pb-3">
                            <div class="flex items-center space-x-2">
                                <div class="w-7 h-7 rounded-lg bg-purple-500/20 text-purple-400 flex items-center justify-center text-xs">
                                    <i class="fa-solid fa-gauge-high"></i>
                                </div>
                                <div>
                                    <h4 class="text-xs font-bold text-white uppercase tracking-wider">Cotas dos Modelos de IA</h4>
                                    <p class="text-[10px] text-gray-400">Capacidade restante e agendamento de reset por modelo</p>
                                </div>
                            </div>
                            <span class="text-[11px] text-gray-400 bg-gray-900/60 px-2.5 py-1 rounded-lg border border-gray-800 font-mono">
                                Última sync: <?= !empty($acc['last_sync_at']) ? date('d/m/Y H:i:s', strtotime($acc['last_sync_at'])) : 'Nunca' ?>
                            </span>
                        </div>

                        <?php if (empty($modelsQuotas)): ?>
                            <div class="p-6 rounded-2xl bg-gray-900/40 border border-gray-800 text-center space-y-3">
                                <i class="fa-solid fa-circle-question text-3xl text-gray-600"></i>
                                <div class="text-sm font-semibold text-gray-300">Nenhuma cota registrada para esta conta ainda.</div>
                                <button type="button" onclick="verifyAccountQuotas(<?= $acc['id'] ?>, this);" class="px-4 py-2 rounded-xl bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-bold transition shadow-md shadow-cyan-600/20 cursor-pointer">
                                    <i class="fa-solid fa-bolt mr-1"></i> Consultar Cotas no Google Agora
                                </button>
                            </div>
                        <?php else: ?>
                            <!-- Origem dos Dados e Status das Cotas -->
                            <div class="flex flex-wrap items-center justify-between gap-2 p-3 rounded-2xl bg-cyan-950/20 border border-cyan-800/30 text-xs text-cyan-300">
                                <div class="flex items-center space-x-2">
                                    <i class="fa-solid fa-satellite-dish text-cyan-400"></i>
                                    <span><strong>API Google CloudCode:</strong> Modelos ativos a partir da versão 3.8</span>
                                </div>
                                <span class="text-[11px] text-gray-400 font-mono">
                                    <i class="fa-solid fa-layer-group mr-1 text-cyan-400"></i>Modelos ativos gerenciados
                                </span>
                            </div>

                            <!-- Cards dos Modelos Principais (Apenas 3.8 em diante) -->
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3.5">
                                <?php 
                                // Prioriza modelos chave ativos (apenas 3.8 em diante)
                                $featuredKeys = [
                                    'gemini-3.8-flash-medium',
                                    'gemini-3.8-flash-high',
                                    'claude-sonnet-4-6',
                                    'claude-opus-4-6-thinking',
                                    'gpt-oss-120b-medium'
                                ];

                                foreach ($featuredKeys as $mKey): 
                                    $mQuota = $modelsQuotas[$mKey] ?? null;
                                    if (!$mQuota && !isset($modelsQuotas[$mKey])) continue;

                                    $displayName = $mQuota['displayName'] ?? $mKey;
                                    $providerRaw = $mQuota['modelProvider'] ?? $mQuota['apiProvider'] ?? 'Google';
                                    $provider = stripos($providerRaw, 'anthropic') !== false ? 'Anthropic' : (stripos($providerRaw, 'openai') !== false ? 'OpenAI' : 'Google');
                                    
                                    $fraction = $mQuota['quotaInfo']['remainingFraction'] ?? 1.0;
                                    $resetTime = $mQuota['quotaInfo']['resetTime'] ?? null;
                                    $maxTokens = $mQuota['maxTokens'] ?? 1048576;

                                    // Lógica especial de cota real para Claude Opus
                                    $isOpus = stripos($mKey, 'opus') !== false;
                                    $opusNote = null;
                                    if ($isOpus) {
                                        $maxTokens = $accOpus['max_tokens'];
                                        $availableTokens = $accOpus['available_tokens'];
                                        $consumedTokens = $accOpus['consumed_tokens'];
                                        $percentage = $accOpus['percentage'];

                                        if (!$accOpus['has_quota']) {
                                            $barCol = 'bg-red-500';
                                            $textCol = 'text-red-400';
                                            $opusNote = '<span class="px-2 py-0.5 rounded bg-red-500/20 text-red-300 font-bold text-[9px]">0 TOKENS (SEM COTA NO GOOGLE)</span>';
                                        } else {
                                            $barCol = 'bg-pink-500';
                                            $textCol = 'text-pink-400';
                                            $opusNote = '<span class="px-2 py-0.5 rounded bg-pink-500/20 text-pink-300 font-bold text-[9px]"><i class="fa-solid fa-gem mr-1"></i>' . number_format($availableTokens, 0, ',', '.') . ' TOKENS ATIVOS</span>';
                                        }
                                    } else {
                                        $percentage = round($fraction * 100);
                                        $barCol = $percentage > 50 ? 'bg-emerald-500' : ($percentage > 20 ? 'bg-amber-500' : 'bg-red-500');
                                        $textCol = $percentage > 50 ? 'text-emerald-400' : ($percentage > 20 ? 'text-amber-400' : 'text-red-400');
                                        $availableTokens = round($maxTokens * $fraction);
                                        $consumedTokens = max(0, $maxTokens - $availableTokens);
                                    }

                                    // Cálculo de tempo até o reset
                                    $resetLabel = 'Automático';
                                    if ($resetTime) {
                                        $resetTs = strtotime($resetTime);
                                        $diffSec = $resetTs - time();
                                        $dateStr = date('d/m H:i', $resetTs);
                                        if ($diffSec > 0) {
                                            $h = floor($diffSec / 3600);
                                            $m = floor(($diffSec % 3600) / 60);
                                            $resetLabel = $h > 0 ? "{$dateStr} (em {$h}h {$m}m)" : "{$dateStr} (em {$m}m)";
                                        } else {
                                            $resetLabel = "{$dateStr} (Renovado)";
                                        }
                                    }
                                ?>
                                    <div class="p-4 rounded-2xl bg-gray-900/80 border <?= $isOpus && !$accOpus['has_quota'] ? 'border-red-900/40' : ($isOpus && $accOpus['has_quota'] ? 'border-pink-500/40 shadow-lg shadow-pink-500/5' : 'border-gray-800/90') ?> hover:border-gray-700 transition space-y-2.5">
                                        <div class="flex items-start justify-between">
                                            <div>
                                                <div class="text-xs font-bold text-white leading-tight flex items-center gap-1.5">
                                                    <span><?= htmlspecialchars($displayName) ?></span>
                                                    <?php if ($isOpus): ?>
                                                        <i class="fa-solid fa-gem text-pink-400 text-[10px]"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="text-[10px] text-gray-400 font-mono mt-0.5"><?= htmlspecialchars($mKey) ?></div>
                                            </div>
                                            <div class="text-right">
                                                <span class="text-sm font-black font-mono <?= $textCol ?>"><?= $percentage ?>%</span>
                                                <div class="text-[9px] text-gray-500 uppercase font-semibold">Cota Restante</div>
                                            </div>
                                        </div>

                                        <?php if ($opusNote): ?>
                                            <div class="pt-0.5"><?= $opusNote ?></div>
                                        <?php endif; ?>

                                        <?php
                                            $spentTokens = $modelUsageStats[$mKey]['tokens'] ?? 0;
                                            if ($spentTokens === 0) {
                                                foreach ($modelUsageStats as $usedMod => $uData) {
                                                    if (stripos($usedMod, str_replace(['gemini-', '-medium', '-thinking', '-low'], '', $mKey)) !== false) {
                                                        $spentTokens += $uData['tokens'];
                                                    }
                                                }
                                            }
                                        ?>
                                        <!-- Destaque de Tokens Disponíveis e Consumidos no Ciclo do Google -->
                                        <div class="grid grid-cols-2 gap-1.5 font-mono text-xs">
                                            <div class="bg-gray-950/70 px-2 py-1.5 rounded-xl border border-gray-800/80 flex flex-col">
                                                <span class="text-gray-500 text-[9px] flex items-center gap-1">
                                                    <i class="fa-solid fa-battery-half text-emerald-400 text-[8px]"></i> Disponível
                                                </span>
                                                <span class="font-bold <?= $textCol ?> text-[11px] truncate">
                                                    <?= number_format($availableTokens, 0, ',', '.') ?> <span class="text-[9px] text-gray-500">tk</span>
                                                </span>
                                            </div>
                                            <div class="bg-gray-950/70 px-2 py-1.5 rounded-xl border border-gray-800/80 flex flex-col" title="<?= $spentTokens > 0 ? 'Consumo total da cota Google. Gateway registrou ' . number_format($spentTokens, 0, ',', '.') . ' tk' : 'Consumo total do pool da conta Google' ?>">
                                                <span class="text-gray-500 text-[9px] flex items-center gap-1">
                                                    <i class="fa-solid fa-fire text-orange-400 text-[8px]"></i> Consumido
                                                </span>
                                                <span class="font-bold text-amber-300 text-[11px] truncate">
                                                    <?= number_format($consumedTokens, 0, ',', '.') ?> <span class="text-[9px] text-gray-500">tk</span>
                                                </span>
                                            </div>
                                        </div>

                                        <!-- Barra de Progresso Visual -->
                                        <div class="w-full h-2 rounded-full bg-gray-950 border border-gray-800/80 overflow-hidden">
                                            <div class="h-full <?= $barCol ?> transition-all duration-700" style="width: <?= $percentage ?>%;"></div>
                                        </div>

                                        <div class="flex items-center justify-between text-[10px] text-gray-400 pt-0.5">
                                            <span class="flex items-center space-x-1" title="<?= $resetTime ? 'Reset exato informado pelo Google: ' . $resetTime : '' ?>">
                                                <i class="fa-regular fa-clock text-[9px]"></i>
                                                <span>Reset: <?= $resetLabel ?></span>
                                            </span>
                                            <span class="px-2 py-0.5 rounded bg-gray-800 text-gray-300 font-mono text-[9px]"><?= $provider ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Gaveta Expansível com Todos os Modelos -->
                            <details class="group mt-3">
                                <summary class="cursor-pointer text-xs text-cyan-400 hover:text-cyan-300 font-bold flex items-center space-x-1.5 select-none py-1.5 px-3 rounded-xl bg-gray-900/40 hover:bg-gray-900/80 border border-gray-800 transition w-fit">
                                    <i class="fa-solid fa-list text-[11px]"></i>
                                    <span>Ver lista completa com todos os <?= count($modelsQuotas) ?> modelos retornados</span>
                                    <i class="fa-solid fa-chevron-down text-[10px] group-open:rotate-180 transition-transform ml-1"></i>
                                </summary>
                                <div class="mt-3 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-2.5 p-3 rounded-2xl bg-gray-950/60 border border-gray-800">
                                    <?php foreach ($modelsQuotas as $modKey => $modDetails): 
                                        // Pular modelos antigos abaixo de 3.8
                                        if (preg_match('/(2\.5|3\.5|3\.6|3\.7)/i', $modKey)) continue;

                                        $f = $modDetails['quotaInfo']['remainingFraction'] ?? 1.0;
                                        if (stripos($modKey, 'opus') !== false && !$accOpus['has_quota']) {
                                            $f = 0.0;
                                        }
                                        $p = round($f * 100);
                                        $col = $p > 50 ? 'text-emerald-400' : ($p > 20 ? 'text-amber-400' : 'text-red-400');
                                    ?>
                                        <div class="p-2.5 rounded-xl bg-gray-900/50 border border-gray-800/60 text-xs flex items-center justify-between">
                                            <div class="truncate mr-2">
                                                <div class="font-medium text-white truncate text-[11px]"><?= htmlspecialchars($modDetails['displayName'] ?? $modKey) ?></div>
                                                <div class="text-[9px] text-gray-500 font-mono truncate"><?= htmlspecialchars($modKey) ?></div>
                                            </div>
                                            <span class="font-bold font-mono text-[11px] <?= $col ?> whitespace-nowrap"><?= $p ?>%</span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </details>
                        <?php endif; ?>
                    </div>
                    </details>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
<!-- MODAL CONEXÃO EM VPS / MANUAL -->
<div id="vpsModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
    <div class="glass-card max-w-lg w-full p-6 rounded-3xl border border-gray-700 bg-gray-900 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-gray-800 pb-3">
            <div class="flex items-center space-x-2.5">
                <div class="w-8 h-8 rounded-xl bg-cyan-500/20 text-cyan-400 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-server"></i>
                </div>
                <h3 class="text-sm font-bold text-white">Conectar Conta Google via VPS</h3>
            </div>
            <button type="button" onclick="document.getElementById('vpsModal').classList.add('hidden')" class="text-gray-400 hover:text-white text-base">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <div class="space-y-3 text-xs text-gray-300">
            <p>Se o painel estiver em uma VPS remota e o redirecionamento automático não abrir a porta <code>51121</code> no seu computador:</p>
            <ol class="list-decimal list-inside space-y-1.5 text-gray-400 bg-gray-950/60 p-3 rounded-xl border border-gray-800">
                <li>Clique no botão abaixo para abrir a autorização Google em nova aba;</li>
                <li>Faça login e permita o acesso;</li>
                <li>Quando a página cair em <code>http://localhost:51121/...</code>, copie a URL inteira da barra de endereços do navegador e cole no campo abaixo.</li>
            </ol>

            <a href="<?= htmlspecialchars($gatewayExternalUrl) ?>/auth/antigravity/login" target="_blank" class="block w-full py-2.5 px-4 rounded-xl bg-gray-800 hover:bg-gray-700 text-cyan-400 text-center font-bold text-xs border border-gray-700 transition">
                <i class="fa-brands fa-google mr-1.5"></i> 1º Passo: Abrir Autorização Google
            </a>

            <form method="POST" action="" class="space-y-3 pt-2">
                <input type="hidden" name="action" value="manual_vps_connect">
                <div>
                    <label class="block font-semibold text-gray-300 mb-1">2º Passo: Cole a URL de redirecionamento ou o código:</label>
                    <textarea name="code_url" rows="3" placeholder="http://localhost:51121/oauth-callback?code=4/0A...&state=..." required class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono text-xs focus:outline-none focus:border-cyan-500"></textarea>
                </div>

                <div class="flex items-center justify-end space-x-2 pt-1">
                    <button type="button" onclick="document.getElementById('vpsModal').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-gray-800 hover:bg-gray-700 text-gray-300 text-xs font-semibold">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2 rounded-xl bg-cyan-600 hover:bg-cyan-500 text-white text-xs font-bold transition shadow-lg shadow-cyan-500/20">
                        Vincular Conta na VPS
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL IMPORTAÇÃO DE CONTAS -->
<div id="importModal" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
    <div class="glass-card max-w-lg w-full p-6 rounded-3xl border border-gray-700 bg-gray-900 shadow-2xl space-y-4">
        <div class="flex items-center justify-between border-b border-gray-800 pb-3">
            <div class="flex items-center space-x-2.5">
                <div class="w-8 h-8 rounded-xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-sm">
                    <i class="fa-solid fa-file-import"></i>
                </div>
                <h3 class="text-sm font-bold text-white">Importar Contas Google para o Pool</h3>
            </div>
            <button type="button" onclick="document.getElementById('importModal').classList.add('hidden')" class="text-gray-400 hover:text-white text-base">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>

        <form method="POST" action="" enctype="multipart/form-data" class="space-y-4 text-xs text-gray-300">
            <input type="hidden" name="action" value="import_accounts">
            <p class="text-gray-400">
                Selecione o arquivo <code>.json</code> exportado do seu localhost ou de outra instalação para restaurar todas as contas conectadas automaticamente:
            </p>

            <div class="p-3 rounded-xl bg-gray-950 border border-dashed border-gray-800 space-y-2">
                <label class="block font-semibold text-gray-300">Opção 1: Selecionar Arquivo .JSON</label>
                <input type="file" name="accounts_file" accept=".json,application/json" class="w-full text-xs text-gray-400 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-emerald-600 file:text-white hover:file:bg-emerald-500 cursor-pointer">
            </div>

            <div>
                <label class="block font-semibold text-gray-300 mb-1">Opção 2: Ou cole o JSON diretamente:</label>
                <textarea name="accounts_json" rows="4" placeholder="[ { &quot;email&quot;: &quot;exemplo@gmail.com&quot;, &quot;refresh_token&quot;: &quot;1//...&quot; } ]" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono text-[11px] focus:outline-none focus:border-emerald-500"></textarea>
            </div>

            <div class="flex items-center justify-end space-x-2 pt-2 border-t border-gray-800">
                <button type="button" onclick="document.getElementById('importModal').classList.add('hidden')" class="px-4 py-2 rounded-xl bg-gray-800 hover:bg-gray-700 text-gray-300 text-xs font-semibold">
                    Cancelar
                </button>
                <button type="submit" class="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition shadow-lg shadow-emerald-500/20">
                    <i class="fa-solid fa-cloud-arrow-up mr-1.5"></i> Importar Contas
                </button>
            </div>
        </form>
    </div>
</div>


<!-- SISTEMA DE SINCRONIZAÇÃO INTELIGENTE (SEGUNDO PLANO E AJAX) -->
<script>
function showBadgeStatus(html, type = 'info') {
    const badge = document.getElementById('bgSyncBadge');
    if (!badge) return;
    badge.className = 'flex items-center space-x-1.5 px-3 py-1.5 rounded-xl text-xs font-semibold backdrop-blur-md transition-all duration-300 shadow-md';
    if (type === 'loading') {
        badge.classList.add('bg-cyan-500/15', 'border', 'border-cyan-500/30', 'text-cyan-300', 'animate-pulse');
    } else if (type === 'success') {
        badge.classList.add('bg-emerald-500/15', 'border', 'border-emerald-500/30', 'text-emerald-300');
    } else if (type === 'error') {
        badge.classList.add('bg-red-500/15', 'border', 'border-red-500/30', 'text-red-300');
    }
    badge.innerHTML = html;
}

function hideBadgeStatus(delay = 3000) {
    setTimeout(() => {
        const badge = document.getElementById('bgSyncBadge');
        if (badge) {
            badge.classList.add('opacity-0');
            setTimeout(() => {
                badge.className = 'hidden';
                badge.classList.remove('opacity-0');
            }, 300);
        }
    }, delay);
}

// SISTEMA UNIFICADO DE SINCRONIZAÇÃO E VERIFICAÇÃO DE COTAS
let isVerifyingQuotas = false;

// Função única para verificar todas as contas (Google API Quotas + Probe Real Opus)
async function verifyAllQuotas() {
    if (isVerifyingQuotas) return false;
    isVerifyingQuotas = true;

    const btnSyncAll = document.getElementById('btnSyncAll');
    const syncAllIcon = document.getElementById('syncAllIcon');
    const syncAllText = document.getElementById('syncAllText');
    const btnMainList = document.querySelectorAll('.btn-verify-all-main');

    if (btnSyncAll) btnSyncAll.disabled = true;
    if (syncAllIcon) syncAllIcon.className = 'fa-solid fa-bolt fa-bounce text-[11px] text-amber-400';
    if (syncAllText) syncAllText.innerText = 'Verificando todas as contas...';

    btnMainList.forEach(b => {
        b.disabled = true;
        b.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin text-sm"></i><span>Verificando Cotas & Opus...</span>';
    });

    showBadgeStatus('<i class="fa-solid fa-circle-notch fa-spin text-[10px]"></i><span>Consultando Google API e testando Claude Opus em todas as contas...</span>', 'loading');

    try {
        const res = await fetch('accounts.php?ajax_verify_quotas=1', { cache: 'no-store' });
        const data = await res.json();

        if (data.success) {
            const opusCount = data.opusAvailableCount || 0;
            const total = data.totalChecked || 0;
            const tkAvail = (data.opusTotalAvailableTokens || 0).toLocaleString('pt-BR');
            const tkMax = (data.opusTotalMaxTokens || 0).toLocaleString('pt-BR');

            const msg = `Sucesso! ${total} conta(s) sincronizadas (${opusCount} com Claude Opus ativo - ${tkAvail} de ${tkMax} tk livres no pool)`;
            showBadgeStatus('<i class="fa-solid fa-circle-check text-[10px] text-emerald-400"></i><span>' + msg + '</span>', 'success');

            sessionStorage.setItem('last_pool_sync_ts', Date.now());
            setTimeout(() => window.location.reload(), 1300);
        } else {
            showBadgeStatus('<i class="fa-solid fa-circle-exclamation text-[10px]"></i><span>' + (data.error || 'Falha na verificação de cotas') + '</span>', 'error');
            resetAllButtons();
            hideBadgeStatus(6000);
        }
    } catch (err) {
        showBadgeStatus('<i class="fa-solid fa-circle-exclamation text-[10px]"></i><span>Erro de conexão com o servidor</span>', 'error');
        resetAllButtons();
        hideBadgeStatus(6000);
    }

    return false;
}

function resetAllButtons() {
    isVerifyingQuotas = false;
    const btnSyncAll = document.getElementById('btnSyncAll');
    const syncAllIcon = document.getElementById('syncAllIcon');
    const syncAllText = document.getElementById('syncAllText');
    const btnMainList = document.querySelectorAll('.btn-verify-all-main');

    if (btnSyncAll) btnSyncAll.disabled = false;
    if (syncAllIcon) syncAllIcon.className = 'fa-solid fa-arrows-rotate text-[11px]';
    if (syncAllText) syncAllText.innerText = 'Sincronizar Todas as Cotas';

    btnMainList.forEach(b => {
        b.disabled = false;
        b.innerHTML = '<i class="fa-solid fa-bolt text-sm"></i><span>Verificar Todas as Cotas</span>';
    });
}

// Função única para verificar uma única conta
async function verifyAccountQuotas(accId, btnEl) {
    if (!accId) return false;
    const oldHtml = btnEl ? btnEl.innerHTML : '';

    if (btnEl) {
        btnEl.disabled = true;
        btnEl.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-[11px]"></i> <span>Verificando...</span>';
    }

    showBadgeStatus('<i class="fa-solid fa-circle-notch fa-spin text-[10px]"></i><span>Verificando cotas e probe de Opus para a conta...</span>', 'loading');

    try {
        const res = await fetch(`accounts.php?ajax_verify_quotas=1&account_id=${accId}`, { cache: 'no-store' });
        const data = await res.json();

        if (data.success) {
            const resItem = (data.results && data.results[0]) ? data.results[0] : null;
            const hasOpus = resItem ? resItem.hasOpus : false;
            const availTk = resItem ? (resItem.availableTokens || 0).toLocaleString('pt-BR') : '0';

            if (btnEl) {
                btnEl.innerHTML = '<i class="fa-solid fa-check text-[11px] text-emerald-400"></i> <span class="text-emerald-300">Cotas Atualizadas!</span>';
            }

            const statusMsg = hasOpus 
                ? `Conta ${resItem?.email || ''}: Sincronizada com Claude Opus liberado (${availTk} tokens)!`
                : `Conta ${resItem?.email || ''}: Sincronizada com sucesso!`;

            showBadgeStatus('<i class="fa-solid fa-circle-check text-[10px] text-emerald-400"></i><span>' + statusMsg + '</span>', 'success');

            sessionStorage.setItem('last_pool_sync_ts', Date.now());
            setTimeout(() => window.location.reload(), 1200);
        } else {
            alert(data.error || 'Erro ao verificar cotas da conta.');
            if (btnEl) {
                btnEl.disabled = false;
                btnEl.innerHTML = oldHtml;
            }
            hideBadgeStatus(4000);
        }
    } catch (err) {
        alert('Erro ao se comunicar com o servidor: ' + err.message);
        if (btnEl) {
            btnEl.disabled = false;
            btnEl.innerHTML = oldHtml;
        }
        hideBadgeStatus(4000);
    }

    return false;
}

// Verificação inteligente e automática em segundo plano ao abrir a página
document.addEventListener('DOMContentLoaded', function() {
    const poolCard = document.getElementById('poolConsolidatedCard');
    const secondsSinceLastSync = poolCard ? parseInt(poolCard.dataset.secondsSinceSync || '99999') : 99999;
    const activeAccounts = poolCard ? parseInt(poolCard.dataset.activeAccounts || '0') : 0;
    const lastSessionSync = sessionStorage.getItem('last_pool_sync_ts');
    const now = Date.now();

    // Se já foi sincronizado nos últimos 90 segundos nesta aba, exibe selo em tempo real
    if (lastSessionSync && (now - parseInt(lastSessionSync)) < 90000) {
        showBadgeStatus('<i class="fa-solid fa-bolt text-[10px] text-emerald-400"></i><span>Cotas em tempo real</span>', 'success');
        hideBadgeStatus(3500);
        return;
    }

    // Se há contas ativas e a última sincronização no banco tem mais de 60 segundos:
    if (activeAccounts > 0 && secondsSinceLastSync > 60) {
        showBadgeStatus('<i class="fa-solid fa-arrows-rotate fa-spin text-[10px]"></i><span>Sincronizando cotas e probe de Opus automaticamente...</span>', 'loading');

        fetch('accounts.php?ajax_verify_quotas=1', { cache: 'no-store' })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    sessionStorage.setItem('last_pool_sync_ts', Date.now());
                    showBadgeStatus('<i class="fa-solid fa-circle-check text-[10px]"></i><span>Cotas atualizadas automaticamente!</span>', 'success');
                    setTimeout(() => window.location.reload(), 1300);
                } else {
                    hideBadgeStatus(2000);
                }
            })
            .catch(() => {
                hideBadgeStatus(2000);
            });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
