<?php
require_once __DIR__ . '/../config/helpers.php';
requireReseller();

$db = getDB();
$resellerId = $_SESSION['user_id'];
$pageTitle = 'Recarga de Saldo da Revenda';
$pageHeaderIcon = '<i class="fa-solid fa-bolt text-amber-400 mr-2.5"></i>';

// Buscar dados do revendedor
$stmtReseller = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmtReseller->execute([$resellerId]);
$reseller = $stmtReseller->fetch();

// Buscar planos visíveis para revenda (target_audience IN ('all', 'reseller'))
try {
    $stmtPlans = $db->query("SELECT * FROM plans WHERE status = 'active' AND target_audience IN ('all', 'reseller') ORDER BY price ASC");
    $resellerPlans = $stmtPlans->fetchAll();
} catch (Exception $e) {
    $resellerPlans = [];
}

if (empty($resellerPlans)) {
    $resellerPlans = [
        [
            'id' => 1,
            'name' => 'Pacote Revenda Silver',
            'price' => 199.00,
            'tokens_amount' => 100000000,
            'badge' => '100 Milhões',
            'features' => "100 Milhões de Tokens p/ Revenda\nCadastro Ilimitado de Clientes\nSem data de expiração",
            'is_featured' => 0
        ],
        [
            'id' => 2,
            'name' => 'Pacote Revenda Gold',
            'price' => 499.00,
            'tokens_amount' => 500000000,
            'badge' => '500 Milhões (Melhor Valor)',
            'features' => "500 Milhões de Tokens p/ Revenda\nClientes Ilimitados\nPrioridade em Suporte VIP",
            'is_featured' => 1
        ],
        [
            'id' => 3,
            'name' => 'Pacote Revenda Titanium',
            'price' => 999.00,
            'tokens_amount' => 1200000000,
            'badge' => '1.2 Bilhão (Master)',
            'features' => "1.2 Bilhão de Tokens p/ Revenda\nMargem Máxima de Lucro\nSuporte VIP Dedicado 24/7",
            'is_featured' => 0
        ]
    ];
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">

    <!-- Top Banner Saldo -->
    <div class="glass-card p-6 sm:p-8 rounded-3xl border flex flex-col md:flex-row items-center justify-between gap-6 relative overflow-hidden" style="border-color: rgba(245, 158, 11, 0.3);">
        <div class="space-y-2">
            <span class="px-3 py-1 rounded-full font-bold text-xs uppercase tracking-wider inline-flex items-center gap-1.5" style="background: rgba(245, 158, 11, 0.15); color: #f59e0b; border: 1px solid rgba(245, 158, 11, 0.3);">
                <i class="fa-solid fa-crown text-amber-500"></i> Saldo Atual da Sua Revenda
            </span>
            <h2 class="text-2xl sm:text-3xl font-extrabold font-mono tracking-tight" style="color: var(--text-heading);">
                <?= number_format($reseller['balance_tokens'], 0, ',', '.') ?> <span class="text-amber-500 text-sm font-sans font-semibold">Tokens em Caixa</span>
            </h2>
            <p class="text-xs" style="color: var(--text-muted);">Adicione créditos ao seu pool no atacado e distribua aos seus clientes com até 500% de margem de lucro.</p>
        </div>
        <div class="hidden md:flex items-center space-x-3 shrink-0">
            <div class="p-4 rounded-2xl glass-card border text-center" style="border-color: var(--border-color);">
                <div class="text-[10px] uppercase font-bold" style="color: var(--text-muted);">Liberação Automática</div>
                <div class="text-xs font-bold text-emerald-500 mt-0.5"><i class="fa-solid fa-bolt mr-1"></i>PIX Instantâneo</div>
            </div>
        </div>
    </div>

    <!-- Tabela de Pacotes de Revenda -->
    <div class="glass-card p-6 sm:p-8 rounded-3xl border space-y-6">
        <div>
            <h3 class="text-base font-bold flex items-center" style="color: var(--text-heading);">
                <i class="fa-solid fa-boxes-stacked text-amber-500 mr-2.5"></i> Pacotes de Recarga Exclusivos para Revenda
            </h3>
            <p class="text-xs mt-1" style="color: var(--text-muted);">Pagamento instantâneo via PIX com liberação imediata via Webhook Mistic Pay.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php foreach ($resellerPlans as $plan): 
                $isFeatured = !empty($plan['is_featured']);
                $featuresList = array_filter(explode("\n", $plan['features'] ?? ''));
            ?>
                <div class="glass-card p-6 rounded-3xl border <?= $isFeatured ? 'relative shadow-xl' : 'hover:border-amber-500/40' ?> flex flex-col justify-between transition group hover:-translate-y-1 duration-300" style="<?= $isFeatured ? 'border-color: rgba(245, 158, 11, 0.6);' : '' ?>">
                    <?php if ($isFeatured): ?>
                        <span class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-0.5 rounded-full bg-gradient-to-r from-amber-500 to-yellow-400 text-gray-950 text-[10px] font-extrabold uppercase tracking-wider shadow-md">
                            <?= htmlspecialchars($plan['badge'] ?: 'Melhor Custo-Benefício') ?>
                        </span>
                    <?php endif; ?>
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider <?= $isFeatured ? 'text-amber-500' : 'text-cyan-400' ?>"><?= htmlspecialchars($plan['name']) ?></span>
                        <div class="text-3xl font-extrabold mt-2 font-mono" style="color: var(--text-heading);">R$ <?= number_format($plan['price'], 0, ',', '.') ?></div>
                        <p class="text-xs mt-1 mb-5 font-mono font-semibold" style="color: var(--text-muted);"><?= number_format($plan['tokens_amount'], 0, ',', '.') ?> Tokens</p>
                        <ul class="text-xs space-y-2.5 mb-6" style="color: var(--text-body);">
                            <?php foreach ($featuresList as $feat): ?>
                                <li class="flex items-center"><i class="fa-solid fa-check <?= $isFeatured ? 'text-amber-500' : 'text-cyan-400' ?> mr-2.5 text-xs"></i> <?= htmlspecialchars(trim($feat)) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <button onclick="openPixModal(<?= $plan['price'] ?>, '<?= htmlspecialchars(addslashes($plan['name'])) ?>', <?= $plan['tokens_amount'] ?>)" class="w-full py-3 rounded-xl <?= $isFeatured ? 'bg-gradient-to-r from-amber-500 to-yellow-400 hover:from-amber-400 hover:to-yellow-300 text-gray-950 font-extrabold shadow-lg shadow-amber-500/20' : 'glass-card font-bold hover:border-amber-500' ?> text-xs transition cursor-pointer flex items-center justify-center gap-2" style="<?= !$isFeatured ? 'color: var(--text-heading);' : '' ?>">
                        <i class="fa-brands fa-pix text-sm"></i>
                        <span>Recarregar <?= htmlspecialchars($plan['name']) ?></span>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<!-- Modal PIX (Mistic Pay) para Revenda -->
<div id="pixModal" class="fixed inset-0 bg-black/80 backdrop-blur-md flex items-center justify-center p-4 z-50 hidden">
    <div class="glass-card max-w-md w-full p-6 sm:p-8 rounded-3xl border space-y-6 relative text-center shadow-2xl">
        <button onclick="closePixModal()" class="absolute top-4 right-4 p-2 rounded-xl glass-card transition cursor-pointer" style="color: var(--text-muted);">
            <i class="fa-solid fa-xmark text-sm"></i>
        </button>

        <div class="w-14 h-14 rounded-2xl bg-amber-500/15 text-amber-500 flex items-center justify-center text-2xl mx-auto border border-amber-500/30">
            <i class="fa-solid fa-qrcode"></i>
        </div>

        <div>
            <h3 class="text-lg font-bold" style="color: var(--text-heading);">Recarga de Revenda via PIX</h3>
            <p class="text-xs mt-1" style="color: var(--text-muted);" id="pixPlanDescription">Carregando dados da cobrança...</p>
        </div>

        <div id="pixLoading" class="py-8">
            <i class="fa-solid fa-circle-notch fa-spin text-3xl text-amber-500"></i>
            <p class="text-xs mt-3" style="color: var(--text-muted);">Gerando QR Code do PIX...</p>
        </div>

        <div id="pixContainer" class="hidden space-y-4">
            <div class="bg-white p-3 rounded-2xl inline-block shadow-xl mx-auto">
                <img id="pixQrCodeImg" src="" alt="QR Code PIX" class="w-48 h-48 mx-auto">
            </div>

            <div>
                <label class="block text-[11px] font-bold uppercase mb-1.5" style="color: var(--text-muted);">Chave Copia e Cola</label>
                <div class="flex items-center space-x-2">
                    <input type="text" id="pixKeyInput" readonly class="w-full p-2.5 text-xs rounded-xl border font-mono select-all focus:outline-none" style="background: var(--bg-surface-elevated); border-color: var(--border-color); color: var(--text-heading);">
                    <button onclick="copyPixKey()" class="px-4 py-2.5 bg-gradient-to-r from-amber-500 to-yellow-400 hover:from-amber-400 hover:to-yellow-300 text-gray-950 text-xs font-bold rounded-xl whitespace-nowrap cursor-pointer transition">
                        Copiar
                    </button>
                </div>
            </div>

            <div class="text-[11px] text-amber-500 flex items-center justify-center space-x-2 p-2.5 rounded-xl border" style="background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.25);">
                <i class="fa-solid fa-arrows-rotate fa-spin"></i>
                <span class="font-semibold">Aguardando confirmação do pagamento...</span>
            </div>
        </div>
    </div>
</div>
    </div>
</div>

<script>
    let currentTxId = null;
    let statusInterval = null;

    async function openPixModal(amount, planName, tokensAmount) {
        document.getElementById('pixModal').classList.remove('hidden');
        document.getElementById('pixLoading').classList.remove('hidden');
        document.getElementById('pixContainer').classList.add('hidden');
        document.getElementById('pixPlanDescription').innerText = `${planName} - R$ ${amount.toFixed(2)}`;

        try {
            const res = await fetch('<?= getAppBaseUrl() ?>/v1/checkout.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ amount, plan_name: planName, tokens_amount: tokensAmount })
            });
            const data = await res.json();

            if (data.status === 'success') {
                currentTxId = data.transaction_id;
                document.getElementById('pixQrCodeImg').src = data.qr_code_base64.startsWith('data:') ? data.qr_code_base64 : `data:image/png;base64,${data.qr_code_base64}`;
                document.getElementById('pixKeyInput').value = data.pix_code;

                document.getElementById('pixLoading').classList.add('hidden');
                document.getElementById('pixContainer').classList.remove('hidden');

                if (statusInterval) clearInterval(statusInterval);
                statusInterval = setInterval(checkPaymentStatus, 4000);
            } else {
                alert('Erro ao gerar PIX: ' + (data.message || 'Tente novamente.'));
                closePixModal();
            }
        } catch (err) {
            alert('Erro de conexão com o gateway PIX.');
            closePixModal();
        }
    }

    function closePixModal() {
        document.getElementById('pixModal').classList.add('hidden');
        if (statusInterval) clearInterval(statusInterval);
    }

    function copyPixKey() {
        const input = document.getElementById('pixKeyInput');
        input.select();
        document.execCommand('copy');
        alert('Chave PIX Copia e Cola copiada para a área de transferência!');
    }

    async function checkPaymentStatus() {
        if (!currentTxId) return;
        try {
            const res = await fetch(`<?= getAppBaseUrl() ?>/v1/payment_status.php?tx_id=${currentTxId}`);
            const data = await res.json();
            if (data.status === 'paid') {
                clearInterval(statusInterval);
                alert('🎉 Pagamento confirmado com sucesso! Saldo da revenda creditado.');
                window.location.reload();
            }
        } catch (e) {}
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
