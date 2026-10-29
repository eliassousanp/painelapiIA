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

$pageTitle = 'Console Realtime (Voz & Streaming Ultra RÃ¡pido)';
$pageHeaderIcon = '<i class="fa-solid fa-bolt text-amber-400 mr-2.5"></i>';

require_once __DIR__ . '/includes/header.php';
?>

<style>
    /* Waveform / Visualizador de Ãudio Reativo */
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

    /* Pulso gravaÃ§Ã£o ativa */
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
        content: 'â–‹';
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

    /* Chips rÃ¡pidos */
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
                        <option value="" disabled selected class="bg-gray-900 text-amber-300">âš ï¸ Nenhum modelo Realtime ativo</option>
                    <?php else: ?>
                        <?php foreach ($activeModels as $m): ?>
                            <option value="<?= htmlspecialchars($m['model_key']) ?>" class="bg-gray-900 text-white">
                                <?= htmlspecialchars($m['name']) ?> <?= !empty($m['is_admin_only']) ? '(ðŸ”’ Admin)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>

            <!-- TOGGLE RÃPIDO: TURBO (0ms) vs NEURAL HD -->
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

            <!-- Seletor de Voz Escondido/SecundÃ¡rio para opÃ§Ãµes avanÃ§adas -->
            <div class="hidden sm:flex items-center gap-1.5 bg-gray-900/90 border border-gray-700/80 rounded-xl px-2.5 py-1.5">
                <i class="fa-solid fa-volume-high text-purple-400 text-xs"></i>
                <select id="cfgVoice" onchange="onVoiceSelectChange()" class="bg-transparent text-gray-200 font-medium text-xs outline-none cursor-pointer">
                    <optgroup label="âš¡ Resposta Imediata" class="bg-gray-900 text-cyan-300">
                        <option value="browser_fast" selected>âš¡ Voz Local (Zero LatÃªncia / 0ms TTS)</option>
                    </optgroup>
                    <optgroup label="PortuguÃªs (Brasil) - Voz Neural EstÃºdio" class="bg-gray-900 text-white">
                        <option value="francisca">Francisca (Feminina Neural HD)</option>
                        <option value="antonio">AntÃ´nio (Masculino Neural HD)</option>
                        <option value="thalita">Thalita (Feminina Neural HD)</option>
                    </optgroup>
                    <optgroup label="English (US) - Neural" class="bg-gray-900 text-white">
                        <option value="aria">Aria (EN)</option>
                        <option value="guy">Guy (EN)</option>
                    </optgroup>
                </select>
            </div>

            <!-- Pesquisa Web em Tempo Real -->
            <label class="flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-gray-800/90 border border-gray-700/80 hover:border-emerald-500/50 text-emerald-400 cursor-pointer text-xs font-medium transition" title="Permite que a IA pesquise cotaÃ§Ãµes, notÃ­cias e dados ao vivo">
                <input type="checkbox" id="cfgWebSearch" checked onchange="updateSession()" class="rounded bg-gray-900 border-gray-700 text-emerald-500 focus:ring-0 cursor-pointer accent-emerald-500">
                <i class="fa-solid fa-globe text-emerald-400"></i>
                <span class="hidden md:inline">Web Search</span>
            </label>

            <!-- Prompt do Sistema -->
            <button onclick="openInstructionsModal()" class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-xl bg-gray-800/90 hover:bg-gray-700 border border-gray-700 text-gray-300 hover:text-white transition cursor-pointer text-xs font-medium" title="Configurar instruÃ§Ãµes e personalidade da IA">
                <i class="fa-solid fa-sliders text-amber-400"></i>
                <span class="hidden lg:inline">InstruÃ§Ãµes</span>
            </button>
        </div>

        <!-- Grupo Direito: LatÃªncia, Status, Conectar & AÃ§Ãµes -->
        <div class="flex items-center gap-2">
            <!-- Badge de LatÃªncia DinÃ¢mica -->
            <div id="badgeLatency" class="hidden sm:flex items-center gap-1.5 px-2.5 py-1 rounded-xl bg-gray-950 border border-gray-800 text-gray-400 text-xs font-mono font-bold" title="Tempo total da Ãºltima resposta">
                <i class="fa-solid fa-stopwatch text-cyan-400"></i>
                <span id="valLatency">-- ms</span>
            </div>

            <!-- Status da ConexÃ£o -->
            <div id="connectionStatus" class="flex items-center space-x-1.5 px-2.5 py-1 rounded-xl border transition-all duration-300 bg-red-500/10 border-red-500/30 text-red-400 font-medium text-xs">
                <span id="statusDot" class="w-2 h-2 rounded-full bg-red-400"></span>
                <span id="statusText">Desconectado</span>
            </div>

            <!-- BotÃ£o Conectar -->
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

    <!-- ÃREA PRINCIPAL: CHAT DE VOZ + LOG DE EVENTOS -->
    <div class="flex-1 flex gap-4 min-h-0 overflow-hidden">

        <!-- COLUNA DO CHAT COM VOZ -->
        <div class="flex-1 glass-card rounded-2xl border border-gray-800 flex flex-col min-h-0 overflow-hidden shadow-2xl relative bg-gray-900/60 backdrop-blur-xl">

            <!-- BARRA DE CHIPS DE SUGESTÃƒO RÃPIDA (1-CLIQUE PARA TESTAR) -->
            <div class="px-4 py-2 border-b border-gray-800/80 bg-gray-950/40 flex items-center gap-2 overflow-x-auto custom-scrollbar shrink-0">
                <span class="text-[11px] font-bold text-gray-400 shrink-0 flex items-center gap-1">
                    <i class="fa-solid fa-wand-magic-sparkles text-amber-400"></i> Testes RÃ¡pidos:
                </span>
                <button type="button" onclick="sendQuickPrompt('Diga olÃ¡ em uma frase bem rÃ¡pida!')" 
                        class="quick-chip px-2.5 py-1 rounded-lg bg-gray-800/80 border border-gray-700/80 text-[11px] text-gray-300 font-medium whitespace-nowrap cursor-pointer">
                    âš¡ Dizer OlÃ¡ RÃ¡pido
                </button>
                <button type="button" onclick="sendQuickPrompt('Qual Ã© o clima em Campo Grande hoje?')" 
                        class="quick-chip px-2.5 py-1 rounded-lg bg-gray-800/80 border border-gray-700/80 text-[11px] text-gray-300 font-medium whitespace-nowrap cursor-pointer">
                    ðŸŒ¤ï¸ Clima Campo Grande
                </button>
                <button type="button" onclick="sendQuickPrompt('Qual a cotaÃ§Ã£o do dÃ³lar agora?')" 
                        class="quick-chip px-2.5 py-1 rounded-lg bg-gray-800/80 border border-gray-700/80 text-[11px] text-gray-300 font-medium whitespace-nowrap cursor-pointer">
                    ðŸ’µ CotaÃ§Ã£o do DÃ³lar
                </button>
                <button type="button" onclick="sendQuickPrompt('Conte uma curiosidade rÃ¡pida sobre inteligÃªncia artificial.')" 
                        class="quick-chip px-2.5 py-1 rounded-lg bg-gray-800/80 border border-gray-700/80 text-[11px] text-gray-300 font-medium whitespace-nowrap cursor-pointer">
                    ðŸš€ Curiosidade IA
                </button>
            </div>

            <!-- ÃREA DE MENSAGENS COM ROLAGEM -->
            <div id="chatMessages" class="flex-1 overflow-y-auto p-4 sm:p-5 space-y-4 custom-scrollbar">
                <div class="flex items-center justify-center h-full text-gray-600">
                    <div class="text-center space-y-3 max-w-md mx-auto">
                        <div class="w-16 h-16 rounded-2xl bg-gradient-to-tr from-cyan-500/20 via-indigo-600/20 to-purple-600/20 flex items-center justify-center mx-auto border border-cyan-500/30 shadow-lg shadow-cyan-500/10">
                            <i class="fa-solid fa-bolt text-2xl text-cyan-400"></i>
                        </div>
                        <h3 class="text-base font-bold text-gray-200">Console de Voz Realtime Ultra RÃ¡pido</h3>
                        <p class="text-xs text-gray-400 leading-relaxed">
                            Respostas em menos de <strong class="text-cyan-300 font-mono">1 segundo</strong> com processamento neural e sÃ­ntese de Ã¡udio imediata.
                        </p>
                        <div class="pt-2 flex justify-center gap-2">
                            <button onclick="toggleConnection()" class="px-4 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-xs shadow-lg transition flex items-center gap-2 cursor-pointer">
                                <i class="fa-solid fa-plug"></i> Conectar e ComeÃ§ar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RODAPÃ‰: MICROFONE, VISUALIZADOR E CAMPO DE TEXTO -->
            <div class="border-t border-gray-800/90 p-3 sm:p-4 bg-gray-950/80 shrink-0 backdrop-blur-md">
                <div class="flex items-center gap-2.5">
                    <!-- BotÃ£o de Microfone Principal -->
                    <button id="btnMic" onclick="toggleRecording()" disabled
                            title="Clique para falar ao vivo (Auto-VAD ativado por padrÃ£o)"
                            class="w-12 h-12 rounded-xl bg-gray-800 hover:bg-gray-700 border border-gray-700 flex items-center justify-center transition-all cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed shrink-0 group relative shadow-md">
                        <i id="micIcon" class="fa-solid fa-microphone text-gray-300 group-hover:text-cyan-400 transition text-lg"></i>
                    </button>

                    <!-- Container de Visualizador Reativo de Ãudio -->
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
                            ðŸŽ™ï¸ Ouvindo vocÃª falar...
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

                    <!-- BotÃ£o de Enviar Mensagem -->
                    <button id="btnSend" onclick="sendTextMessage()" disabled
                            class="w-12 h-12 rounded-xl bg-gradient-to-r from-indigo-600 to-cyan-600 hover:from-indigo-500 hover:to-cyan-500 text-white flex items-center justify-center transition-all cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed shrink-0 shadow-lg shadow-indigo-600/20">
                        <i class="fa-solid fa-paper-plane text-base"></i>
                    </button>
                </div>

                <!-- RodapÃ© com ConfiguraÃ§Ãµes Adicionais e Auto-VAD -->
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
                <i class="fa-solid fa-scroll text-amber-400"></i> InstruÃ§Ãµes do Sistema (Prompt Realtime)
            </h3>
            <button onclick="closeInstructionsModal()" class="text-gray-400 hover:text-white cursor-pointer"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <p class="text-xs text-gray-400">Personalize o comportamento, idioma e tom de voz da IA:</p>
        <textarea id="cfgInstructions" placeholder="Ex: VocÃª Ã© um assistente rÃ¡pido, amigÃ¡vel e responde sempre em portuguÃªs brasileiro de forma direta e concisa..." rows="4" class="w-full bg-gray-950 text-gray-200 text-xs border border-gray-700 rounded-xl p-3 focus:border-cyan-500 outline-none transition resize-none"></textarea>
        
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

<script src="<?= getAppBaseUrl() ?>/assets/js/realtime-client.js?v=<?= time() ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    window.realtimeConsole = new BRLoveRealtimeConsole({
        wsUrl: '<?= $wsUrl ?>',
        apiKey: '<?= htmlspecialchars($firstApiKey) ?>'
    });
    window.realtimeConsole.setVoiceMode('browser_fast');
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
