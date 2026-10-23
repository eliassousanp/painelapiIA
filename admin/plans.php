<?php
require_once __DIR__ . '/../config/helpers.php';
requireAdmin();

$db = getDB();
$pageTitle = 'Planos e Pacotes de Recarga';
$pageHeaderIcon = '<i class="fa-solid fa-bolt text-amber-400 mr-2.5"></i>';
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'create_plan') {
        $name = trim($_POST['name'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $resellerPrice = !empty($_POST['reseller_price']) ? (float)$_POST['reseller_price'] : null;
        $tokensAmount = (int)($_POST['tokens_amount'] ?? 0);
        $badge = trim($_POST['badge'] ?? '');
        $targetAudience = $_POST['target_audience'] ?? 'all';
        $features = trim($_POST['features'] ?? '');
        $isFeatured = isset($_POST['is_featured']) ? 1 : 0;

        if (empty($name) || $price <= 0 || $tokensAmount <= 0) {
            $error = 'Preencha o Nome, Preço válido e Quantidade de Tokens do plano.';
        } else {
            $stmt = $db->prepare("INSERT INTO plans (name, price, reseller_price, tokens_amount, badge, target_audience, features, is_featured, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$name, $price, $resellerPrice, $tokensAmount, $badge, $targetAudience, $features, $isFeatured]);
            $msg = 'Novo plano criado com sucesso!';
        }
    } elseif ($action === 'edit_plan') {
        $planId = (int)$_POST['plan_id'];
        $name = trim($_POST['name'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $resellerPrice = !empty($_POST['reseller_price']) ? (float)$_POST['reseller_price'] : null;
        $tokensAmount = (int)($_POST['tokens_amount'] ?? 0);
        $badge = trim($_POST['badge'] ?? '');
        $targetAudience = $_POST['target_audience'] ?? 'all';
        $features = trim($_POST['features'] ?? '');
        $isFeatured = isset($_POST['is_featured']) ? 1 : 0;

        if ($planId <= 0 || empty($name) || $price <= 0 || $tokensAmount <= 0) {
            $error = 'Preencha o Nome, Preço válido e Quantidade de Tokens para editar o plano.';
        } else {
            $stmt = $db->prepare("UPDATE plans SET name = ?, price = ?, reseller_price = ?, tokens_amount = ?, badge = ?, target_audience = ?, features = ?, is_featured = ? WHERE id = ?");
            $stmt->execute([$name, $price, $resellerPrice, $tokensAmount, $badge, $targetAudience, $features, $isFeatured, $planId]);
            $msg = "Plano '{$name}' atualizado com sucesso!";
        }
    } elseif ($action === 'toggle_plan_status') {
        $planId = (int)$_POST['plan_id'];
        $currentStatus = $_POST['current_status'] ?? 'active';
        $newStatus = ($currentStatus === 'active') ? 'inactive' : 'active';

        $stmt = $db->prepare("UPDATE plans SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $planId]);
        $msg = 'Status do plano alterado com sucesso!';
    } elseif ($action === 'delete_plan') {
        $planId = (int)$_POST['plan_id'];
        $stmt = $db->prepare("DELETE FROM plans WHERE id = ?");
        $stmt->execute([$planId]);
        $msg = 'Plano excluído com sucesso.';
    }
}

// Buscar Planos
$stmtPlans = $db->query("SELECT * FROM plans ORDER BY price ASC");
$plans = $stmtPlans->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<script>
    function editPlanFromData(btn) {
        try {
            const rawData = btn.getAttribute('data-plan');
            if (!rawData) {
                alert('Dados do plano não encontrados no botão.');
                return;
            }
            const p = JSON.parse(rawData);

            document.getElementById('edit_plan_id').value = p.id;
            document.getElementById('edit_name').value = p.name || '';
            document.getElementById('edit_price').value = p.price || '';
            document.getElementById('edit_reseller_price').value = p.reseller_price || '';
            document.getElementById('edit_tokens_amount').value = p.tokens_amount || '';
            document.getElementById('edit_badge').value = p.badge || '';
            document.getElementById('edit_target_audience').value = p.target_audience || 'all';
            document.getElementById('edit_features').value = p.features || '';
            document.getElementById('edit_is_featured').checked = (parseInt(p.is_featured) === 1);

            document.getElementById('editPlanModal').classList.remove('hidden');
        } catch (err) {
            console.error('Erro ao abrir modal de edição:', err);
            alert('Erro ao carregar dados do plano.');
        }
    }

    function closeEditModal() {
        document.getElementById('editPlanModal').classList.add('hidden');
    }
</script>

<div class="space-y-6">

    <!-- Modal/Form de Criação de Novo Plano -->
    <div class="glass-card p-6 rounded-3xl border border-gray-800 space-y-4">
        <div class="flex items-center justify-between border-b border-gray-800 pb-4">
            <div>
                <h3 class="text-base font-bold text-white flex items-center">
                    <i class="fa-solid fa-plus-circle text-emerald-400 mr-2"></i> Criar Novo Plano / Pacote de Tokens
                </h3>
                <p class="text-xs text-gray-400 mt-0.5">Os planos cadastrados aqui aparecem instantaneamente na Área do Cliente.</p>
            </div>
        </div>

        <form method="POST" action="plans.php" class="grid grid-cols-1 md:grid-cols-4 lg:grid-cols-6 gap-4 text-xs">
            <input type="hidden" name="action" value="create_plan">

            <div class="space-y-1 md:col-span-2">
                <label class="font-semibold text-gray-300">Nome do Plano</label>
                <input type="text" name="name" placeholder="Ex: Pro Dev Ilimitado" required class="w-full px-3.5 py-2.5 rounded-xl bg-gray-900 border border-gray-800 text-white focus:outline-none focus:border-indigo-500">
            </div>

            <div class="space-y-1">
                <label class="font-semibold text-gray-300">Preço Varejo (R$)</label>
                <input type="number" step="0.01" name="price" placeholder="149.00" required class="w-full px-3.5 py-2.5 rounded-xl bg-gray-900 border border-gray-800 text-white focus:outline-none focus:border-indigo-500">
            </div>

            <div class="space-y-1">
                <label class="font-semibold text-amber-300">Preço Revenda (R$)</label>
                <input type="number" step="0.01" name="reseller_price" placeholder="79.00" class="w-full px-3.5 py-2.5 rounded-xl bg-gray-900 border border-amber-500/30 text-amber-300 focus:outline-none focus:border-amber-500">
            </div>

            <div class="space-y-1 md:col-span-2">
                <label class="font-semibold text-gray-300">Qtd. Tokens</label>
                <input type="number" name="tokens_amount" placeholder="50000000" required class="w-full px-3.5 py-2.5 rounded-xl bg-gray-900 border border-gray-800 text-white focus:outline-none focus:border-indigo-500 font-mono">
            </div>

            <div class="space-y-1">
                <label class="font-semibold text-gray-300">Badge / Destaque</label>
                <input type="text" name="badge" placeholder="Ex: Mais Popular" class="w-full px-3.5 py-2.5 rounded-xl bg-gray-900 border border-gray-800 text-white focus:outline-none focus:border-indigo-500">
            </div>

            <div class="space-y-1">
                <label class="font-semibold text-gray-300">Público-Alvo</label>
                <select name="target_audience" class="w-full px-3.5 py-2.5 rounded-xl bg-gray-900 border border-gray-800 text-white focus:outline-none focus:border-indigo-500">
                    <option value="all">👥 Todos (Clientes e Revenda)</option>
                    <option value="client">👤 Apenas Clientes Finais</option>
                    <option value="reseller">💼 Apenas Revendedores</option>
                </select>
            </div>

            <div class="space-y-1 md:col-span-4">
                <label class="font-semibold text-gray-300">Recursos (uma linha por item)</label>
                <textarea name="features" rows="2" placeholder="Claude 3.5 Sonnet, Opus & Haiku&#10;Validade Indeterminada&#10;Suporte VIP 24/7" class="w-full px-3.5 py-2 rounded-xl bg-gray-900 border border-gray-800 text-white focus:outline-none focus:border-indigo-500"></textarea>
            </div>

            <div class="flex flex-col justify-end space-y-2">
                <label class="flex items-center space-x-2 cursor-pointer pb-2">
                    <input type="checkbox" name="is_featured" value="1" class="rounded bg-gray-900 border-gray-800 text-indigo-600 focus:ring-0">
                    <span class="text-xs text-amber-300 font-semibold">Card Destacado</span>
                </label>
                <button type="submit" class="w-full py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 to-cyan-500 hover:from-indigo-500 hover:to-cyan-400 text-white font-bold text-xs shadow-lg transition">
                    <i class="fa-solid fa-check mr-1"></i> Salvar Plano
                </button>
            </div>
        </form>
    </div>

    <!-- Tabela de Planos Existentes -->
    <div class="glass-card p-6 rounded-3xl border border-gray-800 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-base font-bold text-white flex items-center">
                <i class="fa-solid fa-list text-purple-400 mr-2"></i> Planos Cadastrados no Banco
            </h3>
            <span class="text-xs text-gray-400">Total: <?= count($plans) ?> plano(s)</span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="text-gray-400 border-b border-gray-800 uppercase font-mono tracking-wider">
                        <th class="py-3 px-4">Nome do Plano</th>
                        <th class="py-3 px-4">Público-Alvo</th>
                        <th class="py-3 px-4">Preço Varejo</th>
                        <th class="py-3 px-4">Preço Revenda</th>
                        <th class="py-3 px-4">Qtd. Tokens</th>
                        <th class="py-3 px-4">Badge</th>
                        <th class="py-3 px-4">Status</th>
                        <th class="py-3 px-4">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800/60 font-mono">
                    <?php if (empty($plans)): ?>
                        <tr>
                            <td colspan="7" class="py-8 text-center text-gray-500 font-sans text-xs">
                                Nenhum plano cadastrado. Preencha o formulário acima para criar o primeiro plano.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($plans as $p): ?>
                            <tr class="hover:bg-gray-900/40">
                                <td class="py-3.5 px-4 font-sans">
                                    <div class="font-bold text-white flex items-center">
                                        <?= htmlspecialchars($p['name']) ?>
                                        <?php if ($p['is_featured']): ?>
                                            <span class="ml-2 px-2 py-0.5 rounded bg-amber-500/20 text-amber-300 text-[10px] font-mono">🔥 Destacado</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-[10px] text-gray-400 font-sans mt-0.5">
                                        <?= htmlspecialchars(str_replace("\n", " • ", $p['features'] ?? '')) ?>
                                    </div>
                                </td>
                                <td class="py-3.5 px-4 font-sans">
                                    <?php 
                                        $aud = $p['target_audience'] ?? 'all';
                                        if ($aud === 'reseller') {
                                            echo '<span class="px-2 py-0.5 rounded bg-amber-500/20 text-amber-300 text-[10px] font-bold">💼 Revenda</span>';
                                        } elseif ($aud === 'client') {
                                            echo '<span class="px-2 py-0.5 rounded bg-indigo-500/20 text-indigo-300 text-[10px] font-bold">👤 Cliente</span>';
                                        } else {
                                            echo '<span class="px-2 py-0.5 rounded bg-gray-800 text-gray-300 text-[10px]">👥 Todos</span>';
                                        }
                                    ?>
                                </td>
                                <td class="py-3.5 px-4 text-emerald-400 font-bold">
                                    R$ <?= number_format($p['price'], 2, ',', '.') ?>
                                </td>
                                <td class="py-3.5 px-4 text-amber-300 font-bold">
                                    R$ <?= number_format($p['reseller_price'] ?? ($p['price'] * 0.5), 2, ',', '.') ?>
                                </td>
                                <td class="py-3.5 px-4 text-cyan-300 font-bold">
                                    <?= number_format($p['tokens_amount'], 0, ',', '.') ?>
                                </td>
                                <td class="py-3.5 px-4 font-sans">
                                    <span class="px-2.5 py-1 rounded-full bg-purple-500/20 text-purple-300 text-[10px]">
                                        <?= htmlspecialchars($p['badge'] ?: 'Padrão') ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 font-sans">
                                    <?php if ($p['status'] === 'active'): ?>
                                        <span class="px-2.5 py-1 rounded-full bg-emerald-500/20 text-emerald-400 font-semibold text-[10px]">Ativo</span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded-full bg-red-500/20 text-red-400 font-semibold text-[10px]">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-4 font-sans">
                                    <div class="flex items-center space-x-3">
                                        <button type="button" 
                                                data-plan='<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>'
                                                onclick="editPlanFromData(this)" 
                                                class="text-xs text-amber-400 hover:text-amber-300 font-bold hover:underline cursor-pointer">
                                            ✏️ Editar
                                        </button>

                                        <form method="POST" action="plans.php" class="inline">
                                            <input type="hidden" name="action" value="toggle_plan_status">
                                            <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                                            <input type="hidden" name="current_status" value="<?= $p['status'] ?>">
                                            <button type="submit" class="text-xs text-indigo-400 hover:underline">
                                                <?= $p['status'] === 'active' ? 'Desativar' : 'Ativar' ?>
                                            </button>
                                        </form>

                                        <form method="POST" action="plans.php" onsubmit="return confirm('Excluir este plano definitivamente?');" class="inline">
                                            <input type="hidden" name="action" value="delete_plan">
                                            <input type="hidden" name="plan_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="text-xs text-red-400 hover:underline">Excluir</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal de Edição de Plano -->
<div id="editPlanModal" class="fixed inset-0 bg-black/80 backdrop-blur-md flex items-center justify-center p-4 z-50 hidden">
    <div class="glass max-w-lg w-full p-6 sm:p-8 rounded-3xl border border-gray-800 space-y-5 relative">
        <button onclick="closeEditModal()" class="absolute top-4 right-4 text-gray-400 hover:text-white">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>

        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 rounded-2xl bg-amber-500/20 text-amber-400 flex items-center justify-center text-xl">
                <i class="fa-solid fa-pen-to-square"></i>
            </div>
            <div>
                <h3 class="text-base font-bold text-white">Editar Plano de Recarga</h3>
                <p class="text-xs text-gray-400">Altere preços, quantidade de tokens e público-alvo</p>
            </div>
        </div>

        <form method="POST" action="plans.php" class="space-y-4 text-xs">
            <input type="hidden" name="action" value="edit_plan">
            <input type="hidden" name="plan_id" id="edit_plan_id">

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div class="space-y-1 sm:col-span-2">
                    <label class="font-semibold text-gray-300">Nome do Plano</label>
                    <input type="text" name="name" id="edit_name" required class="w-full px-3.5 py-2.5 rounded-xl bg-gray-900 border border-gray-800 text-white focus:outline-none focus:border-amber-500">
                </div>

                <div class="space-y-1">
                    <label class="font-semibold text-gray-300">Preço Varejo (R$)</label>
                    <input type="number" step="0.01" name="price" id="edit_price" required class="w-full px-3.5 py-2.5 rounded-xl bg-gray-900 border border-gray-800 text-white focus:outline-none focus:border-amber-500">
                </div>

                <div class="space-y-1">
                    <label class="font-semibold text-amber-300">Preço Revenda (R$)</label>
                    <input type="number" step="0.01" name="reseller_price" id="edit_reseller_price" class="w-full px-3.5 py-2.5 rounded-xl bg-gray-900 border border-amber-500/30 text-amber-300 focus:outline-none focus:border-amber-500">
                </div>

                <div class="space-y-1">
                    <label class="font-semibold text-gray-300">Qtd. Tokens</label>
                    <input type="number" name="tokens_amount" id="edit_tokens_amount" required class="w-full px-3.5 py-2.5 rounded-xl bg-gray-900 border border-gray-800 text-white focus:outline-none focus:border-amber-500 font-mono">
                </div>

                <div class="space-y-1">
                    <label class="font-semibold text-gray-300">Badge / Destaque</label>
                    <input type="text" name="badge" id="edit_badge" class="w-full px-3.5 py-2.5 rounded-xl bg-gray-900 border border-gray-800 text-white focus:outline-none focus:border-amber-500">
                </div>

                <div class="space-y-1">
                    <label class="font-semibold text-gray-300">Público-Alvo</label>
                    <select name="target_audience" id="edit_target_audience" class="w-full px-3.5 py-2.5 rounded-xl bg-gray-900 border border-gray-800 text-white focus:outline-none focus:border-amber-500">
                        <option value="all">👥 Todos (Clientes e Revenda)</option>
                        <option value="client">👤 Apenas Clientes Finais</option>
                        <option value="reseller">💼 Apenas Revendedores</option>
                    </select>
                </div>
            </div>

            <div class="space-y-1">
                <label class="font-semibold text-gray-300">Recursos (uma linha por item)</label>
                <textarea name="features" id="edit_features" rows="3" class="w-full px-3.5 py-2 rounded-xl bg-gray-900 border border-gray-800 text-white focus:outline-none focus:border-amber-500"></textarea>
            </div>

            <div class="flex items-center justify-between pt-2">
                <label class="flex items-center space-x-2 cursor-pointer">
                    <input type="checkbox" name="is_featured" id="edit_is_featured" value="1" class="rounded bg-gray-900 border-gray-800 text-amber-500 focus:ring-0">
                    <span class="text-xs text-amber-300 font-semibold">Destacar Card no Topo</span>
                </label>

                <div class="flex space-x-2">
                    <button type="button" onclick="closeEditModal()" class="px-4 py-2.5 rounded-xl bg-gray-800 hover:bg-gray-700 text-gray-300 font-semibold text-xs">
                        Cancelar
                    </button>
                    <button type="submit" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-amber-500 to-yellow-400 hover:from-amber-400 hover:to-yellow-300 text-gray-950 font-bold text-xs shadow-lg">
                        Salvar Alterações
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
