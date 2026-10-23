<?php
require_once __DIR__ . '/../config/helpers.php';
requireAdmin();

$db = getDB();
$pageTitle = 'Histórico de Vendas PIX';
$pageHeaderIcon = '<i class="fa-solid fa-receipt text-emerald-400 mr-2.5"></i>';

// Buscar Pagamentos Recentes
$stmtPayments = $db->query("SELECT p.*, u.name as user_name, u.whatsapp as user_whatsapp FROM payments p JOIN users u ON p.user_id = u.id ORDER BY p.id DESC LIMIT 50");
$payments = $stmtPayments->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="glass-card p-6 rounded-3xl border border-gray-800 space-y-4">
    <h3 class="text-base font-bold text-white"><i class="fa-solid fa-receipt text-emerald-400 mr-2"></i> Transações PIX via Mistic Pay</h3>
    
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="text-gray-400 border-b border-gray-800 uppercase font-mono tracking-wider">
                    <th class="py-3 px-4">Transação ID</th>
                    <th class="py-3 px-4">Usuário</th>
                    <th class="py-3 px-4">Plano Adquirido</th>
                    <th class="py-3 px-4">Valor R$</th>
                    <th class="py-3 px-4">Tokens Liberados</th>
                    <th class="py-3 px-4">Status</th>
                    <th class="py-3 px-4">Data/Hora</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800/60 font-mono">
                <?php if (empty($payments)): ?>
                    <tr><td colspan="7" class="py-6 text-center text-gray-500 font-sans">Nenhuma transação PIX registrada até o momento.</td></tr>
                <?php else: ?>
                    <?php foreach ($payments as $p): ?>
                        <tr class="hover:bg-gray-900/40">
                            <td class="py-3.5 px-4 text-cyan-300 font-bold select-all"><?= htmlspecialchars($p['transaction_id']) ?></td>
                            <td class="py-3.5 px-4 font-sans">
                                <div class="font-bold text-white"><?= htmlspecialchars($p['user_name']) ?></div>
                                <div class="text-[10px] text-gray-400"><?= htmlspecialchars($p['user_whatsapp']) ?></div>
                            </td>
                            <td class="py-3.5 px-4 font-sans text-gray-300"><?= htmlspecialchars($p['plan_name']) ?></td>
                            <td class="py-3.5 px-4 font-bold text-emerald-400">R$ <?= number_format($p['amount'], 2, ',', '.') ?></td>
                            <td class="py-3.5 px-4 text-gray-300"><?= number_format($p['tokens_amount'], 0, ',', '.') ?></td>
                            <td class="py-3.5 px-4 font-sans">
                                <?php if ($p['status'] === 'paid'): ?>
                                    <span class="px-2.5 py-1 rounded-full bg-emerald-500/20 text-emerald-400 font-bold text-[10px]">PAGO</span>
                                <?php elseif ($p['status'] === 'pending'): ?>
                                    <span class="px-2.5 py-1 rounded-full bg-amber-500/20 text-amber-400 font-bold text-[10px]">PENDENTE</span>
                                <?php else: ?>
                                    <span class="px-2.5 py-1 rounded-full bg-red-500/20 text-red-400 font-bold text-[10px]">CANCELADO</span>
                                <?php endif; ?>
                            </td>
                            <td class="py-3.5 px-4 text-gray-500 text-[11px]"><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
