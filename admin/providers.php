<?php
require_once __DIR__ . '/../config/helpers.php';
requireAdmin();

$db = getDB();
$pageTitle = 'Provedores & Keys (Failover)';
$pageHeaderIcon = '<i class="fa-solid fa-network-wired text-cyan-400 mr-2.5"></i>';
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!validate_csrf()) {
        $error = 'Sessão expirada ou token de segurança CSRF inválido. Recarregue a página.';
    } elseif ($_POST['action'] === 'save_provider') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $baseUrl = trim($_POST['base_url'] ?? '');
        $status = $_POST['status'] ?? 'active';

        if (!empty($name) && !empty($baseUrl)) {
            if ($providerId > 0) {
                $stmt = $db->prepare("UPDATE providers SET name = ?, base_url = ?, status = ? WHERE id = ?");
                $stmt->execute([$name, $baseUrl, $status, $providerId]);
            } else {
                $stmt = $db->prepare("INSERT INTO providers (name, base_url, status) VALUES (?, ?, ?)");
                $stmt->execute([$name, $baseUrl, $status]);
            }
            $msg = 'Provedor salvo com sucesso!';
        } else {
            $error = 'Preencha o Nome e a Base URL do Provedor.';
        }
    } elseif ($_POST['action'] === 'delete_provider') {
        $providerId = (int)$_POST['provider_id'];
        $stmt = $db->prepare("DELETE FROM providers WHERE id = ?");
        $stmt->execute([$providerId]);
        $msg = 'Provedor removido com sucesso.';
    } elseif ($_POST['action'] === 'save_provider_key') {
        $keyId = (int)($_POST['key_id'] ?? 0);
        $providerId = (int)$_POST['provider_id'];
        $apiKey = trim($_POST['api_key'] ?? '');
        $keyLabel = trim($_POST['key_label'] ?? 'Chave Secundária');

        if (!empty($apiKey) && $providerId > 0) {
            if ($keyId > 0) {
                $stmt = $db->prepare("UPDATE provider_keys SET provider_id = ?, api_key = ?, key_label = ? WHERE id = ?");
                $stmt->execute([$providerId, $apiKey, $keyLabel, $keyId]);
            } else {
                $stmt = $db->prepare("INSERT INTO provider_keys (provider_id, api_key, key_label) VALUES (?, ?, ?)");
                $stmt->execute([$providerId, $apiKey, $keyLabel]);
            }
            $msg = 'API Key do Provedor salva com sucesso!';
        } else {
            $error = 'Preencha a API Key e selecione o Provedor.';
        }
    } elseif ($_POST['action'] === 'delete_provider_key') {
        $keyId = (int)$_POST['key_id'];
        $stmt = $db->prepare("DELETE FROM provider_keys WHERE id = ?");
        $stmt->execute([$keyId]);
        $msg = 'Chave do provedor removida com sucesso.';
    } elseif ($_POST['action'] === 'reset_key_errors') {
        $keyId = (int)$_POST['key_id'];
        $stmt = $db->prepare("UPDATE provider_keys SET error_count = 0, status = 'active' WHERE id = ?");
        $stmt->execute([$keyId]);
        $msg = 'Contagem de erros resetada para esta chave.';
    } elseif ($_POST['action'] === 'sync_provider_models') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $customApiKey = trim($_POST['api_key'] ?? '');
        $manualModels = trim($_POST['manual_models'] ?? '');

        if ($providerId > 0) {
            $stmtProv = $db->prepare("SELECT * FROM providers WHERE id = ?");
            $stmtProv->execute([$providerId]);
            $provider = $stmtProv->fetch();

            if ($provider) {
                // Se informou API Key, salva no banco automaticamente se não existir
                if (!empty($customApiKey)) {
                    $cleanApiKey = preg_replace('/^Bearer\s+/i', '', $customApiKey);
                    $stmtCheckKey = $db->prepare("SELECT id FROM provider_keys WHERE provider_id = ? AND api_key = ? LIMIT 1");
                    $stmtCheckKey->execute([$providerId, $cleanApiKey]);
                    if (!$stmtCheckKey->fetch()) {
                        $stmtSaveKey = $db->prepare("INSERT INTO provider_keys (provider_id, api_key, key_label, status) VALUES (?, ?, 'Chave Importada', 'active')");
                        $stmtSaveKey->execute([$providerId, $cleanApiKey]);
                    }
                }

                $importedModels = [];

                // 1. Processa modelos inseridos manualmente
                if (!empty($manualModels)) {
                    $lines = preg_split('/[\r\n,]+/', $manualModels);
                    foreach ($lines as $line) {
                        $mRaw = trim($line);
                        if (!empty($mRaw)) {
                            $importedModels[] = [
                                'upstream' => $mRaw,
                                'name' => $mRaw
                            ];
                        }
                    }
                }

                $apiKey = !empty($customApiKey) ? preg_replace('/^Bearer\s+/i', '', $customApiKey) : '';
                if (empty($apiKey)) {
                    $stmtKey = $db->prepare("SELECT api_key FROM provider_keys WHERE provider_id = ? AND status = 'active' ORDER BY id ASC LIMIT 1");
                    $stmtKey->execute([$providerId]);
                    $keyRow = $stmtKey->fetch();
                    if ($keyRow) {
                        $apiKey = preg_replace('/^Bearer\s+/i', '', $keyRow['api_key']);
                    }
                }

                $baseUrl = rtrim($provider['base_url'], '/');
                $isNvidia = (stripos($provider['name'], 'nvidia') !== false || stripos($baseUrl, 'nvidia.com') !== false);

                // Se não tem manual, tenta cURL
                if (empty($importedModels)) {
                    $endpointsToTry = [
                        $baseUrl . '/models',
                        $baseUrl . '/v1/models'
                    ];

                    if (substr($baseUrl, -3) === '/v1') {
                        $baseWithoutV1 = substr($baseUrl, 0, -3);
                        array_unshift($endpointsToTry, $baseUrl . '/models');
                        array_unshift($endpointsToTry, $baseWithoutV1 . '/v1/models');
                    }

                    $endpointsToTry = array_unique($endpointsToTry);
                    $fetchedData = null;
                    $lastHttpCode = 0;
                    $curlErr = '';

                    foreach ($endpointsToTry as $epUrl) {
                        $ch = curl_init($epUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
                        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Authorization: Bearer ' . $apiKey,
                            'api-key: ' . $apiKey,
                            'Accept: application/json',
                            'User-Agent: BRLoveAPI-Client/5.0'
                        ]);
                        $response = curl_exec($ch);
                        $lastHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $curlErr = curl_error($ch);
                        curl_close($ch);

                        if ($lastHttpCode === 200 && !empty($response)) {
                            $json = json_decode($response, true);
                            if (isset($json['data']) && is_array($json['data'])) {
                                $fetchedData = $json['data'];
                                break;
                            } elseif (isset($json['models']) && is_array($json['models'])) {
                                $fetchedData = $json['models'];
                                break;
                            } elseif (is_array($json) && !empty($json) && isset($json[0]['id'])) {
                                $fetchedData = $json;
                                break;
                            }
                        }
                    }

                    if (is_array($fetchedData)) {
                        foreach ($fetchedData as $mItem) {
                            $mKey = is_array($mItem) ? ($mItem['id'] ?? $mItem['model'] ?? '') : (string)$mItem;
                            $mName = is_array($mItem) ? ($mItem['display_name'] ?? $mItem['name'] ?? $mKey) : $mKey;
                            if (!empty($mKey)) {
                                $importedModels[] = [
                                    'upstream' => $mKey,
                                    'name' => $mName
                                ];
                            }
                        }
                    }

                    // Presets oficiais NVIDIA NIM caso a rota /models da NVIDIA esteja restrita ou bloqueada
                    if (empty($importedModels) && $isNvidia) {
                        $importedModels = [
                            ['upstream' => 'meta/llama-3.3-70b-instruct', 'name' => 'Meta Llama 3.3 70B Instruct'],
                            ['upstream' => 'nvidia/llama-3.1-nemotron-70b-instruct', 'name' => 'NVIDIA Llama 3.1 Nemotron 70B'],
                            ['upstream' => 'deepseek-ai/deepseek-r1', 'name' => 'DeepSeek R1 (Reasoning)'],
                            ['upstream' => 'meta/llama-3.1-405b-instruct', 'name' => 'Meta Llama 3.1 405B Instruct'],
                            ['upstream' => 'mistralai/mixtral-8x22b-instruct-v0.1', 'name' => 'Mixtral 8x22B Instruct'],
                            ['upstream' => 'nvidia/nemotron-4-340b-instruct', 'name' => 'NVIDIA Nemotron 4 340B Instruct'],
                            ['upstream' => 'qwen/qwen2.5-72b-instruct', 'name' => 'Qwen 2.5 72B Instruct']
                        ];
                    }
                }

                if (!empty($importedModels)) {
                    $savedCount = 0;
                    $stmtPM = $db->prepare("INSERT INTO provider_models (provider_id, upstream_model, name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)");
                    foreach ($importedModels as $imp) {
                        $stmtPM->execute([$providerId, $imp['upstream'], $imp['name']]);
                        $savedCount++;
                    }
                    $msg = "⚡ Sucesso! Foram cadastrados $savedCount modelos para o provedor '{$provider['name']}'! Eles já estão disponíveis para você selecionar ou digitar no campo 'Modelo do Provedor'.";
                } else {
                    $detailMsg = '';
                    if ($lastHttpCode === 401) {
                        $detailMsg = ' (HTTP 401: Chave de API inválida ou sem permissão na API externa)';
                    } elseif ($lastHttpCode === 403) {
                        $detailMsg = ' (HTTP 403: Acesso bloqueado pelo provedor)';
                    } elseif ($lastHttpCode === 404) {
                        $detailMsg = ' (HTTP 404: Endpoint /models não encontrado na Base URL informada)';
                    } elseif (!empty($curlErr)) {
                        $detailMsg = " (Erro de conexão: $curlErr)";
                    }
                    $error = 'Não foi possível buscar automaticamente os modelos' . $detailMsg . '. Verifique se a Base URL e a API Key estão corretas ou insira os modelos manualmente na tela de Modelos.';
                }
            }
        }
    }
}

// Buscar Provedores e Suas Chaves (Ocultando o Google Antigravity pois ele tem aba própria)
$stmtP = $db->query("SELECT * FROM providers WHERE name NOT LIKE '%Google Antigravity%' ORDER BY id ASC");
$providers = $stmtP->fetchAll();

$stmtPK = $db->query("SELECT pk.*, p.name as provider_name FROM provider_keys pk JOIN providers p ON pk.provider_id = p.id ORDER BY pk.provider_id ASC, pk.id ASC");
$providerKeys = $stmtPK->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="glass-card p-6 rounded-3xl border border-gray-800 space-y-6">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-gray-800 pb-4">
        <div>
            <h3 class="text-base font-bold text-white flex items-center">
                <i class="fa-solid fa-network-wired text-cyan-400 mr-2"></i> Provedores de API Upstream
            </h3>
            <p class="text-xs text-gray-400 mt-0.5">Cadastre múltiplos provedores (ex: api.b.ai, chat.b.ia, openrouter.ai). Cada modelo pode usar um provedor específico.</p>
        </div>
        <button onclick="openProviderModal()" class="px-4 py-2.5 text-xs rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold whitespace-nowrap shadow-lg shadow-indigo-600/30 transition">
            <i class="fa-solid fa-plus mr-1"></i> Novo Provedor
        </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($providers as $p): ?>
            <div class="p-5 rounded-2xl bg-gray-900/60 border border-gray-800 flex flex-col justify-between">
                <div>
                    <div class="flex items-center justify-between mb-2">
                        <h4 class="font-bold text-white text-sm flex items-center">
                            <i class="fa-solid fa-server text-cyan-400 mr-2 text-xs"></i> <?= htmlspecialchars($p['name']) ?>
                        </h4>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-500/20 text-emerald-400">Ativo</span>
                    </div>
                    <div class="text-xs font-mono text-gray-400 truncate bg-gray-950 p-2.5 rounded-xl border border-gray-800 mb-4 select-all">
                        <?= htmlspecialchars($p['base_url']) ?>
                    </div>
                </div>
                <div class="flex items-center justify-between pt-2 border-t border-gray-800 gap-2">
                    <button onclick='editProvider(<?= json_encode($p) ?>)' class="text-xs text-indigo-400 hover:text-indigo-300 font-semibold">
                        <i class="fa-solid fa-pen-to-square mr-1"></i> Editar
                    </button>
                    <form method="POST" action="providers.php" class="inline" onsubmit="this.querySelector('button').innerHTML='<i class=\'fa-solid fa-spinner fa-spin mr-1\'></i> Buscando...';">
                        <input type="hidden" name="action" value="sync_provider_models">
                        <input type="hidden" name="provider_id" value="<?= $p['id'] ?>">
                        <button type="submit" class="text-xs text-purple-400 hover:text-purple-300 font-semibold flex items-center" title="Listar e importar modelos da API deste provedor">
                            <i class="fa-solid fa-cloud-arrow-down mr-1"></i> Sincronizar Modelos
                        </button>
                    </form>
                    <form method="POST" action="providers.php" onsubmit="return confirm('Excluir este provedor e suas chaves?');" class="inline">
                        <input type="hidden" name="action" value="delete_provider">
                        <input type="hidden" name="provider_id" value="<?= $p['id'] ?>">
                        <button type="submit" class="text-xs text-red-400 hover:text-red-300">Excluir</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="pt-6 border-t border-gray-800 space-y-4">
        <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-gray-800 pb-4">
            <div>
                <h3 class="text-base font-bold text-white flex items-center">
                    <i class="fa-solid fa-key text-amber-400 mr-2"></i> API Keys Mestre (Auto Failover)
                </h3>
                <p class="text-xs text-gray-400 mt-0.5">Se a chave #1 falhar ou atingir rate limit, a chave #2 é acionada automaticamente!</p>
            </div>
            <button onclick="openProviderKeyModal()" class="px-4 py-2.5 text-xs rounded-xl bg-gradient-to-r from-amber-600 to-indigo-600 hover:from-amber-500 hover:to-indigo-500 text-white font-bold whitespace-nowrap shadow-lg shadow-amber-600/30 transition">
                <i class="fa-solid fa-plus mr-1"></i> Adicionar Chave ao Provedor
            </button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead>
                    <tr class="text-gray-400 border-b border-gray-800 uppercase font-mono tracking-wider">
                        <th class="py-3 px-4">Provedor</th>
                        <th class="py-3 px-4">Rótulo da Chave</th>
                        <th class="py-3 px-4">API Key Mestre</th>
                        <th class="py-3 px-4">Erros Registrados</th>
                        <th class="py-3 px-4">Último Uso</th>
                        <th class="py-3 px-4">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800/60 font-mono">
                    <?php if (empty($providerKeys)): ?>
                        <tr><td colspan="6" class="py-6 text-center text-gray-500 font-sans">Nenhuma chave cadastrada. Adicione uma chave acima.</td></tr>
                    <?php else: ?>
                        <?php foreach ($providerKeys as $pk): ?>
                            <tr class="hover:bg-gray-900/40">
                                <td class="py-3.5 px-4 font-sans font-bold text-cyan-300">
                                    <i class="fa-solid fa-server mr-1 text-gray-500 text-xs"></i> <?= htmlspecialchars($pk['provider_name']) ?>
                                </td>
                                <td class="py-3.5 px-4 font-sans font-semibold text-white">
                                    <?= htmlspecialchars($pk['key_label']) ?>
                                </td>
                                <td class="py-3.5 px-4 text-emerald-400 select-all font-bold">
                                    <?= substr($pk['api_key'], 0, 12) . '••••••••••••••••' ?>
                                </td>
                                <td class="py-3.5 px-4">
                                    <?php if ($pk['error_count'] > 0): ?>
                                        <span class="px-2.5 py-1 rounded bg-red-500/20 text-red-400 font-bold text-[11px]">
                                            <?= $pk['error_count'] ?> erros
                                        </span>
                                        <form method="POST" action="providers.php" class="inline ml-2">
                                            <input type="hidden" name="action" value="reset_key_errors">
                                            <input type="hidden" name="key_id" value="<?= $pk['id'] ?>">
                                            <button type="submit" class="text-[10px] text-gray-400 hover:text-white underline">Resetar</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded bg-emerald-500/10 text-emerald-400 font-semibold text-[11px]">0 erros (Perfeita)</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-4 text-gray-500 text-[11px]">
                                    <?= $pk['last_used_at'] ? date('d/m H:i:s', strtotime($pk['last_used_at'])) : 'Nunca usada' ?>
                                </td>
                                <td class="py-3.5 px-4 font-sans">
                                    <form method="POST" action="providers.php" onsubmit="return confirm('Excluir esta chave do provedor?');" class="inline">
                                        <input type="hidden" name="action" value="delete_provider_key">
                                        <input type="hidden" name="key_id" value="<?= $pk['id'] ?>">
                                        <button type="submit" class="text-xs text-red-400 hover:text-red-300">Excluir</button>
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

<!-- Modal de Criar/Editar Provedor -->
<div id="providerModal" class="fixed inset-0 bg-black/80 backdrop-blur-md flex items-center justify-center p-4 z-50 hidden">
    <div class="glass-card max-w-md w-full p-6 rounded-3xl border border-gray-800 space-y-5 relative">
        <button onclick="closeProviderModal()" class="absolute top-4 right-4 text-gray-400 hover:text-white"><i class="fa-solid fa-xmark text-lg"></i></button>

        <div>
            <h3 class="text-base font-bold text-white flex items-center" id="providerModalTitle">
                <i class="fa-solid fa-server text-cyan-400 mr-2"></i> Criar Novo Provedor
            </h3>
        </div>

        <form method="POST" action="providers.php" class="space-y-4 text-xs">
            <input type="hidden" name="action" value="save_provider">
            <input type="hidden" name="provider_id" id="provider_id" value="0">

            <div>
                <label class="block font-semibold text-gray-300 mb-1">Nome do Provedor (ex: api.b.ai)</label>
                <input type="text" name="name" id="provider_name" required placeholder="ex: api.b.ai" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white">
            </div>

            <div>
                <label class="block font-semibold text-gray-300 mb-1">Base URL do Provedor</label>
                <input type="text" name="base_url" id="provider_base_url" required placeholder="https://api.b.ai/v1" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono">
            </div>

            <div>
                <label class="block font-semibold text-gray-300 mb-1">Status</label>
                <select name="status" id="provider_status" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white">
                    <option value="active">Ativo</option>
                    <option value="inactive">Inativo</option>
                </select>
            </div>

            <button type="submit" class="w-full py-3 bg-indigo-600 hover:bg-indigo-500 text-white font-bold rounded-xl transition shadow-lg shadow-indigo-600/30">
                Salvar Provedor
            </button>
        </form>
    </div>
</div>

<!-- Modal de Adicionar Chave ao Provedor -->
<div id="providerKeyModal" class="fixed inset-0 bg-black/80 backdrop-blur-md flex items-center justify-center p-4 z-50 hidden">
    <div class="glass-card max-w-md w-full p-6 rounded-3xl border border-gray-800 space-y-5 relative">
        <button onclick="closeProviderKeyModal()" class="absolute top-4 right-4 text-gray-400 hover:text-white"><i class="fa-solid fa-xmark text-lg"></i></button>

        <div>
            <h3 class="text-base font-bold text-white flex items-center">
                <i class="fa-solid fa-key text-amber-400 mr-2"></i> Adicionar API Key ao Provedor
            </h3>
        </div>

        <form method="POST" action="providers.php" class="space-y-4 text-xs">
            <input type="hidden" name="action" value="save_provider_key">
            <input type="hidden" name="key_id" value="0">

            <div>
                <label class="block font-semibold text-gray-300 mb-1">Selecione o Provedor</label>
                <select name="provider_id" required class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white">
                    <?php foreach ($providers as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['base_url']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-semibold text-gray-300 mb-1">Rótulo da Chave</label>
                <input type="text" name="key_label" placeholder="ex: Chave Principal / Chave Reserva 1" required class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white">
            </div>

            <div>
                <label class="block font-semibold text-gray-300 mb-1">API Key Mestre</label>
                <input type="password" name="api_key" placeholder="sk-..." required class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono">
            </div>

            <button type="submit" class="w-full py-3 bg-amber-600 hover:bg-amber-500 text-white font-bold rounded-xl transition shadow-lg shadow-amber-600/30">
                Adicionar Chave com Failover
            </button>
        </form>
    </div>
</div>

<script>
    function openProviderModal() {
        document.getElementById('provider_id').value = '0';
        document.getElementById('provider_name').value = '';
        document.getElementById('provider_base_url').value = 'https://api.b.ai/v1';
        document.getElementById('provider_status').value = 'active';
        document.getElementById('providerModalTitle').innerHTML = '<i class="fa-solid fa-server text-cyan-400 mr-2"></i> Criar Novo Provedor';
        document.getElementById('providerModal').classList.remove('hidden');
    }

    function editProvider(p) {
        document.getElementById('provider_id').value = p.id;
        document.getElementById('provider_name').value = p.name;
        document.getElementById('provider_base_url').value = p.base_url;
        document.getElementById('provider_status').value = p.status;
        document.getElementById('providerModalTitle').innerHTML = '<i class="fa-solid fa-pen-to-square text-cyan-400 mr-2"></i> Editar Provedor';
        document.getElementById('providerModal').classList.remove('hidden');
    }

    function closeProviderModal() {
        document.getElementById('providerModal').classList.add('hidden');
    }

    function openProviderKeyModal() {
        document.getElementById('providerKeyModal').classList.remove('hidden');
    }

    function closeProviderKeyModal() {
        document.getElementById('providerKeyModal').classList.add('hidden');
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
