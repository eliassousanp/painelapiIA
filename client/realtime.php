<?php
require_once __DIR__ . '/../config/helpers.php';
requireLogin();

// Se for administrador, abre a versÃ£o oficial do painel admin com menu lateral
if (function_exists('isAdmin') && isAdmin() && !isset($_GET['as_client'])) {
    header('Location: ' . getAppBaseUrl() . '/admin/realtime.php');
    exit;
}

$realtimeAccess = getSetting('realtime_access', 'all');

// Se o realtime for restrito para administradores e o usuÃ¡rio atual nÃ£o for admin
if ($realtimeAccess === 'admin' && !isAdmin()) {
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR" class="dark">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Acesso Restrito - Realtime</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    </head>
    <body class="bg-gray-950 text-gray-100 flex items-center justify-center min-h-screen p-4">
        <div class="max-w-md w-full bg-gray-900 border border-gray-800 rounded-3xl p-8 text-center space-y-5 shadow-2xl">
            <div class="w-16 h-16 rounded-2xl bg-amber-500/10 text-amber-400 border border-amber-500/20 flex items-center justify-center mx-auto text-2xl">
                <i class="fa-solid fa-lock"></i>
            </div>
            <h2 class="text-xl font-bold text-white">Console Realtime em Testes Privados</h2>
            <p class="text-xs text-gray-400 leading-relaxed">
                O console de voz em tempo real estÃ¡ temporariamente configurado para acesso exclusivo dos administradores. Ele serÃ¡ liberado em breve para todos os clientes!
            </p>
            <a href="<?= getAppBaseUrl() ?>/client/index.php" class="inline-flex items-center justify-center px-6 py-3 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white font-bold text-xs shadow-lg transition">
                <i class="fa-solid fa-arrow-left mr-2"></i> Voltar para Ãrea do Cliente
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

// Buscar dados do usuÃ¡rio
$stmtUser = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch();

// Buscar primeira API key ativa do usuÃ¡rio
$stmtKeys = $db->prepare("SELECT api_key FROM api_keys WHERE user_id = ? AND status = 'active' ORDER BY id ASC LIMIT 1");
$stmtKeys->execute([$userId]);
$keyRow = $stmtKeys->fetch();
$firstApiKey = $keyRow ? $keyRow['api_key'] : '';

// Buscar apenas modelos ativos com Realtime ativado (is_realtime = 1)
try {
    if (function_exists('isAdmin') && isAdmin()) {
        $stmtModels = $db->query("SELECT model_key, name FROM models WHERE status = 'active' AND is_realtime = 1 ORDER BY id ASC");
    } else {
        $stmtModels = $db->query("SELECT model_key, name FROM models WHERE status = 'active' AND is_realtime = 1 AND is_admin_only = 0 ORDER BY id ASC");
    }
    $activeModels = $stmtModels ? $stmtModels->fetchAll() : [];
} catch (Exception $e) {
    $activeModels = [];
}

$httpBase = getGatewayPublicUrl();
$wsUrl = getGatewayWsUrl();
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="<?= htmlspecialchars(getThemeMode()) ?>" data-scheme="<?= htmlspecialchars(getThemeScheme()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Realtime Console - BRLoveAPI</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link href="<?= getAppBaseUrl() ?>/assets/css/theme.css" rel="stylesheet">
    <script>window.BRLOVE_BASE_URL = '<?= getAppBaseUrl() ?>';</script>
    <script src="<?= getAppBaseUrl() ?>/assets/js/theme.js"></script>
    <style>
        * { font-family: 'Inter', sans-serif; }
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

        /* Waveform / Visualizador */
        .waveform-bar {
            animation: wave 1.2s ease-in-out infinite;
        }
        .waveform-bar:nth-child(2) { animation-delay: 0.1s; }
        .waveform-bar:nth-child(3) { animation-delay: 0.2s; }
        .waveform-bar:nth-child(4) { animation-delay: 0.3s; }
        .waveform-bar:nth-child(5) { animation-delay: 0.4s; }
        .waveform-bar:nth-child(6) { animation-delay: 0.3s; }
        .waveform-bar:nth-child(7) { animation-delay: 0.2s; }
        .waveform-bar:nth-child(8) { animation-delay: 0.1s; }

        @keyframes wave {
            0%, 100% { height: 8px; }
            50% { height: 32px; }
        }
        .waveform-bar.idle {
            animation: none;
            height: 4px;
        }

        /* Recording pulse */
        @keyframes recordPulse {
            0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.5); }
            70% { box-shadow: 0 0 0 20px rgba(239, 68, 68, 0); }
            100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
        }
        .recording-pulse { animation: recordPulse 1.5s infinite; }

        /* Streaming cursor */
        @keyframes blink { 0%, 50% { opacity: 1; } 51%, 100% { opacity: 0; } }
        .streaming-cursor::after {
            content: 'â–Œ';
            animation: blink 0.8s infinite;
            color: #818cf8;
        }

        /* Messages */
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .msg-animate { animation: fadeInUp 0.3s ease-out; }

        /* Event log */
        .event-log-item {
            font-family: 'JetBrains Mono', 'Fira Code', 'Consolas', monospace;
            font-size: 11px;
        }

        /* Scrollbar */
        .custom-scrollbar::-webkit-scrollbar { width: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(99, 102, 241, 0.3); border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(99, 102, 241, 0.5); }

        /* Status dot */
        @keyframes statusPulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }
        .status-pulse { animation: statusPulse 2s infinite; }

        /* Audio playing glow */
        @keyframes audioGlow {
            0%, 100% { box-shadow: 0 0 10px rgba(99, 102, 241, 0.3); }
            50% { box-shadow: 0 0 25px rgba(99, 102, 241, 0.6); }
        }
        .audio-glow { animation: audioGlow 1.5s infinite; }
    </style>
</head>
<body class="bg-gray-950 text-gray-100 antialiased min-h-screen flex flex-col">

    <!-- Top Navigation (Padronizado com todas as pÃ¡ginas do sistema) -->
    <nav class="glass border-b border-gray-800 sticky top-0 z-50">
        <div class="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between">
            
            <!-- Esquerda: Logo + Badges -->
            <div class="flex items-center space-x-3">
                <a href="<?= getAppBaseUrl() ?>/index.php" class="flex items-center space-x-2">
                    <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-indigo-600 via-purple-600 to-cyan-400 flex items-center justify-center font-bold text-lg text-white shadow-lg shadow-indigo-500/20">
                        <i class="fa-solid fa-heart-pulse"></i>
                    </div>
                    <span class="text-xl font-bold text-white tracking-tight">BRLove<span class="text-indigo-400">API</span></span>
                </a>
                <a href="<?= getAppBaseUrl() ?>/client/index.php" class="text-xs px-2.5 py-1 rounded-full bg-indigo-500/20 hover:bg-indigo-500/30 text-indigo-300 font-semibold border border-indigo-500/30 transition hidden sm:inline-flex items-center">
                    <i class="fa-solid fa-gauge mr-1"></i> Ãrea do Cliente
                </a>
                <span class="text-xs px-2.5 py-1 rounded-full bg-gradient-to-r from-indigo-500/20 to-purple-500/20 text-purple-300 font-semibold border border-purple-500/30 flex items-center">
                    <i class="fa-solid fa-waveform-lines mr-1 text-purple-400"></i> Realtime
                </span>
            </div>

            <!-- Centro: Links de NavegaÃ§Ã£o das Outras SeÃ§Ãµes do Painel -->
            <div class="hidden xl:flex items-center space-x-1 text-xs font-medium text-gray-300">
                <a href="<?= getAppBaseUrl() ?>/client/index.php" class="px-3 py-1.5 rounded-lg hover:bg-gray-800 hover:text-white transition flex items-center">
                    <i class="fa-solid fa-gauge mr-1.5 text-indigo-400"></i> Dashboard
                </a>
                <a href="<?= getAppBaseUrl() ?>/client/index.php#chaves-section" class="px-3 py-1.5 rounded-lg hover:bg-gray-800 hover:text-white transition flex items-center">
                    <i class="fa-solid fa-key mr-1.5 text-emerald-400"></i> Chaves API
                </a>
                <a href="<?= getAppBaseUrl() ?>/client/index.php#endpoints-section" class="px-3 py-1.5 rounded-lg hover:bg-gray-800 hover:text-white transition flex items-center">
                    <i class="fa-solid fa-network-wired mr-1.5 text-cyan-400"></i> Endpoints
                </a>
                <a href="<?= getAppBaseUrl() ?>/client/index.php#planos-section" class="px-3 py-1.5 rounded-lg hover:bg-gray-800 hover:text-white transition flex items-center">
                    <i class="fa-solid fa-bolt mr-1.5 text-amber-400"></i> Planos & Recargas
                </a>
                <?php if (function_exists('isAdmin') && isAdmin()): ?>
                    <a href="<?= getAppBaseUrl() ?>/admin/models.php" class="px-3 py-1.5 rounded-lg hover:bg-purple-900/30 text-purple-300 hover:text-white transition flex items-center border border-purple-500/20">
                        <i class="fa-solid fa-microchip mr-1.5 text-purple-400"></i> Modelos IA
                    </a>
                    <a href="<?= getAppBaseUrl() ?>/admin/whatsapp.php" class="px-3 py-1.5 rounded-lg hover:bg-emerald-900/30 text-emerald-300 hover:text-white transition flex items-center border border-emerald-500/20">
                        <i class="fa-brands fa-whatsapp mr-1.5 text-emerald-400"></i> WhatsApp BOT
                    </a>
                <?php endif; ?>
            </div>

            <!-- Direita: ConexÃ£o + Saldo + WhatsApp + BotÃµes -->
            <div class="flex items-center space-x-2 sm:space-x-3">
                <!-- Status de ConexÃ£o WebSocket -->
                <div id="connectionStatus" class="flex items-center space-x-1.5 text-xs px-2.5 py-1.5 rounded-lg border transition-all duration-300 bg-red-500/10 border-red-500/30 text-red-400">
                    <span id="statusDot" class="w-2 h-2 rounded-full bg-red-400"></span>
                    <span id="statusText" class="hidden sm:inline">Desconectado</span>
                </div>

                <!-- Saldo de Tokens -->
                <span class="text-xs text-indigo-300 font-mono hidden lg:inline-flex items-center bg-indigo-500/10 px-3 py-1.5 rounded-lg border border-indigo-500/20" title="Saldo de tokens disponÃ­vel">
                    <i class="fa-solid fa-coins mr-1.5 text-indigo-400"></i> <?= number_format($user['balance_tokens'] ?? 0, 0, ',', '.') ?>
                </span>

                <!-- WhatsApp do UsuÃ¡rio -->
                <?php if (!empty($user['whatsapp'])): ?>
                    <span class="text-xs text-emerald-400 font-mono hidden md:inline-flex items-center bg-emerald-500/10 px-3 py-1.5 rounded-lg border border-emerald-500/20">
                        <i class="fa-brands fa-whatsapp mr-1.5 text-base"></i> <?= htmlspecialchars($user['whatsapp']); ?>
                    </span>
                <?php endif; ?>

                <!-- Link Painel Cliente -->
                <a href="<?= getAppBaseUrl() ?>/client/index.php" class="text-xs px-3 py-2 rounded-xl glass hover:bg-gray-800 text-gray-300 transition flex items-center">
                    <i class="fa-solid fa-gauge mr-1.5 text-indigo-400"></i> <span class="hidden sm:inline">Painel</span>
                </a>

                <!-- BotÃ£o Painel Admin se for Admin -->
                <?php if (function_exists('isAdmin') && isAdmin()): ?>
                    <a href="<?= getAppBaseUrl() ?>/admin/index.php" class="text-xs px-3 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white font-medium shadow-md transition flex items-center">
                        <i class="fa-solid fa-shield-halved mr-1.5"></i> Admin
                    </a>
                <?php endif; ?>

                <!-- BotÃ£o Sair -->
                <a href="<?= getAppBaseUrl() ?>/logout.php" title="Sair da Conta" class="text-xs px-3 py-2 rounded-xl glass hover:bg-red-500/20 hover:text-red-400 text-gray-300 transition flex items-center">
                    <i class="fa-solid fa-right-from-bracket sm:mr-1"></i> <span class="hidden sm:inline">Sair</span>
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="flex-1 max-w-[1600px] mx-auto w-full px-4 sm:px-6 lg:px-8 py-6 flex flex-col lg:flex-row gap-5" style="height: calc(100vh - 64px);">

        <!-- Coluna Esquerda: ConfiguraÃ§Ã£o + Chat -->
        <div class="flex flex-col flex-1 min-w-0 gap-5">

            <!-- ConfiguraÃ§Ãµes -->
            <div class="glass rounded-2xl p-5 border border-gray-800 shrink-0">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-bold text-white flex items-center">
                        <i class="fa-solid fa-sliders text-indigo-400 mr-2"></i> ConfiguraÃ§Ã£o da SessÃ£o
                    </h2>
                    <button id="btnToggleConfig" onclick="toggleConfig()" class="text-xs text-gray-400 hover:text-white transition">
                        <i class="fa-solid fa-chevron-up" id="configChevron"></i>
                    </button>
                </div>

                <div id="configPanel" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <!-- Modelo -->
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1.5">
                            <i class="fa-solid fa-microchip text-indigo-400 mr-1"></i> Modelo
                        </label>
                        <select id="cfgModel" onchange="updateSession()" class="w-full bg-gray-900/90 text-gray-100 text-xs border border-gray-700 rounded-xl px-3 py-2.5 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30 outline-none transition cursor-pointer">
                            <?php if (empty($activeModels)): ?>
                                <option value="" disabled selected>âš ï¸ Nenhum modelo Realtime ativado no painel</option>
                            <?php else: ?>
                                <?php foreach ($activeModels as $m): ?>
                                    <option value="<?= htmlspecialchars($m['model_key']) ?>"><?= htmlspecialchars($m['name']) ?> (<?= htmlspecialchars($m['model_key']) ?>)</option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                    </div>

                    <!-- Voz TTS -->
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1.5">
                            <i class="fa-solid fa-volume-high text-purple-400 mr-1"></i> Voz
                        </label>
                        <select id="cfgVoice" onchange="updateSession()" class="w-full bg-gray-900/90 text-gray-100 text-xs border border-gray-700 rounded-xl px-3 py-2.5 focus:border-purple-500 focus:ring-1 focus:ring-purple-500/30 outline-none transition cursor-pointer">
                            <optgroup label="âš¡ Super RÃ¡pida (Zero LatÃªncia)" class="bg-gray-900 text-cyan-300">
                                <option value="browser_fast">âš¡ Voz Local Navegador (Ultra RÃ¡pida / 0ms)</option>
                            </optgroup>
                            <optgroup label="PortuguÃªs (Brasil) - Voz Neural" class="bg-gray-900 text-white">
                                <option value="francisca" selected>ðŸ‡§ðŸ‡· Francisca (Feminina)</option>
                                <option value="antonio">ðŸ‡§ðŸ‡· AntÃ´nio (Masculino)</option>
                                <option value="thalita">ðŸ‡§ðŸ‡· Thalita (Feminina)</option>
                            </optgroup>
                            <optgroup label="English (US) - Neural" class="bg-gray-900 text-white">
                                <option value="aria">ðŸ‡ºðŸ‡¸ Aria (EN Feminina)</option>
                                <option value="guy">ðŸ‡ºðŸ‡¸ Guy (EN Masculino)</option>
                                <option value="jenny">ðŸ‡ºðŸ‡¸ Jenny (EN Feminina)</option>
                                <option value="andrew">ðŸ‡ºðŸ‡¸ Andrew (EN Masculino)</option>
                            </optgroup>
                        </select>
                    </div>

                    <!-- Temperatura -->
                    <div>
                        <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1.5">
                            <i class="fa-solid fa-temperature-half text-amber-400 mr-1"></i> Temperatura: <span id="tempValue" class="text-indigo-300">0.7</span>
                        </label>
                        <input type="range" id="cfgTemp" min="0" max="2" step="0.1" value="0.7" onchange="document.getElementById('tempValue').textContent=this.value; updateSession();" class="w-full accent-indigo-500 h-2 bg-gray-800 rounded-lg cursor-pointer">
                    </div>

                    <!-- BotÃ£o Conectar -->
                    <div class="flex items-end">
                        <button id="btnConnect" onclick="toggleConnection()" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white font-bold text-xs shadow-lg shadow-indigo-500/30 transition-all flex items-center justify-center cursor-pointer">
                            <i class="fa-solid fa-plug mr-2"></i> Conectar
                        </button>
                    </div>
                </div>

                <!-- Barra de Ferramentas / Pesquisa Web ao Vivo -->
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3 pt-3 border-t border-gray-800/80">
                    <label class="flex items-center gap-2 cursor-pointer text-xs font-semibold text-emerald-400 hover:text-emerald-300 transition">
                        <input type="checkbox" id="cfgWebSearch" checked onchange="updateSession()" class="rounded bg-gray-900 border-gray-700 text-emerald-500 focus:ring-0 cursor-pointer accent-emerald-500 w-4 h-4">
                        <span class="flex items-center gap-1.5">
                            <i class="fa-solid fa-globe text-emerald-400"></i>
                            Pesquisa Web ao Vivo (NotÃ­cias, CotaÃ§Ãµes e Fatos de Hoje)
                        </span>
                    </label>
                    <span class="text-[11px] text-gray-500 flex items-center gap-1">
                        <i class="fa-solid fa-bolt text-amber-400"></i> Grounding em tempo real via Gateway
                    </span>
                </div>

                <!-- InstruÃ§Ãµes do Sistema (colapsÃ¡vel) -->
                <div class="mt-3">
                    <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1.5">
                        <i class="fa-solid fa-scroll text-cyan-400 mr-1"></i> InstruÃ§Ãµes do Sistema (opcional)
                    </label>
                    <textarea id="cfgInstructions" onblur="updateSession()" placeholder="Ex: VocÃª Ã© um assistente amigÃ¡vel e responde sempre em portuguÃªs..." rows="2" class="w-full bg-gray-900/90 text-gray-200 text-xs border border-gray-700 rounded-xl px-3 py-2.5 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/30 outline-none transition resize-none"></textarea>
                </div>
            </div>

            <!-- Chat Area -->
            <div class="flex-1 glass rounded-2xl border border-gray-800 flex flex-col min-h-0 overflow-hidden">
                <!-- Chat Messages -->
                <div id="chatMessages" class="flex-1 overflow-y-auto p-5 space-y-4 custom-scrollbar">
                    <div class="flex items-center justify-center h-full text-gray-600">
                        <div class="text-center space-y-3">
                            <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-indigo-600/20 to-purple-600/20 flex items-center justify-center mx-auto border border-indigo-500/20">
                                <i class="fa-solid fa-waveform-lines text-2xl text-indigo-400"></i>
                            </div>
                            <p class="text-sm font-semibold text-gray-400">Realtime Console</p>
                            <p class="text-xs text-gray-500">Conecte-se e comece a conversar por texto ou voz</p>
                        </div>
                    </div>
                </div>

                <!-- Input Area -->
                <div class="border-t border-gray-800 p-4">
                    <div class="flex items-center gap-3">
                        <!-- BotÃ£o de Voz (Clique para Iniciar/Parar e Enviar) -->
                        <button id="btnMic" onclick="toggleRecording()"
                                disabled
                                title="Clique para falar e clique novamente para enviar"
                                class="w-12 h-12 rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 flex items-center justify-center transition-all cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed shrink-0 group relative">
                            <i id="micIcon" class="fa-solid fa-microphone text-gray-400 group-hover:text-indigo-400 transition text-lg"></i>
                        </button>

                        <!-- Waveform Visualizer & Timer -->
                        <div id="waveformContainer" class="hidden items-center gap-2.5 h-12 px-3.5 bg-red-500/10 border border-red-500/30 rounded-xl">
                            <span class="w-2.5 h-2.5 rounded-full bg-red-500 animate-ping"></span>
                            <span id="recordingTimer" class="text-xs font-mono font-bold text-red-400">00:00</span>
                            <div class="flex items-end gap-0.5 h-6">
                                <div class="waveform-bar w-1 bg-red-500 rounded-full"></div>
                                <div class="waveform-bar w-1 bg-orange-400 rounded-full"></div>
                                <div class="waveform-bar w-1 bg-yellow-400 rounded-full"></div>
                                <div class="waveform-bar w-1 bg-red-400 rounded-full"></div>
                                <div class="waveform-bar w-1 bg-pink-400 rounded-full"></div>
                            </div>
                            <span class="text-[11px] text-red-300 font-medium ml-1">ðŸŽ™ï¸ Ouvindo... Fale e pause que a IA responde</span>
                            <button type="button" onclick="endLiveConversation()" class="ml-2 px-2.5 py-1 bg-red-600 hover:bg-red-500 text-white rounded-lg text-xs font-bold transition cursor-pointer flex items-center gap-1.5 shadow-md shadow-red-500/30" title="Encerrar conversa de voz imediatamente">
                                <i class="fa-solid fa-phone-slash"></i> Parar
                            </button>
                        </div>

                        <!-- Text Input -->
                        <div class="flex-1 relative">
                            <input id="textInput" type="text" placeholder="Digite sua mensagem ou use o microfone..." disabled
                                   onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault();sendTextMessage();}"
                                   class="w-full bg-gray-900/90 text-gray-100 text-sm border border-gray-700 rounded-xl px-4 py-3 pr-12 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500/30 outline-none transition disabled:opacity-40 disabled:cursor-not-allowed">
                        </div>

                        <!-- Send Button -->
                        <button id="btnSend" onclick="sendTextMessage()" disabled
                                class="w-12 h-12 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white flex items-center justify-center transition-all cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed shrink-0">
                            <i class="fa-solid fa-paper-plane"></i>
                        </button>
                    </div>

                    <!-- Linha de OpÃ§Ã£o MÃ£os Livres / Auto VAD -->
                    <div class="flex items-center justify-between mt-2.5 pt-2 border-t border-gray-800/60 px-1 text-xs">
                        <label class="flex items-center gap-2 cursor-pointer text-gray-300 hover:text-white select-none">
                            <input type="checkbox" id="cfgHandsFree" checked class="rounded border-gray-700 text-indigo-600 focus:ring-indigo-500 bg-gray-900 cursor-pointer">
                            <span class="flex items-center gap-1.5 font-medium"><i class="fa-solid fa-headset text-indigo-400"></i> Auto-VAD (Envio automÃ¡tico ao pausar a fala)</span>
                        </label>
                        <span class="text-[11px] text-gray-500 hidden sm:inline"><i class="fa-solid fa-bolt text-amber-400 mr-1"></i>Conversa contÃ­nua e sem cliques</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Coluna Direita: Event Log -->
        <div class="w-full lg:w-96 glass rounded-2xl border border-gray-800 flex flex-col min-h-0 overflow-hidden shrink-0">
            <div class="p-4 border-b border-gray-800 flex items-center justify-between shrink-0">
                <h3 class="text-sm font-bold text-white flex items-center">
                    <i class="fa-solid fa-list-timeline text-cyan-400 mr-2"></i> Log de Eventos
                </h3>
                <div class="flex items-center space-x-2">
                    <span id="eventCount" class="text-[10px] font-mono text-gray-500 bg-gray-800 px-2 py-0.5 rounded">0 eventos</span>
                    <button onclick="clearEventLog()" class="text-xs text-gray-500 hover:text-red-400 transition" title="Limpar log">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </div>
            </div>
            <div id="eventLog" class="flex-1 overflow-y-auto p-3 space-y-1 custom-scrollbar text-[11px]">
                <div class="text-center text-gray-600 py-10">
                    <i class="fa-solid fa-satellite-dish text-2xl mb-2 block text-gray-700"></i>
                    <p>Eventos WebSocket aparecerÃ£o aqui</p>
                </div>
            </div>
            <!-- Usage Footer -->
            <div class="p-3 border-t border-gray-800 shrink-0">
                <div class="flex items-center justify-between text-[10px] text-gray-500">
                    <span><i class="fa-solid fa-coins text-indigo-400 mr-1"></i> Tokens: <strong id="usageTokens" class="text-gray-300">0</strong></span>
                    <span><i class="fa-solid fa-message text-purple-400 mr-1"></i> Mensagens: <strong id="usageMessages" class="text-gray-300">0</strong></span>
                    <span id="modelBadge" class="px-2 py-0.5 rounded bg-indigo-500/20 text-indigo-300 font-mono"></span>
                </div>
            </div>
        </div>
    </main>

    <!-- Toast Notification -->
    <div id="toast" class="fixed bottom-6 left-1/2 -translate-x-1/2 px-6 py-3 rounded-2xl glass border border-emerald-500/30 text-emerald-400 text-sm font-semibold shadow-2xl transform translate-y-20 opacity-0 pointer-events-none transition-all duration-500 z-50 flex items-center">
        <i class="fa-solid fa-circle-check mr-2 text-emerald-400"></i>
        <span id="toastMsg"></span>
    </div>

<script src="<?= getAppBaseUrl() ?>/assets/js/realtime-client.js?v=<?= time() ?>"></script>
<script>
function showToast(msg) {
    const t = document.getElementById('toast');
    const m = document.getElementById('toastMsg');
    if (t && m) {
        m.textContent = msg;
        t.classList.remove('translate-y-20', 'opacity-0', 'pointer-events-none');
        setTimeout(() => t.classList.add('translate-y-20', 'opacity-0', 'pointer-events-none'), 3000);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    window.realtimeConsole = new BRLoveRealtimeConsole({
        wsUrl: '<?= $wsUrl ?>',
        apiKey: '<?= htmlspecialchars($firstApiKey) ?>'
    });
    window.realtimeConsole.setVoiceMode('browser_fast');

    const cfgModel = document.getElementById('cfgModel');
    const modelBadge = document.getElementById('modelBadge');
    if (cfgModel && modelBadge) {
        modelBadge.textContent = cfgModel.value;
        cfgModel.addEventListener('change', () => {
            modelBadge.textContent = cfgModel.value;
        });
    }
});
</script>

</body>
</html>
