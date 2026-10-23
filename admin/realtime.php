<?php
require_once __DIR__ . '/../config/helpers.php';
requireAdmin();

$db = getDB();
$userId = $_SESSION['user_id'] ?? 1;

// Buscar primeira API key ativa
$stmtKeys = $db->query("SELECT api_key FROM api_keys WHERE status = 'active' ORDER BY id ASC LIMIT 1");
$keyRow = $stmtKeys ? $stmtKeys->fetch() : null;
$firstApiKey = $keyRow ? $keyRow['api_key'] : '';

// Buscar modelos ativos com is_realtime = 1
try {
    $stmtModels = $db->query("SELECT model_key, name, is_admin_only FROM models WHERE status = 'active' AND is_realtime = 1 ORDER BY id ASC");
    $activeModels = $stmtModels ? $stmtModels->fetchAll() : [];
} catch (Exception $e) {
    $activeModels = [];
}

$httpBase = getGatewayPublicUrl();
$wsUrl = getGatewayWsUrl();

$pageTitle = 'Console Realtime (Voz & Streaming Ultra Rápido)';
$pageHeaderIcon = '<i class="fa-solid fa-bolt text-amber-400 mr-2.5"></i>';

require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* Waveform / Visualizador de Áudio Reativo */
    .waveform-bar {
        animation: waveAnim 1.1s ease-in-out infinite;
        transform-origin: bottom;
    }
    .waveform-bar:nth-child(1) { animation-delay: 0.0s; height: 10px; }
    .waveform-bar:nth-child(2) { animation-delay: 0.2s; height: 22px; }
    .waveform-bar:nth-child(3) { animation-delay: 0.4s; height: 28px; }
    .waveform-bar:nth-child(4) { animation-delay: 0.15s; height: 16px; }
    .waveform-bar:nth-child(5) { animation-delay: 0.35s; height: 24px; }
    .waveform-bar.idle { animation: none !important; height: 4px !important; }

    @keyframes waveAnim {
        0%, 100% { transform: scaleY(0.3); }
        50% { transform: scaleY(1); }
    }

    /* Pulso gravação ativa */
    @keyframes pulseRing {
        0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.6); }
        70% { box-shadow: 0 0 0 16px rgba(239, 68, 68, 0); }
        100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); }
    }
    .recording-ring { animation: pulseRing 1.5s infinite; }

    /* Pulso Turbo */
    @keyframes turboGlow {
        0%, 100% { border-color: rgba(6, 182, 212, 0.4); box-shadow: 0 0 10px rgba(6, 182, 212, 0.1); }
        50% { border-color: rgba(6, 182, 212, 0.9); box-shadow: 0 0 18px rgba(6, 182, 212, 0.3); }
    }
    .turbo-active { animation: turboGlow 2.5s infinite; }

    /* Streaming cursor */
    @keyframes blink { 0%, 50% { opacity: 1; } 51%, 100% { opacity: 0; } }
    .streaming-cursor::after {
        content: '▋';
        animation: blink 0.7s infinite;
        color: #818cf8;
        margin-left: 2px;
    }

    /* Mensagens Fade In */
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(6px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .msg-animate { animation: fadeInUp 0.22s ease-out; }

    /* Scrollbar personalizada */
    .custom-scrollbar::-webkit-scrollbar { width: 5px; height: 5px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(99, 102, 241, 0.25); border-radius: 4px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(99, 102, 241, 0.55); }

    .status-pulse { animation: statusPulse 2s infinite; }
    @keyframes statusPulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

    /* Chips rápidos */
    .quick-chip {
        transition: all 0.2s ease;
    }
    .quick-chip:hover {
        transform: translateY(-1px);
        background: rgba(99, 102, 241, 0.25);
        border-color: rgba(129, 140, 248, 0.5);
    }
</style>

<div class="flex flex-col space-y-3" style="height: calc(100vh - 120px); min-height: 560px;">

    <!-- BARRA SUPERIOR DE CONTROLE E STATUS (DESIGN MODERNO ULTRA COMPACTO) -->
    <div class="glass-card px-4 py-2.5 rounded-2xl border border-gray-800/90 flex flex-wrap items-center justify-between gap-3 text-xs shrink-0 shadow-lg bg-gray-900/70 backdrop-blur-xl">
        <!-- Grupo Esquerdo: Modelo, Voz Turbo/HD, Web Search e Ajustes -->
        <div class="flex items-center gap-2.5 flex-wrap">
            <!-- Modelo Realtime -->
            <div class="flex items-center gap-1.5 bg-gray-900/95 border border-gray-700/80 rounded-xl px-3 py-1.5 shadow-inner">
                <i class="fa-solid fa-microchip text-indigo-400 text-xs"></i>
                <select id="cfgModel" onchange="updateSession()" class="bg-transparent text-white font-semibold text-xs outline-none cursor-pointer">
                    <?php if (empty($activeModels)): ?>
                        <option value="" disabled selected class="bg-gray-900 text-amber-300">⚠️ Nenhum modelo Realtime ativo</option>
                    <?php else: ?>
                        <?php foreach ($activeModels as $m): ?>
                            <option value="<?= htmlspecialchars($m['model_key']) ?>" class="bg-gray-900 text-white">
                                <?= htmlspecialchars($m['name']) ?> <?= !empty($m['is_admin_only']) ? '(🔒 Admin)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <!-- TOGGLE RÁPIDO: TURBO (0ms) vs NEURAL HD -->
            <div class="flex items-center bg-gray-950 p-1 rounded-xl border border-cyan-500/40 shadow-inner">
                <button type="button" id="btnModeTurbo" onclick="setVoiceMode('browser_fast')" 
                        class="px-2.5 py-1 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 bg-cyan-500 text-gray-950 shadow">
                    <i class="fa-solid fa-bolt text-amber-300"></i>
                    <span>Modo Turbo <strong class="hidden sm:inline font-mono text-[10px] bg-cyan-900/60 text-cyan-200 px-1 rounded ml-0.5">~800ms</strong></span>
                </button>
                <button type="button" id="btnModeHD" onclick="setVoiceMode('francisca')" 
                        class="px-2.5 py-1 rounded-lg text-xs font-medium text-gray-400 hover:text-white transition-all flex items-center gap-1.5">
                    <i class="fa-solid fa-sparkles text-purple-400"></i>
                    <span>Voz HD Neural</span>
                </button>
            </div>

            <!-- Seletor de Voz Escondido/Secundário para opções avançadas -->
            <div class="hidden sm:flex items-center gap-1.5 bg-gray-900/90 border border-gray-700/80 rounded-xl px-2.5 py-1.5">
                <i class="fa-solid fa-volume-high text-purple-400 text-xs"></i>
                <select id="cfgVoice" onchange="onVoiceSelectChange()" class="bg-transparent text-gray-200 font-medium text-xs outline-none cursor-pointer">
                    <optgroup label="⚡ Resposta Imediata" class="bg-gray-900 text-cyan-300">
                        <option value="browser_fast" selected>⚡ Voz Local (Zero Latência / 0ms TTS)</option>
                    </optgroup>
                    <optgroup label="Português (Brasil) - Voz Neural Estúdio" class="bg-gray-900 text-white">
                        <option value="francisca">Francisca (Feminina Neural HD)</option>
                        <option value="antonio">Antônio (Masculino Neural HD)</option>
                        <option value="thalita">Thalita (Feminina Neural HD)</option>
                    </optgroup>
                    <optgroup label="English (US) - Neural" class="bg-gray-900 text-white">
                        <option value="aria">Aria (EN)</option>
                        <option value="guy">Guy (EN)</option>
                    </optgroup>
                </select>
            </div>

            <!-- Pesquisa Web em Tempo Real -->
            <label class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-gray-800/90 border border-gray-700/80 hover:border-emerald-500/50 text-emerald-400 cursor-pointer text-xs font-medium transition" title="Permite que a IA pesquise cotações, notícias e dados ao vivo">
                <input type="checkbox" id="cfgWebSearch" checked onchange="updateSession()" class="rounded bg-gray-900 border-gray-700 text-emerald-500 focus:ring-0 cursor-pointer accent-emerald-500">
                <i class="fa-solid fa-globe text-emerald-400"></i>
                <span class="hidden md:inline">Web Search</span>
            </label>

            <!-- Prompt do Sistema -->
            <button onclick="openInstructionsModal()" class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-gray-800/90 hover:bg-gray-700 border border-gray-700 text-gray-300 hover:text-white transition cursor-pointer text-xs font-medium" title="Configurar instruções e personalidade da IA">
                <i class="fa-solid fa-sliders text-amber-400"></i>
                <span class="hidden lg:inline">Instruções</span>
            </button>
        </div>

        <!-- Grupo Direito: Latência, Status, Conectar & Ações -->
        <div class="flex items-center gap-2">
            <!-- Badge de Latência Dinâmica -->
            <div id="badgeLatency" class="hidden sm:flex items-center gap-1.5 px-2.5 py-1 rounded-xl bg-gray-950 border border-gray-800 text-gray-400 text-xs font-mono font-bold" title="Tempo total da última resposta">
                <i class="fa-solid fa-stopwatch text-cyan-400"></i>
                <span id="valLatency">-- ms</span>
            </div>

            <!-- Status da Conexão -->
            <div id="connectionStatus" class="flex items-center space-x-1.5 px-2.5 py-1 rounded-xl border transition-all duration-300 bg-red-500/10 border-red-500/30 text-red-400 font-medium text-xs">
                <span id="statusDot" class="w-2 h-2 rounded-full bg-red-400"></span>
                <span id="statusText">Desconectado</span>
            </div>

            <!-- Botão Conectar -->
            <button id="btnConnect" onclick="toggleConnection()" class="px-3.5 py-1.5 rounded-xl bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white font-bold text-xs shadow-md transition flex items-center gap-1.5 cursor-pointer">
                <i class="fa-solid fa-plug"></i> Conectar
            </button>

            <!-- Toggle Log -->
            <button id="btnToggleLog" onclick="toggleLogPanel()" class="px-2.5 py-1.5 rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 text-gray-300 hover:text-white transition flex items-center gap-1 text-xs" title="Exibir/Ocultar Log de Eventos">
                <i class="fa-solid fa-list-timeline text-cyan-400"></i>
                <span id="logToggleText" class="hidden sm:inline">Log</span>
            </button>

            <!-- Limpar Chat -->
            <button onclick="clearChat()" class="p-1.5 px-2.5 rounded-xl bg-gray-800/80 hover:bg-red-500/20 hover:text-red-400 text-gray-400 border border-gray-700 transition cursor-pointer" title="Limpar conversa">
                <i class="fa-solid fa-trash-can text-xs"></i>
            </button>
        </div>
    </div>

    <!-- ÁREA PRINCIPAL: CHAT DE VOZ + LOG DE EVENTOS -->
    <div class="flex-1 flex gap-4 min-h-0 overflow-hidden">

        <!-- COLUNA DO CHAT COM VOZ -->
        <div class="flex-1 glass-card rounded-2xl border border-gray-800 flex flex-col min-h-0 overflow-hidden shadow-2xl relative bg-gray-900/60 backdrop-blur-xl">

            <!-- BARRA DE CHIPS DE SUGESTÃO RÁPIDA (1-CLIQUE PARA TESTAR) -->
            <div class="px-4 py-2 border-b border-gray-800/80 bg-gray-950/40 flex items-center gap-2 overflow-x-auto custom-scrollbar shrink-0">
                <span class="text-[11px] font-bold text-gray-400 shrink-0 flex items-center gap-1">
                    <i class="fa-solid fa-wand-magic-sparkles text-amber-400"></i> Testes Rápidos:
                </span>
                <button type="button" onclick="sendQuickPrompt('Diga olá em uma frase bem rápida!')" 
                        class="quick-chip px-2.5 py-1 rounded-lg bg-gray-800/80 border border-gray-700/80 text-[11px] text-gray-300 font-medium whitespace-nowrap cursor-pointer">
                    ⚡ Dizer Olá Rápido
                </button>
                <button type="button" onclick="sendQuickPrompt('Qual é o clima em Campo Grande hoje?')" 
                        class="quick-chip px-2.5 py-1 rounded-lg bg-gray-800/80 border border-gray-700/80 text-[11px] text-gray-300 font-medium whitespace-nowrap cursor-pointer">
                    🌤️ Clima Campo Grande
                </button>
                <button type="button" onclick="sendQuickPrompt('Qual a cotação do dólar agora?')" 
                        class="quick-chip px-2.5 py-1 rounded-lg bg-gray-800/80 border border-gray-700/80 text-[11px] text-gray-300 font-medium whitespace-nowrap cursor-pointer">
                    💵 Cotação do Dólar
                </button>
                <button type="button" onclick="sendQuickPrompt('Conte uma curiosidade rápida sobre inteligência artificial.')" 
                        class="quick-chip px-2.5 py-1 rounded-lg bg-gray-800/80 border border-gray-700/80 text-[11px] text-gray-300 font-medium whitespace-nowrap cursor-pointer">
                    🚀 Curiosidade IA
                </button>
            </div>

            <!-- ÁREA DE MENSAGENS COM ROLAGEM -->
            <div id="chatMessages" class="flex-1 overflow-y-auto p-4 sm:p-5 space-y-4 custom-scrollbar">
                <div class="flex items-center justify-center h-full text-gray-600">
                    <div class="text-center space-y-3 max-w-md mx-auto">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-cyan-500/20 via-indigo-600/20 to-purple-600/20 flex items-center justify-center mx-auto border border-cyan-500/30 shadow-lg shadow-cyan-500/10">
                            <i class="fa-solid fa-bolt text-2xl text-cyan-400"></i>
                        </div>
                        <h3 class="text-base font-bold text-gray-200">Console de Voz Realtime Ultra Rápido</h3>
                        <p class="text-xs text-gray-400 leading-relaxed">
                            Respostas em menos de <strong class="text-cyan-300 font-mono">1 segundo</strong> com processamento neural e síntese de áudio imediata.
                        </p>
                        <div class="pt-2 flex justify-center gap-2">
                            <button onclick="toggleConnection()" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs shadow-lg transition flex items-center gap-2 cursor-pointer">
                                <i class="fa-solid fa-plug"></i> Conectar e Começar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RODAPÉ: MICROFONE, VISUALIZADOR E CAMPO DE TEXTO -->
            <div class="border-t border-gray-800/90 p-3 sm:p-4 bg-gray-950/80 shrink-0 backdrop-blur-md">
                <div class="flex items-center gap-2.5">
                    <!-- Botão de Microfone Principal -->
                    <button id="btnMic" onclick="toggleRecording()" disabled
                            title="Clique para falar ao vivo (Auto-VAD ativado por padrão)"
                            class="w-12 h-12 rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 flex items-center justify-center transition-all cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed shrink-0 group relative shadow-md">
                        <i id="micIcon" class="fa-solid fa-microphone text-gray-300 group-hover:text-cyan-400 transition text-lg"></i>
                    </button>

                    <!-- Container de Visualizador Reativo de Áudio -->
                    <div id="waveformContainer" class="hidden items-center gap-2.5 h-12 px-3.5 bg-gradient-to-r from-red-500/15 to-purple-500/15 border border-red-500/30 rounded-xl flex-1 sm:flex-initial">
                        <span class="w-2.5 h-2.5 rounded-full bg-red-500 animate-ping shrink-0"></span>
                        <span id="recordingTimer" class="text-xs font-mono font-bold text-red-400 shrink-0">00:00</span>
                        
                        <div class="flex items-end gap-1 h-6 shrink-0">
                            <div class="waveform-bar w-1 bg-cyan-400 rounded-full"></div>
                            <div class="waveform-bar w-1 bg-indigo-400 rounded-full"></div>
                            <div class="waveform-bar w-1 bg-purple-400 rounded-full"></div>
                            <div class="waveform-bar w-1 bg-pink-400 rounded-full"></div>
                            <div class="waveform-bar w-1 bg-red-400 rounded-full"></div>
                        </div>

                        <span id="waveformStatusText" class="text-xs text-red-300 font-medium truncate hidden md:inline">
                            🎙️ Ouvindo você falar...
                        </span>

                        <button type="button" onclick="endLiveConversation()" class="ml-auto px-2.5 py-1 bg-red-600 hover:bg-red-500 text-white rounded-lg text-xs font-bold transition cursor-pointer flex items-center gap-1 shadow shrink-0">
                            <i class="fa-solid fa-phone-slash"></i> <span class="hidden sm:inline">Parar</span>
                        </button>
                    </div>

                    <!-- Campo de Entrada de Texto -->
                    <div class="flex-1 relative" id="textInputContainer">
                        <input id="textInput" type="text" placeholder="Digite uma mensagem ou clique no microfone para falar..." disabled
                               onkeydown="if(event.key==='Enter' && !event.shiftKey){event.preventDefault();sendTextMessage();}"
                               class="w-full bg-gray-900 text-gray-100 text-sm border border-gray-700/80 rounded-xl px-4 py-3 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500/30 outline-none transition disabled:opacity-40 disabled:cursor-not-allowed placeholder-gray-500">
                    </div>

                    <!-- Botão de Enviar Mensagem -->
                    <button id="btnSend" onclick="sendTextMessage()" disabled
                            class="w-12 h-12 rounded-xl bg-gradient-to-r from-indigo-600 to-cyan-600 hover:from-indigo-500 hover:to-cyan-500 text-white flex items-center justify-center transition-all cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed shrink-0 shadow-lg shadow-indigo-600/20">
                        <i class="fa-solid fa-paper-plane text-base"></i>
                    </button>
                </div>

                <!-- Rodapé com Configurações Adicionais e Auto-VAD -->
                <div class="flex items-center justify-between mt-2.5 pt-2 border-t border-gray-800/80 px-1 text-xs">
                    <label class="flex items-center gap-2 cursor-pointer text-gray-300 hover:text-white select-none">
                        <input type="checkbox" id="cfgHandsFree" checked class="rounded border-gray-700 text-cyan-500 focus:ring-0 bg-gray-900 cursor-pointer accent-cyan-500">
                        <span class="flex items-center gap-1.5 font-medium">
                            <i class="fa-solid fa-headset text-cyan-400"></i> Auto-VAD (Conversa ao vivo: pausa envia automaticamente)
                        </span>
                    </label>

                    <div class="flex items-center gap-3 text-gray-400 text-[11px]">
                        <span class="flex items-center gap-1">
                            <i class="fa-solid fa-bolt text-amber-400"></i> STT 0ms via Web Speech API
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <!-- COLUNA DIREITA: EVENT LOG (WEBSOCKET DEVTOOLS) -->
        <div id="logPanel" class="w-80 lg:w-96 glass-card rounded-2xl border border-gray-800 flex flex-col min-h-0 overflow-hidden shrink-0 hidden md:flex bg-gray-900/60 backdrop-blur-xl">
            <div class="p-3 border-b border-gray-800 flex items-center justify-between bg-gray-950/60 shrink-0">
                <h4 class="text-xs font-bold text-white flex items-center gap-1.5">
                    <i class="fa-solid fa-satellite-dish text-cyan-400"></i> Eventos WebSocket
                </h4>
                <div class="flex items-center space-x-2">
                    <span id="eventCount" class="text-[10px] font-mono text-gray-400 bg-gray-800 px-2 py-0.5 rounded">0</span>
                    <button onclick="clearEventLog()" class="text-xs text-gray-500 hover:text-red-400 transition cursor-pointer" title="Limpar log">
                        <i class="fa-solid fa-trash-can"></i>
                    </button>
                </div>
            </div>

            <div id="eventLog" class="flex-1 overflow-y-auto p-2.5 space-y-1.5 custom-scrollbar text-[11px] font-mono">
                <div class="text-center text-gray-600 py-16 font-sans">
                    <i class="fa-solid fa-terminal text-2xl mb-2 block text-gray-700"></i>
                    <p class="text-xs">Aguardando eventos...</p>
                </div>
            </div>

            <div class="p-2.5 border-t border-gray-800 bg-gray-950/60 text-[10px] text-gray-400 flex items-center justify-between shrink-0">
                <span>Tokens: <strong id="usageTokens" class="text-cyan-300 font-mono">0</strong></span>
                <span>Mensagens: <strong id="usageMessages" class="text-purple-300 font-mono">0</strong></span>
            </div>
        </div>

    </div>

</div>

<!-- MODAL FLUTUANTE DE PROMPT DO SISTEMA -->
<div id="modalInstructions" class="fixed inset-0 z-50 bg-black/75 backdrop-blur-md hidden items-center justify-center p-4">
    <div class="bg-gray-900 border border-gray-800 rounded-2xl max-w-lg w-full p-5 space-y-4 shadow-2xl">
        <div class="flex items-center justify-between border-b border-gray-800 pb-3">
            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                <i class="fa-solid fa-scroll text-amber-400"></i> Instruções do Sistema (Prompt Realtime)
            </h3>
            <button onclick="closeInstructionsModal()" class="text-gray-400 hover:text-white cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <p class="text-xs text-gray-400">Personalize o comportamento, idioma e tom de voz da IA:</p>
        <textarea id="cfgInstructions" placeholder="Ex: Você é um assistente rápido, amigável e responde sempre em português brasileiro de forma direta e concisa..." rows="4" class="w-full bg-gray-950 text-gray-200 text-xs border border-gray-700 rounded-xl p-3 focus:border-cyan-500 outline-none transition resize-none"></textarea>
        
        <!-- Slider de Temperatura dentro do modal -->
        <div class="flex items-center justify-between pt-1">
            <span class="text-xs text-gray-400 flex items-center gap-1.5"><i class="fa-solid fa-temperature-half text-cyan-400"></i> Temperatura:</span>
            <div class="flex items-center gap-2">
                <input id="cfgTemp" type="range" min="0" max="1" step="0.05" value="0.7"
                       oninput="document.getElementById('tempVal').textContent = this.value; updateSession();"
                       class="w-24 accent-cyan-500 cursor-pointer h-1.5 bg-gray-700 rounded-lg">
                <span id="tempVal" class="text-cyan-400 font-mono font-bold text-xs w-6 text-right">0.7</span>
            </div>
        </div>

        <div class="flex justify-end gap-2 pt-2 border-t border-gray-800">
            <button onclick="closeInstructionsModal()" class="px-4 py-2 rounded-xl bg-gray-800 hover:bg-gray-700 text-xs text-gray-300 font-semibold transition cursor-pointer">Cancelar</button>
            <button onclick="saveAndApplyInstructions()" class="px-4 py-2 rounded-xl bg-cyan-600 hover:bg-cyan-500 text-xs text-white font-bold transition flex items-center gap-1.5 cursor-pointer">
                <i class="fa-solid fa-check"></i> Salvar & Aplicar
            </button>
        </div>
    </div>
</div>

<script>
// =============================================
// CONSTANTES & CONFIGURAÇÃO
// =============================================
const WS_URL = '<?= $wsUrl ?>';
const API_KEY = '<?= htmlspecialchars($firstApiKey) ?>';

// =============================================
// ESTADO GLOBAL
// =============================================
let ws = null;
let isConnected = false;
let isRecording = false;
let isLiveCallActive = false;
let mediaRecorder = null;
let audioChunks = [];
let eventCounter = 0;
let totalTokens = 0;
let totalMessages = 0;
let currentStreamingEl = null;
let audioSentenceQueue = [];
let isPlayingAudioSentence = false;
let currentAudioPlayer = null;
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
let requestStartTime = 0;
let activeMicStream = null;

// =============================================
// MODOS DE VOZ: TURBO (0ms) vs NEURAL HD
// =============================================
function setVoiceMode(mode) {
    const btnTurbo = document.getElementById('btnModeTurbo');
    const btnHD = document.getElementById('btnModeHD');
    const selVoice = document.getElementById('cfgVoice');

    if (selVoice) selVoice.value = mode;

    if (mode === 'browser_fast') {
        btnTurbo.className = 'px-2.5 py-1 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 bg-cyan-500 text-gray-950 shadow';
        btnHD.className = 'px-2.5 py-1 rounded-lg text-xs font-medium text-gray-400 hover:text-white transition-all flex items-center gap-1.5';
    } else {
        btnHD.className = 'px-2.5 py-1 rounded-lg text-xs font-bold transition-all flex items-center gap-1.5 bg-purple-600 text-white shadow';
        btnTurbo.className = 'px-2.5 py-1 rounded-lg text-xs font-medium text-gray-400 hover:text-white transition-all flex items-center gap-1.5';
    }

    updateSession();
}

function onVoiceSelectChange() {
    const selVoice = document.getElementById('cfgVoice');
    if (selVoice.value === 'browser_fast') {
        setVoiceMode('browser_fast');
    } else {
        setVoiceMode(selVoice.value);
    }
}

// =============================================
// INICIALIZAÇÃO DE SPEECH RECOGNITION (0ms STT)
// =============================================
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
                textInput.placeholder = '🎙️ "' + recognizedText + '"';
            }
            triggerVadSilenceCheck();
        };

        speechRecognizer.onerror = (e) => {
            console.warn('[SpeechRec Warn]', e.error);
        };
    }
} catch (e) {
    console.warn('SpeechRecognition não suportado neste navegador:', e);
}

function triggerVadSilenceCheck() {
    if (vadSilenceTimer) clearTimeout(vadSilenceTimer);
    if (!isRecording) return;

    const handsFree = document.getElementById('cfgHandsFree')?.checked ?? true;
    if (!handsFree) return;

    if (recognizedText.trim().length > 1) {
        vadSilenceTimer = setTimeout(() => {
            if (isRecording && recognizedText.trim().length > 1) {
                console.log('[Auto-VAD] Pausa na fala detectada! Enviando:', recognizedText);
                finishSpeakingAndSend();
            }
        }, 600);
    }
}

// =============================================
// WEBSOCKET: CONEXÃO & GESTÃO
// =============================================
function toggleConnection() {
    if (isConnected) {
        disconnect();
    } else {
        connect();
    }
}

function connect() {
    if (ws && ws.readyState === WebSocket.OPEN) return;

    setStatus('connecting');
    const url = `${WS_URL}?api_key=${encodeURIComponent(API_KEY)}`;

    try {
        ws = new WebSocket(url);
    } catch (err) {
        setStatus('error');
        return;
    }

    ws.onopen = () => {
        logEvent('ws.open', { url: WS_URL }, 'client');
    };

    ws.onmessage = (e) => {
        try {
            const data = JSON.parse(e.data);
            handleServerEvent(data);
        } catch (err) {
            console.error('Erro ao processar mensagem do WebSocket:', err);
        }
    };

    ws.onclose = (e) => {
        isConnected = false;
        setStatus('disconnected');
        setControlsEnabled(false);
        logEvent('ws.close', { code: e.code, reason: e.reason }, 'server');
    };

    ws.onerror = (err) => {
        setStatus('error');
        logEvent('ws.error', { message: 'Erro na conexão WebSocket' }, 'server');
    };
}

function disconnect() {
    endLiveConversation();
    if (ws) {
        ws.close();
        ws = null;
    }
    isConnected = false;
    setStatus('disconnected');
    setControlsEnabled(false);
}

function sendWsEvent(type, payload = {}) {
    if (!ws || ws.readyState !== WebSocket.OPEN) return;
    const event = { type, ...payload };
    ws.send(JSON.stringify(event));
    logEvent(type, payload, 'client');
}

function updateSession() {
    if (!isConnected) return;
    const wsCheck = document.getElementById('cfgWebSearch');
    const selVoice = document.getElementById('cfgVoice');
    sendWsEvent('session.update', {
        session: {
            model: document.getElementById('cfgModel').value,
            voice: selVoice ? selVoice.value : 'browser_fast',
            temperature: parseFloat(document.getElementById('cfgTemp').value || '0.7'),
            instructions: document.getElementById('cfgInstructions').value || '',
            web_search: wsCheck ? wsCheck.checked : true
        }
    });
}

// =============================================
// PROCESSAMENTO DE EVENTOS RECEBIDOS
// =============================================
function handleServerEvent(data) {
    const type = data.type;
    logEvent(type, data, 'server');

    switch (type) {
        case 'session.created':
            isConnected = true;
            setStatus('connected');
            setControlsEnabled(true);
            updateSession();
            addSystemMessage('✨ Sessão Realtime conectada com sucesso!', 'info');
            break;

        case 'input_audio_buffer.speech_started':
            addSystemMessage('🎤 Processando fala...', 'processing');
            break;

        case 'response.web_search.started':
            addSystemMessage('🌐 Pesquisando ao vivo na Web: "' + (data.query || '') + '"...', 'websearch');
            break;

        case 'response.web_search.completed':
            removeSystemMessage('websearch');
            if (data.count > 0) {
                addSystemMessage('✅ ' + data.count + ' fontes consultadas em tempo real.', 'websearch-done');
                setTimeout(() => removeSystemMessage('websearch-done'), 3500);
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
            if (data.delta || data.transcript) {
                enqueueSentenceAudio(data.delta, data.transcript);
            }
            break;

        case 'response.audio.done':
            // Finalização do áudio
            break;

        case 'response.cancelled':
            if (currentAudioPlayer) {
                try { currentAudioPlayer.pause(); currentAudioPlayer.currentTime = 0; } catch (e) {}
                currentAudioPlayer = null;
            }
            if (window.speechSynthesis && window.speechSynthesis.speaking) {
                try { window.speechSynthesis.cancel(); } catch (e) {}
            }
            audioSentenceQueue = [];
            isPlayingAudioSentence = false;
            isResponseStreamingActive = false;
            break;

        case 'response.done':
            isResponseStreamingActive = false;
            const latency = data.response?.latency_ms || (requestStartTime > 0 ? (Date.now() - requestStartTime) : 0);
            if (latency > 0) {
                updateLatencyDisplay(latency);
                tagLastAssistantMessageLatency(latency);
            }
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

function updateLatencyDisplay(latencyMs) {
    const badge = document.getElementById('badgeLatency');
    const val = document.getElementById('valLatency');
    if (badge && val) {
        badge.classList.remove('hidden');
        val.textContent = `${latencyMs} ms`;
        if (latencyMs < 1000) {
            val.className = 'text-emerald-400 font-mono font-bold';
        } else if (latencyMs < 2000) {
            val.className = 'text-cyan-400 font-mono font-bold';
        } else {
            val.className = 'text-amber-400 font-mono font-bold';
        }
    }
}

function tagLastAssistantMessageLatency(latencyMs) {
    const container = document.getElementById('chatMessages');
    const assistantMsgs = container.querySelectorAll('[data-role="assistant"]');
    if (assistantMsgs.length > 0) {
        const lastEl = assistantMsgs[assistantMsgs.length - 1];
        const footerEl = lastEl.querySelector('.msg-footer');
        if (footerEl && !footerEl.querySelector('.latency-tag')) {
            const tag = document.createElement('span');
            tag.className = 'latency-tag text-[10px] px-2 py-0.5 rounded-md bg-gray-950/80 border border-emerald-500/30 text-emerald-400 font-mono font-bold flex items-center gap-1';
            tag.innerHTML = `<i class="fa-solid fa-bolt text-amber-300"></i> ${latencyMs}ms`;
            footerEl.appendChild(tag);
        }
    }
}

// =============================================
// ENVIOS: TEXTO & CHIPS RÁPIDOS
// =============================================
function sendTextMessage() {
    const input = document.getElementById('textInput');
    const text = input.value.trim();
    if (!text || !isConnected) return;

    input.value = '';
    requestStartTime = Date.now();

    sendWsEvent('conversation.item.create', {
        item: {
            role: 'user',
            content: [{ type: 'text', text: text }]
        }
    });

    sendWsEvent('response.create', { generate_audio: true });
}

function sendQuickPrompt(promptText) {
    if (!isConnected) {
        connect();
        setTimeout(() => {
            if (isConnected) {
                const input = document.getElementById('textInput');
                if (input) input.value = promptText;
                sendTextMessage();
            }
        }, 1200);
        return;
    }
    const input = document.getElementById('textInput');
    if (input) input.value = promptText;
    sendTextMessage();
}

// =============================================
// CONVERSA POR VOZ AO VIVO & AUTO-VAD (LIVE CALL)
// =============================================
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
        console.error('Erro microfone:', err);
        addSystemMessage('⚠️ Não foi possível acessar o microfone. Verifique as permissões.', 'error');
        endLiveConversation();
    }
}

function startListeningCycle() {
    if (!isLiveCallActive || !isConnected || isRecording) return;

    // Barge-in: corta fala do assistente imediatamente se o usuário começar a falar
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

    // UI Feedback: Estado de Escuta Ativa
    const btn = document.getElementById('btnMic');
    btn.className = 'w-12 h-12 rounded-xl bg-red-600 hover:bg-red-500 border border-red-500 flex items-center justify-center transition-all cursor-pointer shrink-0 group relative recording-ring shadow-lg shadow-red-500/40';
    btn.title = '🎙️ Gravando... Fale livremente. A pausa envia na hora!';
    document.getElementById('micIcon').className = 'fa-solid fa-microphone text-white text-lg animate-pulse';

    const waveContainer = document.getElementById('waveformContainer');
    waveContainer.classList.remove('hidden');
    waveContainer.classList.add('flex');
    const waveText = document.getElementById('waveformStatusText');
    if (waveText) waveText.textContent = '🎙️ Ouvindo você falar...';

    const textInput = document.getElementById('textInput');
    if (textInput) textInput.placeholder = '🎙️ Ouvindo você falar...';

    if (speechRecognizer) {
        try { speechRecognizer.start(); } catch (e) {}
    }

    // Timer de gravação
    const timerEl = document.getElementById('recordingTimer');
    if (timerEl) timerEl.textContent = '00:00';
    recordingTimerInterval = setInterval(() => {
        const elapsed = Math.floor((Date.now() - recordingStartTime) / 1000);
        const mins = String(Math.floor(elapsed / 60)).padStart(2, '0');
        const secs = String(elapsed % 60).padStart(2, '0');
        if (timerEl) timerEl.textContent = `${mins}:${secs}`;
    }, 500);

    // MediaRecorder de segurança (caso Web Speech não capture)
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
                const textEl = document.getElementById('textInput');
                if (textEl) textEl.value = '';
                requestStartTime = Date.now();
                sendWsEvent('conversation.item.create', {
                    item: {
                        role: 'user',
                        content: [{ type: 'text', text: finalTranscript }]
                    }
                });
                sendWsEvent('response.create', { generate_audio: true });
                return;
            }

            // Fallback para áudio bruto se não houver transcrição do navegador
            if (audioChunks.length > 0 && isConnected) {
                const blob = new Blob(audioChunks, { type: mediaRecorder.mimeType || 'audio/webm' });
                const reader = new FileReader();
                reader.onloadend = () => {
                    const base64 = reader.result.split(',')[1];
                    if (base64 && isConnected) {
                        requestStartTime = Date.now();
                        addSystemMessage('🎙️ Enviando áudio e transcrevendo via IA...', 'processing');
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

function finishSpeakingAndSend() {
    if (!isRecording) return;
    isRecording = false;

    if (recordingTimerInterval) { clearInterval(recordingTimerInterval); recordingTimerInterval = null; }
    if (vadSilenceTimer) { clearTimeout(vadSilenceTimer); vadSilenceTimer = null; }
    if (speechRecognizer) { try { speechRecognizer.stop(); } catch (e) {} }

    // UI de processamento
    const btn = document.getElementById('btnMic');
    btn.className = 'w-12 h-12 rounded-xl bg-purple-600 hover:bg-purple-500 border border-purple-500 flex items-center justify-center transition-all cursor-pointer shrink-0 shadow-lg shadow-purple-500/25';
    btn.title = '⚡ IA respondendo... Clique para encerrar';
    document.getElementById('micIcon').className = 'fa-solid fa-volume-high text-white text-lg animate-pulse';

    const waveText = document.getElementById('waveformStatusText');
    if (waveText) waveText.textContent = '⚡ IA gerando resposta...';

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

    sendWsEvent('response.cancel', {});

    if (currentAudioPlayer) {
        try { currentAudioPlayer.pause(); currentAudioPlayer.currentTime = 0; } catch (e) {}
        currentAudioPlayer = null;
    }
    audioSentenceQueue = [];
    isPlayingAudioSentence = false;

    if (window.speechSynthesis && window.speechSynthesis.speaking) {
        try { window.speechSynthesis.cancel(); } catch (e) {}
    }

    if (recordingTimerInterval) { clearInterval(recordingTimerInterval); recordingTimerInterval = null; }
    if (vadSilenceTimer) { clearTimeout(vadSilenceTimer); vadSilenceTimer = null; }
    if (speechRecognizer) { try { speechRecognizer.stop(); } catch (e) {} }
    if (mediaRecorder && mediaRecorder.state !== 'inactive') { try { mediaRecorder.stop(); } catch (e) {} }
    if (activeMicStream) {
        activeMicStream.getTracks().forEach(t => t.stop());
        activeMicStream = null;
    }

    // Reset UI
    const btn = document.getElementById('btnMic');
    if (btn) {
        btn.className = 'w-12 h-12 rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 flex items-center justify-center transition-all cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed shrink-0 group relative shadow-md';
        btn.title = 'Clique para falar ao vivo';
    }
    const micIcon = document.getElementById('micIcon');
    if (micIcon) {
        micIcon.className = 'fa-solid fa-microphone text-gray-300 group-hover:text-cyan-400 transition text-lg';
    }

    const waveContainer = document.getElementById('waveformContainer');
    if (waveContainer) {
        waveContainer.classList.add('hidden');
        waveContainer.classList.remove('flex');
    }

    const textInput = document.getElementById('textInput');
    if (textInput) {
        textInput.placeholder = 'Digite uma mensagem ou clique no microfone para falar...';
    }
}

function checkAutoContinueSpeech() {
    const handsFree = document.getElementById('cfgHandsFree')?.checked ?? true;
    if (isLiveCallActive && handsFree && isConnected && !isRecording && !isResponseStreamingActive && !isPlayingAudioSentence) {
        setTimeout(() => {
            if (isLiveCallActive && handsFree && isConnected && !isRecording && !isPlayingAudioSentence && !isResponseStreamingActive) {
                console.log('[Auto-VAD] Reativando microfone para continuidade da conversa...');
                startListeningCycle();
            }
        }, 350);
    }
}

// =============================================
// REPRODUÇÃO DE ÁUDIO TTS (STREAMING & PLAYBACK)
// =============================================
function enqueueSentenceAudio(base64Audio, transcript) {
    const selectedVoice = document.getElementById('cfgVoice')?.value || 'browser_fast';

    if (selectedVoice === 'browser_fast' && window.speechSynthesis && transcript) {
        // MODO TURBO: FALA INSTANTÂNEA DIRETO PELO NAVEGADOR (0ms TTS)
        const utter = new SpeechSynthesisUtterance(transcript);
        utter.lang = 'pt-BR';
        utter.rate = 1.1;
        isPlayingAudioSentence = true;
        utter.onend = () => {
            isPlayingAudioSentence = false;
            checkAutoContinueSpeech();
        };
        utter.onerror = () => {
            isPlayingAudioSentence = false;
            checkAutoContinueSpeech();
        };
        window.speechSynthesis.speak(utter);
        return;
    }

    if (base64Audio) {
        audioSentenceQueue.push({ base64: base64Audio, transcript: transcript });
        if (!isPlayingAudioSentence) {
            playNextSentenceAudio();
        }
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

        const playPromise = currentAudioPlayer.play();
        if (playPromise !== undefined) {
            playPromise.catch(err => {
                console.warn('Autoplay bloqueado pelo navegador:', err);
                isPlayingAudioSentence = false;
            });
        }
    } catch (err) {
        console.error('Erro ao reproduzir sentença de áudio:', err);
        playNextSentenceAudio();
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
// RENDERIZAÇÃO DE MENSAGENS NA INTERFACE
// =============================================
function addChatMessage(role, text, isStreaming = false) {
    if (role === 'user') {
        const clean = (text || '').trim();
        const now = Date.now();
        if (clean && lastRenderedUserMsg.text === clean && (now - lastRenderedUserMsg.time < 2500)) {
            return null;
        }
        lastRenderedUserMsg = { text: clean, time: now };
    }

    const container = document.getElementById('chatMessages');
    if (container.querySelector('.text-gray-600')) container.innerHTML = '';

    const msgDiv = document.createElement('div');
    msgDiv.className = `msg-animate flex ${role === 'user' ? 'justify-end' : 'justify-start'}`;
    msgDiv.setAttribute('data-role', role);

    const isUser = role === 'user';
    const bubbleClasses = isUser
        ? 'bg-gradient-to-br from-indigo-600/30 to-purple-600/20 border-indigo-500/40 text-gray-100 shadow-md'
        : 'bg-gray-800/80 border-gray-700/70 text-gray-200 shadow-md';

    const icon = isUser
        ? '<i class="fa-solid fa-user text-indigo-400"></i>'
        : '<i class="fa-solid fa-bolt text-cyan-400"></i>';

    const title = isUser ? 'Você' : 'Assistente Realtime';

    msgDiv.innerHTML = `
        <div class="max-w-[85%] sm:max-w-[75%] ${isUser ? 'order-2' : ''}">
            <div class="flex items-center gap-2 mb-1.5 ${isUser ? 'justify-end' : ''}">
                <span class="w-6 h-6 rounded-lg ${isUser ? 'bg-indigo-500/20' : 'bg-cyan-500/20'} flex items-center justify-center text-xs shadow">${icon}</span>
                <span class="text-[11px] text-gray-400 font-bold">${title}</span>
            </div>
            <div class="px-4 py-3 rounded-2xl border ${bubbleClasses} text-sm leading-relaxed ${isStreaming ? 'streaming-cursor' : ''}" data-content>
                ${escapeHtml(text || '')}
            </div>
            <div class="msg-footer flex items-center gap-2 mt-1.5 px-1 ${isUser ? 'justify-end' : 'justify-start'}">
                ${!isUser ? `
                    <button onclick="copyMsgText(this)" class="text-[11px] text-gray-500 hover:text-gray-300 transition flex items-center gap-1 cursor-pointer" title="Copiar texto">
                        <i class="fa-solid fa-copy"></i>
                    </button>
                    <button onclick="speakMsgText(this)" class="text-[11px] text-gray-500 hover:text-cyan-400 transition flex items-center gap-1 cursor-pointer" title="Ouvir novamente">
                        <i class="fa-solid fa-volume-high"></i>
                    </button>
                ` : ''}
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
    if (contentEl) contentEl.classList.remove('streaming-cursor');
}

function copyMsgText(btn) {
    const bubble = btn.closest('.max-w-[85%], .max-w-[75%]')?.querySelector('[data-content]');
    if (bubble) {
        navigator.clipboard.writeText(bubble.textContent.trim());
        const orig = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-check text-emerald-400"></i>';
        setTimeout(() => btn.innerHTML = orig, 1500);
    }
}

function speakMsgText(btn) {
    const bubble = btn.closest('.max-w-[85%], .max-w-[75%]')?.querySelector('[data-content]');
    if (bubble && window.speechSynthesis) {
        window.speechSynthesis.cancel();
        const utter = new SpeechSynthesisUtterance(bubble.textContent.trim());
        utter.lang = 'pt-BR';
        utter.rate = 1.1;
        window.speechSynthesis.speak(utter);
    }
}

function addSystemMessage(text, msgType = 'info') {
    const container = document.getElementById('chatMessages');
    if (container.querySelector('.text-gray-600')) container.innerHTML = '';

    const msgDiv = document.createElement('div');
    msgDiv.className = 'msg-animate flex justify-center';
    if (msgType) msgDiv.setAttribute('data-msg-type', msgType);

    const colors = {
        info: 'bg-gray-800/80 text-gray-300 border-gray-700/60',
        error: 'bg-red-500/15 text-red-400 border-red-500/30',
        processing: 'bg-indigo-500/15 text-indigo-300 border-indigo-500/30',
        websearch: 'bg-emerald-500/15 text-emerald-300 border-emerald-500/30'
    };

    msgDiv.innerHTML = `
        <div class="px-3.5 py-1.5 rounded-xl border text-xs ${colors[msgType] || colors.info} shadow">
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

function clearChat() {
    document.getElementById('chatMessages').innerHTML = `
        <div class="flex items-center justify-center h-full text-gray-600">
            <div class="text-center space-y-3 max-w-md mx-auto">
                <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-cyan-500/20 via-indigo-600/20 to-purple-600/20 flex items-center justify-center mx-auto border border-cyan-500/30 shadow-lg shadow-cyan-500/10">
                    <i class="fa-solid fa-bolt text-2xl text-cyan-400"></i>
                </div>
                <h3 class="text-base font-bold text-gray-200">Conversa Limpa</h3>
                <p class="text-xs text-gray-400">Pronto para iniciar uma nova conversa em tempo real.</p>
            </div>
        </div>
    `;
    audioSentenceQueue = [];
}

// =============================================
// LOG DE EVENTOS WEBSOCKET
// =============================================
function logEvent(type, data, source = 'server') {
    eventCounter++;
    const container = document.getElementById('eventLog');
    if (container.querySelector('.text-gray-600')) container.innerHTML = '';

    const now = new Date().toLocaleTimeString('pt-BR', { hour12: false });
    const isServer = source === 'server';

    const div = document.createElement('div');
    div.className = 'p-2 rounded-lg bg-gray-950/80 border border-gray-800/80 hover:border-gray-700 transition';

    div.innerHTML = `
        <div class="flex items-center justify-between text-[10px] mb-1">
            <span class="${isServer ? 'text-cyan-400' : 'text-purple-400'} font-bold flex items-center gap-1">
                <i class="fa-solid ${isServer ? 'fa-arrow-down' : 'fa-arrow-up'} text-[9px]"></i>
                ${type}
            </span>
            <span class="text-gray-600">${now}</span>
        </div>
        <pre class="text-[10px] text-gray-400 overflow-x-auto max-h-24 custom-scrollbar whitespace-pre-wrap">${escapeHtml(JSON.stringify(data, null, 2))}</pre>
    `;

    container.insertBefore(div, container.firstChild);
    document.getElementById('eventCount').textContent = `${eventCounter} eventos`;
}

function clearEventLog() {
    eventCounter = 0;
    document.getElementById('eventLog').innerHTML = `
        <div class="text-center text-gray-600 py-16 font-sans">
            <i class="fa-solid fa-terminal text-2xl mb-2 block text-gray-700"></i>
            <p class="text-xs">Log de eventos limpo</p>
        </div>
    `;
    document.getElementById('eventCount').textContent = '0';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// =============================================
// STATUS & INTERFACE
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
            btn: '<i class="fa-solid fa-plug"></i> Conectar',
            btnClass: 'bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500'
        },
        connecting: {
            bg: 'bg-amber-500/10 border-amber-500/30 text-amber-400',
            dot: 'bg-amber-400 status-pulse',
            text: 'Conectando...',
            btn: '<i class="fa-solid fa-spinner fa-spin"></i> Conectando...',
            btnClass: 'bg-amber-600/70 cursor-wait'
        },
        connected: {
            bg: 'bg-emerald-500/10 border-emerald-500/30 text-emerald-400',
            dot: 'bg-emerald-400 status-pulse',
            text: 'Conectado',
            btn: '<i class="fa-solid fa-plug-circle-xmark"></i> Desconectar',
            btnClass: 'bg-red-600/80 hover:bg-red-500'
        },
        error: {
            bg: 'bg-red-500/10 border-red-500/30 text-red-400',
            dot: 'bg-red-400',
            text: 'Erro de Conexão',
            btn: '<i class="fa-solid fa-plug"></i> Tentar Novamente',
            btnClass: 'bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500'
        }
    };

    const cfg = configs[status] || configs.disconnected;
    statusEl.className = `flex items-center space-x-1.5 px-2.5 py-1 rounded-xl border transition-all duration-300 font-semibold text-xs ${cfg.bg}`;
    dot.className = `w-2 h-2 rounded-full ${cfg.dot}`;
    text.textContent = cfg.text;
    btn.innerHTML = cfg.btn;
    btn.className = `px-3.5 py-1.5 rounded-xl ${cfg.btnClass} text-white font-bold text-xs shadow-md transition flex items-center gap-1.5 cursor-pointer`;
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

function toggleLogPanel() {
    const panel = document.getElementById('logPanel');
    if (panel) {
        panel.classList.toggle('hidden');
        const isHidden = panel.classList.contains('hidden');
        const toggleBtn = document.getElementById('logToggleText');
        if (toggleBtn) toggleBtn.textContent = isHidden ? 'Ver Log' : 'Log';
    }
}

function openInstructionsModal() {
    const modal = document.getElementById('modalInstructions');
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}

function closeInstructionsModal() {
    const modal = document.getElementById('modalInstructions');
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}

function saveAndApplyInstructions() {
    closeInstructionsModal();
    updateSession();
    addSystemMessage('✨ Instruções salvas e aplicadas na sessão!', 'info');
}

// Inicializar com conexão automática se usuário clicar no campo ou microfone
document.addEventListener('DOMContentLoaded', () => {
    // Modo Turbo ativado por padrão para velocidade instantânea
    setVoiceMode('browser_fast');
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
