<?php
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/whatsapp.php';
requireAdmin();

$db = getDB();
$pageTitle = 'WhatsApp & Agente IA';
$pageHeaderIcon = '<i class="fa-brands fa-whatsapp text-emerald-400 mr-2.5"></i>';
$msg = '';
$error = '';

// Processar formulários POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_bot_settings') {
        $botActive = isset($_POST['whatsapp_bot_active']) ? '1' : '0';
        $botModel = trim($_POST['whatsapp_bot_model'] ?? 'gemini-3.8-flash-medium');
        $botPrompt = trim($_POST['whatsapp_bot_prompt'] ?? '');

        setSetting('whatsapp_bot_active', $botActive);
        setSetting('whatsapp_bot_model', $botModel);
        setSetting('whatsapp_bot_prompt', $botPrompt);

        $msg = 'Configurações do Agente IA do WhatsApp salvas com sucesso!';
    } elseif ($_POST['action'] === 'save_templates') {
        setSetting('whatsapp_msg_otp', trim($_POST['whatsapp_msg_otp'] ?? ''));
        setSetting('whatsapp_msg_welcome', trim($_POST['whatsapp_msg_welcome'] ?? ''));
        setSetting('whatsapp_msg_pix', trim($_POST['whatsapp_msg_pix'] ?? ''));
        setSetting('whatsapp_msg_low_balance', trim($_POST['whatsapp_msg_low_balance'] ?? ''));

        $msg = 'Modelos de mensagens personalizadas salvos com sucesso!';
    } elseif ($_POST['action'] === 'send_test_message') {
        $phone = trim($_POST['phone'] ?? '');
        $message = trim($_POST['message'] ?? '');

        if (empty($phone) || empty($message)) {
            $error = 'Preencha o número de WhatsApp e a mensagem.';
        } else {
            $res = whatsapp_send_text($phone, $message);
            if (!empty($res['success'])) {
                $msg = 'Mensagem de teste enviada com sucesso para ' . htmlspecialchars($phone) . '!';
            } else {
                $error = 'Falha no envio: ' . ($res['error'] ?? 'Erro desconhecido');
            }
        }
    } elseif ($_POST['action'] === 'reconnect_instance') {
        $instKey = get_whatsapp_default_instance();
        whatsapp_api_request("instances/{$instKey}/connect", 'POST');
        $stmt = $db->prepare("UPDATE whatsapp_instances SET status = 'connecting' WHERE instance_key = ?");
        $stmt->execute([$instKey]);
        $msg = 'Novo QR Code gerado! Aponte a câmera do seu celular para conectar.';
    } elseif ($_POST['action'] === 'disconnect_instance') {
        $instKey = get_whatsapp_default_instance();
        whatsapp_api_request("instances/{$instKey}/disconnect", 'POST');
        $stmt = $db->prepare("UPDATE whatsapp_instances SET status = 'disconnected', qr_code = NULL WHERE instance_key = ?");
        $stmt->execute([$instKey]);
        $msg = 'Instância do WhatsApp desconectada.';
    }
}

// Carregar Configurações Atuais
$botActive = (getSetting('whatsapp_bot_active', '0') === '1');
$botModel = getSetting('whatsapp_bot_model', 'gemini-3.8-flash-medium');
$botPrompt = getSetting('whatsapp_bot_prompt', "Você é a atendente virtual de inteligência artificial da BRLove API.\nSeu objetivo é tirar dúvidas sobre a plataforma, explicar como funcionam as recargas de tokens, modelos disponíveis e ajudar o cliente a escolher o melhor plano.\nResponda sempre com simpatia, rapidez, boa formatação e em português do Brasil.");

$tplOtp = getSetting('whatsapp_msg_otp', "🔐 *Seu Código de Confirmação BRLove API:* *{codigo}*\n\nDigite este código no site para confirmar o seu acesso.\n\n_Válido por 10 minutos._");
$tplWelcome = getSetting('whatsapp_msg_welcome', "👋 Olá, *{nome}*! Bem-vindo à plataforma *BRLove API* de Inteligência Artificial.\nSua conta foi criada com sucesso e já está pronta para uso! Acesse seu painel e confira sua chave de API.");
$tplPix = getSetting('whatsapp_msg_pix', "✅ *Pagamento Confirmado!*\n\nOlá *{nome}*, seu pagamento de *R$ {valor}* via PIX foi aprovado com sucesso!\nForam creditados *{tokens} tokens* em sua conta. Bons usos! 🚀");
$tplLow = getSetting('whatsapp_msg_low_balance', "⚠️ *Alerta de Saldo BRLove API*\n\nOlá *{nome}*, seu saldo de tokens está em *{saldo}*. Para evitar pausas nas suas aplicações de IA, faça uma recarga no seu painel.");

// Carregar Modelos Ativos para o Select do Bot
$stmtModels = $db->query("SELECT model_key, name FROM models WHERE status = 'active' ORDER BY id ASC");
$activeModels = $stmtModels ? $stmtModels->fetchAll() : [];

// Status da Instância
$instKey = get_whatsapp_default_instance();
$statusRes = whatsapp_api_request("instances/{$instKey}/status");
$instanceStatus = $statusRes['status'] ?? 'disconnected';
$instanceQr = $statusRes['qrcode'] ?? '';
$instanceQrDataUrl = $statusRes['qrDataUrl'] ?? '';
$instancePhone = $statusRes['phone'] ?? '';

// Histórico
$recentMessages = [];
try {
    $stmtM = $db->query("SELECT * FROM whatsapp_messages ORDER BY id DESC LIMIT 8");
    $recentMessages = $stmtM ? $stmtM->fetchAll() : [];
} catch (Exception $e) {}

require_once __DIR__ . '/includes/header.php';
?>

<!-- QR Code Generator CDN -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<div class="space-y-6">

    <!-- Mensagens de Alerta -->
    <?php if ($msg): ?>
        <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-xs font-semibold flex items-center justify-between shadow-lg shadow-emerald-950/20">
            <span class="flex items-center gap-2"><i class="fa-solid fa-circle-check text-emerald-400"></i> <?= htmlspecialchars($msg) ?></span>
            <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-red-500/10 border border-red-500/30 text-red-300 text-xs font-semibold flex items-center justify-between shadow-lg shadow-red-950/20">
            <span class="flex items-center gap-2"><i class="fa-solid fa-triangle-exclamation text-red-400"></i> <?= htmlspecialchars($error) ?></span>
            <button onclick="this.parentElement.remove()" class="text-red-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>
    <?php endif; ?>

    <!-- BARRA DE NAVEGAÇÃO POR ABAS -->
    <div class="flex flex-wrap gap-2 p-1.5 rounded-2xl bg-gray-900/80 border border-gray-800 text-xs font-semibold">
        <button onclick="switchTab('tab_qrcode')" id="btn_tab_qrcode" class="tab-btn px-4 py-2.5 rounded-xl bg-purple-600 text-white shadow-lg transition flex items-center gap-2">
            <i class="fa-solid fa-qrcode"></i> 1. Conexão & QR Code
        </button>
        <button onclick="switchTab('tab_agent')" id="btn_tab_agent" class="tab-btn px-4 py-2.5 rounded-xl text-gray-400 hover:text-white hover:bg-gray-800 transition flex items-center gap-2">
            <i class="fa-solid fa-robot text-purple-400"></i> 2. Agente IA (Auto-Responder)
            <?php if ($botActive): ?>
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
            <?php endif; ?>
        </button>
        <button onclick="switchTab('tab_templates')" id="btn_tab_templates" class="tab-btn px-4 py-2.5 rounded-xl text-gray-400 hover:text-white hover:bg-gray-800 transition flex items-center gap-2">
            <i class="fa-solid fa-envelope-open-text text-amber-400"></i> 3. Personalizar Mensagens
        </button>
        <button onclick="switchTab('tab_test')" id="btn_tab_test" class="tab-btn px-4 py-2.5 rounded-xl text-gray-400 hover:text-white hover:bg-gray-800 transition flex items-center gap-2">
            <i class="fa-solid fa-paper-plane text-cyan-400"></i> 4. Teste de Disparo & Logs
        </button>
    </div>

    <!-- ============================================================= -->
    <!-- ABA 1: CONEXÃO & QR CODE -->
    <!-- ============================================================= -->
    <div id="tab_qrcode" class="tab-content space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            
            <!-- Visualizador do QR Code -->
            <div class="lg:col-span-6 bg-gray-900/60 backdrop-blur-xl border border-gray-800 rounded-3xl p-6 shadow-xl space-y-5 flex flex-col items-center justify-center text-center">
                <div class="w-full flex items-center justify-between pb-3 border-b border-gray-800/80">
                    <span class="text-xs font-bold text-white flex items-center gap-2">
                        <i class="fa-brands fa-whatsapp text-emerald-400 text-lg"></i>
                        Leitura do QR Code
                    </span>
                    <?php if ($instanceStatus === 'connected'): ?>
                        <span class="px-2.5 py-1 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 text-[10px] font-bold flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span> CONECTADO
                        </span>
                    <?php else: ?>
                        <span class="px-2.5 py-1 rounded-full bg-amber-500/20 text-amber-300 border border-amber-500/30 text-[10px] font-bold flex items-center gap-1.5">
                            <span class="w-2 h-2 rounded-full bg-amber-400 animate-ping"></span> AGUARDANDO LEITURA
                        </span>
                    <?php endif; ?>
                </div>

                <!-- Box do QR Code -->
                <div class="p-4 bg-white rounded-3xl shadow-2xl relative my-2 overflow-hidden flex items-center justify-center" style="width: 220px; height: 220px;">
                    <div id="qrcode_area"></div>
                    <?php if ($instanceStatus === 'connected'): ?>
                        <div class="absolute inset-0 bg-emerald-950/95 backdrop-blur-sm flex flex-col items-center justify-center p-4">
                            <i class="fa-solid fa-circle-check text-emerald-400 text-4xl mb-2"></i>
                            <span class="text-emerald-100 font-bold text-sm">WhatsApp Pareado</span>
                            <span class="text-emerald-300/80 text-[11px] mt-1">Sua instância está pronta para enviar e receber mensagens!</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="flex gap-2.5 pt-2">
                    <form method="POST" action="whatsapp.php" class="inline">
                        <input type="hidden" name="action" value="reconnect_instance">
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold transition flex items-center gap-1.5 shadow-lg shadow-purple-600/30">
                            <i class="fa-solid fa-arrows-rotate"></i> Gerar Novo QR Code
                        </button>
                    </form>
                    <form method="POST" action="whatsapp.php" class="inline" onsubmit="return confirm('Deseja realmente desconectar o WhatsApp?');">
                        <input type="hidden" name="action" value="disconnect_instance">
                        <button type="submit" class="px-4 py-2.5 rounded-xl bg-red-600/20 hover:bg-red-600/30 border border-red-500/30 text-red-300 text-xs font-bold transition flex items-center gap-1.5">
                            <i class="fa-solid fa-power-off"></i> Desconectar
                        </button>
                    </form>
                </div>
            </div>

            <!-- Instruções Passo a Passo -->
            <div class="lg:col-span-6 bg-gray-900/60 backdrop-blur-xl border border-gray-800 rounded-3xl p-6 shadow-xl space-y-4">
                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-mobile-screen-button text-indigo-400"></i>
                    Como Conectar seu Celular
                </h3>

                <div class="space-y-3 text-xs text-gray-300">
                    <div class="p-3 rounded-2xl bg-gray-950 border border-gray-800 flex items-start gap-3">
                        <div class="w-6 h-6 rounded-full bg-purple-600/30 text-purple-300 flex items-center justify-center font-bold text-xs flex-shrink-0">1</div>
                        <div>
                            <strong class="text-white">Abra o WhatsApp:</strong> No seu celular, toque nos 3 pontinhos (Android) ou em Configurações (iPhone).
                        </div>
                    </div>

                    <div class="p-3 rounded-2xl bg-gray-950 border border-gray-800 flex items-start gap-3">
                        <div class="w-6 h-6 rounded-full bg-purple-600/30 text-purple-300 flex items-center justify-center font-bold text-xs flex-shrink-0">2</div>
                        <div>
                            <strong class="text-white">Aparelhos Conectados:</strong> Selecione a opção "Aparelhos Conectados" e depois toque em "Conectar um Aparelho".
                        </div>
                    </div>

                    <div class="p-3 rounded-2xl bg-gray-950 border border-gray-800 flex items-start gap-3">
                        <div class="w-6 h-6 rounded-full bg-purple-600/30 text-purple-300 flex items-center justify-center font-bold text-xs flex-shrink-0">3</div>
                        <div>
                            <strong class="text-white">Escanear:</strong> Aponte a câmera do seu smartphone para o QR Code ao lado. Pronto! O sistema conecta automaticamente.
                        </div>
                    </div>
                </div>

                <!-- Detalhes do Gateway -->
                <div class="p-4 rounded-2xl bg-indigo-950/30 border border-indigo-800/30 space-y-1.5 text-xs">
                    <span class="text-indigo-300 font-bold flex items-center gap-1.5">
                        <i class="fa-solid fa-server"></i> Servidor Unificado Ativo
                    </span>
                    <p class="text-gray-400 text-[11px] leading-relaxed">
                        Tanto o envio de mensagens do WhatsApp quanto o Gateway de Inteligência Artificial rodam juntos no mesmo processo (<code class="text-cyan-300 font-mono">localhost:3000</code>).
                    </p>
                </div>
            </div>

        </div>
    </div>

    <!-- ============================================================= -->
    <!-- ABA 2: AGENTE IA (AUTO-RESPONDER DO WHATSAPP) -->
    <!-- ============================================================= -->
    <div id="tab_agent" class="tab-content space-y-6 hidden">
        <form method="POST" action="whatsapp.php" class="space-y-6">
            <input type="hidden" name="action" value="save_bot_settings">

            <div class="bg-gray-900/60 backdrop-blur-xl border border-gray-800 rounded-3xl p-6 shadow-xl space-y-6">
                
                <!-- Cabeçalho com Switch de Ativação -->
                <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 pb-5 border-b border-gray-800/80">
                    <div>
                        <h3 class="text-base font-bold text-white flex items-center gap-2">
                            <i class="fa-solid fa-robot text-purple-400 text-lg"></i>
                            Atendimento Automático com Agente IA
                        </h3>
                        <p class="text-xs text-gray-400 mt-1">Quando ativado, a inteligência artificial responde automaticamente aos clientes que enviarem mensagens no seu WhatsApp.</p>
                    </div>

                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="whatsapp_bot_active" value="1" <?= $botActive ? 'checked' : '' ?> class="sr-only peer">
                        <div class="w-14 h-7 bg-gray-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-emerald-500"></div>
                        <span class="ml-3 text-xs font-bold text-white"><?= $botActive ? '<span class="text-emerald-400">ATIVO</span>' : '<span class="text-gray-400">INATIVO</span>' ?></span>
                    </label>
                </div>

                <!-- Escolha do Modelo de IA -->
                <div class="space-y-2">
                    <label class="block text-xs font-bold text-white flex items-center gap-1.5">
                        <i class="fa-solid fa-microchip text-cyan-400"></i>
                        Escolha o Modelo de IA para Responder no WhatsApp
                    </label>
                    <p class="text-[11px] text-gray-400">Recomendamos modelos rápidos e econômicos como Gemini 3.7 Flash Medium ou Claude 3.5 Sonnet.</p>
                    <select name="whatsapp_bot_model" class="w-full p-3 rounded-xl bg-gray-950 border border-gray-800 text-cyan-300 font-mono text-xs focus:outline-none focus:border-purple-500">
                        <?php foreach ($activeModels as $m): ?>
                            <option value="<?= htmlspecialchars($m['model_key']) ?>" <?= ($botModel === $m['model_key']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['name']) ?> (<?= htmlspecialchars($m['model_key']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Prompt do Agente -->
                <div class="space-y-2">
                    <div class="flex items-center justify-between">
                        <label class="block text-xs font-bold text-white flex items-center gap-1.5">
                            <i class="fa-solid fa-brain text-purple-400"></i>
                            Prompt de Personalidade do Agente (Instruções do Sistema)
                        </label>
                        <span class="text-[10px] text-gray-500">Instrua como o agente deve responder aos clientes</span>
                    </div>
                    <textarea name="whatsapp_bot_prompt" rows="8" class="w-full p-4 rounded-2xl bg-gray-950 border border-gray-800 text-white font-mono text-xs leading-relaxed focus:outline-none focus:border-purple-500" placeholder="Você é o assistente virtual da BRLove API..."><?= htmlspecialchars($botPrompt) ?></textarea>
                </div>

                <!-- Dica rápida de prompt -->
                <div class="p-3.5 rounded-2xl bg-purple-950/30 border border-purple-800/30 flex items-start gap-2.5 text-xs text-purple-200">
                    <i class="fa-solid fa-lightbulb text-amber-400 mt-0.5"></i>
                    <div>
                        <strong>Dica de Prompt:</strong> Você pode incluir informações sobre os preços dos seus planos, suporte técnico, link do painel de login e orientações para pagamento via PIX.
                    </div>
                </div>

                <button type="submit" class="w-full py-3.5 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-bold text-xs shadow-xl shadow-purple-600/30 transition flex items-center justify-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i> Salvar Configurações do Agente IA
                </button>
            </div>
        </form>
    </div>

    <!-- ============================================================= -->
    <!-- ABA 3: PERSONALIZAR MENSAGENS AUTOMÁTICAS (TEMPLATES) -->
    <!-- ============================================================= -->
    <div id="tab_templates" class="tab-content space-y-6 hidden">
        <form method="POST" action="whatsapp.php" class="space-y-6">
            <input type="hidden" name="action" value="save_templates">

            <div class="bg-gray-900/60 backdrop-blur-xl border border-gray-800 rounded-3xl p-6 shadow-xl space-y-6">
                <div class="pb-4 border-b border-gray-800/80">
                    <h3 class="text-base font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-envelope-open-text text-amber-400 text-lg"></i>
                        Personalização das Mensagens Automáticas
                    </h3>
                    <p class="text-xs text-gray-400 mt-1">Configure o texto exato enviado aos clientes em cada evento do sistema. Use as tags entre chaves para valores dinâmicos.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 text-xs">
                    
                    <!-- 1. Mensagem de OTP -->
                    <div class="p-4 rounded-2xl bg-gray-950 border border-gray-800 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-amber-300 flex items-center gap-1.5">
                                <i class="fa-solid fa-key"></i> 1. Código de Confirmação (OTP)
                            </span>
                            <span class="text-[10px] text-gray-500 font-mono">Tag: {codigo}</span>
                        </div>
                        <textarea name="whatsapp_msg_otp" rows="4" class="w-full p-2.5 rounded-xl bg-gray-900 border border-gray-800 text-white font-mono text-xs focus:outline-none focus:border-amber-500"><?= htmlspecialchars($tplOtp) ?></textarea>
                        <p class="text-[10px] text-gray-500">Disparado no cadastro e login de novos clientes.</p>
                    </div>

                    <!-- 2. Mensagem de Boas-Vindas -->
                    <div class="p-4 rounded-2xl bg-gray-950 border border-gray-800 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-indigo-300 flex items-center gap-1.5">
                                <i class="fa-solid fa-hand-wave"></i> 2. Boas-Vindas / Novo Cadastro
                            </span>
                            <span class="text-[10px] text-gray-500 font-mono">Tags: {nome}, {whatsapp}</span>
                        </div>
                        <textarea name="whatsapp_msg_welcome" rows="4" class="w-full p-2.5 rounded-xl bg-gray-900 border border-gray-800 text-white font-mono text-xs focus:outline-none focus:border-indigo-500"><?= htmlspecialchars($tplWelcome) ?></textarea>
                        <p class="text-[10px] text-gray-500">Enviado logo após a criação da conta do cliente.</p>
                    </div>

                    <!-- 3. Mensagem de Pagamento PIX Aprovado -->
                    <div class="p-4 rounded-2xl bg-gray-950 border border-gray-800 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-emerald-300 flex items-center gap-1.5">
                                <i class="fa-solid fa-circle-check"></i> 3. Pagamento PIX Aprovado
                            </span>
                            <span class="text-[10px] text-gray-500 font-mono">Tags: {nome}, {valor}, {tokens}</span>
                        </div>
                        <textarea name="whatsapp_msg_pix" rows="4" class="w-full p-2.5 rounded-xl bg-gray-900 border border-gray-800 text-white font-mono text-xs focus:outline-none focus:border-emerald-500"><?= htmlspecialchars($tplPix) ?></textarea>
                        <p class="text-[10px] text-gray-500">Disparado automaticamente na confirmação do webhook PIX.</p>
                    </div>

                    <!-- 4. Alerta de Saldo Baixo -->
                    <div class="p-4 rounded-2xl bg-gray-950 border border-gray-800 space-y-2">
                        <div class="flex items-center justify-between">
                            <span class="font-bold text-rose-300 flex items-center gap-1.5">
                                <i class="fa-solid fa-triangle-exclamation"></i> 4. Alerta de Saldo Baixo
                            </span>
                            <span class="text-[10px] text-gray-500 font-mono">Tags: {nome}, {saldo}</span>
                        </div>
                        <textarea name="whatsapp_msg_low_balance" rows="4" class="w-full p-2.5 rounded-xl bg-gray-900 border border-gray-800 text-white font-mono text-xs focus:outline-none focus:border-rose-500"><?= htmlspecialchars($tplLow) ?></textarea>
                        <p class="text-[10px] text-gray-500">Disparado manualmente ou quando o saldo de tokens está no fim.</p>
                    </div>

                </div>

                <button type="submit" class="w-full py-3.5 rounded-xl bg-gradient-to-r from-amber-600 to-orange-600 hover:from-amber-500 hover:to-orange-500 text-white font-bold text-xs shadow-xl shadow-amber-600/30 transition flex items-center justify-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i> Salvar Todos os Modelos de Mensagens
                </button>
            </div>
        </form>
    </div>

    <!-- ============================================================= -->
    <!-- ABA 4: TESTE DE DISPARO & LOGS -->
    <!-- ============================================================= -->
    <div id="tab_test" class="tab-content space-y-6 hidden">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

            <!-- Formulário de Teste -->
            <div class="lg:col-span-5 bg-gray-900/60 backdrop-blur-xl border border-gray-800 rounded-3xl p-6 shadow-xl space-y-4">
                <div class="pb-3 border-b border-gray-800/80">
                    <h3 class="text-sm font-bold text-white flex items-center gap-2">
                        <i class="fa-solid fa-paper-plane text-cyan-400"></i>
                        Disparo Instantâneo de Teste
                    </h3>
                    <p class="text-[11px] text-gray-400 mt-0.5">Teste o envio com normalização automática de DDD.</p>
                </div>

                <form method="POST" action="whatsapp.php" class="space-y-4 text-xs">
                    <input type="hidden" name="action" value="send_test_message">

                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">WhatsApp de Destino (DDD + Número)</label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-emerald-400 font-bold">+55</span>
                            <input type="text" name="phone" required placeholder="11 99999-9999" class="w-full pl-12 pr-3 py-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono focus:outline-none focus:border-cyan-500">
                        </div>
                    </div>

                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">Mensagem</label>
                        <textarea name="message" rows="4" required class="w-full p-3 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono text-xs focus:outline-none focus:border-cyan-500">Olá! Mensagem de teste do WhatsApp BRLove API com validação inteligente de DDD.</textarea>
                    </div>

                    <button type="submit" class="w-full py-3 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-bold text-xs shadow-xl shadow-emerald-600/25 transition flex items-center justify-center gap-2">
                        <i class="fa-brands fa-whatsapp text-sm"></i> Enviar Mensagem Agora
                    </button>
                </form>
            </div>

            <!-- Tabela de Últimos Envios -->
            <div class="lg:col-span-7 bg-gray-900/60 backdrop-blur-xl border border-gray-800 rounded-3xl p-6 shadow-xl space-y-4">
                <h3 class="text-sm font-bold text-white flex items-center gap-2">
                    <i class="fa-solid fa-clock-rotate-left text-purple-400"></i>
                    Últimos Disparos Registrados
                </h3>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead>
                            <tr class="text-gray-400 border-b border-gray-800 text-[10px] uppercase font-mono">
                                <th class="py-2.5 px-3">Destino</th>
                                <th class="py-2.5 px-3">Mensagem</th>
                                <th class="py-2.5 px-3">Status</th>
                                <th class="py-2.5 px-3">Data</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-800/50 text-[11px] font-mono">
                            <?php if (empty($recentMessages)): ?>
                                <tr>
                                    <td colspan="4" class="py-6 text-center text-gray-500">Nenhum disparo registrado ainda.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentMessages as $m): ?>
                                    <tr class="hover:bg-gray-800/30">
                                        <td class="py-2.5 px-3 text-emerald-400 font-bold"><?= htmlspecialchars($m['destination']) ?></td>
                                        <td class="py-2.5 px-3 text-gray-300 truncate max-w-xs" title="<?= htmlspecialchars($m['message']) ?>"><?= htmlspecialchars($m['message']) ?></td>
                                        <td class="py-2.5 px-3"><span class="px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 text-[10px]">Entregue</span></td>
                                        <td class="py-2.5 px-3 text-gray-500 text-[10px]"><?= date('d/m H:i', strtotime($m['sent_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

</div>

<script>
    // Gerenciador de Abas
    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('bg-purple-600', 'text-white', 'shadow-lg');
            btn.classList.add('text-gray-400');
        });

        const activeTab = document.getElementById(tabId);
        const activeBtn = document.getElementById('btn_' + tabId);
        if (activeTab) activeTab.classList.remove('hidden');
        if (activeBtn) {
            activeBtn.classList.add('bg-purple-600', 'text-white', 'shadow-lg');
            activeBtn.classList.remove('text-gray-400');
        }
    }

    // Inicializar QR Code e Polling de Conexão em Tempo Real
    document.addEventListener("DOMContentLoaded", () => {
        const qrContainer = document.getElementById("qrcode_area");
        const qrData = <?= json_encode($instanceQr) ?>;
        const qrDataUrl = <?= json_encode($instanceQrDataUrl) ?>;
        const isConnected = <?= ($instanceStatus === 'connected') ? 'true' : 'false' ?>;

        if (!isConnected && !qrDataUrl && qrData && qrContainer && typeof QRCode !== 'undefined') {
            qrContainer.innerHTML = '';
            new QRCode(qrContainer, {
                text: qrData,
                width: 190,
                height: 190,
                colorDark : "#0f172a",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.M
            });
        }

        // Se estiver desconectado ou aguardando leitura, faz polling a cada 3s para detectar quando o celular ler
        if (!isConnected) {
            const checkInterval = setInterval(async () => {
                try {
                    const res = await fetch('../api/whatsapp_proxy.php?route=instances/inst_default/status');
                    const data = await res.json();
                    if (data && data.status === 'connected') {
                        clearInterval(checkInterval);
                        window.location.reload();
                    } else if (data && data.qrDataUrl) {
                        const area = document.getElementById("qrcode_area");
                        if (area) {
                            area.innerHTML = '<img src="' + data.qrDataUrl + '" alt="QR Code" class="w-full h-full object-contain">';
                        }
                    }
                } catch (e) {}
            }, 3000);
        }
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
