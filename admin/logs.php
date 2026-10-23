<?php
require_once __DIR__ . '/../config/helpers.php';
requireAdmin();

$db = getDB();
$pageTitle = 'Logs de Consumo da API';
$pageHeaderIcon = '<i class="fa-solid fa-list-check text-amber-400 mr-2.5"></i>';

// Buscar Logs de Uso Recentes
$stmtLogs = $db->query("SELECT l.*, u.name as user_name, k.key_name FROM usage_logs l JOIN users u ON l.user_id = u.id JOIN api_keys k ON l.api_key_id = k.id ORDER BY l.id DESC LIMIT 100");
$logs = $stmtLogs->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="glass-card p-6 rounded-3xl border border-gray-800 space-y-4">
    <h3 class="text-base font-bold text-white"><i class="fa-solid fa-list-check text-amber-400 mr-2"></i> Logs de Chamadas de API em Tempo Real</h3>
    
    <div class="overflow-x-auto">
        <table class="w-full text-left text-xs">
            <thead>
                <tr class="text-gray-400 border-b border-gray-800 uppercase font-mono tracking-wider">
                    <th class="py-3 px-4">Usuário</th>
                    <th class="py-3 px-4">Nome da Key</th>
                    <th class="py-3 px-4">Modelo Solicitado</th>
                    <th class="py-3 px-4">Prompt Tokens</th>
                    <th class="py-3 px-4">Completion Tokens</th>
                    <th class="py-3 px-4 text-center">Multiplicador</th>
                    <th class="py-3 px-4">Total Debitado</th>
                    <th class="py-3 px-4">Data/Hora</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800/60 font-mono">
                <?php if (empty($logs)): ?>
                    <tr><td colspan="8" class="py-6 text-center text-gray-500 font-sans">Nenhum log de consumo registrado até o momento.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <tr class="hover:bg-gray-900/40">
                            <td class="py-3.5 px-4 font-sans font-bold text-white"><?= htmlspecialchars($l['user_name']) ?></td>
                            <td class="py-3.5 px-4 font-sans text-cyan-300"><?= htmlspecialchars($l['key_name']) ?></td>
                            <td class="py-3.5 px-4 text-purple-300 font-bold"><?= htmlspecialchars($l['model']) ?></td>
                            <td class="py-3.5 px-4 text-gray-400"><?= number_format($l['prompt_tokens'], 0, ',', '.') ?></td>
                            <td class="py-3.5 px-4 text-gray-400"><?= number_format($l['completion_tokens'], 0, ',', '.') ?></td>
                            <td class="py-3.5 px-4 text-center">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= (float)($l['multiplier'] ?? 1.0) > 1.5 ? 'bg-purple-500/20 text-purple-300' : 'bg-cyan-500/10 text-cyan-400' ?>">
                                    <?= number_format((float)($l['multiplier'] ?? 1.0), 1) ?>x
                                </span>
                            </td>
                            <td class="py-3.5 px-4 font-bold text-cyan-400"><?= number_format($l['total_tokens'], 0, ',', '.') ?></td>
                            <td class="py-3.5 px-4 text-gray-500 text-[11px]"><?= date('d/m/Y H:i:s', strtotime($l['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
