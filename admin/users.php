<?php
require_once __DIR__ . '/../config/helpers.php';
requireAdmin();

$db = getDB();
$pageTitle = 'Usuários e Revendedores';
$pageHeaderIcon = '<i class="fa-solid fa-users-gear text-indigo-400 mr-2.5"></i>';
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'create_user') {
        $name = trim($_POST['name'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'user';
        $initialTokens = (int)($_POST['initial_tokens'] ?? 10000000);

        if (empty($name) || empty($whatsapp) || empty($password)) {
            $error = 'Preencha Nome, WhatsApp e Senha para cadastrar.';
        } else {
            // Limpar caracteres não numéricos do WhatsApp
            $cleanWa = preg_replace('/\D/', '', $whatsapp);
            $stmtCheck = $db->prepare("SELECT id FROM users WHERE whatsapp = ?");
            $stmtCheck->execute([$cleanWa]);
            if ($stmtCheck->fetch()) {
                $error = 'Este número de WhatsApp já está cadastrado!';
            } else {
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                $stmtInsert = $db->prepare("INSERT INTO users (name, whatsapp, email, password, password_hash, role, balance_tokens, status, is_whatsapp_verified) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 1)");
                $stmtInsert->execute([$name, $cleanWa, $email ?: null, $passwordHash, $passwordHash, $role, $initialTokens]);
                
                // Gerar chave de API inicial automaticamente
                $newUserId = $db->lastInsertId();
                $newApiKey = 'sk-ai-' . bin2hex(random_bytes(16));
                $stmtKey = $db->prepare("INSERT INTO api_keys (user_id, key_name, api_key) VALUES (?, 'Chave Inicial', ?)");
                $stmtKey->execute([$newUserId, $newApiKey]);

                $msg = "Usuário/Revendedor {$name} cadastrado com sucesso!";
            }
        }
    } elseif ($_POST['action'] === 'update_user_balance') {
        $userId = (int)$_POST['user_id'];
        $addTokens = (int)$_POST['add_tokens'];
        $status = $_POST['status'] ?? 'active';

        $stmt = $db->prepare("UPDATE users SET balance_tokens = balance_tokens + ?, status = ? WHERE id = ?");
        $stmt->execute([$addTokens, $status, $userId]);
        $msg = 'Saldo de tokens do usuário atualizado!';
    } elseif ($_POST['action'] === 'update_user_role') {
        $userId = (int)$_POST['user_id'];
        $newRole = $_POST['role'] ?? 'user';
        $stmt = $db->prepare("UPDATE users SET role = ? WHERE id = ?");
        $stmt->execute([$newRole, $userId]);
        $msg = 'Papel (Role) do usuário atualizado!';
    } elseif ($_POST['action'] === 'toggle_user_status') {
        $userId = (int)$_POST['user_id'];
        $currentStatus = $_POST['current_status'] ?? 'active';
        $newStatus = ($currentStatus === 'active') ? 'suspended' : 'active';

        $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $userId]);
        $msg = 'Status do usuário alterado com sucesso!';
    } elseif ($_POST['action'] === 'send_whatsapp_message') {
        require_once __DIR__ . '/../config/whatsapp.php';
        $phone = trim($_POST['phone'] ?? '');
        $message = trim($_POST['message'] ?? '');
        $userName = trim($_POST['user_name'] ?? 'Cliente');

        if (empty($phone) || empty($message)) {
            $error = 'Preencha o número de WhatsApp e a mensagem para enviar.';
        } else {
            $res = whatsapp_send_text($phone, $message);
            if (!empty($res['success'])) {
                $msg = "Mensagem WhatsApp enviada com sucesso para {$userName} ({$phone})!";
            } else {
                $error = "Falha ao enviar mensagem para {$userName}: " . ($res['error'] ?? 'Erro desconhecido na API.');
            }
        }
    }
}

// Buscar Usuários
$stmtUsers = $db->query("SELECT * FROM users ORDER BY id DESC");
$users = $stmtUsers->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="glass-card p-6 rounded-3xl border border-gray-800 space-y-4">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
        <div>
            <h3 class="text-base font-bold text-white"><i class="fa-solid fa-users-gear text-indigo-400 mr-2"></i> Usuários e Revendedores Cadastrados</h3>
            <p class="text-xs text-gray-400">Gerencie saldo de tokens, adicione créditos manuais ou suspenda acesso.</p>
        </div>
        <input type="text" id="userSearchInput" onkeyup="filterUsersTable()" placeholder="Buscar por Nome/WhatsApp..." class="px-4 py-2 text-xs rounded-xl bg-gray-900 border border-gray-800 text-white w-full sm:w-64 focus:outline-none focus:border-indigo-500">
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs" id="usersTable">
            <thead>
                <tr class="text-gray-400 border-b border-gray-800 uppercase font-mono tracking-wider">
                    <th class="py-3 px-4">Usuário</th>
                    <th class="py-3 px-4">Papel</th>
                    <th class="py-3 px-4">Plano</th>
                    <th class="py-3 px-4">Saldo de Tokens</th>
                    <th class="py-3 px-4">Consumo Total</th>
                    <th class="py-3 px-4">Status</th>
                    <th class="py-3 px-4">Adicionar Saldo</th>
                    <th class="py-3 px-4">WhatsApp</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800/60 font-mono">
                <?php foreach ($users as $u): ?>
                    <tr class="hover:bg-gray-900/40">
                        <td class="py-3.5 px-4 font-sans">
                            <div class="font-bold text-white"><?= htmlspecialchars($u['name']) ?></div>
                            <div class="text-[11px] text-emerald-400 font-mono"><i class="fa-brands fa-whatsapp mr-1"></i><?= htmlspecialchars($u['whatsapp']) ?></div>
                            <?php if ($u['email']): ?>
                                <div class="text-[10px] text-gray-500"><?= htmlspecialchars($u['email']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="py-3.5 px-4 font-sans">
                            <?php if ($u['role'] === 'admin'): ?>
                                <span class="px-2.5 py-1 rounded-full bg-purple-500/20 text-purple-300 font-bold text-[10px]">ADMIN</span>
                            <?php else: ?>
                                <span class="px-2.5 py-1 rounded-full bg-gray-800 text-gray-300 font-semibold text-[10px]">CLIENTE</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-3.5 px-4 font-sans text-gray-300">
                            <?= htmlspecialchars($u['plan_type']) ?>
                        </td>
                        <td class="py-3.5 px-4 text-cyan-300 font-bold">
                            <?= number_format($u['balance_tokens'], 0, ',', '.') ?>
                        </td>
                        <td class="py-3.5 px-4 text-gray-400">
                            <?= number_format($u['tokens_used'], 0, ',', '.') ?>
                        </td>
                        <td class="py-3.5 px-4 font-sans">
                            <form method="POST" action="users.php" class="inline">
                                <input type="hidden" name="action" value="toggle_user_status">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <input type="hidden" name="current_status" value="<?= $u['status'] ?>">
                                <?php if ($u['status'] === 'active'): ?>
                                    <button type="submit" title="Clique para suspender" class="px-2.5 py-1 rounded-full bg-emerald-500/20 text-emerald-400 font-semibold text-[11px] hover:bg-emerald-500/30 transition">
                                        ● Ativo
                                    </button>
                                <?php else: ?>
                                    <button type="submit" title="Clique para ativar" class="px-2.5 py-1 rounded-full bg-red-500/20 text-red-400 font-semibold text-[11px] hover:bg-red-500/30 transition">
                                        ● Suspenso
                                    </button>
                                <?php endif; ?>
                            </form>
                        </td>
                        <td class="py-3.5 px-4 font-sans">
                            <form method="POST" action="users.php" class="flex items-center space-x-2">
                                <input type="hidden" name="action" value="update_user_balance">
                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                <input type="number" name="add_tokens" placeholder="+ Tokens" required class="w-24 px-2.5 py-1.5 bg-gray-950 border border-gray-800 rounded-lg text-xs text-white focus:outline-none focus:border-indigo-500">
                                <button type="submit" class="px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold transition">
                                    Adicionar
                                </button>
                            </form>
                        </td>
                        <td class="py-3.5 px-4 font-sans">
                            <button type="button" onclick="openSendWaModal('<?= htmlspecialchars($u['whatsapp']) ?>', '<?= htmlspecialchars(addslashes($u['name'])) ?>')" class="px-2.5 py-1.5 rounded-xl bg-emerald-500/20 hover:bg-emerald-500/30 text-emerald-300 border border-emerald-500/30 text-[11px] font-semibold transition flex items-center gap-1.5 whitespace-nowrap shadow-sm">
                                <i class="fa-brands fa-whatsapp text-emerald-400"></i> Enviar Msg
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function filterUsersTable() {
        const input = document.getElementById('userSearchInput').value.toLowerCase();
        const rows = document.querySelectorAll('#usersTable tbody tr');

        rows.forEach(row => {
            const text = row.innerText.toLowerCase();
            row.style.display = text.includes(input) ? '' : 'none';
        });
    }
</script>


<!-- Modal de Disparo Rápido de WhatsApp -->
<div id="waSendModal" class="fixed inset-0 bg-black/80 backdrop-blur-md flex items-center justify-center p-4 z-50 hidden">
    <div class="glass-card max-w-lg w-full p-6 rounded-3xl border border-gray-800 space-y-4 relative shadow-2xl">
        <button onclick="closeSendWaModal()" class="absolute top-4 right-4 text-gray-400 hover:text-white"><i class="fa-solid fa-xmark text-lg"></i></button>

        <div>
            <h3 class="text-base font-bold text-white flex items-center">
                <i class="fa-brands fa-whatsapp text-emerald-400 text-xl mr-2"></i> Enviar Mensagem para o Cliente
            </h3>
            <p class="text-xs text-gray-400 mt-1">Dispare avisos, confirmações e notificações com validação automática de DDD brasileiro.</p>
        </div>

        <form method="POST" action="users.php" class="space-y-4 text-xs">
            <input type="hidden" name="action" value="send_whatsapp_message">
            <input type="hidden" name="user_name" id="modal_wa_user_name">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-gray-300 mb-1">Destinatário</label>
                    <input type="text" id="modal_wa_name_display" readonly class="w-full p-2.5 rounded-xl bg-gray-950/70 border border-gray-800 text-gray-300 font-bold">
                </div>
                <div>
                    <label class="block font-semibold text-gray-300 mb-1">WhatsApp</label>
                    <input type="text" name="phone" id="modal_wa_phone" required class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-emerald-400 font-mono font-bold">
                </div>
            </div>

            <!-- Box informativo sobre verificação de DDD e 9º dígito -->
            <div id="modal_wa_ddd_info" class="p-3 rounded-xl bg-indigo-950/40 border border-indigo-800/40 text-[11px] text-indigo-200 flex items-start gap-2">
                <i class="fa-solid fa-circle-info text-indigo-400 mt-0.5"></i>
                <span id="modal_wa_ddd_text">Analisando DDD do cliente...</span>
            </div>

            <!-- Modelos Pré-definidos -->
            <div>
                <span class="text-[10px] text-gray-400 block mb-1.5 font-semibold">Modelos Rápidos de Mensagem:</span>
                <div class="flex flex-wrap gap-1.5">
                    <button type="button" onclick="fillWaTemplate('recharge')" class="px-2 py-1 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 text-[10px]">
                        ⚡ Saldo Recarregado
                    </button>
                    <button type="button" onclick="fillWaTemplate('low_tokens')" class="px-2 py-1 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 text-[10px]">
                        ⚠️ Saldo Baixo
                    </button>
                    <button type="button" onclick="fillWaTemplate('welcome')" class="px-2 py-1 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 text-[10px]">
                        👋 Boas-vindas
                    </button>
                </div>
            </div>

            <div>
                <label class="block font-semibold text-gray-300 mb-1">Conteúdo da Mensagem</label>
                <textarea name="message" id="modal_wa_message" rows="4" required class="w-full p-3 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono text-xs focus:outline-none focus:border-emerald-500"></textarea>
            </div>

            <button type="submit" class="w-full py-3.5 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-bold text-xs shadow-xl shadow-emerald-600/25 transition flex items-center justify-center gap-2">
                <i class="fa-brands fa-whatsapp text-base"></i> Enviar Mensagem Agora
            </button>
        </form>
    </div>
</div>

<script>
    function openSendWaModal(phone, name) {
        document.getElementById('modal_wa_phone').value = phone;
        document.getElementById('modal_wa_name_display').value = name;
        document.getElementById('modal_wa_user_name').value = name;
        
        // Analisar DDD
        const clean = phone.replace(/\D/g, '');
        let ddd = 0;
        if (clean.startsWith('55') && clean.length >= 4) {
            ddd = parseInt(clean.substring(2, 4), 10);
        } else if (clean.length >= 2) {
            ddd = parseInt(clean.substring(0, 2), 10);
        }

        const infoBox = document.getElementById('modal_wa_ddd_text');
        if (ddd >= 11 && ddd <= 28) {
            infoBox.innerHTML = '<strong>DDD ' + ddd + ' detectado (SP/RJ/ES):</strong> Regra do 9º dígito validada automaticamente.';
        } else if (ddd > 28) {
            infoBox.innerHTML = '<strong>DDD ' + ddd + ' detectado:</strong> Compatibilidade garantida (suporte a 8 e 9 dígitos).';
        } else {
            infoBox.innerText = 'Verificação de DDD e formato brasileiro ativo.';
        }

        // Mensagem padrão
        document.getElementById('modal_wa_message').value = 'Olá, ' + name + '! Tudo bem? Esta é uma mensagem de contato do suporte BRLove API.';
        document.getElementById('waSendModal').classList.remove('hidden');
    }

    function closeSendWaModal() {
        document.getElementById('waSendModal').classList.add('hidden');
    }

    function fillWaTemplate(type) {
        const name = document.getElementById('modal_wa_name_display').value || 'Cliente';
        const msgEl = document.getElementById('modal_wa_message');
        if (type === 'recharge') {
            msgEl.value = 'Olá, ' + name + '! Seus tokens foram recarregados com sucesso em sua conta BRLove API. Bons usos em seus projetos!';
        } else if (type === 'low_tokens') {
            msgEl.value = 'Aviso BRLove API: Olá ' + name + ', informamos que seu saldo de tokens está chegando ao fim. Para evitar interrupções na sua API, faça uma recarga no painel.';
        } else if (type === 'welcome') {
            msgEl.value = 'Olá, ' + name + '! Seja muito bem-vindo à nossa plataforma BRLove API de Inteligência Artificial. Sua chave já está pronta para uso.';
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
