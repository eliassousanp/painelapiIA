<?php
require_once __DIR__ . '/../config/helpers.php';
requireLogin();

$db = getDB();
$userId = $_SESSION['user_id'];

$msg = '';
$error = '';

// Criar nova API Key ou Revogar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_key') {
        $keyName = trim($_POST['key_name'] ?? 'Nova Key');
        $newKey = generateApiKey();
        $stmt = $db->prepare("INSERT INTO api_keys (user_id, api_key, key_name) VALUES (?, ?, ?)");
        $stmt->execute([$userId, $newKey, $keyName]);
        $msg = 'Nova chave de API gerada com sucesso!';
    } elseif ($_POST['action'] === 'revoke_key') {
        $keyId = (int)($_POST['key_id'] ?? 0);
        $stmt = $db->prepare("UPDATE api_keys SET status = 'revoked' WHERE id = ? AND user_id = ?");
        $stmt->execute([$keyId, $userId]);
        $msg = 'Chave revogada com sucesso.';
    }
}

// Buscar dados atualizados do usuário
$stmtUser = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch();

$realtimeAccess = getSetting('realtime_access', 'all');
$canAccessRealtime = ($realtimeAccess === 'all' || isAdmin());

// Buscar API Keys do cliente
$stmtKeys = $db->prepare("SELECT * FROM api_keys WHERE user_id = ? ORDER BY id DESC");
$stmtKeys->execute([$userId]);
$apiKeys = $stmtKeys->fetchAll();

// Detectar as Base URLs atuais do servidor (suporta HTTP, HTTPS, Nginx, Cloudflare e VPS)
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['SERVER_PORT'] ?? 80) == 443
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
$protocol = $isHttps ? "https://" : "http://";
$domainName = $_SERVER['HTTP_HOST'] ?? 'localhost';
$appBaseDir = getAppBaseUrl();

$nodeGatewayBase = getGatewayPublicUrl();
$realtimeWsUrl = getGatewayWsUrl();

// Claude Code CLI usa base URL SEM /v1 (ele adiciona automaticamente)
$anthropicBaseUrl = $nodeGatewayBase;
// VS Code, Python, Node.js, cURL usam base URL COM /v1
$openaiBaseUrl = $nodeGatewayBase . '/v1';
$apiBaseUrl = $nodeGatewayBase . '/v1';

// Buscar modelos de IA ativos cadastrados
try {
    $stmtClientModels = $db->query("SELECT model_key, name, is_realtime, is_smart_routing, multiplier FROM models WHERE status = 'active' ORDER BY id ASC");
    $activeModels = $stmtClientModels->fetchAll();
    if (!$canAccessRealtime) {
        $activeModels = array_values(array_filter($activeModels, function($m) { return empty($m['is_realtime']); }));
    }
} catch (Exception $e) {
    $activeModels = [];
}

if (empty($activeModels)) {
    $activeModels = [
        ['model_key' => 'claude-sonnet-5-5', 'name' => 'Claude Sonnet 5.5'],
        ['model_key' => 'claude-opus-5-5', 'name' => 'Claude Opus 5.5 Thinking'],
        ['model_key' => 'claude-5-5-haiku', 'name' => 'Claude 5.5 Haiku'],
        ['model_key' => 'claude-3-7-sonnet', 'name' => 'Claude 3.7 Sonnet'],
        ['model_key' => 'claude-3-5-sonnet', 'name' => 'Claude 3.5 Sonnet']
    ];
}

// Buscar planos de recarga ativos para clientes
try {
    $stmtPlans = $db->query("SELECT * FROM plans WHERE status = 'active' AND target_audience IN ('all', 'client') ORDER BY price ASC");
    $activePlans = $stmtPlans->fetchAll();
} catch (Exception $e) {
    $activePlans = [];
}

if (empty($activePlans)) {
    $activePlans = [
        [
            'id' => 1,
            'name' => 'Starter Pack',
            'price' => 49.00,
            'tokens_amount' => 5000000,
            'badge' => 'Starter',
            'features' => "Claude Sonnet 5.5 & Haiku\nValidade Indeterminada\nSuporte Básico",
            'is_featured' => 0
        ],
        [
            'id' => 2,
            'name' => 'Pro Dev Ilimitado',
            'price' => 149.00,
            'tokens_amount' => 50000000,
            'badge' => 'Mais Popular',
            'features' => "Claude Sonnet 5.5, Opus 5.5 & Haiku\nValidade Indeterminada\nSuporte Prioritário VIP\nMúltiplas Chaves Ativas",
            'is_featured' => 1
        ],
        [
            'id' => 3,
            'name' => 'Team Enterprise',
            'price' => 299.00,
            'tokens_amount' => 200000000,
            'badge' => 'Enterprise',
            'features' => "Acesso Total a Todos os Modelos Claude 5.5\nValidade Indeterminada\nSuporte VIP 24/7\nChaves de API Ilimitadas",
            'is_featured' => 0
        ]
    ];
}

$firstApiKey = count($apiKeys) > 0 ? $apiKeys[0]['api_key'] : 'sk-ai-sua-chave-aqui';

$currentMode = getThemeMode();
if (isset($_COOKIE['theme_mode']) && in_array($_COOKIE['theme_mode'], ['light', 'dark'])) {
    $currentMode = $_COOKIE['theme_mode'];
}
$isDark = ($currentMode === 'dark');
$currentScheme = getThemeScheme();
if (isset($_COOKIE['theme_scheme'])) {
    $currentScheme = $_COOKIE['theme_scheme'];
}
?>
<!DOCTYPE html>
<html lang="pt-BR" class="<?= $isDark ? 'dark' : '' ?>" data-theme="<?= htmlspecialchars($currentMode) ?>" data-scheme="<?= htmlspecialchars($currentScheme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Área do Cliente - BRLove API</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class'
        }
    </script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .glass {
            background: rgba(17, 24, 39, 0.75);
            backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.08);
        }
        .glass-card {
            background: rgba(31, 41, 55, 0.5);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        .code-block {
            background: #0d1117;
            border: 1px solid #21262d;
        }
    </style>
    <link href="<?= getAppBaseUrl() ?>/assets/css/theme.css?v=<?= time() ?>" rel="stylesheet">
    <script>window.BRLOVE_BASE_URL = '<?= getAppBaseUrl() ?>';</script>
    <script src="<?= getAppBaseUrl() ?>/assets/js/theme.js"></script>
</head>
<body class="bg-gray-950 text-gray-100 font-sans antialiased min-h-screen pb-16">

    <!-- Top Navigation -->
    <nav class="glass border-b border-gray-800 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-3 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            <div class="flex items-center space-x-2 sm:space-x-3 min-w-0">
                <a href="<?= getAppBaseUrl() ?>/index.php" class="flex items-center space-x-2 shrink-0">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-indigo-600 via-purple-600 to-cyan-400 flex items-center justify-center font-bold text-lg text-white shadow-lg shadow-indigo-500/20">
                        <i class="fa-solid fa-heart-pulse"></i>
                    </div>
                    <span class="text-lg sm:text-xl font-bold text-white tracking-tight">BRLove<span class="text-indigo-400">API</span></span>
                </a>
                <span class="text-xs px-2.5 py-1 rounded-full bg-indigo-500/20 text-indigo-300 font-semibold border border-indigo-500/30 hidden sm:inline-block shrink-0">
                    Área do Cliente
                </span>
            </div>

            <div class="flex items-center space-x-1.5 sm:space-x-3 shrink-0">
                <span class="text-xs sm:text-sm text-emerald-400 font-mono hidden md:inline-flex items-center bg-emerald-500/10 px-3 py-1.5 rounded-lg border border-emerald-500/20">
                    <i class="fa-brands fa-whatsapp mr-1.5 text-base"></i> <?php echo htmlspecialchars($user['whatsapp']); ?>
                </span>
                
                <!-- Botão de Alternar Tema (Claro / Escuro) -->
                <button type="button" title="Alternar entre modo claro e escuro" class="btn-theme-toggle w-9 h-9 rounded-xl bg-gray-900 hover:bg-gray-800 border border-gray-800 flex items-center justify-center transition cursor-pointer shrink-0">
                    <i class="theme-toggle-icon fa-solid <?= $isDark ? 'fa-moon text-indigo-400' : 'fa-sun text-amber-400' ?> text-sm pointer-events-none"></i>
                </button>

                <?php if ($canAccessRealtime): ?>
                <a href="<?= getAppBaseUrl() ?>/client/realtime.php" title="Realtime API" class="text-xs px-2.5 sm:px-3.5 py-2 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white font-medium shadow-md shadow-indigo-500/20 transition flex items-center shrink-0">
                    <i class="fa-solid fa-waveform-lines sm:mr-1.5 text-purple-300"></i> <span class="hidden sm:inline">Realtime</span>
                </a>
                <?php endif; ?>
                <?php if (isAdmin()): ?>
                    <a href="<?= getAppBaseUrl() ?>/admin/index.php" title="Painel Admin" class="text-xs px-2.5 sm:px-3.5 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white font-medium shadow-md transition flex items-center shrink-0">
                        <i class="fa-solid fa-shield-halved sm:mr-1"></i> <span class="hidden sm:inline">Admin</span>
                    </a>
                <?php endif; ?>
                <a href="<?= getAppBaseUrl() ?>/logout.php" title="Sair do Painel" class="text-xs px-2.5 sm:px-3.5 py-2 rounded-xl glass hover:bg-gray-800 text-gray-300 transition flex items-center gap-1 shrink-0">
                    <span class="hidden sm:inline">Sair</span> <i class="fa-solid fa-right-from-bracket"></i>
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Container -->
    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-8">

        <?php if ($msg): ?>
            <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-sm flex items-center justify-between">
                <div class="flex items-center">
                    <i class="fa-solid fa-circle-check text-xl mr-3"></i>
                    <span><?php echo htmlspecialchars($msg); ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-emerald-200"><i class="fa-solid fa-xmark"></i></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="p-4 rounded-2xl bg-red-500/10 border border-red-500/30 text-red-400 text-sm flex items-center justify-between">
                <div class="flex items-center">
                    <i class="fa-solid fa-circle-exclamation text-xl mr-3"></i>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-red-400 hover:text-red-200"><i class="fa-solid fa-xmark"></i></button>
            </div>
        <?php endif; ?>

        <!-- Summary Cards Grid (Design Futurist & Ultra Informative) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5">
            <!-- Card 1: Saldo de Tokens -->
            <div class="glass p-6 rounded-3xl border border-indigo-500/20 relative overflow-hidden group hover:-translate-y-1 hover:border-indigo-500/50 hover:shadow-2xl hover:shadow-indigo-500/10 transition-all duration-300">
                <div class="absolute top-0 right-0 w-32 h-32 bg-indigo-500/10 rounded-full blur-2xl group-hover:bg-indigo-500/20 transition-all"></div>
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-bold text-indigo-300 uppercase tracking-wider flex items-center">
                        <i class="fa-solid fa-coins mr-1.5 text-indigo-400"></i> Saldo de Tokens
                    </span>
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-indigo-600 to-indigo-400 text-white flex items-center justify-center shadow-lg shadow-indigo-500/30 text-sm font-bold">
                        <i class="fa-solid fa-wallet"></i>
                    </div>
                </div>
                <div class="text-2xl sm:text-3xl font-extrabold text-white font-mono tracking-tight drop-shadow-sm">
                    <?php echo number_format($user['balance_tokens'], 0, ',', '.'); ?>
                </div>
                <div class="flex items-center justify-between mt-3 text-[11px]">
                    <span class="text-emerald-400 font-semibold flex items-center">
                        <i class="fa-solid fa-circle text-[7px] mr-1 text-emerald-400 animate-pulse"></i> Saldo Ativo
                    </span>
                    <span class="text-gray-400 font-mono">Pronto p/ Uso</span>
                </div>
                <div class="w-full bg-gray-900/80 h-2 rounded-full mt-2.5 overflow-hidden border border-gray-800 p-0.5">
                    <div class="bg-gradient-to-r from-indigo-500 via-cyan-400 to-emerald-400 h-full rounded-full transition-all duration-500" style="width: <?php echo min(100, max(15, round(($user['balance_tokens'] / max(1, $user['balance_tokens'] + $user['tokens_used'])) * 100))); ?>%"></div>
                </div>
            </div>

            <!-- Card 2: Tokens Consumidos -->
            <div class="glass p-6 rounded-3xl border border-cyan-500/20 relative overflow-hidden group hover:-translate-y-1 hover:border-cyan-500/50 hover:shadow-2xl hover:shadow-cyan-500/10 transition-all duration-300">
                <div class="absolute top-0 right-0 w-32 h-32 bg-cyan-500/10 rounded-full blur-2xl group-hover:bg-cyan-500/20 transition-all"></div>
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-bold text-cyan-300 uppercase tracking-wider flex items-center">
                        <i class="fa-solid fa-chart-line mr-1.5 text-cyan-400"></i> Tokens Consumidos
                    </span>
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-cyan-600 to-teal-400 text-white flex items-center justify-center shadow-lg shadow-cyan-500/30 text-sm font-bold">
                        <i class="fa-solid fa-bolt-lightning"></i>
                    </div>
                </div>
                <div class="text-2xl sm:text-3xl font-extrabold text-cyan-400 font-mono tracking-tight drop-shadow-sm">
                    <?php echo number_format($user['tokens_used'], 0, ',', '.'); ?>
                </div>
                <div class="flex items-center justify-between mt-3 text-[11px]">
                    <span class="text-gray-400">Total Utilizado</span>
                    <span class="text-cyan-300 font-mono font-semibold bg-cyan-500/10 px-2 py-0.5 rounded border border-cyan-500/20">
                        <?php echo number_format($user['tokens_used'], 0, ',', '.'); ?> tokens
                    </span>
                </div>
                <p class="text-[10px] text-gray-500 mt-2 flex items-center">
                    <i class="fa-solid fa-clock-rotate-left mr-1 text-cyan-400"></i> Atualizado a cada requisição
                </p>
            </div>

            <!-- Card 3: Chaves Ativas -->
            <div class="glass p-6 rounded-3xl border border-emerald-500/20 relative overflow-hidden group hover:-translate-y-1 hover:border-emerald-500/50 hover:shadow-2xl hover:shadow-emerald-500/10 transition-all duration-300">
                <div class="absolute top-0 right-0 w-32 h-32 bg-emerald-500/10 rounded-full blur-2xl group-hover:bg-emerald-500/20 transition-all"></div>
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-bold text-emerald-300 uppercase tracking-wider flex items-center">
                        <i class="fa-solid fa-key mr-1.5 text-emerald-400"></i> Chaves de API
                    </span>
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-emerald-600 to-emerald-400 text-white flex items-center justify-center shadow-lg shadow-emerald-500/30 text-sm font-bold">
                        <i class="fa-solid fa-shield-halved"></i>
                    </div>
                </div>
                <div class="flex items-baseline space-x-2">
                    <span class="text-2xl sm:text-3xl font-extrabold text-emerald-400 font-mono tracking-tight">
                        <?php echo count($apiKeys); ?>
                    </span>
                    <span class="text-xs font-semibold text-emerald-300">Ativa<?php echo count($apiKeys) > 1 ? 's' : ''; ?></span>
                </div>
                <div class="flex items-center justify-between mt-3 text-[11px]">
                    <span class="text-gray-400 font-mono truncate max-w-[120px]">
                        <?php echo !empty($apiKeys) ? substr($apiKeys[0]['api_key'], 0, 10) . '...' : 'Nenhuma key'; ?>
                    </span>
                    <a href="#chaves-section" class="text-emerald-400 hover:text-emerald-300 font-bold flex items-center hover:underline">
                        Gerenciar <i class="fa-solid fa-arrow-down ml-1 text-[10px]"></i>
                    </a>
                </div>
                <p class="text-[10px] text-gray-500 mt-2 flex items-center">
                    <i class="fa-solid fa-lock mr-1 text-emerald-400"></i> Criptografia SHA-256
                </p>
            </div>

            <!-- Card 4: Plano Atual & Recarga -->
            <div class="glass p-6 rounded-3xl border border-purple-500/20 relative overflow-hidden group hover:-translate-y-1 hover:border-purple-500/50 hover:shadow-2xl hover:shadow-purple-500/10 transition-all duration-300">
                <div class="absolute top-0 right-0 w-32 h-32 bg-purple-500/10 rounded-full blur-2xl group-hover:bg-purple-500/20 transition-all"></div>
                <div class="flex items-center justify-between mb-3">
                    <span class="text-xs font-bold text-purple-300 uppercase tracking-wider flex items-center">
                        <i class="fa-solid fa-crown mr-1.5 text-amber-400"></i> Plano Atual
                    </span>
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-purple-600 via-pink-500 to-amber-400 text-white flex items-center justify-center shadow-lg shadow-purple-500/30 text-sm font-bold">
                        <i class="fa-solid fa-gem"></i>
                    </div>
                </div>
                <div class="text-xl font-bold text-white truncate drop-shadow-sm">
                    <?php echo htmlspecialchars($user['plan_type']); ?>
                </div>
                <div class="mt-3">
                    <a href="#planos-section" class="w-full py-2.5 px-3 rounded-xl bg-gradient-to-r from-purple-600 via-indigo-600 to-cyan-500 hover:from-purple-500 hover:to-cyan-400 text-white font-bold text-xs shadow-lg shadow-purple-600/30 transition-all duration-300 flex items-center justify-center cursor-pointer group-hover:scale-[1.02]">
                        <i class="fa-solid fa-bolt mr-1.5 text-amber-300 animate-bounce"></i> Escolher Plano / Recarregar
                    </a>
                </div>
            </div>
        </div>

        <?php if ($canAccessRealtime): ?>
        <!-- Banner Destaque Realtime -->
        <div class="glass p-6 rounded-3xl border border-indigo-500/30 bg-gradient-to-r from-indigo-950/50 via-purple-950/30 to-gray-900/80 relative overflow-hidden flex flex-col md:flex-row items-center justify-between gap-6 shadow-xl">
            <div class="space-y-2">
                <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-indigo-500/20 text-indigo-300 text-xs font-bold border border-indigo-500/30">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-ping"></span>
                    <span>NOVO • WEBSOCKET REALTIME</span>
                </div>
                <h3 class="text-lg sm:text-xl font-extrabold text-white flex items-center gap-2">
                    <i class="fa-solid fa-waveform-lines text-indigo-400"></i> Console Realtime — IA com Voz & Streaming
                </h3>
                <p class="text-xs text-gray-400 max-w-2xl leading-relaxed">
                    Experimente a comunicação bidirecional em tempo real via WebSocket com voz neural realista (Francisca, Antônio, Thalita e inglês), streaming de tokens e push-to-talk.
                </p>
            </div>
            <a href="<?= getAppBaseUrl() ?>/client/realtime.php" class="shrink-0 px-5 py-3 rounded-2xl bg-gradient-to-r from-indigo-600 via-purple-600 to-cyan-500 hover:from-indigo-500 hover:to-cyan-400 text-white font-bold text-xs sm:text-sm shadow-lg shadow-indigo-500/30 transition-all duration-300 flex items-center gap-2 hover:scale-105">
                <i class="fa-solid fa-headset text-sm"></i> Abrir Console Realtime
            </a>
        </div>
        <?php endif; ?>

        <!-- 🔥 SEÇÃO PRINCIPAL: GUIA DE ENDPOINTS & CONEXÃO DE MODELOS -->
        <div class="glass p-6 sm:p-8 rounded-3xl border border-gray-800 space-y-6 shadow-2xl relative overflow-hidden">
            <div class="absolute top-0 right-0 w-80 h-80 bg-indigo-600/10 rounded-full blur-3xl -z-10"></div>
            
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-gray-800 pb-6">
                <div>
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <i class="fa-solid fa-network-wired text-cyan-400 mr-2.5"></i> Endpoints & Guia de Conexão da API
                    </h2>
                    <p class="text-xs text-gray-400 mt-1">Conecte seus projetos, scripts ou extensoes do VS Code (Cline, Roo Code, Continue, OpenCode) aos nossos endpoints.</p>
                </div>
                <div class="flex items-center space-x-2">
                    <span class="px-3 py-1.5 rounded-xl bg-emerald-500/10 text-emerald-400 text-xs font-semibold border border-emerald-500/20">
                        <i class="fa-solid fa-circle text-[8px] mr-1.5 text-emerald-400 animate-pulse"></i> OpenAI & Anthropic Compatible
                    </span>
                </div>
            </div>

            <!-- Box de Base URLs Inteligente (Dual Format) -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Base URL para Claude Code CLI & Anthropic SDK -->
                <div class="bg-gray-900/90 p-5 rounded-2xl border border-purple-500/30 flex flex-col justify-between gap-3 shadow-inner relative overflow-hidden group hover:border-purple-500/50 transition">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px] font-bold text-purple-400 uppercase tracking-wider flex items-center">
                                <i class="fa-solid fa-terminal mr-1.5"></i> Base URL (Claude Code CLI / Anthropic SDK)
                            </span>
                            <span class="px-2 py-0.5 rounded bg-purple-500/20 text-purple-300 text-[10px] font-mono">x-api-key</span>
                        </div>
                        <div class="text-sm sm:text-base font-mono text-white font-bold select-all flex items-center space-x-2 pt-1">
                            <i class="fa-solid fa-link text-purple-400 text-xs"></i>
                            <span class="truncate" id="baseUrlAnthropicText"><?php echo $anthropicBaseUrl; ?></span>
                        </div>
                    </div>
                    <button onclick="copyToClipboard('<?php echo $anthropicBaseUrl; ?>', 'Base URL Anthropic copiada!')" class="w-full py-2.5 rounded-xl bg-purple-600/30 hover:bg-purple-600/50 text-purple-200 border border-purple-500/40 hover:border-purple-400 font-bold text-xs transition flex items-center justify-center cursor-pointer">
                        <i class="fa-solid fa-copy mr-2"></i> Copiar Base URL Anthropic
                    </button>
                </div>

                <!-- Base URL para OpenAI SDK / VS Code (Cline, Roo Code, Continue, Cursor) -->
                <div class="bg-gray-900/90 p-5 rounded-2xl border border-indigo-500/30 flex flex-col justify-between gap-3 shadow-inner relative overflow-hidden group hover:border-indigo-500/50 transition">
                    <div class="space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-[11px] font-bold text-indigo-400 uppercase tracking-wider flex items-center">
                                <i class="fa-solid fa-code mr-1.5"></i> Base URL (OpenAI Format / VS Code / Cursor)
                            </span>
                            <span class="px-2 py-0.5 rounded bg-indigo-500/20 text-indigo-300 text-[10px] font-mono">Bearer Token</span>
                        </div>
                        <div class="text-sm sm:text-base font-mono text-white font-bold select-all flex items-center space-x-2 pt-1">
                            <i class="fa-solid fa-link text-indigo-400 text-xs"></i>
                            <span class="truncate" id="baseUrlOpenaiText"><?php echo $openaiBaseUrl; ?></span>
                        </div>
                    </div>
                    <button onclick="copyToClipboard('<?php echo $openaiBaseUrl; ?>', 'Base URL OpenAI copiada!')" class="w-full py-2.5 rounded-xl bg-indigo-600/30 hover:bg-indigo-600/50 text-indigo-200 border border-indigo-500/40 hover:border-indigo-400 font-bold text-xs transition flex items-center justify-center cursor-pointer">
                        <i class="fa-solid fa-copy mr-2"></i> Copiar Base URL OpenAI (/v1)
                    </button>
                </div>
            </div>

            <!-- Grid de Endpoints Disponíveis -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
                <div class="glass-card p-4 rounded-xl border border-gray-800 hover:border-indigo-500/40 transition cursor-pointer" onclick="switchTab('curl')">
                    <div class="flex items-center space-x-2 text-indigo-400 text-xs font-bold uppercase tracking-wider mb-2">
                        <span class="px-2 py-0.5 rounded bg-indigo-500/20 font-mono">POST</span>
                        <span>Chat Completions</span>
                    </div>
                    <code class="text-xs font-mono text-gray-200 block truncate select-all">/v1/chat/completions</code>
                    <p class="text-[11px] text-gray-400 mt-2">Padrão OpenAI usado por VSCode, Cursor, LangChain e bibliotecas padrão.</p>
                </div>

                <div class="glass-card p-4 rounded-xl border border-gray-800 hover:border-purple-500/40 transition cursor-pointer" onclick="switchTab('claudecode')">
                    <div class="flex items-center space-x-2 text-purple-400 text-xs font-bold uppercase tracking-wider mb-2">
                        <span class="px-2 py-0.5 rounded bg-purple-500/20 font-mono">POST</span>
                        <span>Anthropic Messages</span>
                    </div>
                    <code class="text-xs font-mono text-gray-200 block truncate select-all">/v1/messages</code>
                    <p class="text-[11px] text-gray-400 mt-2">Compatível nativamente com o SDK oficial da Anthropic e Claude Code CLI.</p>
                </div>

                <div class="glass-card p-4 rounded-xl border border-amber-500/30 hover:border-amber-500/60 transition bg-amber-500/5 cursor-pointer" onclick="switchTab('audio')">
                    <div class="flex items-center space-x-2 text-amber-400 text-xs font-bold uppercase tracking-wider mb-2">
                        <span class="px-2 py-0.5 rounded bg-amber-500/20 font-mono">POST</span>
                        <span>Transcrição de Áudio</span>
                    </div>
                    <code class="text-xs font-mono text-amber-300 block truncate select-all">/v1/audio/transcriptions</code>
                    <p class="text-[11px] text-gray-400 mt-2">Áudio ➔ Texto multimodal (Gemini/Whisper). Envie MP3, WAV ou OGG em base64.</p>
                </div>

                <div class="glass-card p-4 rounded-xl border border-gray-800 hover:border-emerald-500/40 transition">
                    <div class="flex items-center space-x-2 text-emerald-400 text-xs font-bold uppercase tracking-wider mb-2">
                        <span class="px-2 py-0.5 rounded bg-emerald-500/20 font-mono">GET</span>
                        <span>Listar Modelos</span>
                    </div>
                    <code class="text-xs font-mono text-gray-200 block truncate select-all">/v1/models</code>
                    <p class="text-[11px] text-gray-400 mt-2">Retorna a lista completa de modelos disponíveis na API.</p>
                </div>

                <?php if ($canAccessRealtime): ?>
                <div class="glass-card p-4 rounded-xl border border-rose-500/30 hover:border-rose-500/60 transition bg-rose-500/5 cursor-pointer" onclick="switchTab('realtime')">
                    <div class="flex items-center space-x-2 text-rose-400 text-xs font-bold uppercase tracking-wider mb-2">
                        <span class="px-2 py-0.5 rounded bg-rose-500/20 font-mono">WS</span>
                        <span>Realtime Voice</span>
                    </div>
                    <code class="text-xs font-mono text-rose-300 block truncate select-all">/v1/realtime</code>
                    <p class="text-[11px] text-gray-400 mt-2">Voz bidirecional em tempo real compatível com OpenAI e Azure.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Modelos Disponíveis (Seletor Interativo) -->
            <?php 
                $defaultClientModel = !empty($activeModels) ? $activeModels[0]['model_key'] : 'claude-sonnet-5.5';
            ?>
            <div class="bg-gray-900/50 p-5 rounded-2xl border border-gray-800/80 space-y-3">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-gray-800/60 pb-3">
                    <span class="text-xs font-semibold text-gray-300 uppercase tracking-wider flex items-center">
                        <i class="fa-solid fa-microchip text-indigo-400 mr-2 text-sm"></i> Clique em um modelo para personalizar os tutoriais abaixo:
                    </span>
                    <span class="text-xs text-indigo-300 font-mono bg-indigo-500/10 px-3 py-1 rounded-lg border border-indigo-500/20">
                        Modelo selecionado: <strong id="selected-model-name" class="text-cyan-400 font-bold"><?= htmlspecialchars($defaultClientModel) ?></strong>
                    </span>
                </div>
                <div class="flex flex-wrap gap-2 pt-1">
                    <?php foreach ($activeModels as $m): 
                        $key = htmlspecialchars($m['model_key']);
                        $badge = $key;
                        if (strpos($key, 'sonnet-5') !== false || $key === 'claude-sonnet-5.5') { $badge = '🔥 ' . $key; }
                        elseif (strpos($key, 'opus-5') !== false || $key === 'claude-opus-5.5') { $badge = '👑 ' . $key; }
                        elseif (strpos($key, 'haiku') !== false) { $badge = '⚡ ' . $key; }
                        elseif ($key === 'claude-3-7-sonnet') { $badge = '🚀 ' . $key; }
                        $mult = (float)($m['multiplier'] ?? 1.0);
                        $isSmart = !empty($m['is_smart_routing']);
                    ?>
                        <button type="button" 
                                onclick="selectModel('<?= $key ?>')" 
                                data-model="<?= $key ?>"
                                data-is-realtime="<?= !empty($m['is_realtime']) ? '1' : '0' ?>" 
                                class="model-select-btn px-3.5 py-2 rounded-xl border text-xs font-mono transition cursor-pointer flex items-center space-x-1.5 <?= ($key === $defaultClientModel) ? 'bg-indigo-600/30 text-indigo-200 border-indigo-500/50 shadow-lg shadow-indigo-500/20 font-bold ring-2 ring-indigo-500/40' : 'bg-gray-800/60 text-gray-300 border-gray-700 hover:bg-gray-800 hover:text-white' ?>">
                            <span><?= $badge ?></span>
                            <?php if ($isSmart): ?>
                                <span class="px-1.5 py-0.5 rounded-full text-[9px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">⚡ Auto</span>
                            <?php elseif ($mult != 1.0): ?>
                                <span class="px-1.5 py-0.5 rounded-full text-[9px] font-bold <?= $mult > 1.5 ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'bg-cyan-500/20 text-cyan-300 border border-cyan-500/30' ?>"><?= number_format($mult, 1) ?>x</span>
                            <?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                    <!-- Modelo de Transcrição de Áudio (Multimodal) -->
                    <button type="button" 
                            onclick="switchTab('audio')" 
                            class="px-3.5 py-2 rounded-xl border text-xs font-mono transition cursor-pointer flex items-center space-x-1.5 bg-amber-500/10 text-amber-300 border-amber-500/30 hover:bg-amber-500/20 hover:border-amber-400 shadow-sm">
                        <span>🎙️ whisper-1</span>
                        <span class="px-1.5 py-0.5 rounded-full text-[9px] font-bold bg-amber-500/20 text-amber-200 border border-amber-500/40">Áudio ➔ Texto</span>
                    </button>
                </div>
            </div>

            <!-- Abas de Exemplos de Código Interativos -->
            <div>
                <div class="flex items-center space-x-2 border-b border-gray-800 pb-3 overflow-x-auto">
                    <button onclick="switchTab('vscode')" id="tab-vscode" class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold transition bg-indigo-600 text-white shadow-lg">
                        <i class="fa-solid fa-code mr-1.5"></i> VS Code (Cline / Roo Code / Continue / OpenCode)
                    </button>
                    <button onclick="switchTab('curl')" id="tab-curl" class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold text-gray-400 hover:text-white transition">
                        <i class="fa-solid fa-terminal mr-1.5"></i> cURL (Terminal)
                    </button>
                    <button onclick="switchTab('python')" id="tab-python" class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold text-gray-400 hover:text-white transition">
                        <i class="fa-brands fa-python mr-1.5"></i> Python (OpenAI SDK)
                    </button>
                    <button onclick="switchTab('node')" id="tab-node" class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold text-gray-400 hover:text-white transition">
                        <i class="fa-brands fa-node-js mr-1.5"></i> Node.js / JavaScript
                    </button>
                    <button onclick="switchTab('claudecode')" id="tab-claudecode" class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold text-gray-400 hover:text-white transition">
                        <i class="fa-solid fa-terminal mr-1.5 text-purple-400"></i> Claude Code CLI
                    </button>
                    <button onclick="switchTab('audio')" id="tab-audio" class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold text-gray-400 hover:text-white transition flex items-center border border-amber-500/20 bg-amber-500/5 hover:bg-amber-500/10 shrink-0">
                        <i class="fa-solid fa-microphone mr-1.5 text-amber-400"></i> Transcrição de Áudio
                    </button>
                    <?php if ($canAccessRealtime): ?>
                    <button onclick="switchTab('realtime')" id="tab-realtime" style="display: none;" class="tab-btn px-4 py-2 rounded-xl text-xs font-semibold text-gray-400 hover:text-white transition flex items-center border border-rose-500/20 bg-rose-500/5 hover:bg-rose-500/10">
                        <i class="fa-solid fa-microphone-lines mr-1.5 text-rose-400 animate-pulse"></i> Realtime Voice (WebSocket)
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Conteúdo Tab: VS Code Extensões -->
                <div id="tab-content-vscode" class="tab-content pt-4 space-y-4">
                    <div class="bg-indigo-950/40 p-5 rounded-2xl border border-indigo-500/30 space-y-4">
                        <div class="font-bold text-indigo-300 flex items-center text-sm">
                            <i class="fa-solid fa-wand-magic-sparkles mr-2 text-indigo-400"></i> Como configurar no Cline, Roo Code, Continue.dev, Cursor ou OpenCode:
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                            <!-- Passo 1 -->
                            <div class="bg-gray-900/90 p-4 rounded-xl border border-gray-800 space-y-1.5">
                                <div class="text-indigo-400 font-bold flex items-center">
                                    <span class="w-5 h-5 rounded-full bg-indigo-500/20 text-indigo-300 inline-flex items-center justify-center mr-2 text-[11px]">1</span> Provedor de API
                                </div>
                                <p class="text-gray-300">Selecione <code class="text-cyan-300 font-mono font-bold">OpenAI Compatible</code> ou <code class="text-cyan-300 font-mono font-bold">Custom OpenAI</code>.</p>
                            </div>

                            <!-- Passo 2 -->
                            <div class="bg-gray-900/90 p-4 rounded-xl border border-gray-800 space-y-1.5">
                                <div class="text-indigo-400 font-bold flex items-center justify-between">
                                    <div class="flex items-center">
                                        <span class="w-5 h-5 rounded-full bg-indigo-500/20 text-indigo-300 inline-flex items-center justify-center mr-2 text-[11px]">2</span> Base URL
                                    </div>
                                    <button onclick="copyToClipboard('<?php echo $openaiBaseUrl; ?>', 'Base URL copiada!')" class="text-[10px] px-2 py-0.5 rounded bg-gray-800 hover:bg-gray-700 text-gray-300 transition">
                                        <i class="fa-solid fa-copy mr-1"></i> Copiar
                                    </button>
                                </div>
                                <code class="text-cyan-300 font-mono select-all block truncate bg-gray-950 p-1.5 rounded border border-gray-800/80"><?php echo $openaiBaseUrl; ?></code>
                            </div>

                            <!-- Passo 3 -->
                            <div class="bg-gray-900/90 p-4 rounded-xl border border-gray-800 space-y-1.5">
                                <div class="text-indigo-400 font-bold flex items-center justify-between">
                                    <div class="flex items-center">
                                        <span class="w-5 h-5 rounded-full bg-indigo-500/20 text-indigo-300 inline-flex items-center justify-center mr-2 text-[11px]">3</span> API Key
                                    </div>
                                    <button onclick="copyToClipboard('<?php echo $firstApiKey; ?>', 'API Key copiada!')" class="text-[10px] px-2 py-0.5 rounded bg-gray-800 hover:bg-gray-700 text-gray-300 transition">
                                        <i class="fa-solid fa-copy mr-1"></i> Copiar
                                    </button>
                                </div>
                                <code class="text-cyan-300 font-mono select-all block truncate bg-gray-950 p-1.5 rounded border border-gray-800/80"><?php echo $firstApiKey; ?></code>
                            </div>

                            <!-- Passo 4 -->
                            <div class="bg-gray-900/90 p-4 rounded-xl border border-gray-800 space-y-1.5">
                                <div class="text-indigo-400 font-bold flex items-center">
                                    <span class="w-5 h-5 rounded-full bg-indigo-500/20 text-indigo-300 inline-flex items-center justify-center mr-2 text-[11px]">4</span> Model ID
                                </div>
                                <code id="model-display-vscode" class="text-cyan-300 font-mono font-bold block truncate bg-gray-950 p-1.5 rounded border border-gray-800/80">claude-3-5-sonnet</code>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Conteúdo Tab: cURL -->
                <div id="tab-content-curl" class="tab-content pt-4 hidden">
                    <div class="code-block p-4 rounded-xl relative font-mono text-xs text-gray-200 overflow-x-auto">
                        <button onclick="copyToClipboard(document.getElementById('code-curl-text').innerText, 'cURL copiado!')" class="absolute top-3 right-3 px-3 py-1.5 rounded-lg bg-gray-800 hover:bg-gray-700 text-white text-[11px] transition">
                            <i class="fa-solid fa-copy mr-1"></i> Copiar
                        </button>
                        <pre id="code-curl-text">curl <?php echo $apiBaseUrl; ?>/chat/completions \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer <?php echo $firstApiKey; ?>" \
  -d '{
    "model": "claude-3-5-sonnet",
    "messages": [
      {"role": "user", "content": "Olá, me dê um exemplo de código PHP limpo!"}
    ]
  }'</pre>
                    </div>
                </div>

                <!-- Conteúdo Tab: Python -->
                <div id="tab-content-python" class="tab-content pt-4 hidden">
                    <div class="code-block p-4 rounded-xl relative font-mono text-xs text-gray-200 overflow-x-auto">
                        <button onclick="copyToClipboard(document.getElementById('code-python-text').innerText, 'Código Python copiado!')" class="absolute top-3 right-3 px-3 py-1.5 rounded-lg bg-gray-800 hover:bg-gray-700 text-white text-[11px] transition">
                            <i class="fa-solid fa-copy mr-1"></i> Copiar
                        </button>
                        <pre id="code-python-text">from openai import OpenAI

client = OpenAI(
    base_url="<?php echo $apiBaseUrl; ?>",
    api_key="<?php echo $firstApiKey; ?>"
)

response = client.chat.completions.create(
    model="claude-3-5-sonnet",
    messages=[
        {"role": "user", "content": "Explique a teoria da relatividade em 2 frases."}
    ]
)

print(response.choices[0].message.content)</pre>
                    </div>
                </div>

                <!-- Conteúdo Tab: Node.js -->
                <div id="tab-content-node" class="tab-content pt-4 hidden">
                    <div class="code-block p-4 rounded-xl relative font-mono text-xs text-gray-200 overflow-x-auto">
                        <button onclick="copyToClipboard(document.getElementById('code-node-text').innerText, 'Código Node.js copiado!')" class="absolute top-3 right-3 px-3 py-1.5 rounded-lg bg-gray-800 hover:bg-gray-700 text-white text-[11px] transition">
                            <i class="fa-solid fa-copy mr-1"></i> Copiar
                        </button>
                        <pre id="code-node-text">import OpenAI from 'openai';

const openai = new OpenAI({
  baseURL: '<?php echo $apiBaseUrl; ?>',
  apiKey: '<?php echo $firstApiKey; ?>',
});

async function main() {
  const completion = await openai.chat.completions.create({
    model: 'claude-3-5-sonnet',
    messages: [{ role: 'user', content: 'Escreva um hello world em TypeScript' }],
  });

  console.log(completion.choices[0].message.content);
}

main();</pre>
                    </div>
                </div>

                <!-- Conteúdo Tab: Claude Code CLI -->
                <div id="tab-content-claudecode" class="tab-content pt-4 space-y-4 hidden">
                    <div class="bg-purple-950/40 p-5 rounded-2xl border border-purple-500/30 text-xs text-purple-200 space-y-4">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-purple-500/20 pb-3">
                            <div>
                                <div class="font-bold text-purple-300 flex items-center text-sm">
                                    <i class="fa-solid fa-terminal mr-2 text-purple-400"></i> Como configurar no Claude Code CLI oficial:
                                </div>
                                <p class="text-xs text-gray-300 mt-1">Selecione o seu terminal para visualizar os comandos ajustados para o seu sistema:</p>
                            </div>

                            <!-- Sub-abas de Terminal para Claude Code CLI -->
                            <div class="flex items-center space-x-1.5 bg-gray-900/90 p-1.5 rounded-xl border border-gray-800 shrink-0">
                                <button type="button" onclick="switchCliTerminal('ps')" id="cli-tab-ps" class="cli-term-btn px-3 py-1.5 rounded-lg text-xs font-semibold transition bg-blue-600 text-white shadow-md flex items-center">
                                    <i class="fa-brands fa-windows mr-1.5 text-blue-300"></i> PowerShell
                                </button>
                                <button type="button" onclick="switchCliTerminal('cmd')" id="cli-tab-cmd" class="cli-term-btn px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-400 hover:text-white transition flex items-center">
                                    <i class="fa-solid fa-terminal mr-1.5 text-amber-400"></i> CMD
                                </button>
                                <button type="button" onclick="switchCliTerminal('bash')" id="cli-tab-bash" class="cli-term-btn px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-400 hover:text-white transition flex items-center">
                                    <i class="fa-brands fa-linux mr-1.5 text-emerald-400"></i> Bash
                                </button>
                            </div>
                        </div>

                        <!-- 1. Windows PowerShell -->
                        <div id="cli-content-ps" class="cli-term-content space-y-2">
                            <div class="code-block p-4 rounded-xl relative font-mono text-xs text-gray-200 overflow-x-auto bg-gray-950 border border-gray-800">
                                <button onclick="copyToClipboard(document.getElementById('code-claudecode-ps').innerText, 'Comando PowerShell copiado!')" class="absolute top-3 right-3 px-3 py-1.5 rounded-lg bg-gray-800 hover:bg-gray-700 text-white text-[11px] transition flex items-center">
                                    <i class="fa-solid fa-copy mr-1.5"></i> Copiar
                                </button>
                                <pre id="code-claudecode-ps">$env:ANTHROPIC_BASE_URL="<?php echo $anthropicBaseUrl; ?>"
$env:ANTHROPIC_API_KEY="<?php echo $firstApiKey; ?>"
claude --model claude-3-5-sonnet</pre>
                            </div>
                        </div>

                        <!-- 2. Windows CMD -->
                        <div id="cli-content-cmd" class="cli-term-content space-y-2 hidden">
                            <div class="code-block p-4 rounded-xl relative font-mono text-xs text-gray-200 overflow-x-auto bg-gray-950 border border-gray-800">
                                <button onclick="copyToClipboard(document.getElementById('code-claudecode-cmd').innerText, 'Comando CMD copiado!')" class="absolute top-3 right-3 px-3 py-1.5 rounded-lg bg-gray-800 hover:bg-gray-700 text-white text-[11px] transition flex items-center">
                                    <i class="fa-solid fa-copy mr-1.5"></i> Copiar
                                </button>
                                <pre id="code-claudecode-cmd">set ANTHROPIC_BASE_URL=<?php echo $anthropicBaseUrl; ?>

set ANTHROPIC_API_KEY=<?php echo $firstApiKey; ?>

claude --model claude-3-5-sonnet</pre>
                            </div>
                        </div>

                        <!-- 3. Linux / macOS / Bash -->
                        <div id="cli-content-bash" class="cli-term-content space-y-2 hidden">
                            <div class="code-block p-4 rounded-xl relative font-mono text-xs text-gray-200 overflow-x-auto bg-gray-950 border border-gray-800">
                                <button onclick="copyToClipboard(document.getElementById('code-claudecode-bash').innerText, 'Comando Bash copiado!')" class="absolute top-3 right-3 px-3 py-1.5 rounded-lg bg-gray-800 hover:bg-gray-700 text-white text-[11px] transition flex items-center">
                                    <i class="fa-solid fa-copy mr-1.5"></i> Copiar
                                </button>
                                <pre id="code-claudecode-bash">export ANTHROPIC_BASE_URL="<?php echo $anthropicBaseUrl; ?>"
export ANTHROPIC_API_KEY="<?php echo $firstApiKey; ?>"
claude --model claude-3-5-sonnet</pre>
                            </div>
                        </div>

                        <div class="p-3.5 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-200 text-xs space-y-1.5 mt-3">
                            <div class="font-bold flex items-center text-amber-300">
                                <i class="fa-solid fa-lightbulb mr-2 text-amber-400"></i> Dica importante sobre seleção de modelo:
                            </div>
                            <ul class="list-disc list-inside space-y-1 text-gray-300 pl-1">
                                <li>No terminal (PowerShell, CMD ou Bash), inicie o Claude passando o modelo desejado via parâmetro: <code class="text-amber-300 font-mono">claude --model <span class="selected-model-text">claude-3-5-sonnet</span></code>.</li>
                                <li>O comando <code class="text-amber-300 font-mono">/model</code> só funciona <strong>dentro do chat interativo</strong> do Claude após ser iniciado.</li>
                            </ul>
                        </div>
                    </div>
                </div>
                <!-- Conteúdo Tab: Realtime Voice (WebSocket) -->
                <?php if ($canAccessRealtime): ?>
                <div id="tab-content-realtime" class="tab-content pt-4 space-y-5 hidden">
                    <div class="bg-gradient-to-br from-rose-950/40 via-purple-950/30 to-gray-950 p-6 rounded-2xl border border-rose-500/30 space-y-6">
                        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 border-b border-rose-500/20 pb-4">
                            <div>
                                <div class="font-bold text-rose-300 flex items-center text-base">
                                    <i class="fa-solid fa-microphone-lines mr-2.5 text-rose-400 text-lg"></i> API de Voz em Tempo Real (WebSocket)
                                </div>
                                <p class="text-xs text-gray-300 mt-1">
                                    Conectividade bidirecional por WebSocket de ultra baixa latência com suporte a áudio neural, comandos de voz (Function Calling) e compatibilidade total com o protocolo OpenAI e Azure Realtime.
                                </p>
                            </div>
                            <div class="flex items-center space-x-2 shrink-0">
                                <a href="<?= getAppBaseUrl() ?>/client/realtime.php" target="_blank" class="px-4 py-2 rounded-xl bg-gradient-to-r from-rose-600 to-purple-600 hover:from-rose-500 hover:to-purple-500 text-white font-bold text-xs shadow-lg shadow-rose-600/30 transition flex items-center">
                                    <i class="fa-solid fa-headset mr-1.5"></i> Abrir Console de Teste
                                </a>
                            </div>
                        </div>

                        <!-- 4 Passos de Conexão Rápida -->
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
                            <div class="bg-gray-900/90 p-4 rounded-xl border border-gray-800 space-y-1.5">
                                <div class="text-rose-400 font-bold flex items-center justify-between">
                                    <span><i class="fa-solid fa-network-wired mr-1.5"></i> Protocolo</span>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-rose-500/20 text-rose-300">WebSocket</span>
                                </div>
                                <code class="text-rose-300 font-mono select-all block truncate bg-gray-950 p-1.5 rounded border border-gray-800/80">WSS / WS</code>
                            </div>

                            <div class="bg-gray-900/90 p-4 rounded-xl border border-gray-800 space-y-1.5">
                                <div class="text-rose-400 font-bold flex items-center justify-between">
                                    <span><i class="fa-solid fa-link mr-1.5"></i> Endpoint WS</span>
                                    <button onclick="copyToClipboard('<?php echo $realtimeWsUrl; ?>', 'URL WebSocket copiada!')" class="text-[10px] px-2 py-0.5 rounded bg-gray-800 hover:bg-gray-700 text-gray-300 transition">
                                        <i class="fa-solid fa-copy mr-1"></i> Copiar
                                    </button>
                                </div>
                                <code class="text-cyan-300 font-mono select-all block truncate bg-gray-950 p-1.5 rounded border border-gray-800/80"><?php echo $realtimeWsUrl; ?></code>
                            </div>

                            <div class="bg-gray-900/90 p-4 rounded-xl border border-gray-800 space-y-1.5">
                                <div class="text-rose-400 font-bold flex items-center justify-between">
                                    <span><i class="fa-solid fa-key mr-1.5"></i> Autenticação</span>
                                    <button onclick="copyToClipboard('<?php echo $firstApiKey; ?>', 'API Key copiada!')" class="text-[10px] px-2 py-0.5 rounded bg-gray-800 hover:bg-gray-700 text-gray-300 transition">
                                        <i class="fa-solid fa-copy mr-1"></i> Copiar
                                    </button>
                                </div>
                                <code class="text-cyan-300 font-mono select-all block truncate bg-gray-950 p-1.5 rounded border border-gray-800/80">?api-key=<?php echo $firstApiKey; ?></code>
                            </div>

                            <div class="bg-gray-900/90 p-4 rounded-xl border border-gray-800 space-y-1.5">
                                <div class="text-rose-400 font-bold flex items-center justify-between">
                                    <span><i class="fa-solid fa-robot mr-1.5"></i> Modelo de Voz</span>
                                    <span class="text-[10px] px-1.5 py-0.5 rounded bg-purple-500/20 text-purple-300">Ativo</span>
                                </div>
                                <code class="text-purple-300 font-mono font-bold block truncate bg-gray-950 p-1.5 rounded border border-gray-800/80">Realtime</code>
                            </div>
                        </div>

                        <!-- Exemplos de Integração Realtime -->
                        <div class="space-y-4">
                            <!-- Exemplo JavaScript Web -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-rose-300 font-bold flex items-center">
                                        <i class="fa-brands fa-js mr-1.5 text-amber-400"></i> Exemplo em JavaScript (Navegador ou Node.js)
                                    </span>
                                    <button onclick="copyToClipboard(document.getElementById('code-realtime-js').innerText, 'Código JS copiado!')" class="px-2.5 py-1 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 text-[11px] transition">
                                        <i class="fa-solid fa-copy mr-1"></i> Copiar
                                    </button>
                                </div>
                                <div class="code-block p-4 rounded-xl font-mono text-xs text-gray-200 overflow-x-auto bg-gray-950 border border-gray-800">
<pre id="code-realtime-js">// 1. Conectar ao WebSocket Realtime da API
const wsUrl = "<?php echo $realtimeWsUrl; ?>?model=Realtime&api-key=<?php echo $firstApiKey; ?>";
const ws = new WebSocket(wsUrl);

ws.onopen = () => {
  console.log("Conectado ao assistente Realtime!");

  // 2. Configurar a sessão (instruções e ferramentas se desejar)
  ws.send(JSON.stringify({
    type: "session.update",
    session: {
      instructions: "Você é um assistente rápido e prestativo. Fale sempre em português do Brasil de forma concisa."
    }
  }));
};

// 3. Receber eventos de texto, ferramentas e áudio da IA
ws.onmessage = (event) => {
  const msg = JSON.parse(event.data);

  // Transcrição de áudio do usuário concluída
  if (msg.type === "conversation.item.input_audio_transcription.completed") {
    console.log("Usuário falou:", msg.transcript);
  }

  // Resposta em texto sendo gerada
  if (msg.type === "response.audio_transcript.delta") {
    process.stdout ? process.stdout.write(msg.delta) : console.log(msg.delta);
  }

  // Pedaço de áudio com voz neural da IA (MP3 / base64)
  if (msg.type === "response.audio.delta") {
    // Decodifique nativamente via Web Audio API (decodeAudioData) ou toque como preferir
    console.log("Áudio recebido, tamanho:", msg.delta.length);
  }
};

// 4. Enviar áudio do usuário (em base64, gravado em WebM/Opus ou PCM 24kHz)
function enviarAudio(base64Audio) {
  ws.send(JSON.stringify({ type: "input_audio_buffer.append", audio: base64Audio }));
  ws.send(JSON.stringify({ type: "input_audio_buffer.commit" }));
}</pre>
                                </div>
                            </div>

                            <!-- Exemplo Python -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-rose-300 font-bold flex items-center">
                                        <i class="fa-brands fa-python mr-1.5 text-blue-400"></i> Exemplo em Python (Biblioteca websockets)
                                    </span>
                                    <button onclick="copyToClipboard(document.getElementById('code-realtime-py').innerText, 'Código Python copiado!')" class="px-2.5 py-1 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 text-[11px] transition">
                                        <i class="fa-solid fa-copy mr-1"></i> Copiar
                                    </button>
                                </div>
                                <div class="code-block p-4 rounded-xl font-mono text-xs text-gray-200 overflow-x-auto bg-gray-950 border border-gray-800">
<pre id="code-realtime-py">import asyncio
import json
import websockets

WS_URL = "<?php echo $realtimeWsUrl; ?>?model=Realtime&api-key=<?php echo $firstApiKey; ?>"

async def main():
    async with websockets.connect(WS_URL) as ws:
        print("Conectado ao assistente Realtime!")

        # Enviar configuração da sessão
        await ws.send(json.dumps({
            "type": "session.update",
            "session": {
                "instructions": "Responda de forma curta e amigável em português."
            }
        }))

        # Enviar mensagem de texto ou áudio
        await ws.send(json.dumps({
            "type": "conversation.item.create",
            "item": {
                "role": "user",
                "content": [{"type": "text", "text": "Olá! Me dê um resumo do clima hoje."}]
            }
        }))
        await ws.send(json.dumps({"type": "response.create", "generate_audio": True}))

        # Escutar respostas
        async for message in ws:
            event = json.loads(message)
            if event.get("type") == "response.audio_transcript.delta":
                print(event.get("delta", ""), end="", flush=True)
            elif event.get("type") == "response.done":
                print("
[Resposta concluída]")
                break

asyncio.run(main())</pre>
                                </div>
                            </div>
                        </div>

                        <!-- Card de Compatibilidade Azure OpenAI / OpenAI Oficial -->
                        <div class="p-4 rounded-xl bg-purple-900/30 border border-purple-500/30 text-xs text-purple-200 space-y-2">
                            <div class="font-bold flex items-center text-purple-300 text-sm">
                                <i class="fa-solid fa-plug-circle-check mr-2 text-purple-400"></i> Compatibilidade com Sistemas Existentes (Azure & OpenAI)
                            </div>
                            <p class="text-gray-300 leading-relaxed">
                                Se o seu sistema legado ou aplicação foi programado para a <strong>Azure OpenAI Realtime</strong> ou <strong>OpenAI Realtime API</strong>, basta substituir o endpoint WebSocket por <code class="text-purple-300 font-mono font-bold"><?php echo $realtimeWsUrl; ?></code> e usar a sua chave como parâmetro <code class="text-cyan-300 font-mono">api-key</code> ou header <code class="text-cyan-300 font-mono">Authorization: Bearer</code>. O protocolo é 100% compatível!
                            </p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Conteúdo Tab: Transcrição de Áudio (Multimodal) -->
                <div id="tab-content-audio" class="tab-content pt-4 space-y-4 hidden">
                    <div class="bg-gradient-to-r from-amber-950/40 via-gray-900/90 to-amber-950/20 p-5 rounded-2xl border border-amber-500/30 space-y-4">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-gray-800/80 pb-3">
                            <div class="font-bold text-amber-300 flex items-center text-sm">
                                <i class="fa-solid fa-waveform mr-2 text-amber-400"></i> Transcrição de Áudio com IA Multimodal (Áudio ➔ Texto)
                            </div>
                            <span class="text-xs px-2.5 py-1 rounded-full bg-amber-500/20 text-amber-300 border border-amber-500/30 font-mono flex items-center self-start sm:self-auto">
                                <i class="fa-solid fa-bolt mr-1 text-amber-400"></i> Modelo: Gemini Multimodal / Whisper
                            </span>
                        </div>

                        <p class="text-xs text-gray-300 leading-relaxed">
                            Converta qualquer áudio em texto com alta fidelidade, pontuação automática e reconhecimento de sotaques em português. Basta enviar o áudio em formato <code class="text-amber-300 font-mono">base64</code> via JSON para o endpoint <code class="text-amber-300 font-mono font-bold">/v1/audio/transcriptions</code>.
                        </p>

                        <!-- Detalhes do Endpoint -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-xs">
                            <div class="bg-gray-950/80 p-3 rounded-xl border border-gray-800">
                                <span class="text-gray-400 block text-[11px] mb-1">Método & Rota:</span>
                                <code class="text-amber-300 font-mono font-bold block truncate">POST <?= $openaiBaseUrl ?>/audio/transcriptions</code>
                            </div>
                            <div class="bg-gray-950/80 p-3 rounded-xl border border-gray-800">
                                <span class="text-gray-400 block text-[11px] mb-1">Formatos de Áudio:</span>
                                <span class="text-gray-200 font-mono text-[11px]">MP3, WAV, OGG, WebM, M4A, AAC</span>
                            </div>
                            <div class="bg-gray-950/80 p-3 rounded-xl border border-gray-800">
                                <span class="text-gray-400 block text-[11px] mb-1">Autenticação:</span>
                                <code class="text-cyan-300 font-mono text-[11px]">Bearer <?= substr($firstApiKey, 0, 10) ?>••••</code>
                            </div>
                        </div>

                        <!-- Exemplos de Código -->
                        <div class="space-y-4 pt-2">
                            <!-- Exemplo cURL -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-amber-300 font-bold flex items-center">
                                        <i class="fa-solid fa-terminal mr-1.5 text-gray-400"></i> Exemplo com cURL (Terminal / Bash)
                                    </span>
                                    <button onclick="copyToClipboard(document.getElementById('code-audio-curl').innerText, 'cURL copiado!')" class="px-2.5 py-1 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 text-[11px] transition">
                                        <i class="fa-solid fa-copy mr-1"></i> Copiar
                                    </button>
                                </div>
                                <div class="code-block p-4 rounded-xl font-mono text-xs text-gray-200 overflow-x-auto bg-gray-950 border border-gray-800">
<pre id="code-audio-curl">curl -X POST "<?= $openaiBaseUrl ?>/audio/transcriptions" \
  -H "Authorization: Bearer <?= $firstApiKey ?>" \
  -H "Content-Type: application/json" \
  -d '{
    "audio": "GkXfo59ChoEBQveBAULygQ8...",
    "mimeType": "audio/mp3"
  }'</pre>
                                </div>
                            </div>

                            <!-- Exemplo Python -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-amber-300 font-bold flex items-center">
                                        <i class="fa-brands fa-python mr-1.5 text-blue-400"></i> Exemplo em Python (Ler arquivo local e transcrever)
                                    </span>
                                    <button onclick="copyToClipboard(document.getElementById('code-audio-py').innerText, 'Python copiado!')" class="px-2.5 py-1 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 text-[11px] transition">
                                        <i class="fa-solid fa-copy mr-1"></i> Copiar
                                    </button>
                                </div>
                                <div class="code-block p-4 rounded-xl font-mono text-xs text-gray-200 overflow-x-auto bg-gray-950 border border-gray-800">
<pre id="code-audio-py">import base64
import requests

API_URL = "<?= $openaiBaseUrl ?>/audio/transcriptions"
API_KEY = "<?= $firstApiKey ?>"

# 1. Carrega o arquivo de áudio (MP3, WAV, OGG, WebM)
with open("gravacao.mp3", "rb") as f:
    audio_base64 = base64.b64encode(f.read()).decode("utf-8")

# 2. Envia para transcrição
response = requests.post(
    API_URL,
    headers={
        "Authorization": f"Bearer {API_KEY}",
        "Content-Type": "application/json"
    },
    json={
        "audio": audio_base64,
        "mimeType": "audio/mp3"
    }
)

resultado = response.json()
print("Transcrição:", resultado.get("text"))</pre>
                                </div>
                            </div>

                            <!-- Exemplo Node.js -->
                            <div class="space-y-2">
                                <div class="flex items-center justify-between text-xs">
                                    <span class="text-amber-300 font-bold flex items-center">
                                        <i class="fa-brands fa-node-js mr-1.5 text-emerald-400"></i> Exemplo em Node.js / JavaScript
                                    </span>
                                    <button onclick="copyToClipboard(document.getElementById('code-audio-node').innerText, 'Node.js copiado!')" class="px-2.5 py-1 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 text-[11px] transition">
                                        <i class="fa-solid fa-copy mr-1"></i> Copiar
                                    </button>
                                </div>
                                <div class="code-block p-4 rounded-xl font-mono text-xs text-gray-200 overflow-x-auto bg-gray-950 border border-gray-800">
<pre id="code-audio-node">import fs from 'fs';

const audioBase64 = fs.readFileSync('gravacao.mp3').toString('base64');

const response = await fetch('<?= $openaiBaseUrl ?>/audio/transcriptions', {
  method: 'POST',
  headers: {
    'Authorization': 'Bearer <?= $firstApiKey ?>',
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    audio: audioBase64,
    mimeType: 'audio/mp3'
  })
});

const data = await response.json();
console.log('Texto transcrito:', data.text);</pre>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

        <!-- 🔑 MINHAS CHAVES DE API -->
        <div id="chaves-section" class="glass p-6 sm:p-8 rounded-3xl border border-gray-800 space-y-6">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-gray-800 pb-4">
                <div>
                    <h2 class="text-lg font-bold text-white flex items-center">
                        <i class="fa-solid fa-key text-cyan-400 mr-2"></i> Minhas Chaves de API (sk-ai-...)
                    </h2>
                    <p class="text-xs text-gray-400">Gere chaves exclusivas para conectar seus projetos à API</p>
                </div>

                <form method="POST" action="index.php" class="flex items-center space-x-2 w-full sm:w-auto">
                    <input type="hidden" name="action" value="create_key">
                    <input type="text" name="key_name" placeholder="Nome da Key (ex: VSCode)" required class="px-3.5 py-2.5 text-xs rounded-xl bg-gray-900 border border-gray-800 text-white focus:outline-none focus:border-indigo-500 w-full sm:w-48">
                    <button type="submit" class="px-4 py-2.5 text-xs rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold whitespace-nowrap shadow-lg shadow-indigo-600/30 transition">
                        <i class="fa-solid fa-plus mr-1"></i> Nova Chave
                    </button>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead>
                        <tr class="text-gray-400 border-b border-gray-800 uppercase font-mono tracking-wider">
                            <th class="py-3 px-4">Nome da Key</th>
                            <th class="py-3 px-4">Chave API</th>
                            <th class="py-3 px-4">Tokens Gastos</th>
                            <th class="py-3 px-4">Status</th>
                            <th class="py-3 px-4">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-800/60 font-mono">
                        <?php if (empty($apiKeys)): ?>
                            <tr>
                                <td colspan="5" class="py-8 text-center text-gray-500 font-sans text-xs">
                                    Nenhuma chave de API gerada. Clique em "Nova Chave" acima para criar uma.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($apiKeys as $key): ?>
                                <tr class="hover:bg-gray-900/40">
                                    <td class="py-3.5 px-4 font-sans font-semibold text-white">
                                        <?php echo htmlspecialchars($key['key_name']); ?>
                                    </td>
                                    <td class="py-3.5 px-4 font-bold select-all">
                                        <div class="flex items-center space-x-2">
                                            <span id="key-text-<?php echo $key['id']; ?>" class="text-cyan-300 tracking-wider">
                                                <?php echo substr($key['api_key'], 0, 10) . '••••••••••••••••'; ?>
                                            </span>
                                            <button onclick="toggleKeyVisibility('<?php echo $key['id']; ?>', '<?php echo $key['api_key']; ?>')" class="text-gray-500 hover:text-white p-1" title="Mostrar/Ocultar">
                                                <i class="fa-solid fa-eye text-xs" id="eye-icon-<?php echo $key['id']; ?>"></i>
                                            </button>
                                            <button onclick="copyToClipboard('<?php echo $key['api_key']; ?>', 'Chave de API copiada!')" class="text-indigo-400 hover:text-indigo-300 p-1" title="Copiar Chave">
                                                <i class="fa-solid fa-copy text-xs"></i>
                                            </button>
                                        </div>
                                    </td>
                                    <td class="py-3.5 px-4 text-gray-300">
                                        <?php echo number_format($key['tokens_used'], 0, ',', '.'); ?>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <?php if ($key['status'] === 'active'): ?>
                                            <span class="px-2.5 py-1 rounded-full bg-emerald-500/20 text-emerald-400 font-sans font-semibold text-[11px]">Ativa</span>
                                        <?php else: ?>
                                            <span class="px-2.5 py-1 rounded-full bg-red-500/20 text-red-400 font-sans font-semibold text-[11px]">Revogada</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-3.5 px-4 font-sans">
                                        <?php if ($key['status'] === 'active'): ?>
                                            <form method="POST" action="index.php" onsubmit="return confirm('Tem certeza que deseja revogar esta chave?');" class="inline">
                                                <input type="hidden" name="action" value="revoke_key">
                                                <input type="hidden" name="key_id" value="<?php echo $key['id']; ?>">
                                                <button type="submit" class="text-xs text-red-400 hover:text-red-300 hover:underline">Revogar</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-gray-600">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 💳 PLANOS & RECARGAS PIX (MISTIC PAY) -->
        <div id="planos-section" class="glass p-6 sm:p-8 rounded-3xl border border-gray-800 space-y-6">
            <div>
                <h2 class="text-lg font-bold text-white flex items-center">
                    <i class="fa-solid fa-bolt text-amber-400 mr-2"></i> Adicionar Tokens / Planos de Recarga
                </h2>
                <p class="text-xs text-gray-400 mt-1">Recarregue via PIX instantâneo e liberte seus tokens na hora</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <?php foreach ($activePlans as $plan): 
                    $isFeatured = !empty($plan['is_featured']);
                    $featuresList = array_filter(explode("\n", $plan['features'] ?? ''));
                ?>
                    <div class="glass-card p-6 rounded-2xl border <?= $isFeatured ? 'border-2 border-indigo-500/80 shadow-xl shadow-indigo-600/10 relative' : 'border-gray-800 hover:border-gray-700' ?> flex flex-col justify-between transition">
                        <?php if ($isFeatured): ?>
                            <span class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-0.5 rounded-full bg-gradient-to-r from-indigo-600 to-cyan-500 text-white text-[10px] font-bold uppercase tracking-wider">
                                <?= htmlspecialchars($plan['badge'] ?: 'Recomendado') ?>
                            </span>
                        <?php endif; ?>
                        <div>
                            <span class="text-xs font-bold uppercase tracking-wider <?= $isFeatured ? 'text-indigo-400' : 'text-cyan-400' ?>"><?= htmlspecialchars($plan['name']) ?></span>
                            <div class="text-3xl font-extrabold text-white mt-2">R$ <?= number_format($plan['price'], 0, ',', '.') ?></div>
                            <p class="text-xs text-gray-400 mt-1 mb-4"><?= number_format($plan['tokens_amount'], 0, ',', '.') ?> Tokens</p>
                            <ul class="text-xs text-gray-300 space-y-2 mb-6">
                                <?php foreach ($featuresList as $feat): ?>
                                    <li><i class="fa-solid fa-check <?= $isFeatured ? 'text-indigo-400' : 'text-cyan-400' ?> mr-2"></i> <?= htmlspecialchars(trim($feat)) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <button onclick="openPixModal(<?= $plan['price'] ?>, '<?= htmlspecialchars(addslashes($plan['name'])) ?>', <?= $plan['tokens_amount'] ?>)" class="w-full py-3 rounded-xl <?= $isFeatured ? 'bg-gradient-to-r from-indigo-600 to-cyan-500 hover:from-indigo-500 hover:to-cyan-400 text-white font-bold shadow-lg shadow-indigo-600/20' : 'glass hover:bg-gray-800 text-white font-semibold' ?> text-xs transition cursor-pointer">
                            Comprar <?= htmlspecialchars($plan['name']) ?>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    </main>

    <!-- Modal PIX (Mistic Pay) -->
    <div id="pixModal" class="fixed inset-0 bg-black/80 backdrop-blur-md flex items-center justify-center p-4 z-50 hidden">
        <div class="glass max-w-md w-full p-6 sm:p-8 rounded-3xl border border-gray-800 space-y-6 relative text-center">
            <button onclick="closePixModal()" class="absolute top-4 right-4 text-gray-400 hover:text-white">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>

            <div class="w-12 h-12 rounded-2xl bg-emerald-500/20 text-emerald-400 flex items-center justify-center text-2xl mx-auto">
                <i class="fa-solid fa-qrcode"></i>
            </div>

            <div>
                <h3 class="text-lg font-bold text-white">Pagamento via PIX</h3>
                <p class="text-xs text-gray-400 mt-1" id="pixPlanDescription">Carregando dados da cobrança...</p>
            </div>

            <div id="pixLoading" class="py-8">
                <i class="fa-solid fa-circle-notch fa-spin text-3xl text-indigo-500"></i>
                <p class="text-xs text-gray-400 mt-3">Gerando QR Code do PIX...</p>
            </div>

            <div id="pixContainer" class="hidden space-y-4">
                <div class="bg-white p-3 rounded-2xl inline-block shadow-xl mx-auto">
                    <img id="pixQrCodeImg" src="" alt="QR Code PIX" class="w-48 h-48 mx-auto">
                </div>

                <div>
                    <label class="block text-[11px] font-semibold text-gray-400 uppercase mb-1">Chave Copia e Cola</label>
                    <div class="flex items-center space-x-2">
                        <input type="text" id="pixKeyInput" readonly class="w-full p-2.5 text-xs rounded-xl bg-gray-900 border border-gray-800 text-white font-mono select-all">
                        <button onclick="copyPixKey()" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold rounded-xl whitespace-nowrap">
                            Copiar
                        </button>
                    </div>
                </div>

                <div class="text-[11px] text-emerald-400 flex items-center justify-center space-x-2 bg-emerald-500/10 p-2.5 rounded-xl border border-emerald-500/20">
                    <i class="fa-solid fa-arrows-rotate fa-spin"></i>
                    <span>Aguardando confirmação do pagamento...</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="fixed bottom-6 right-6 px-5 py-3 rounded-2xl bg-indigo-600 text-white font-semibold text-xs shadow-2xl transition transform translate-y-20 opacity-0 pointer-events-none z-50 flex items-center space-x-2">
        <i class="fa-solid fa-circle-check text-base"></i>
        <span id="toastMsg">Mensagem</span>
    </div>

    <script>
        let currentTxId = null;
        let statusInterval = null;

        let currentSelectedModel = '<?= $defaultClientModel ?>';
        const anthropicBaseUrl = '<?php echo $anthropicBaseUrl; ?>';
        const openaiBaseUrl = '<?php echo $openaiBaseUrl; ?>';
        const apiBaseUrl = openaiBaseUrl;
        const firstApiKey = '<?php echo $firstApiKey; ?>';

        function selectModel(modelKey) {
            currentSelectedModel = modelKey;

            const selectedNameEl = document.getElementById('selected-model-name');
            if (selectedNameEl) selectedNameEl.innerText = modelKey;

            // Verificar se o modelo selecionado é Realtime
            const selectedBtn = document.querySelector(`.model-select-btn[data-model="${modelKey}"]`);
            const isRealtime = (selectedBtn && selectedBtn.getAttribute('data-is-realtime') === '1') || modelKey.toLowerCase().includes('realtime');

            // Destacar botão do modelo selecionado
            document.querySelectorAll('.model-select-btn').forEach(btn => {
                if (btn.getAttribute('data-model') === modelKey) {
                    btn.className = 'model-select-btn px-3.5 py-2 rounded-xl border text-xs font-mono transition cursor-pointer flex items-center space-x-1.5 ' + 
                        (isRealtime ? 'bg-rose-600/30 text-rose-200 border-rose-500/50 shadow-lg shadow-rose-500/20 font-bold ring-2 ring-rose-500/40' : 'bg-indigo-600/30 text-indigo-200 border-indigo-500/50 shadow-lg shadow-indigo-500/20 font-bold ring-2 ring-indigo-500/40');
                } else {
                    btn.className = 'model-select-btn px-3.5 py-2 rounded-xl border text-xs font-mono transition cursor-pointer flex items-center space-x-1.5 bg-gray-800/60 text-gray-300 border-gray-700 hover:bg-gray-800 hover:text-white';
                }
            });

            // IDs das abas normais de código / completions
            const normalTabs = ['tab-vscode', 'tab-curl', 'tab-python', 'tab-node', 'tab-claudecode'];
            const rtTabBtn = document.getElementById('tab-realtime');

            if (isRealtime) {
                // SE O MODELO É REALTIME:
                // 1. Oculta rigorosamente todas as abas que NÃO são de Realtime
                normalTabs.forEach(id => {
                    const btn = document.getElementById(id);
                    if (btn) btn.style.setProperty('display', 'none', 'important');
                });

                // 2. Exibe exclusivamente a aba Realtime Voice e ativa ela
                if (rtTabBtn) {
                    rtTabBtn.style.removeProperty('display');
                    rtTabBtn.style.display = 'inline-flex';
                    switchTab('realtime');
                }
            } else {
                // SE O MODELO É NORMAL (Completions / Código):
                // 1. Oculta rigorosamente a aba de Realtime
                if (rtTabBtn) {
                    rtTabBtn.style.setProperty('display', 'none', 'important');
                }

                // 2. Exibe todas as abas normais de código
                normalTabs.forEach(id => {
                    const btn = document.getElementById(id);
                    if (btn) {
                        btn.style.removeProperty('display');
                        btn.style.display = 'inline-flex';
                    }
                });

                // 3. Se a aba aberta no momento for a do Realtime, volta para VS Code
                const activeContent = document.querySelector('.tab-content:not(.hidden)');
                if (!activeContent || activeContent.id === 'tab-content-realtime') {
                    switchTab('vscode');
                }

                // Atualizar os exemplos de código com o modelo selecionado
                const vscodeModelEl = document.getElementById('model-display-vscode');
                if (vscodeModelEl) vscodeModelEl.innerText = modelKey;

                const curlEl = document.getElementById('code-curl-text');
                if (curlEl) {
                    curlEl.innerText = `curl ${openaiBaseUrl}/chat/completions \\\n  -H "Content-Type: application/json" \\\n  -H "Authorization: Bearer ${firstApiKey}" \\\n  -d '{\n    "model": "${modelKey}",\n    "messages": [\n      {"role": "user", "content": "Olá, me dê um exemplo de código PHP limpo!"}\n    ]\n  }'`;
                }

                const pythonEl = document.getElementById('code-python-text');
                if (pythonEl) {
                    pythonEl.innerText = `from openai import OpenAI\n\nclient = OpenAI(\n    base_url="${openaiBaseUrl}",\n    api_key="${firstApiKey}"\n)\n\nresponse = client.chat.completions.create(\n    model="${modelKey}",\n    messages=[\n        {"role": "user", "content": "Explique a teoria da relatividade em 2 frases."}\n    ]\n)\n\nprint(response.choices[0].message.content)`;
                }

                const nodeEl = document.getElementById('code-node-text');
                if (nodeEl) {
                    nodeEl.innerText = `import OpenAI from 'openai';\n\nconst openai = new OpenAI({\n  baseURL: '${openaiBaseUrl}',\n  apiKey: '${firstApiKey}',\n});\n\nasync function main() {\n  const completion = await openai.chat.completions.create({\n    model: '${modelKey}',\n    messages: [{ role: 'user', content: 'Escreva um hello world em TypeScript' }],\n  });\n\n  console.log(completion.choices[0].message.content);\n}\n\nmain();`;
                }

                const psEl = document.getElementById('code-claudecode-ps');
                if (psEl) psEl.innerText = `$env:ANTHROPIC_BASE_URL="${anthropicBaseUrl}"\n$env:ANTHROPIC_API_KEY="${firstApiKey}"\nclaude --model ${modelKey}`;

                const cmdEl = document.getElementById('code-claudecode-cmd');
                if (cmdEl) cmdEl.innerText = `set ANTHROPIC_BASE_URL=${anthropicBaseUrl}\nset ANTHROPIC_API_KEY=${firstApiKey}\nclaude --model ${modelKey}`;

                const bashEl = document.getElementById('code-claudecode-bash');
                if (bashEl) bashEl.innerText = `export ANTHROPIC_BASE_URL="${anthropicBaseUrl}"\nexport ANTHROPIC_API_KEY="${firstApiKey}"\nclaude --model ${modelKey}`;

                document.querySelectorAll('.selected-model-text').forEach(el => {
                    el.innerText = modelKey;
                });
            }

            showToast(`Modelo selecionado: ${modelKey}`);
        }

        function switchCliTerminal(term) {
            document.querySelectorAll('.cli-term-btn').forEach(btn => {
                btn.className = 'cli-term-btn px-3 py-1.5 rounded-lg text-xs font-semibold text-gray-400 hover:text-white transition flex items-center';
            });
            document.querySelectorAll('.cli-term-content').forEach(c => {
                c.classList.add('hidden');
            });

            const activeBtn = document.getElementById('cli-tab-' + term);
            if (term === 'ps') {
                activeBtn.className = 'cli-term-btn px-3 py-1.5 rounded-lg text-xs font-semibold transition bg-blue-600 text-white shadow-md flex items-center';
            } else if (term === 'cmd') {
                activeBtn.className = 'cli-term-btn px-3 py-1.5 rounded-lg text-xs font-semibold transition bg-amber-600 text-white shadow-md flex items-center';
            } else if (term === 'bash') {
                activeBtn.className = 'cli-term-btn px-3 py-1.5 rounded-lg text-xs font-semibold transition bg-emerald-600 text-white shadow-md flex items-center';
            }

            const activeContent = document.getElementById('cli-content-' + term);
            if (activeContent) activeContent.classList.remove('hidden');
        }

        function switchTab(tabName) {
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.className = 'tab-btn px-4 py-2 rounded-xl text-xs font-semibold text-gray-400 hover:text-white transition';
            });
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });

            document.getElementById('tab-' + tabName).className = 'tab-btn px-4 py-2 rounded-xl text-xs font-semibold transition bg-indigo-600 text-white shadow-lg';
            document.getElementById('tab-content-' + tabName).classList.remove('hidden');
        }

        function toggleKeyVisibility(id, fullKey) {
            const span = document.getElementById('key-text-' + id);
            const icon = document.getElementById('eye-icon-' + id);

            if (span.innerText.includes('••••')) {
                span.innerText = fullKey;
                icon.className = 'fa-solid fa-eye-slash text-xs';
            } else {
                span.innerText = fullKey.substring(0, 10) + '••••••••••••••••';
                icon.className = 'fa-solid fa-eye text-xs';
            }
        }

        function copyToClipboard(text, message) {
            navigator.clipboard.writeText(text);
            showToast(message);
        }

        function showToast(message) {
            const toast = document.getElementById('toast');
            document.getElementById('toastMsg').innerText = message;
            toast.classList.remove('translate-y-20', 'opacity-0', 'pointer-events-none');
            setTimeout(() => {
                toast.classList.add('translate-y-20', 'opacity-0', 'pointer-events-none');
            }, 3000);
        }

        async function openPixModal(amount, planName, tokensAmount) {
            document.getElementById('pixModal').classList.remove('hidden');
            document.getElementById('pixLoading').classList.remove('hidden');
            document.getElementById('pixContainer').classList.add('hidden');
            document.getElementById('pixPlanDescription').innerText = planName + ' - R$ ' + amount + ',00';

            try {
                const res = await fetch('<?= getAppBaseUrl() ?>/api/checkout_pix.php?action=create', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ amount: amount, plan_name: planName, tokens_amount: tokensAmount })
                });

                const data = await res.json();
                if (data.success) {
                    currentTxId = data.tx_id;
                    document.getElementById('pixQrCodeImg').src = data.qr_code_base64;
                    document.getElementById('pixKeyInput').value = data.qr_code;
                    document.getElementById('pixLoading').classList.add('hidden');
                    document.getElementById('pixContainer').classList.remove('hidden');

                    if (statusInterval) clearInterval(statusInterval);
                    statusInterval = setInterval(() => checkPaymentStatus(currentTxId), 5000);
                } else {
                    alert('Erro ao gerar PIX: ' + (data.message || 'Tente novamente.'));
                    closePixModal();
                }
            } catch (err) {
                alert('Erro na requisição. Verifique a integração com o Mistic Pay.');
                closePixModal();
            }
        }

        async function checkPaymentStatus(txId) {
            try {
                const res = await fetch('<?= getAppBaseUrl() ?>/api/checkout_pix.php?action=check_status&tx_id=' + txId);
                const data = await res.json();
                if (data.paid) {
                    clearInterval(statusInterval);
                    alert('🎉 Pagamento PIX Confirmado! Seus tokens foram liberados.');
                    window.location.reload();
                }
            } catch (err) {}
        }

        function closePixModal() {
            document.getElementById('pixModal').classList.add('hidden');
            if (statusInterval) clearInterval(statusInterval);
        }

        function copyPixKey() {
            const input = document.getElementById('pixKeyInput');
            input.select();
            navigator.clipboard.writeText(input.value);
            showToast('Chave PIX Copia e Cola copiada!');
        }
    </script>
</body>
</html>
