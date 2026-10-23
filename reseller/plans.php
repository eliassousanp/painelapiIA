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

    <div class="glass p-6 sm:p-8 rounded-3xl border border-amber-500/30 flex flex-col md:flex-row items-center justify-between gap-6 shadow-2xl relative overflow-hidden">
        <div class="space-y-2">
            <span class="px-3 py-1 rounded-full bg-amber-500/20 text-amber-300 font-bold text-xs uppercase tracking-wider">
                <i class="fa-solid fa-crown mr-1"></i> Saldo da Sua Revenda
            </span>
            <h2 class="text-2xl sm:text-3xl font-extrabold text-white font-mono">
                <?= number_format($reseller['balance_tokens'], 0, ',', '.') ?> <span class="text-amber-400 text-sm font-sans font-normal">Tokens Disponíveis</span>
            </h2>
            <p class="text-xs text-gray-400">Escolha um dos pacotes abaixo para adicionar créditos ao seu pool e continuar distribuindo aos seus clientes.</p>
        </div>
    </div>

    <!-- Tabela de Pacotes de Revenda -->
    <div class="glass p-6 sm:p-8 rounded-3xl border border-gray-800 space-y-6">
        <div>
            <h3 class="text-base font-bold text-white flex items-center">
                <i class="fa-solid fa-boxes-stacked text-amber-400 mr-2"></i> Pacotes de Recarga Exclusivos para Revenda
            </h3>
            <p class="text-xs text-gray-400 mt-1">Pagamento instantâneo via PIX com liberação automática de tokens</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <?php foreach ($resellerPlans as $plan): 
                $isFeatured = !empty($plan['is_featured']);
                $featuresList = array_filter(explode("\n", $plan['features'] ?? ''));
            ?>
                <div class="glass-card p-6 rounded-2xl border <?= $isFeatured ? 'border-2 border-amber-500/80 shadow-xl shadow-amber-500/10 relative' : 'border-gray-800 hover:border-gray-700' ?> flex flex-col justify-between transition">
                    <?php if ($isFeatured): ?>
                        <span class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-0.5 rounded-full bg-gradient-to-r from-amber-500 to-yellow-400 text-gray-950 text-[10px] font-extrabold uppercase tracking-wider">
                            <?= htmlspecialchars($plan['badge'] ?: 'Melhor Valor') ?>
                        </span>
                    <?php endif; ?>
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider <?= $isFeatured ? 'text-amber-400' : 'text-cyan-400' ?>"><?= htmlspecialchars($plan['name']) ?></span>
                        <div class="text-3xl font-extrabold text-white mt-2">R$ <?= number_format($plan['price'], 0, ',', '.') ?></div>
                        <p class="text-xs text-gray-400 mt-1 mb-4"><?= number_format($plan['tokens_amount'], 0, ',', '.') ?> Tokens</p>
                        <ul class="text-xs text-gray-300 space-y-2 mb-6">
                            <?php foreach ($featuresList as $feat): ?>
                                <li><i class="fa-solid fa-check <?= $isFeatured ? 'text-amber-400' : 'text-cyan-400' ?> mr-2"></i> <?= htmlspecialchars(trim($feat)) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <button onclick="openPixModal(<?= $plan['price'] ?>, '<?= htmlspecialchars(addslashes($plan['name'])) ?>', <?= $plan['tokens_amount'] ?>)" class="w-full py-3 rounded-xl <?= $isFeatured ? 'bg-gradient-to-r from-amber-500 to-yellow-400 hover:from-amber-400 hover:to-yellow-300 text-gray-950 font-extrabold shadow-lg shadow-amber-500/20' : 'glass hover:bg-gray-800 text-white font-semibold' ?> text-xs transition cursor-pointer">
                        Recarregar <?= htmlspecialchars($plan['name']) ?>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</div>

<!-- Modal PIX (Mistic Pay) para Revenda -->
<div id="pixModal" class="fixed inset-0 bg-black/80 backdrop-blur-md flex items-center justify-center p-4 z-50 hidden">
    <div class="glass max-w-md w-full p-6 sm:p-8 rounded-3xl border border-gray-800 space-y-6 relative text-center">
        <button onclick="closePixModal()" class="absolute top-4 right-4 text-gray-400 hover:text-white">
            <i class="fa-solid fa-xmark text-lg"></i>
        </button>

        <div class="w-12 h-12 rounded-2xl bg-amber-500/20 text-amber-400 flex items-center justify-center text-2xl mx-auto">
            <i class="fa-solid fa-qrcode"></i>
        </div>

        <div>
            <h3 class="text-lg font-bold text-white">Recarga da Revenda via PIX</h3>
            <p class="text-xs text-gray-400 mt-1" id="pixPlanDescription">Carregando dados da cobrança...</p>
        </div>

        <div id="pixLoading" class="py-8">
            <i class="fa-solid fa-circle-notch fa-spin text-3xl text-amber-500"></i>
            <p class="text-xs text-gray-400 mt-3">Gerando QR Code do PIX...</p>
        </div>

        <div id="pixContainer" class="hidden space-y-4">
            <div class="bg-white p-3 rounded-2xl inline-block shadow-xl mx-auto">
                <img id="pixQrCodeImg" src="" alt="QR Code PIX" class="w-48 h-48 mx-auto">
            </div>

            <div>
                <label class="block text-[11px] font-semibold text-gray-400 uppercase mb-1">Chave Copia e Cola</label>
                <div class="flex items-center space-x-2">
                    <input type="text" id="pixKeyInput" readonly class="w-full p-2.5 text-xs rounded-xl bg-gray-900 border border-gray-800 text-white font-mono select-all">
                    <button onclick="copyPixKey()" class="px-4 py-2.5 bg-amber-500 hover:bg-amber-400 text-gray-950 text-xs font-bold rounded-xl whitespace-nowrap">
                        Copiar
                    </button>
                </div>
            </div>

            <div class="text-[11px] text-amber-400 flex items-center justify-center space-x-2 bg-amber-500/10 p-2.5 rounded-xl border border-amber-500/20">
                <i class="fa-solid fa-arrows-rotate fa-spin"></i>
                <span>Aguardando confirmação do pagamento...</span>
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
