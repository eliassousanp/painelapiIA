<?php
require_once __DIR__ . '/../config/helpers.php';
requireReseller();

$db = getDB();
$resellerId = $_SESSION['user_id'];
$pageTitle = 'Painel de Gestão da Revenda';
$pageHeaderIcon = '<i class="fa-solid fa-briefcase text-amber-400 mr-2.5"></i>';
$msg = '';
$error = '';

// Buscar dados atualizados do revendedor
$stmtReseller = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmtReseller->execute([$resellerId]);
$reseller = $stmtReseller->fetch();

// Buscar planos prontos ativos para clientes
try {
    $stmtActivePlans = $db->query("SELECT * FROM plans WHERE status = 'active' AND target_audience IN ('all', 'client') ORDER BY price ASC");
    $activePlans = $stmtActivePlans->fetchAll();
} catch (Exception $e) {
    $activePlans = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validate_csrf()) {
        $error = 'Sessão expirada ou token de segurança CSRF inválido. Recarregue a página.';
    } else {
        $action = $_POST['action'];

        if ($action === 'create_client') {
        $name = trim($_POST['name'] ?? '');
        $whatsapp = trim($_POST['whatsapp'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $initialTokens = (int)($_POST['initial_tokens'] ?? 0);
        $planType = trim($_POST['plan_type'] ?? 'Personalizado');

        if (empty($name) || empty($whatsapp) || empty($password)) {
            $error = 'Preencha Nome, WhatsApp e Senha para cadastrar o cliente.';
        } elseif ($initialTokens > 0 && $reseller['balance_tokens'] < $initialTokens) {
            $error = 'Saldo de tokens da sua revenda insuficiente para esta transferência inicial!';
        } else {
            $cleanWa = preg_replace('/\D/', '', $whatsapp);
            $stmtCheck = $db->prepare("SELECT id FROM users WHERE whatsapp = ?");
            $stmtCheck->execute([$cleanWa]);

            if ($stmtCheck->fetch()) {
                $error = 'Este WhatsApp já está cadastrado no sistema!';
            } else {
                $db->beginTransaction();
                try {
                    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
                    $stmtInsert = $db->prepare("INSERT INTO users (name, whatsapp, email, password, password_hash, role, parent_id, balance_tokens, plan_type, status, is_whatsapp_verified) VALUES (?, ?, ?, ?, ?, 'user', ?, ?, ?, 'active', 1)");
                    $stmtInsert->execute([$name, $cleanWa, $email ?: null, $passwordHash, $passwordHash, $resellerId, $initialTokens, $planType]);

                    $newClientId = $db->lastInsertId();

                    // Gerar API Key para o cliente no padrão oficial Claude
                    $newApiKey = generateApiKey();
                    $stmtKey = $db->prepare("INSERT INTO api_keys (user_id, key_name, api_key) VALUES (?, 'Chave Inicial', ?)");
                    $stmtKey->execute([$newClientId, $newApiKey]);

                    // Deduzir tokens do saldo do revendedor
                    if ($initialTokens > 0) {
                        $stmtDeduct = $db->prepare("UPDATE users SET balance_tokens = balance_tokens - ? WHERE id = ?");
                        $stmtDeduct->execute([$initialTokens, $resellerId]);
                    }

                    $db->commit();

                    // Recarregar dados do revendedor
                    $stmtReseller->execute([$resellerId]);
                    $reseller = $stmtReseller->fetch();

                    $msg = "Cliente {$name} cadastrado com sucesso no plano '{$planType}' com " . number_format($initialTokens, 0, ',', '.') . " tokens!";
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Erro ao cadastrar cliente: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'assign_plan') {
        $clientId = (int)$_POST['client_id'];
        $planId = (int)$_POST['plan_id'];

        // Buscar plano
        $stmtP = $db->prepare("SELECT * FROM plans WHERE id = ? AND status = 'active'");
        $stmtP->execute([$planId]);
        $plan = $stmtP->fetch();

        if (!$plan) {
            $error = 'Plano selecionado não foi encontrado ou está inativo.';
        } else {
            $addTokens = (int)$plan['tokens_amount'];
            if ($reseller['balance_tokens'] < $addTokens) {
                $error = 'Seu saldo de revenda é insuficiente para atribuir este plano (' . number_format($addTokens, 0, ',', '.') . ' tokens)! Recarregue sua revenda.';
            } else {
                $db->beginTransaction();
                try {
                    // Deduzir da revenda
                    $stmt1 = $db->prepare("UPDATE users SET balance_tokens = balance_tokens - ? WHERE id = ?");
                    $stmt1->execute([$addTokens, $resellerId]);

                    // Creditar no cliente e atualizar o tipo do plano
                    $stmt2 = $db->prepare("UPDATE users SET balance_tokens = balance_tokens + ?, plan_type = ? WHERE id = ? AND parent_id = ?");
                    $stmt2->execute([$addTokens, $plan['name'], $clientId, $resellerId]);

                    $db->commit();

                    // Recarregar dados do revendedor
                    $stmtReseller->execute([$resellerId]);
                    $reseller = $stmtReseller->fetch();

                    $msg = "Plano '{$plan['name']}' atribuído com sucesso! " . number_format($addTokens, 0, ',', '.') . " tokens creditados no cliente.";
                } catch (Exception $e) {
                    $db->rollBack();
                    $error = 'Erro ao atribuir plano: ' . $e->getMessage();
                }
            }
        }
    } elseif ($action === 'transfer_tokens') {
        $clientId = (int)$_POST['client_id'];
        $addTokens = (int)$_POST['add_tokens'];

        if ($addTokens <= 0) {
            $error = 'Informe uma quantidade válida de tokens para transferir.';
        } elseif ($reseller['balance_tokens'] < $addTokens) {
            $error = 'Seu saldo de revenda é insuficiente para essa transferência!';
        } else {
            $db->beginTransaction();
            try {
                $stmt1 = $db->prepare("UPDATE users SET balance_tokens = balance_tokens - ? WHERE id = ?");
                $stmt1->execute([$addTokens, $resellerId]);

                $stmt2 = $db->prepare("UPDATE users SET balance_tokens = balance_tokens + ? WHERE id = ? AND parent_id = ?");
                $stmt2->execute([$addTokens, $clientId, $resellerId]);

                $db->commit();

                // Recarregar dados do revendedor
                $stmtReseller->execute([$resellerId]);
                $reseller = $stmtReseller->fetch();

                $msg = "Transferência de " . number_format($addTokens, 0, ',', '.') . " tokens concluída com sucesso!";
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Erro na transferência: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'toggle_client_status') {
        $clientId = (int)$_POST['client_id'];
        $currentStatus = $_POST['current_status'] ?? 'active';
        $newStatus = ($currentStatus === 'active') ? 'suspended' : 'active';

        $stmt = $db->prepare("UPDATE users SET status = ? WHERE id = ? AND parent_id = ?");
        $stmt->execute([$newStatus, $clientId, $resellerId]);
        $msg = 'Status do cliente alterado com sucesso!';
    }
    }
}

// Buscar clientes da revenda
$stmtMyClients = $db->prepare("SELECT * FROM users WHERE parent_id = ? ORDER BY id DESC");
$stmtMyClients->execute([$resellerId]);
$myClients = $stmtMyClients->fetchAll();

// Estatísticas da Revenda
$totalClients = count($myClients);
$totalTokensDistributed = 0;
foreach ($myClients as $c) {
    $totalTokensDistributed += $c['balance_tokens'];
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">

    <?php if ($msg): ?>
        <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-400 text-xs flex items-center justify-between">
            <div class="flex items-center space-x-2">
                <i class="fa-solid fa-circle-check text-base"></i>
                <span><?= htmlspecialchars($msg) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-emerald-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="p-4 rounded-2xl bg-red-500/10 border border-red-500/30 text-red-400 text-xs flex items-center justify-between">
            <div class="flex items-center space-x-2">
                <i class="fa-solid fa-triangle-exclamation text-base"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
            <button onclick="this.parentElement.remove()" class="text-red-400 hover:text-white"><i class="fa-solid fa-xmark"></i></button>
        </div>
    <?php endif; ?>

    <!-- Summary Cards Grid para o Revendedor -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <div class="glass-card p-6 rounded-3xl border relative overflow-hidden group hover:-translate-y-1 transition duration-300" style="border-color: rgba(245, 158, 11, 0.25);">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-bold uppercase tracking-wider text-amber-500">Banca de Tokens (Revenda)</span>
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-amber-500 to-yellow-300 text-gray-950 flex items-center justify-center text-sm font-bold shadow-lg shadow-amber-500/20">
                    <i class="fa-solid fa-wallet"></i>
                </div>
            </div>
            <div class="text-2xl sm:text-3xl font-extrabold font-mono tracking-tight" style="color: var(--text-heading);">
                <?= number_format($reseller['balance_tokens'], 0, ',', '.') ?>
            </div>
            <div class="flex items-center justify-between mt-3 text-xs">
                <span style="color: var(--text-muted);">Tokens no seu pool</span>
                <a href="plans.php" class="text-amber-500 hover:underline font-bold flex items-center gap-1">Recarregar PIX <i class="fa-solid fa-arrow-right text-[10px]"></i></a>
            </div>
        </div>

        <div class="glass-card p-6 rounded-3xl border relative overflow-hidden group hover:-translate-y-1 transition duration-300" style="border-color: rgba(6, 182, 212, 0.25);">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-bold uppercase tracking-wider text-cyan-400">Clientes Cadastrados</span>
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-cyan-600 to-teal-400 text-white flex items-center justify-center text-sm font-bold shadow-lg shadow-cyan-500/20">
                    <i class="fa-solid fa-users"></i>
                </div>
            </div>
            <div class="text-2xl sm:text-3xl font-extrabold text-cyan-400 font-mono tracking-tight">
                <?= $totalClients ?>
            </div>
            <p class="text-xs mt-3" style="color: var(--text-muted);">Clientes ativos na sua carteira</p>
        </div>

        <div class="glass-card p-6 rounded-3xl border relative overflow-hidden group hover:-translate-y-1 transition duration-300" style="border-color: rgba(147, 51, 234, 0.25);">
            <div class="flex items-center justify-between mb-3">
                <span class="text-xs font-bold uppercase tracking-wider text-purple-400">Tokens em Uso na Rede</span>
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-indigo-600 to-purple-400 text-white flex items-center justify-center text-sm font-bold shadow-lg shadow-purple-500/20">
                    <i class="fa-solid fa-chart-pie"></i>
                </div>
            </div>
            <div class="text-2xl sm:text-3xl font-extrabold font-mono tracking-tight" style="color: var(--text-heading);">
                <?= number_format($totalTokensDistributed, 0, ',', '.') ?>
            </div>
            <p class="text-xs mt-3" style="color: var(--text-muted);">Saldo total atribuído aos clientes</p>
        </div>
    </div>

    <!-- Formulário de Cadastro de Novo Cliente -->
    <div class="glass-card p-6 sm:p-7 rounded-3xl border space-y-5">
        <div class="border-b pb-4" style="border-color: var(--border-color);">
            <h3 class="text-base font-bold flex items-center" style="color: var(--text-heading);">
                <i class="fa-solid fa-user-plus text-amber-500 mr-2.5"></i> Cadastrar Novo Cliente & Atribuir Plano
            </h3>
            <p class="text-xs mt-1" style="color: var(--text-muted);">Selecione um Plano Pronto ou digite a quantidade de tokens avulsa que deseja creditar.</p>
        </div>

        <form method="POST" action="index.php" class="space-y-4 text-xs">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create_client">
            <input type="hidden" name="plan_type" id="create_plan_type" value="Personalizado">

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                <div class="space-y-1.5">
                    <label class="font-bold" style="color: var(--text-heading);">Nome do Cliente</label>
                    <input type="text" name="name" placeholder="Ex: João da Silva" required class="w-full px-3.5 py-2.5 rounded-xl border focus:outline-none focus:ring-2 focus:ring-amber-500/40" style="background: var(--bg-surface-elevated); border-color: var(--border-color); color: var(--text-heading);">
                </div>

                <div class="space-y-1.5">
                    <label class="font-bold" style="color: var(--text-heading);">WhatsApp (com DDD)</label>
                    <input type="text" name="whatsapp" placeholder="5511999999999" required class="w-full px-3.5 py-2.5 rounded-xl border focus:outline-none focus:ring-2 focus:ring-amber-500/40 font-mono" style="background: var(--bg-surface-elevated); border-color: var(--border-color); color: var(--text-heading);">
                </div>

                <div class="space-y-1.5">
                    <label class="font-bold" style="color: var(--text-heading);">E-mail (opcional)</label>
                    <input type="email" name="email" placeholder="cliente@email.com" class="w-full px-3.5 py-2.5 rounded-xl border focus:outline-none focus:ring-2 focus:ring-amber-500/40" style="background: var(--bg-surface-elevated); border-color: var(--border-color); color: var(--text-heading);">
                </div>

                <div class="space-y-1.5">
                    <label class="font-bold" style="color: var(--text-heading);">Senha Inicial</label>
                    <input type="password" name="password" placeholder="Mínimo 6 caracteres" required class="w-full px-3.5 py-2.5 rounded-xl border focus:outline-none focus:ring-2 focus:ring-amber-500/40" style="background: var(--bg-surface-elevated); border-color: var(--border-color); color: var(--text-heading);">
                </div>

                <div class="space-y-1.5">
                    <label class="font-bold text-amber-500">Selecione o Plano Pronto (Opcional)</label>
                    <select id="planPresetSelect" onchange="onPlanPresetChange(this)" class="w-full px-3.5 py-2.5 rounded-xl border focus:outline-none focus:ring-2 focus:ring-amber-500/40 font-semibold" style="background: var(--bg-surface-elevated); border-color: rgba(245, 158, 11, 0.4); color: #f59e0b;">
                        <option value="">-- Escolher um Plano Pronto --</option>
                        <?php foreach ($activePlans as $pl): ?>
                            <option value="<?= $pl['tokens_amount'] ?>" data-name="<?= htmlspecialchars($pl['name']) ?>">
                                <?= htmlspecialchars($pl['name']) ?> (<?= number_format($pl['tokens_amount'], 0, ',', '.') ?> Tokens)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="space-y-1.5">
                    <label class="font-bold" style="color: var(--text-heading);">Tokens Iniciais (Crédito)</label>
                    <input type="number" name="initial_tokens" id="create_initial_tokens" placeholder="5000000" required class="w-full px-3.5 py-2.5 rounded-xl border focus:outline-none focus:ring-2 focus:ring-amber-500/40 font-mono font-bold" style="background: var(--bg-surface-elevated); border-color: var(--border-color); color: var(--text-heading);">
                </div>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-yellow-400 hover:from-amber-400 hover:to-yellow-300 text-gray-950 font-bold text-xs shadow-lg shadow-amber-500/20 transition flex items-center cursor-pointer hover:scale-[1.02]">
                    <i class="fa-solid fa-plus mr-2"></i> Cadastrar Cliente & Atribuir Plano
                </button>
            </div>
        </form>
    </div>

    <!-- Tabela de Clientes da Revenda -->
    <div class="glass-card p-6 sm:p-7 rounded-3xl border space-y-4">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b pb-4" style="border-color: var(--border-color);">
            <div>
                <h3 class="text-base font-bold flex items-center" style="color: var(--text-heading);">
                    <i class="fa-solid fa-users text-cyan-400 mr-2.5"></i> Meus Clientes Gerenciados
                </h3>
                <p class="text-xs mt-0.5" style="color: var(--text-muted);">Atribua novos planos, recarregue tokens ou controle o status de acesso.</p>
            </div>
            <div class="relative w-full sm:w-72">
                <i class="fa-solid fa-magnifying-glass absolute left-3.5 top-1/2 -translate-y-1/2 text-xs" style="color: var(--text-muted);"></i>
                <input type="text" id="clientSearch" onkeyup="filterClients()" placeholder="Buscar por nome ou zap..." class="pl-9 pr-4 py-2 text-xs rounded-xl border w-full focus:outline-none focus:ring-2 focus:ring-amber-500/30" style="background: var(--bg-surface-elevated); border-color: var(--border-color); color: var(--text-heading);">
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs" id="clientsTable">
                <thead>
                    <tr class="border-b uppercase font-mono tracking-wider text-[11px]" style="border-color: var(--border-color); color: var(--text-muted);">
                        <th class="py-3 px-4">Cliente</th>
                        <th class="py-3 px-4">Plano</th>
                        <th class="py-3 px-4">Saldo Atual</th>
                        <th class="py-3 px-4">Status</th>
                        <th class="py-3 px-4">Atribuir Plano</th>
                        <th class="py-3 px-4">Saldo Avulso</th>
                    </tr>
                </thead>
                <tbody class="divide-y font-mono" style="border-color: var(--border-color);">
                    <?php if (empty($myClients)): ?>
                        <tr>
                            <td colspan="6" class="py-10 text-center font-sans text-xs" style="color: var(--text-muted);">
                                <i class="fa-solid fa-user-slash text-2xl mb-2 block opacity-40"></i>
                                Nenhum cliente cadastrado ainda na sua revenda. Use o formulário acima para criar o primeiro!
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($myClients as $c): ?>
                            <tr class="hover:bg-black/5 dark:hover:bg-white/5 transition">
                                <td class="py-3.5 px-4 font-sans">
                                    <div class="font-bold" style="color: var(--text-heading);"><?= htmlspecialchars($c['name']) ?></div>
                                    <div class="text-[11px] text-emerald-500 font-mono mt-0.5"><i class="fa-brands fa-whatsapp mr-1"></i><?= htmlspecialchars($c['whatsapp']) ?></div>
                                </td>
                                <td class="py-3.5 px-4 font-sans">
                                    <span class="px-2.5 py-1 rounded-full font-bold text-[10px]" style="background: rgba(147, 51, 234, 0.15); color: #c084fc; border: 1px solid rgba(147, 51, 234, 0.3);">
                                        <?= htmlspecialchars($c['plan_type'] ?: 'Personalizado') ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 font-bold text-cyan-400">
                                    <?= number_format($c['balance_tokens'], 0, ',', '.') ?>
                                </td>
                                <td class="py-3.5 px-4 font-sans">
                                    <form method="POST" action="index.php" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_client_status">
                                        <input type="hidden" name="client_id" value="<?= $c['id'] ?>">
                                        <input type="hidden" name="current_status" value="<?= $c['status'] ?>">
                                        <?php if ($c['status'] === 'active'): ?>
                                            <button type="submit" title="Clique para bloquear acesso" class="px-2.5 py-1 rounded-full bg-emerald-500/15 text-emerald-500 border border-emerald-500/30 font-bold text-[11px] hover:bg-emerald-500/25 transition cursor-pointer">
                                                ● Ativo
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" title="Clique para ativar acesso" class="px-2.5 py-1 rounded-full bg-red-500/15 text-red-500 border border-red-500/30 font-bold text-[11px] hover:bg-red-500/25 transition cursor-pointer">
                                                ● Suspenso
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                                <td class="py-3.5 px-4 font-sans">
                                    <form method="POST" action="index.php" class="flex items-center space-x-1.5">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="assign_plan">
                                        <input type="hidden" name="client_id" value="<?= $c['id'] ?>">
                                        <select name="plan_id" required class="px-2 py-1.5 rounded-lg border text-xs focus:outline-none max-w-[170px]" style="background: var(--bg-surface-elevated); border-color: var(--border-color); color: var(--text-heading);">
                                            <option value="">-- Selecionar --</option>
                                            <?php foreach ($activePlans as $pl): ?>
                                                <option value="<?= $pl['id'] ?>">
                                                    <?= htmlspecialchars($pl['name']) ?> (<?= number_format($pl['tokens_amount'], 0, ',', '.') ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="px-2.5 py-1.5 rounded-lg bg-gradient-to-r from-amber-500 to-yellow-400 text-gray-950 text-xs font-bold hover:scale-105 transition shrink-0 cursor-pointer">
                                            Ativar
                                        </button>
                                    </form>
                                </td>
                                <td class="py-3.5 px-4 font-sans">
                                    <form method="POST" action="index.php" class="flex items-center space-x-1.5">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="transfer_tokens">
                                        <input type="hidden" name="client_id" value="<?= $c['id'] ?>">
                                        <input type="number" name="add_tokens" placeholder="+ Tokens" required class="w-24 px-2 py-1.5 rounded-lg border text-xs font-mono focus:outline-none" style="background: var(--bg-surface-elevated); border-color: var(--border-color); color: var(--text-heading);">
                                        <button type="submit" class="px-2.5 py-1.5 rounded-lg glass-card font-bold text-xs transition shrink-0 cursor-pointer hover:border-amber-500" style="color: var(--text-heading);">
                                            + Saldo
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function onPlanPresetChange(select) {
        const selectedOpt = select.options[select.selectedIndex];
        const tokens = selectedOpt.value;
        const name = selectedOpt.getAttribute('data-name');

        if (tokens) {
            document.getElementById('create_initial_tokens').value = tokens;
            document.getElementById('create_plan_type').value = name || 'Personalizado';
        }
    }

    function filterClients() {
        const input = document.getElementById('clientSearch').value.toLowerCase();
        const rows = document.querySelectorAll('#clientsTable tbody tr');
        rows.forEach(row => {
            row.style.display = row.innerText.toLowerCase().includes(input) ? '' : 'none';
        });
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
