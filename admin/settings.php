<?php
require_once __DIR__ . '/../config/helpers.php';
requireAdmin();

$db = getDB();
$pageTitle = 'Configurações Globais';
$pageHeaderIcon = '<i class="fa-solid fa-sliders text-gray-400 mr-2.5"></i>';
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validate_csrf()) {
        $error = 'Sessão expirada ou token de segurança CSRF inválido. Recarregue a página.';
    } elseif ($_POST['action'] === 'update_settings') {
        setSetting('pre_prompt', trim($_POST['pre_prompt'] ?? ''));
        setSetting('mistic_client_id', trim($_POST['mistic_client_id'] ?? ''));
        setSetting('mistic_client_secret', trim($_POST['mistic_client_secret'] ?? ''));
        setSetting('gateway_public_url', trim($_POST['gateway_public_url'] ?? ''));
        setSetting('gateway_api_url', trim($_POST['gateway_api_url'] ?? 'http://127.0.0.1:3000'));
        setSetting('whatsapp_api_url', trim($_POST['whatsapp_api_url'] ?? 'http://127.0.0.1:3000'));
        setSetting('whatsapp_default_instance', trim($_POST['whatsapp_default_instance'] ?? ''));
        setSetting('realtime_access', trim($_POST['realtime_access'] ?? 'all'));
        setSetting('theme_mode', trim($_POST['theme_mode'] ?? 'light'));
        setSetting('theme_color_scheme', trim($_POST['theme_color_scheme'] ?? 'purple_cyan'));
        setSetting('mcp_local_mode', isset($_POST['mcp_local_mode']) ? '1' : '0');

        $msg = 'Configurações globais salvas com sucesso!';
    }
}

// Obter Configurações Atuais
$prePrompt = getSetting('pre_prompt', 'Você é o Claude Opus 5.5 Thinking, um modelo de inteligência artificial de última geração desenvolvido para raciocínio avançado, arquitetura de software e programação de alto nível.');
$misticId = getSetting('mistic_client_id', '');
$misticSec = getSetting('mistic_client_secret', '');
$gatewayPublicUrl = getSetting('gateway_public_url', '');
$gatewayApiUrl = getSetting('gateway_api_url', 'http://127.0.0.1:3000');
$whatsappUrl = getSetting('whatsapp_api_url', 'http://127.0.0.1:3000');
$realtimeAccess = getSetting('realtime_access', 'all');
$themeMode = getSetting('theme_mode', 'light');
$themeScheme = getSetting('theme_color_scheme', 'purple_cyan');
$mcpLocalMode = getSetting('mcp_local_mode', '0') === '1';
$availableThemes = getThemeThemes();

require_once __DIR__ . '/includes/header.php';
?>

<div class="glass-card p-6 sm:p-8 rounded-3xl border border-gray-800">
    <form method="POST" action="settings.php" class="space-y-6">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_settings">

        <!-- Aparência & Temas Globais -->
        <div class="bg-gray-900/80 p-5 rounded-2xl border border-gray-800 space-y-5">
            <h3 class="text-xs font-bold text-amber-400 uppercase tracking-wider flex items-center justify-between">
                <span><i class="fa-solid fa-palette mr-2"></i> Aparência & Identidade Visual Global</span>
                <span class="text-[10px] text-gray-400 font-normal">Controle exclusivo do Administrador</span>
            </h3>
            
            <div class="space-y-4">
                <div>
                    <label class="block font-semibold text-gray-300 text-xs mb-2">Modo Padrão da Plataforma</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs max-w-xl">
                        <label class="flex items-center gap-3 p-3.5 rounded-xl border <?= $themeMode === 'light' ? 'border-amber-400 bg-amber-500/10 text-white font-bold ring-2 ring-amber-400/30' : 'border-gray-800 bg-gray-950 text-gray-400' ?> cursor-pointer hover:border-gray-700 transition">
                            <input type="radio" name="theme_mode" value="light" <?= $themeMode === 'light' ? 'checked' : '' ?> class="accent-amber-400">
                            <div>
                                <div class="font-bold text-white flex items-center gap-1.5"><i class="fa-solid fa-sun text-amber-400"></i> Clean White</div>
                                <div class="text-[10px] text-gray-400">Fundo branco limpo profissional de alto contraste</div>
                            </div>
                        </label>
                        <label class="flex items-center gap-3 p-3.5 rounded-xl border <?= $themeMode === 'dark' ? 'border-indigo-400 bg-indigo-500/10 text-white font-bold ring-2 ring-indigo-400/30' : 'border-gray-800 bg-gray-950 text-gray-400' ?> cursor-pointer hover:border-gray-700 transition">
                            <input type="radio" name="theme_mode" value="dark" <?= $themeMode === 'dark' ? 'checked' : '' ?> class="accent-indigo-400">
                            <div>
                                <div class="font-bold text-white flex items-center gap-1.5"><i class="fa-solid fa-moon text-indigo-400"></i> Cyber Titanium</div>
                                <div class="text-[10px] text-gray-400">Modo escuro espacial Obsidian de alto contraste</div>
                            </div>
                        </label>
                    </div>
                </div>

                <div>
                    <label class="block font-semibold text-gray-300 text-xs mb-2">Paleta de Cores do Sistema (Definida pelo Admin)</label>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 text-xs">
                        <?php foreach ($availableThemes as $tid => $t): ?>
                            <label class="flex items-center gap-3 p-3 rounded-xl border <?= $themeScheme === $tid ? 'border-indigo-400 bg-indigo-500/10 ring-2 ring-indigo-400/30 font-bold' : 'border-gray-800 bg-gray-950 text-gray-400' ?> cursor-pointer hover:border-gray-700 transition">
                                <input type="radio" name="theme_color_scheme" value="<?= $tid ?>" <?= $themeScheme === $tid ? 'checked' : '' ?> class="accent-indigo-400">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <span class="w-3.5 h-3.5 rounded-full shrink-0 shadow-sm" style="background-color: <?= $t['primary'] ?>;"></span>
                                        <span class="font-bold text-white text-xs truncate"><?= htmlspecialchars($t['name']) ?></span>
                                    </div>
                                    <div class="text-[10px] text-gray-400 truncate mt-0.5"><?= htmlspecialchars($t['desc']) ?></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- System Pre-Prompt Fallback -->
        <div class="bg-gray-900/80 p-5 rounded-2xl border border-gray-800 space-y-4">
            <div class="flex items-center justify-between flex-wrap gap-2">
                <div>
                    <h3 class="text-xs font-bold text-purple-400 uppercase tracking-wider flex items-center">
                        <i class="fa-solid fa-terminal mr-2"></i> 1. Pré-Prompt Global (Fallback)
                    </h3>
                    <p class="text-[11px] text-gray-400 mt-0.5">Este prompt será usado como fallback caso um modelo não possua pré-prompt próprio configurado.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="loadFablePrompt('global_pre_prompt')" class="px-3 py-1.5 rounded-lg bg-purple-600/20 hover:bg-purple-600/40 text-purple-300 border border-purple-500/30 text-xs font-medium transition flex items-center gap-1.5">
                        <i class="fa-solid fa-wand-magic-sparkles text-amber-300"></i> Carregar Fable 5.1 Otimizado
                    </button>
                    <button type="button" onclick="document.getElementById('global_pre_prompt').value = ''" class="px-2.5 py-1.5 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-400 text-xs transition">
                        Limpar
                    </button>
                </div>
            </div>
            <textarea name="pre_prompt" id="global_pre_prompt" rows="6" class="w-full p-3 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono text-xs focus:outline-none focus:border-purple-500" placeholder="Insira o pré-prompt global aqui..."><?= htmlspecialchars($prePrompt) ?></textarea>
        </div>

        <!-- Mistic Pay PIX Gateway -->
        <div class="bg-gray-900/80 p-5 rounded-2xl border border-gray-800 space-y-4">
            <h3 class="text-xs font-bold text-emerald-400 uppercase tracking-wider flex items-center">
                <i class="fa-solid fa-qrcode mr-2"></i> 2. Gateway Mistic Pay (PIX Automático)
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div>
                    <label class="block font-semibold text-gray-300 mb-1">Mistic Client ID</label>
                    <input type="text" name="mistic_client_id" value="<?= htmlspecialchars($misticId) ?>" placeholder="Client ID do Mistic Pay" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono">
                </div>
                <div>
                    <label class="block font-semibold text-gray-300 mb-1">Mistic Client Secret</label>
                    <input type="password" name="mistic_client_secret" value="<?= htmlspecialchars($misticSec) ?>" placeholder="Client Secret do Mistic Pay" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono">
                </div>
            </div>
        </div>

        <!-- URLs do Gateway e API (Domínio / VPS) -->
        <div class="bg-gray-900/80 p-5 rounded-2xl border border-gray-800 space-y-4">
            <h3 class="text-xs font-bold text-cyan-400 uppercase tracking-wider flex items-center">
                <i class="fa-solid fa-network-wired mr-2"></i> 3. Gateway de IA & URLs de Domínio
            </h3>
            <p class="text-[11px] text-gray-400">Configure o subdomínio público entregue aos clientes e a URL interna de comunicação do PHP com o Node.js.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div>
                    <label class="block font-semibold text-gray-300 mb-1">URL Pública da API (Ex: Subdomínio / VPS)</label>
                    <input type="text" name="gateway_public_url" value="<?= htmlspecialchars($gatewayPublicUrl) ?>" placeholder="https://api.seudominio.com" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono">
                    <span class="text-[10px] text-gray-500 mt-1 block">Ex: <code>https://api.seudominio.com</code> (vazio = autodeteção)</span>
                </div>
                <div>
                    <label class="block font-semibold text-gray-300 mb-1">URL Interna do Gateway (PHP &rarr; Node.js)</label>
                    <input type="text" name="gateway_api_url" value="<?= htmlspecialchars($gatewayApiUrl) ?>" required placeholder="http://127.0.0.1:3000" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono">
                    <span class="text-[10px] text-gray-500 mt-1 block">Comunicação local interna (padrão: <code>http://127.0.0.1:3000</code>)</span>
                </div>
            </div>
        </div>

        <!-- WhatsApp API -->
        <div class="bg-gray-900/80 p-5 rounded-2xl border border-gray-800 space-y-4">
            <h3 class="text-xs font-bold text-indigo-400 uppercase tracking-wider flex items-center">
                <i class="fa-brands fa-whatsapp mr-2"></i> 4. API de WhatsApp (Node.js Baileys Proxy)
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div>
                    <label class="block font-semibold text-gray-300 mb-1">URL da API WhatsApp</label>
                    <input type="text" name="whatsapp_api_url" value="<?= htmlspecialchars($whatsappUrl) ?>" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono">
                </div>
            </div>
        </div>

        <!-- Disponibilidade do Realtime -->
        <div class="bg-gray-900/80 p-5 rounded-2xl border border-gray-800 space-y-4">
            <h3 class="text-xs font-bold text-indigo-400 uppercase tracking-wider flex items-center">
                <i class="fa-solid fa-waveform-lines mr-2"></i> 4. Disponibilidade do Console Realtime (Voz & Streaming)
            </h3>
            <p class="text-[11px] text-gray-400">Escolha quem pode acessar e utilizar o Console Realtime de Voz na plataforma.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div>
                    <label class="block font-semibold text-gray-300 mb-1">Acesso ao Realtime</label>
                    <select name="realtime_access" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono text-xs">
                        <option value="all" <?= ($realtimeAccess === 'all') ? 'selected' : '' ?>>🌐 Liberado para Todos (Clientes & Admin)</option>
                        <option value="admin" <?= ($realtimeAccess === 'admin') ? 'selected' : '' ?>>🔒 Exclusivo para Administrador (Oculto para Clientes)</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- MCP: Modo Local (leitura de código do disco) -->
        <div class="bg-gray-900/80 p-5 rounded-2xl border border-gray-800 space-y-4">
            <h3 class="text-xs font-bold text-teal-400 uppercase tracking-wider flex items-center">
                <i class="fa-solid fa-folder-tree mr-2"></i> 5. MCP — Leitura de Código Local (Subagente)
            </h3>
            <p class="text-[11px] text-gray-400">Quando ligado, o MCP tem permissão para ler o código direto do disco desta máquina em <b>qualquer diretório ou projeto</b> solicitado pela IA. Deixe <b>desligado na VPS</b>: na VPS os clientes enviam código pelo parâmetro <code>conteudos</code>.</p>
            <label class="flex items-center gap-3 p-3.5 rounded-xl border <?= $mcpLocalMode ? 'border-teal-400 bg-teal-500/10 ring-2 ring-teal-400/30' : 'border-gray-800 bg-gray-950' ?> cursor-pointer hover:border-gray-700 transition max-w-xl">
                <input type="checkbox" name="mcp_local_mode" value="1" <?= $mcpLocalMode ? 'checked' : '' ?> class="accent-teal-400 w-4 h-4">
                <div>
                    <div class="font-bold text-white text-xs flex items-center gap-1.5"><i class="fa-solid fa-hard-drive text-teal-400"></i> Ativar Leitura de Disco Local (Modo Desenvolvedor)</div>
                    <div class="text-[10px] text-gray-400">Permite ao Claude ler arquivos e pastas em qualquer projeto deste computador. Na VPS mantenha desligado.</div>
                </div>
            </label>
        </div>

        <button type="submit" class="w-full py-4 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-bold text-sm shadow-xl shadow-purple-600/30 transition">
            <i class="fa-solid fa-floppy-disk mr-2"></i> Salvar Configurações Globais
        </button>
    </form>
</div>

<script>
const FABLE_OPTIMIZED_PROMPT = `# CLAUDE FABLE 5.1 & MYTHOS REASONING ENGINE (OPTIMIZED SYSTEM PROMPT)

You are operating under the cognitive architecture of Claude Fable 5.1 (Mythos-Class Reasoning Engine). You are a world-class principal software architect, deep systems engineer, and analytical polymath. Your mission is to provide exceptionally high-intelligence, technically rigorous, deeply reasoned, and production-ready solutions.

### 1. CORE COGNITIVE PRINCIPLES & DEEP REASONING
* First-Principles Thinking: Break down complex problems into their foundational components before proposing a solution. Analyze root causes rather than patching superficial symptoms.
* Proactive Edge-Case Analysis: Anticipate concurrency issues, race conditions, edge cases, payload overflows, memory leaks, network latencies, and security vulnerabilities before writing code.
* Critical Honesty & Rigor: Maintain technical truth over polite consensus. If an approach proposed by the user contains architectural flaws, security risks, performance bottlenecks, or scalability limits, state so clearly and respectfully, explaining the trade-offs and presenting the superior alternative.
* No Speculative Fabrication: Never guess facts, API specifications, flags, or library signatures. If something is unknown or ambiguous, deduce logically, verify via codebase context, or clearly state the uncertainty.

### 2. SOFTWARE ENGINEERING & CODE QUALITY STANDARDS
* Production-Grade Completeness: Deliver fully functional, robust, and idiomatic code. Never leave placeholders, truncated implementations, or lazy comments like "// TODO" or "// rest of code goes here".
* Zero Technical Debt: Implement defensive programming, strict input validation, sanitized database queries (prepared statements), proper escaping, and resilient error handling.
* High-Performance Architecture: Optimize database queries, connection pooling, and payload serialization. Maintain minimal runtime overhead and prevent memory leaks.
* Security & Vulnerability Immunity: Strictly protect against OWASP Top 10 vulnerabilities (SQL Injection, XSS, CSRF, SSRF, RCE, insecure deserialization, IDOR, and timing attacks).

### 3. TONE, DEMEANOR & COMMUNICATION PROTOCOL
* High Information Density: Every sentence must provide real value. Avoid filler phrases, robotic pleasantries, and hollow disclaimers.
* Concise & Direct: Provide the solution directly and clearly. Accompany complex solutions with brief, high-level rationale and architectural context.
* Constructive Ownership: Acknowledge issues objectively and proceed immediately to the optimal fix without unnecessary self-abasement or submissiveness.
* Clean Formatting: Use standard markdown with appropriate syntax highlighting. Use lists and bullet points only when they genuinely clarify multifaceted information.

### 4. LANGUAGE ADAPTABILITY
* Default to the language of the prompt (prioritizing fluent, natural Brazilian Portuguese when addressed in Portuguese) while preserving industry-standard English technical terms, variable names, and code syntax.`;

function loadFablePrompt(elementId) {
    const el = document.getElementById(elementId);
    if (el) {
        el.value = FABLE_OPTIMIZED_PROMPT;
        el.focus();
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
