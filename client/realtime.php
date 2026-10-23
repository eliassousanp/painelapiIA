<?php
require_once __DIR__ . '/../config/helpers.php';
requireLogin();

// Se for administrador, abre a versão oficial do painel admin com menu lateral
if (function_exists('isAdmin') && isAdmin() && !isset($_GET['as_client'])) {
    header('Location: ' . getAppBaseUrl() . '/admin/realtime.php');
    exit;
}

$realtimeAccess = getSetting('realtime_access', 'all');

// Se o realtime for restrito para administradores e o usuário atual não for admin
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
                O console de voz em tempo real está temporariamente configurado para acesso exclusivo dos administradores. Ele será liberado em breve para todos os clientes!
            </p>
            <a href="<?= getAppBaseUrl() ?>/client/index.php" class="inline-flex items-center justify-center px-6 py-3 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white font-bold text-xs shadow-lg transition">
                <i class="fa-solid fa-arrow-left mr-2"></i> Voltar para Área do Cliente
            </a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$db = getDB();
$userId = $_SESSION['user_id'];

// Buscar dados do usuário
$stmtUser = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$user = $stmtUser->fetch();

// Buscar primeira API key ativa do usuário
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
            content: '▌';
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

    <!-- Top Navigation (Padronizado com todas as páginas do sistema) -->
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
                    <i class="fa-solid fa-gauge mr-1"></i> Área do Cliente
                </a>
                <span class="text-xs px-2.5 py-1 rounded-full bg-gradient-to-r from-indigo-500/20 to-purple-500/20 text-purple-300 font-semibold border border-purple-500/30 flex items-center">
                    <i class="fa-solid fa-waveform-lines mr-1 text-purple-400"></i> Realtime
                </span>
            </div>

            <!-- Centro: Links de Navegação das Outras Seções do Painel -->
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

            <!-- Direita: Conexão + Saldo + WhatsApp + Botões -->
            <div class="flex items-center space-x-2 sm:space-x-3">
                <!-- Status de Conexão WebSocket -->
                <div id="connectionStatus" class="flex items-center space-x-1.5 text-xs px-2.5 py-1.5 rounded-lg border transition-all duration-300 bg-red-500/10 border-red-500/30 text-red-400">
                    <span id="statusDot" class="w-2 h-2 rounded-full bg-red-400"></span>
                    <span id="statusText" class="hidden sm:inline">Desconectado</span>
                </div>

                <!-- Saldo de Tokens -->
                <span class="text-xs text-indigo-300 font-mono hidden lg:inline-flex items-center bg-indigo-500/10 px-3 py-1.5 rounded-lg border border-indigo-500/20" title="Saldo de tokens disponível">
                    <i class="fa-solid fa-coins mr-1.5 text-indigo-400"></i> <?= number_format($user['balance_tokens'] ?? 0, 0, ',', '.') ?>
                </span>

                <!-- WhatsApp do Usuário -->
                <?php if (!empty($user['whatsapp'])): ?>
                    <span class="text-xs text-emerald-400 font-mono hidden md:inline-flex items-center bg-emerald-500/10 px-3 py-1.5 rounded-lg border border-emerald-500/20">
                        <i class="fa-brands fa-whatsapp mr-1.5 text-base"></i> <?= htmlspecialchars($user['whatsapp']); ?>
                    </span>
                <?php endif; ?>

                <!-- Link Painel Cliente -->
                <a href="<?= getAppBaseUrl() ?>/client/index.php" class="text-xs px-3 py-2 rounded-xl glass hover:bg-gray-800 text-gray-300 transition flex items-center">
                    <i class="fa-solid fa-gauge mr-1.5 text-indigo-400"></i> <span class="hidden sm:inline">Painel</span>
                </a>

                <!-- Botão Painel Admin se for Admin -->
                <?php if (function_exists('isAdmin') && isAdmin()): ?>
                    <a href="<?= getAppBaseUrl() ?>/admin/index.php" class="text-xs px-3 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white font-medium shadow-md transition flex items-center">
                        <i class="fa-solid fa-shield-halved mr-1.5"></i> Admin
                    </a>
                <?php endif; ?>

                <!-- Botão Sair -->
                <a href="<?= getAppBaseUrl() ?>/logout.php" title="Sair da Conta" class="text-xs px-3 py-2 rounded-xl glass hover:bg-red-500/20 hover:text-red-400 text-gray-300 transition flex items-center">
                    <i class="fa-solid fa-right-from-bracket sm:mr-1"></i> <span class="hidden sm:inline">Sair</span>
                </a>
            </div>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="flex-1 max-w-[1600px] mx-auto w-full px-4 sm:px-6 lg:px-8 py-6 flex flex-col lg:flex-row gap-5" style="height: calc(100vh - 64px);">

        <!-- Coluna Esquerda: Configuração + Chat -->
        <div class="flex flex-col flex-1 min-w-0 gap-5">

            <!-- Configurações -->
            <div class="glass rounded-2xl p-5 border border-gray-800 shrink-0">
                <div class="flex items-center justify-between mb-4">
                    <h2 class="text-sm font-bold text-white flex items-center">
                        <i class="fa-solid fa-sliders text-indigo-400 mr-2"></i> Configuração da Sessão
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
                                <option value="" disabled selected>⚠️ Nenhum modelo Realtime ativado no painel</option>
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
                            <optgroup label="⚡ Super Rápida (Zero Latência)" class="bg-gray-900 text-cyan-300">
                                <option value="browser_fast">⚡ Voz Local Navegador (Ultra Rápida / 0ms)</option>
                            </optgroup>
                            <optgroup label="Português (Brasil) - Voz Neural" class="bg-gray-900 text-white">
                                <option value="francisca" selected>🇧🇷 Francisca (Feminina)</option>
                                <option value="antonio">🇧🇷 Antônio (Masculino)</option>
                                <option value="thalita">🇧🇷 Thalita (Feminina)</option>
                            </optgroup>
                            <optgroup label="English (US) - Neural" class="bg-gray-900 text-white">
                                <option value="aria">🇺🇸 Aria (EN Feminina)</option>
                                <option value="guy">🇺🇸 Guy (EN Masculino)</option>
                                <option value="jenny">🇺🇸 Jenny (EN Feminina)</option>
                                <option value="andrew">🇺🇸 Andrew (EN Masculino)</option>
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

                    <!-- Botão Conectar -->
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
                            Pesquisa Web ao Vivo (Notícias, Cotações e Fatos de Hoje)
                        </span>
                    </label>
                    <span class="text-[11px] text-gray-500 flex items-center gap-1">
                        <i class="fa-solid fa-bolt text-amber-400"></i> Grounding em tempo real via Gateway
                    </span>
                </div>

                <!-- Instruções do Sistema (colapsável) -->
                <div class="mt-3">
                    <label class="block text-[11px] font-semibold text-gray-400 uppercase tracking-wider mb-1.5">
                        <i class="fa-solid fa-scroll text-cyan-400 mr-1"></i> Instruções do Sistema (opcional)
                    </label>
                    <textarea id="cfgInstructions" onblur="updateSession()" placeholder="Ex: Você é um assistente amigável e responde sempre em português..." rows="2" class="w-full bg-gray-900/90 text-gray-200 text-xs border border-gray-700 rounded-xl px-3 py-2.5 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/30 outline-none transition resize-none"></textarea>
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
                        <!-- Botão de Voz (Clique para Iniciar/Parar e Enviar) -->
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
                            <span class="text-[11px] text-red-300 font-medium ml-1">🎙️ Ouvindo... Fale e pause que a IA responde</span>
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

                    <!-- Linha de Opção Mãos Livres / Auto VAD -->
                    <div class="flex items-center justify-between mt-2.5 pt-2 border-t border-gray-800/60 px-1 text-xs">
                        <label class="flex items-center gap-2 cursor-pointer text-gray-300 hover:text-white select-none">
                            <input type="checkbox" id="cfgHandsFree" checked class="rounded border-gray-700 text-indigo-600 focus:ring-indigo-500 bg-gray-900 cursor-pointer">
                            <span class="flex items-center gap-1.5 font-medium"><i class="fa-solid fa-headset text-indigo-400"></i> Auto-VAD (Envio automático ao pausar a fala)</span>
                        </label>
                        <span class="text-[11px] text-gray-500 hidden sm:inline"><i class="fa-solid fa-bolt text-amber-400 mr-1"></i>Conversa contínua e sem cliques</span>
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
                    <p>Eventos WebSocket aparecerão aqui</p>
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

<script>
// =============================================
// CONFIGURAÇÃO
// =============================================
const WS_URL = '<?= $wsUrl ?>';
const API_KEY = '<?= htmlspecialchars($firstApiKey) ?>';
const HTTP_BASE = '<?= $httpBase ?>';

// =============================================
// ESTADO
// =============================================
let ws = null;
let isConnected = false;
let isRecording = false;
let mediaRecorder = null;
let audioChunks = [];
let audioContext = null;
let totalTokens = 0;
let totalMessages = 0;
let eventCounter = 0;
let currentStreamingEl = null;
let reconnectAttempts = 0;
let reconnectTimer = null;
let currentAudioPlayer = null;
let audioQueue = []; // fila de chunks base64 de áudio
let recordingStartTime = 0;
let recordingTimerInterval = null;
let speechRecognizer = null;
let recognizedText = '';
let vadSilenceTimer = null;
let vadAudioContext = null;
let vadAnalyser = null;
let vadMicrophone = null;
let vadInterval = null;
let isAudioSpeaking = false;
let lastAudioSoundTime = 0;
let lastRenderedUserMsg = { text: '', time: 0 };
let isResponseStreamingActive = false;

// Inicialização do SpeechRecognition Nativo (STT no Navegador com Baixíssima Latência)
try {
    const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (SpeechRec) {
        speechRecognizer = new SpeechRec();
        speechRecognizer.continuous = true;
        speechRecognizer.interimResults = true;
        speechRecognizer.lang = 'pt-BR';

        speechRecognizer.onresult = (event) => {
            let full = '';
            for (let i = 0; i < event.results.length; ++i) {
                full += event.results[i][0].transcript + ' ';
            }
            recognizedText = full.trim();
            const textInput = document.getElementById('textInput');
            if (textInput && isRecording) {
                textInput.placeholder = '🎙️ Ouvindo: "' + recognizedText + '"';
            }
            triggerVadSilenceCheck();
        };

        speechRecognizer.onerror = (e) => {
            console.warn('[Realtime SpeechRec Warn]', e.error);
        };
    }
} catch (e) {
    console.warn('SpeechRecognition nativo indisponível:', e);
}

// =============================================
// CONEXÃO WEBSOCKET
// =============================================
function toggleConnection() {
    if (isConnected) {
        disconnect();
    } else {
        connect();
    }
}

function connect() {
    if (ws && ws.readyState <= 1) return;

    if (!API_KEY) {
        showToast('⚠️ Nenhuma API Key encontrada. Crie uma no painel primeiro.');
        return;
    }

    setStatus('connecting');
    const url = WS_URL + '?api_key=' + encodeURIComponent(API_KEY);

    try {
        ws = new WebSocket(url);
    } catch (e) {
        setStatus('error');
        logEvent('error', { message: 'Falha ao criar WebSocket: ' + e.message }, 'client');
        return;
    }

    ws.onopen = () => {
        reconnectAttempts = 0;
        logEvent('connection.opened', {}, 'client');
    };

    ws.onmessage = (evt) => {
        let data;
        try {
            data = JSON.parse(evt.data);
        } catch (e) {
            return;
        }

        handleServerEvent(data);
    };

    ws.onclose = (evt) => {
        isConnected = false;
        setStatus('disconnected');
        setControlsEnabled(false);
        logEvent('connection.closed', { code: evt.code, reason: evt.reason || 'N/A' }, 'client');

        // Reconexão automática (exceto se fechou por auth error)
        if (evt.code !== 4001 && reconnectAttempts < 5) {
            const delay = Math.min(1000 * Math.pow(2, reconnectAttempts), 16000);
            reconnectAttempts++;
            logEvent('connection.reconnecting', { attempt: reconnectAttempts, delay_ms: delay }, 'client');
            reconnectTimer = setTimeout(connect, delay);
        }
    };

    ws.onerror = () => {
        logEvent('connection.error', {}, 'client');
    };
}

function disconnect() {
    endLiveConversation();
    if (reconnectTimer) clearTimeout(reconnectTimer);
    reconnectAttempts = 999; // impede reconexão
    if (ws) ws.close(1000, 'Desconectado pelo usuário');
    setStatus('disconnected');
    setControlsEnabled(false);
}

function sendWsEvent(type, data = {}) {
    if (!ws || ws.readyState !== 1) return;
    const evt = { type, ...data };
    ws.send(JSON.stringify(evt));
    logEvent(type, data, 'client');
}

// =============================================
// HANDLER DE EVENTOS DO SERVIDOR
// =============================================
function handleServerEvent(data) {
    const type = data.type;
    logEvent(type, data, 'server');

    switch (type) {
        case 'session.created':
            isConnected = true;
            setStatus('connected');
            setControlsEnabled(true);
            updateSession(); // enviar config inicial
            break;

        case 'session.updated':
            if (data.session?.model) {
                document.getElementById('modelBadge').textContent = data.session.model;
            }
            break;

        case 'input_audio_buffer.committed':
            // áudio commitado, esperando transcrição
            break;

        case 'input_audio_buffer.speech_started':
            addSystemMessage('🎤 Processando áudio...', 'processing');
            break;

        case 'response.web_search.started':
            addSystemMessage('🌐 Pesquisando na Web em tempo real: "' + (data.query || '') + '"...', 'websearch');
            break;

        case 'response.web_search.completed':
            removeSystemMessage('websearch');
            if (data.count > 0) {
                addSystemMessage('✅ ' + data.count + ' fontes consultadas ao vivo na internet.', 'websearch-done');
                setTimeout(() => removeSystemMessage('websearch-done'), 4000);
            }
            break;

        case 'conversation.item.created':
            if (data.item?.role === 'user') {
                const text = data.item.content?.[0]?.transcript || data.item.content?.[0]?.text || '';
                if (text) {
                    removeSystemMessage('processing');
                    addChatMessage('user', text);
                    totalMessages++;
                    updateUsage();
                }
            }
            break;

        case 'conversation.item.input_audio_transcription.completed':
            // já tratado em conversation.item.created
            break;

        case 'response.created':
            isResponseStreamingActive = true;
            currentStreamingEl = addChatMessage('assistant', '', true);
            break;

        case 'response.text.delta':
            if (currentStreamingEl && data.delta) {
                appendToStreamingMessage(currentStreamingEl, data.delta);
            }
            break;

        case 'response.text.done':
            if (currentStreamingEl) {
                finalizeStreamingMessage(currentStreamingEl);
                currentStreamingEl = null;
            }
            break;

        case 'response.audio.delta':
            if (data.delta) {
                enqueueSentenceAudio(data.delta, data.transcript);
            }
            break;

        case 'response.audio.done':
            // Áudio concluído
            break;

        case 'response.cancelled':
            console.log('[Realtime Client] Resposta cancelada pelo servidor.');
            if (currentAudioPlayer) {
                try { currentAudioPlayer.pause(); currentAudioPlayer.currentTime = 0; } catch (e) {}
                currentAudioPlayer = null;
            }
            audioSentenceQueue = [];
            isPlayingAudioSentence = false;
            isResponseStreamingActive = false;
            break;

        case 'response.done':
            isResponseStreamingActive = false;
            if (data.response?.usage) {
                totalTokens += data.response.usage.total_tokens || 0;
                totalMessages++;
                updateUsage();
            }
            if (!isPlayingAudioSentence) {
                checkAutoContinueSpeech();
            }
            break;

        case 'error':
            const errMsg = data.message || data.error?.message || 'Erro desconhecido';
            addSystemMessage('❌ ' + errMsg, 'error');
            break;
    }
}

// =============================================
// SESSÃO
// =============================================
function updateSession() {
    if (!isConnected) return;
    const wsCheck = document.getElementById('cfgWebSearch');
    sendWsEvent('session.update', {
        session: {
            model: document.getElementById('cfgModel').value,
            voice: document.getElementById('cfgVoice').value,
            temperature: parseFloat(document.getElementById('cfgTemp').value),
            instructions: document.getElementById('cfgInstructions').value,
            web_search: wsCheck ? wsCheck.checked : true
        }
    });
}

// =============================================
// ENVIAR MENSAGEM DE TEXTO
// =============================================
function sendTextMessage() {
    const input = document.getElementById('textInput');
    const text = input.value.trim();
    if (!text || !isConnected) return;

    input.value = '';

    // Adicionar item de conversa
    sendWsEvent('conversation.item.create', {
        item: {
            role: 'user',
            content: [{ type: 'text', text: text }]
        }
    });

    // Solicitar resposta
    sendWsEvent('response.create', { generate_audio: true });
}



// =============================================
// GRAVAÇÃO DE ÁUDIO COM AUTO-VAD INTELIGENTE
// =============================================
// SESSÃO DE VOZ CONTÍNUA & AUTO-VAD (LIVE VOICE)
// =============================================
let activeMicStream = null;
let isLiveCallActive = false;

function toggleRecording() {
    if (isLiveCallActive || isRecording || isPlayingAudioSentence || isResponseStreamingActive) {
        endLiveConversation();
    } else {
        startLiveConversation();
    }
}

async function startLiveConversation() {
    if (!isConnected) {
        addSystemMessage('⚠️ Conecte o WebSocket primeiro antes de iniciar a conversa de voz.', 'error');
        return;
    }

    try {
        if (!activeMicStream) {
            activeMicStream = await navigator.mediaDevices.getUserMedia({ audio: true });
        }
        isLiveCallActive = true;
        startListeningCycle();
    } catch (err) {
        console.error('Erro ao acessar microfone:', err);
        addSystemMessage('⚠️ Não foi possível acessar o microfone. Verifique as permissões.', 'error');
        endLiveConversation();
    }
}

function startListeningCycle() {
    if (!isLiveCallActive || !isConnected || isRecording) return;

    // Barge-in: se a IA ainda estiver falando, corta imediatamente
    if (currentAudioPlayer) {
        try { currentAudioPlayer.pause(); } catch (e) {}
        currentAudioPlayer = null;
    }
    if (window.speechSynthesis && window.speechSynthesis.speaking) {
        try { window.speechSynthesis.cancel(); } catch (e) {}
    }
    audioSentenceQueue = [];
    isPlayingAudioSentence = false;

    isRecording = true;
    recordingStartTime = Date.now();
    recognizedText = '';
    isAudioSpeaking = false;
    lastAudioSoundTime = Date.now();

    // UI de Escuta Ativa
    const btn = document.getElementById('btnMic');
    btn.className = 'w-12 h-12 rounded-xl bg-red-600 hover:bg-red-500 border border-red-500 flex items-center justify-center transition-all cursor-pointer shrink-0 group relative recording-pulse shadow-lg shadow-red-500/30';
    btn.title = '🎙️ Microfone ouvindo... Fale e pause para enviar (ou clique para desligar)';
    document.getElementById('micIcon').className = 'fa-solid fa-microphone text-white text-lg animate-pulse';

    const waveContainer = document.getElementById('waveformContainer');
    waveContainer.classList.remove('hidden');
    waveContainer.classList.add('flex');
    const waveText = waveContainer.querySelector('span:last-child');
    if (waveText) waveText.textContent = '🎙️ Ouvindo... Pode falar normalmente';

    const textInput = document.getElementById('textInput');
    if (textInput) textInput.placeholder = '🎙️ Ouvindo você falar...';

    // Inicia Speech Recognition
    if (speechRecognizer) {
        try { speechRecognizer.start(); } catch (e) {}
    }

    // Analisador RMS via Web Audio API
    try {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (AudioContextClass && activeMicStream) {
            vadAudioContext = new AudioContextClass();
            vadAnalyser = vadAudioContext.createAnalyser();
            vadAnalyser.fftSize = 256;
            vadMicrophone = vadAudioContext.createMediaStreamSource(activeMicStream);
            vadMicrophone.connect(vadAnalyser);

            const dataArray = new Uint8Array(vadAnalyser.frequencyBinCount);
            vadInterval = setInterval(() => {
                if (!isRecording) return;
                vadAnalyser.getByteFrequencyData(dataArray);
                let sum = 0;
                for (let i = 0; i < dataArray.length; i++) sum += dataArray[i];
                const avg = sum / dataArray.length;

                if (avg > 15) {
                    isAudioSpeaking = true;
                    lastAudioSoundTime = Date.now();
                } else if (isAudioSpeaking && (Date.now() - lastAudioSoundTime > 1200)) {
                    // Só usa o VAD acústico bruto se o SpeechRecognition não tiver capturado texto
                    if (isRecording && (!recognizedText || recognizedText.trim().length <= 1)) {
                        console.log('[Realtime VAD Audio] Silêncio acústico detectado. Enviando áudio...');
                        finishSpeakingAndSend();
                    }
                }
            }, 100);
        }
    } catch (e) {
        console.warn('[Realtime VAD Warn]', e);
    }

    // Timer visual
    const timerEl = document.getElementById('recordingTimer');
    if (timerEl) timerEl.textContent = '00:00';
    recordingTimerInterval = setInterval(() => {
        const elapsed = Math.floor((Date.now() - recordingStartTime) / 1000);
        const mins = String(Math.floor(elapsed / 60)).padStart(2, '0');
        const secs = String(elapsed % 60).padStart(2, '0');
        if (timerEl) timerEl.textContent = `${mins}:${secs}`;
    }, 500);

    // MediaRecorder no stream já aberto
    let mimeType = 'audio/webm;codecs=opus';
    if (!MediaRecorder.isTypeSupported(mimeType)) {
        mimeType = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : '';
    }

    audioChunks = [];
    try {
        mediaRecorder = mimeType ? new MediaRecorder(activeMicStream, { mimeType }) : new MediaRecorder(activeMicStream);
        mediaRecorder.ondataavailable = (e) => {
            if (e.data && e.data.size > 0) audioChunks.push(e.data);
        };
        mediaRecorder.onstop = async () => {
            const finalTranscript = recognizedText.trim();
            if (finalTranscript && isConnected) {
                const inputEl = document.getElementById('textInput');
                if (inputEl) inputEl.value = '';
                sendWsEvent('conversation.item.create', {
                    item: {
                        role: 'user',
                        content: [{ type: 'text', text: finalTranscript }]
                    }
                });
                sendWsEvent('response.create', { generate_audio: true });
                return;
            }

            // Fallback via áudio puro se não houve transcrição local
            if (audioChunks.length > 0 && isConnected) {
                const blob = new Blob(audioChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
                const reader = new FileReader();
                reader.onloadend = () => {
                    const base64 = reader.result.split(',')[1];
                    if (base64 && isConnected) {
                        addSystemMessage('🎙️ Transcrevendo áudio via IA...', 'processing');
                        sendWsEvent('input_audio_buffer.append', { audio: base64 });
                        sendWsEvent('input_audio_buffer.commit', {});
                    }
                };
                reader.readAsDataURL(blob);
            }
        };
        mediaRecorder.start(250);
    } catch (e) {
        console.warn('MediaRecorder error:', e);
    }
}

function triggerVadSilenceCheck() {
    if (vadSilenceTimer) clearTimeout(vadSilenceTimer);
    if (!isRecording) return;

    if (recognizedText.trim().length > 1) {
        vadSilenceTimer = setTimeout(() => {
            if (isRecording && recognizedText.trim().length > 1) {
                console.log('[Realtime VAD] Pausa na fala detectada! Enviando pergunta...');
                finishSpeakingAndSend();
            }
        }, 650);
    }
}

function finishSpeakingAndSend() {
    if (!isRecording) return;
    isRecording = false;

    if (recordingTimerInterval) {
        clearInterval(recordingTimerInterval);
        recordingTimerInterval = null;
    }
    if (vadSilenceTimer) {
        clearTimeout(vadSilenceTimer);
        vadSilenceTimer = null;
    }
    if (vadInterval) {
        clearInterval(vadInterval);
        vadInterval = null;
    }
    if (vadAudioContext) {
        try { vadAudioContext.close(); } catch (e) {}
        vadAudioContext = null;
    }
    if (speechRecognizer) {
        try { speechRecognizer.stop(); } catch (e) {}
    }

    // UI muda para "IA Respondendo..." sem desligar a conversa
    const btn = document.getElementById('btnMic');
    btn.className = 'w-12 h-12 rounded-xl bg-purple-600/80 hover:bg-purple-500 border border-purple-500/80 flex items-center justify-center transition-all cursor-pointer shrink-0 group relative shadow-lg shadow-purple-500/20';
    btn.title = '⚡ IA respondendo... Clique caso queira encerrar a chamada';
    document.getElementById('micIcon').className = 'fa-solid fa-volume-high text-white text-lg animate-pulse';

    const waveContainer = document.getElementById('waveformContainer');
    const waveText = waveContainer.querySelector('span:last-child');
    if (waveText) waveText.textContent = '⚡ IA pensando e respondendo...';

    const textInput = document.getElementById('textInput');
    if (textInput) textInput.placeholder = '⚡ IA respondendo por voz...';

    try {
        if (mediaRecorder && mediaRecorder.state !== 'inactive') {
            mediaRecorder.stop();
        }
    } catch (e) {}
}

function endLiveConversation() {
    isLiveCallActive = false;
    isRecording = false;
    isResponseStreamingActive = false;

    // 1. Avisa o backend para parar na hora
    sendWsEvent('response.cancel', {});

    // 2. Limpa e para imediatamente qualquer áudio em reprodução ou na fila
    if (currentAudioPlayer) {
        try {
            currentAudioPlayer.pause();
            currentAudioPlayer.currentTime = 0;
        } catch (e) {}
        currentAudioPlayer = null;
    }
    audioSentenceQueue = [];
    isPlayingAudioSentence = false;

    if (window.speechSynthesis && window.speechSynthesis.speaking) {
        try { window.speechSynthesis.cancel(); } catch (e) {}
    }

    // 3. Cancela todos os timers e fluxos
    if (recordingTimerInterval) {
        clearInterval(recordingTimerInterval);
        recordingTimerInterval = null;
    }
    if (vadSilenceTimer) {
        clearTimeout(vadSilenceTimer);
        vadSilenceTimer = null;
    }
    if (vadInterval) {
        clearInterval(vadInterval);
        vadInterval = null;
    }
    if (vadAudioContext) {
        try { vadAudioContext.close(); } catch (e) {}
        vadAudioContext = null;
    }
    if (speechRecognizer) {
        try { speechRecognizer.stop(); } catch (e) {}
    }
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        try { mediaRecorder.stop(); } catch (e) {}
    }
    if (activeMicStream) {
        activeMicStream.getTracks().forEach(t => t.stop());
        activeMicStream = null;
    }

    // 4. UI Reset Total
    const btn = document.getElementById('btnMic');
    if (btn) {
        btn.className = 'w-12 h-12 rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 flex items-center justify-center transition-all cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed shrink-0 group relative';
        btn.title = 'Clique para iniciar conversa por voz';
    }
    const micIcon = document.getElementById('micIcon');
    if (micIcon) {
        micIcon.className = 'fa-solid fa-microphone text-gray-400 group-hover:text-indigo-400 transition text-lg';
    }

    const waveContainer = document.getElementById('waveformContainer');
    if (waveContainer) {
        waveContainer.classList.add('hidden');
        waveContainer.classList.remove('flex');
    }

    const textInput = document.getElementById('textInput');
    if (textInput) {
        textInput.placeholder = 'Digite sua mensagem ou use o microfone...';
    }
}

function stopRecording() {
    finishSpeakingAndSend();
}

// =============================================
// PLAYBACK DE ÁUDIO TTS EM STREAMING POR SENTENÇA
// =============================================
let audioSentenceQueue = [];
let isPlayingAudioSentence = false;
let lastAudioUrl = null;

function enqueueSentenceAudio(base64Audio, transcript) {
    // Se a chamada foi encerrada, ignora qualquer áudio residual
    if (!isLiveCallActive && !isRecording && !isPlayingAudioSentence && audioSentenceQueue.length === 0) {
        return;
    }

    const selectedVoice = document.getElementById('cfgVoice')?.value || '';
    if (selectedVoice === 'browser_fast' && window.speechSynthesis && transcript) {
        // Modo local instantâneo do navegador
        const utter = new SpeechSynthesisUtterance(transcript);
        utter.lang = 'pt-BR';
        utter.rate = 1.1;
        window.speechSynthesis.speak(utter);
        return;
    }

    audioSentenceQueue.push({ base64: base64Audio, transcript: transcript });
    if (!isPlayingAudioSentence) {
        playNextSentenceAudio();
    }
}

function checkAutoContinueSpeech() {
    const handsFree = document.getElementById('cfgHandsFree')?.checked ?? true;
    // CRÍTICO: Só reativa a escuta se a chamada ao vivo (isLiveCallActive) estiver REALMENTE ativa!
    if (isLiveCallActive && handsFree && isConnected && !isRecording && !isResponseStreamingActive && !isPlayingAudioSentence) {
        setTimeout(() => {
            if (isLiveCallActive && handsFree && isConnected && !isRecording && !isPlayingAudioSentence && !isResponseStreamingActive) {
                console.log('[Realtime] Reativando microfone para sua próxima fala contínua...');
                startListeningCycle();
            }
        }, 400);
    }
}

function playNextSentenceAudio() {
    if (audioSentenceQueue.length === 0) {
        isPlayingAudioSentence = false;
        checkAutoContinueSpeech();
        return;
    }

    isPlayingAudioSentence = true;
    const item = audioSentenceQueue.shift();

    try {
        const audioBlob = base64ToBlob(item.base64, 'audio/mpeg');
        const audioUrl = URL.createObjectURL(audioBlob);
        lastAudioUrl = audioUrl;

        if (currentAudioPlayer) {
            try { currentAudioPlayer.pause(); } catch(e) {}
            currentAudioPlayer = null;
        }

        currentAudioPlayer = new Audio(audioUrl);
        currentAudioPlayer.playbackRate = 1.05;

        currentAudioPlayer.onended = () => {
            URL.revokeObjectURL(audioUrl);
            playNextSentenceAudio();
        };

        currentAudioPlayer.onerror = () => {
            URL.revokeObjectURL(audioUrl);
            playNextSentenceAudio();
        };

        // Adicionar botão de reprodução/replay no último balão do assistente
        const container = document.getElementById('chatMessages');
        const assistantMsgs = container.querySelectorAll('.msg-animate');
        if (assistantMsgs.length > 0) {
            const lastMsg = assistantMsgs[assistantMsgs.length - 1];
            if (lastMsg && !lastMsg.querySelector('.btn-audio-replay')) {
                const replayBtn = document.createElement('button');
                replayBtn.className = 'btn-audio-replay mt-2.5 text-[11px] text-indigo-300 hover:text-white flex items-center gap-1.5 px-3 py-1 rounded-xl bg-indigo-500/20 hover:bg-indigo-500/30 border border-indigo-500/30 transition cursor-pointer font-medium';
                replayBtn.innerHTML = '<i class="fa-solid fa-volume-high text-indigo-400"></i> Ouvir Novamente';
                replayBtn.onclick = () => {
                    const sound = new Audio(audioUrl);
                    sound.play();
                };
                const bubble = lastMsg.querySelector('[data-content]');
                if (bubble && bubble.parentElement) {
                    bubble.parentElement.appendChild(replayBtn);
                }
            }
        }

        const playPromise = currentAudioPlayer.play();
        if (playPromise !== undefined) {
            playPromise.then(() => {
                // Áudio reproduzindo
            }).catch(err => {
                console.warn('Autoplay bloqueado pelo navegador:', err);
                addSystemMessage('🔊 Clique em "Ouvir Novamente" acima para escutar a resposta em áudio.', 'info');
            });
        }
    } catch (err) {
        console.error('Erro no playback:', err);
    }
}

function base64ToBlob(base64, mimeType) {
    const byteChars = atob(base64);
    const byteArrays = [];
    for (let offset = 0; offset < byteChars.length; offset += 512) {
        const slice = byteChars.slice(offset, offset + 512);
        const byteNumbers = new Array(slice.length);
        for (let i = 0; i < slice.length; i++) {
            byteNumbers[i] = slice.charCodeAt(i);
        }
        byteArrays.push(new Uint8Array(byteNumbers));
    }
    return new Blob(byteArrays, { type: mimeType });
}

// =============================================
// UI — CHAT MESSAGES
// =============================================
function addChatMessage(role, text, isStreaming = false) {
    if (role === 'user') {
        const clean = (text || '').trim();
        const now = Date.now();
        if (clean && lastRenderedUserMsg.text === clean && (now - lastRenderedUserMsg.time < 2500)) {
            // Ignora duplicata idêntica recente
            return null;
        }
        lastRenderedUserMsg = { text: clean, time: now };
    }

    const container = document.getElementById('chatMessages');

    // Remove placeholder
    if (container.querySelector('.text-gray-600')) {
        container.innerHTML = '';
    }

    const msgDiv = document.createElement('div');
    msgDiv.className = `msg-animate flex ${role === 'user' ? 'justify-end' : 'justify-start'}`;

    const isUser = role === 'user';
    const bubbleClasses = isUser
        ? 'bg-indigo-600/30 border-indigo-500/30 text-gray-100'
        : 'bg-gray-800/60 border-gray-700/50 text-gray-200';

    const icon = isUser
        ? '<i class="fa-solid fa-user text-indigo-400"></i>'
        : '<i class="fa-solid fa-robot text-purple-400"></i>';

    msgDiv.innerHTML = `
        <div class="max-w-[80%] ${isUser ? 'order-2' : ''}">
            <div class="flex items-center gap-2 mb-1 ${isUser ? 'justify-end' : ''}">
                <span class="w-6 h-6 rounded-lg ${isUser ? 'bg-indigo-500/20' : 'bg-purple-500/20'} flex items-center justify-center text-xs">${icon}</span>
                <span class="text-[10px] text-gray-500 font-semibold uppercase">${isUser ? 'Você' : 'Assistente'}</span>
            </div>
            <div class="px-4 py-3 rounded-2xl border ${bubbleClasses} text-sm leading-relaxed ${isStreaming ? 'streaming-cursor' : ''}" data-content>
                ${text || ''}
            </div>
        </div>
    `;

    container.appendChild(msgDiv);
    container.scrollTop = container.scrollHeight;

    return msgDiv;
}

function appendToStreamingMessage(el, delta) {
    const contentEl = el.querySelector('[data-content]');
    if (contentEl) {
        contentEl.textContent += delta;
    }
    const container = document.getElementById('chatMessages');
    container.scrollTop = container.scrollHeight;
}

function finalizeStreamingMessage(el) {
    const contentEl = el.querySelector('[data-content]');
    if (contentEl) {
        contentEl.classList.remove('streaming-cursor');
    }
}

function addSystemMessage(text, msgType = 'info') {
    const container = document.getElementById('chatMessages');
    if (container.querySelector('.text-gray-600')) {
        container.innerHTML = '';
    }

    const msgDiv = document.createElement('div');
    msgDiv.className = 'msg-animate flex justify-center';
    if (msgType) msgDiv.setAttribute('data-msg-type', msgType);

    const colors = {
        info: 'bg-gray-800/40 text-gray-400 border-gray-700/30',
        error: 'bg-red-500/10 text-red-400 border-red-500/20',
        processing: 'bg-indigo-500/10 text-indigo-300 border-indigo-500/20'
    };

    msgDiv.innerHTML = `
        <div class="px-4 py-2 rounded-xl border text-xs ${colors[msgType] || colors.info}">
            ${text}
        </div>
    `;

    container.appendChild(msgDiv);
    container.scrollTop = container.scrollHeight;
}

function removeSystemMessage(msgType) {
    const container = document.getElementById('chatMessages');
    const el = container.querySelector(`[data-msg-type="${msgType}"]`);
    if (el) el.remove();
}

// =============================================
// UI — EVENT LOG
// =============================================
function logEvent(type, data, source = 'server') {
    eventCounter++;
    const container = document.getElementById('eventLog');

    // Remove placeholder
    if (container.querySelector('.text-gray-600')) {
        container.innerHTML = '';
    }

    const now = new Date().toLocaleTimeString('pt-BR', { hour12: false });
    const isServer = source === 'server';

    // Cor do tipo de evento
    let typeColor = 'text-gray-400';
    if (type.startsWith('error')) typeColor = 'text-red-400';
    else if (type.startsWith('session')) typeColor = 'text-emerald-400';
    else if (type.startsWith('response')) typeColor = 'text-indigo-400';
    else if (type.startsWith('input_audio')) typeColor = 'text-amber-400';
    else if (type.startsWith('conversation')) typeColor = 'text-purple-400';
    else if (type.startsWith('connection')) typeColor = 'text-cyan-400';

    const arrow = isServer ? '⬇' : '⬆';
    const arrowColor = isServer ? 'text-emerald-500' : 'text-blue-400';

    // Limpar campos volumosos para o log
    const logData = { ...data };
    delete logData.type;
    delete logData.event_id;
    if (logData.audio) logData.audio = `[base64 ${logData.audio.length} chars]`;
    if (logData.delta && logData.delta.length > 100) logData.delta = logData.delta.slice(0, 80) + '...';

    const hasDetails = Object.keys(logData).length > 0;

    const item = document.createElement('div');
    item.className = 'event-log-item py-1.5 px-2 rounded-lg hover:bg-gray-800/50 transition cursor-default border-l-2 border-transparent hover:border-indigo-500/30';

    item.innerHTML = `
        <div class="flex items-center gap-1.5">
            <span class="text-gray-600 shrink-0">${now}</span>
            <span class="${arrowColor} shrink-0">${arrow}</span>
            <span class="${typeColor} font-semibold truncate">${type}</span>
        </div>
        ${hasDetails ? `<div class="mt-0.5 text-gray-600 truncate pl-[72px]">${JSON.stringify(logData).slice(0, 120)}</div>` : ''}
    `;

    container.appendChild(item);
    container.scrollTop = container.scrollHeight;

    document.getElementById('eventCount').textContent = eventCounter + ' eventos';
}

function clearEventLog() {
    document.getElementById('eventLog').innerHTML = '';
    eventCounter = 0;
    document.getElementById('eventCount').textContent = '0 eventos';
}

// =============================================
// UI — STATUS & HELPERS
// =============================================
function setStatus(status) {
    const statusEl = document.getElementById('connectionStatus');
    const dot = document.getElementById('statusDot');
    const text = document.getElementById('statusText');
    const btn = document.getElementById('btnConnect');

    const configs = {
        disconnected: {
            bg: 'bg-red-500/10 border-red-500/30 text-red-400',
            dot: 'bg-red-400',
            text: 'Desconectado',
            btn: '<i class="fa-solid fa-plug mr-2"></i> Conectar',
            btnClass: 'bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500'
        },
        connecting: {
            bg: 'bg-amber-500/10 border-amber-500/30 text-amber-400',
            dot: 'bg-amber-400 status-pulse',
            text: 'Conectando...',
            btn: '<i class="fa-solid fa-spinner fa-spin mr-2"></i> Conectando',
            btnClass: 'bg-amber-600/50 cursor-wait'
        },
        connected: {
            bg: 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400',
            dot: 'bg-emerald-400 status-pulse',
            text: 'Conectado',
            btn: '<i class="fa-solid fa-plug-circle-xmark mr-2"></i> Desconectar',
            btnClass: 'bg-red-600/80 hover:bg-red-500'
        },
        error: {
            bg: 'bg-red-500/10 border-red-500/30 text-red-400',
            dot: 'bg-red-400',
            text: 'Erro',
            btn: '<i class="fa-solid fa-plug mr-2"></i> Reconectar',
            btnClass: 'bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500'
        }
    };

    const cfg = configs[status] || configs.disconnected;

    statusEl.className = `flex items-center space-x-2 text-xs px-3 py-1.5 rounded-lg border transition-all duration-300 ${cfg.bg}`;
    dot.className = `w-2 h-2 rounded-full ${cfg.dot}`;
    text.textContent = cfg.text;
    btn.innerHTML = cfg.btn;
    btn.className = `w-full py-2.5 rounded-xl ${cfg.btnClass} text-white font-bold text-xs shadow-lg transition-all flex items-center justify-center cursor-pointer`;
}

function setControlsEnabled(enabled) {
    document.getElementById('textInput').disabled = !enabled;
    document.getElementById('btnSend').disabled = !enabled;
    document.getElementById('btnMic').disabled = !enabled;
}

function updateUsage() {
    document.getElementById('usageTokens').textContent = totalTokens.toLocaleString('pt-BR');
    document.getElementById('usageMessages').textContent = totalMessages;
}

function toggleConfig() {
    const panel = document.getElementById('configPanel');
    const chevron = document.getElementById('configChevron');
    const instructions = document.getElementById('cfgInstructions')?.parentElement;

    if (panel.style.display === 'none') {
        panel.style.display = '';
        if (instructions) instructions.style.display = '';
        chevron.className = 'fa-solid fa-chevron-up';
    } else {
        panel.style.display = 'none';
        if (instructions) instructions.style.display = 'none';
        chevron.className = 'fa-solid fa-chevron-down';
    }
}

function showToast(message) {
    const toast = document.getElementById('toast');
    document.getElementById('toastMsg').innerText = message;
    toast.classList.remove('translate-y-20', 'opacity-0', 'pointer-events-none');
    setTimeout(() => {
        toast.classList.add('translate-y-20', 'opacity-0', 'pointer-events-none');
    }, 3000);
}

// Init: set model badge
document.getElementById('modelBadge').textContent = document.getElementById('cfgModel').value;
document.getElementById('cfgModel').addEventListener('change', () => {
    document.getElementById('modelBadge').textContent = document.getElementById('cfgModel').value;
});
</script>

</body>
</html>
