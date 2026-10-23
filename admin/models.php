<?php
require_once __DIR__ . '/../config/helpers.php';
requireAdmin();

$db = getDB();
$pageTitle = 'Modelos & Pré-Prompts Customizados';
$pageHeaderIcon = '<i class="fa-solid fa-microchip text-purple-400 mr-2.5"></i>';
$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'save_model') {
        $modelId = (int)($_POST['model_id'] ?? 0);
        $modelKey = trim($_POST['model_key'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $providerId = !empty($_POST['provider_id']) ? (int)$_POST['provider_id'] : null;
        $upstreamModel = trim($_POST['upstream_model'] ?? 'deepseek-v4-flash');
        $modelPrePrompt = trim($_POST['pre_prompt'] ?? '');
        $multiplier = isset($_POST['multiplier']) ? floatval($_POST['multiplier']) : 1.00;
        if ($multiplier <= 0) $multiplier = 1.00;
        $status = $_POST['status'] ?? 'active';
        $isAdminOnly = !empty($_POST['is_admin_only']) ? 1 : 0;
        $isRealtime = !empty($_POST['is_realtime']) ? 1 : 0;
        $isSmartRouting = !empty($_POST['is_smart_routing']) ? 1 : 0;
        $routingFast = trim($_POST['routing_fast_model'] ?? '');
        $routingFastMult = isset($_POST['routing_fast_multiplier']) ? floatval($_POST['routing_fast_multiplier']) : 1.00;
        $routingMedium = trim($_POST['routing_medium_model'] ?? '');
        $routingMediumMult = isset($_POST['routing_medium_multiplier']) ? floatval($_POST['routing_medium_multiplier']) : 1.50;
        $routingHeavy = trim($_POST['routing_heavy_model'] ?? '');
        $routingHeavyMult = isset($_POST['routing_heavy_multiplier']) ? floatval($_POST['routing_heavy_multiplier']) : 3.00;

        if (!empty($modelKey) && !empty($name)) {
            if ($modelId > 0) {
                $stmt = $db->prepare("UPDATE models SET model_key = ?, name = ?, provider_id = ?, upstream_model = ?, multiplier = ?, pre_prompt = ?, status = ?, is_admin_only = ?, is_realtime = ?, is_smart_routing = ?, routing_fast_model = ?, routing_fast_multiplier = ?, routing_medium_model = ?, routing_medium_multiplier = ?, routing_heavy_model = ?, routing_heavy_multiplier = ? WHERE id = ?");
                $stmt->execute([$modelKey, $name, $providerId, $upstreamModel, $multiplier, $modelPrePrompt, $status, $isAdminOnly, $isRealtime, $isSmartRouting, $routingFast, $routingFastMult, $routingMedium, $routingMediumMult, $routingHeavy, $routingHeavyMult, $modelId]);
            } else {
                $stmt = $db->prepare("INSERT INTO models (model_key, name, provider_id, upstream_model, multiplier, pre_prompt, status, is_admin_only, is_realtime, is_smart_routing, routing_fast_model, routing_fast_multiplier, routing_medium_model, routing_medium_multiplier, routing_heavy_model, routing_heavy_multiplier) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$modelKey, $name, $providerId, $upstreamModel, $multiplier, $modelPrePrompt, $status, $isAdminOnly, $isRealtime, $isSmartRouting, $routingFast, $routingFastMult, $routingMedium, $routingMediumMult, $routingHeavy, $routingHeavyMult]);
            }
            $msg = 'Modelo salvo com sucesso!';
        } else {
            $error = 'Preencha a Chave do Modelo e o Nome.';
        }
    } elseif ($_POST['action'] === 'toggle_model_status') {
        $modelId = (int)$_POST['model_id'];
        $currentStatus = $_POST['current_status'] ?? 'active';
        $newStatus = ($currentStatus === 'active') ? 'inactive' : 'active';

        $stmt = $db->prepare("UPDATE models SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $modelId]);
        $msg = 'Status do modelo alterado com sucesso!';
    } elseif ($_POST['action'] === 'delete_model') {
        $modelId = (int)$_POST['model_id'];
        $stmt = $db->prepare("DELETE FROM models WHERE id = ?");
        $stmt->execute([$modelId]);
        $msg = 'Modelo removido com sucesso.';
        } elseif ($_POST['action'] === 'sync_provider_models') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $customApiKey = trim($_POST['api_key'] ?? '');

        if ($providerId > 0) {
            $stmtProv = $db->prepare("SELECT * FROM providers WHERE id = ?");
            $stmtProv->execute([$providerId]);
            $provider = $stmtProv->fetch();

            if ($provider) {
                $isAntigravity = (
                    $providerId == 2 ||
                    stripos($provider['name'], 'antigravity') !== false ||
                    stripos($provider['name'], 'google') !== false ||
                    stripos($provider['base_url'], 'googleapis') !== false
                );

                if ($isAntigravity) {
                    $importedModels = [
                        ['upstream' => 'gemini-3.8-flash-medium', 'name' => 'Gemini 3.8 Flash Medium'],
                        ['upstream' => 'gemini-3.8-flash-high', 'name' => 'Gemini 3.8 Flash High'],
                        ['upstream' => 'claude-sonnet-4-6', 'name' => 'Claude Sonnet 4.6 (Thinking)'],
                        ['upstream' => 'claude-opus-4-6-thinking', 'name' => 'Claude Opus 4.6 (Thinking)'],
                        ['upstream' => 'gpt-oss-120b-medium', 'name' => 'GPT-OSS 120B (Medium)']
                    ];

                    $savedCount = 0;
                    $stmtIns = $db->prepare("INSERT INTO provider_models (provider_id, upstream_model, name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)");
                    foreach ($importedModels as $imp) {
                        $stmtIns->execute([$providerId, $imp['upstream'], $imp['name']]);
                        $savedCount++;
                    }
                    $msg = "⚡ Sucesso! Foram sincronizados $savedCount modelos do Google Antigravity para você cadastrar/mascarar no painel!";
                    $gwSyncUrl = getGatewayInternalUrl() . '/auth/antigravity/sync';
                    $syncCtx = stream_context_create(['http' => ['timeout' => 5]]);
                    @file_get_contents($gwSyncUrl, false, $syncCtx);
                } else {
                    $apiKey = $customApiKey;
                    if (empty($apiKey)) {
                        $stmtKey = $db->prepare("SELECT api_key FROM provider_keys WHERE provider_id = ? AND status = 'active' ORDER BY id ASC LIMIT 1");
                        $stmtKey->execute([$providerId]);
                        $keyRow = $stmtKey->fetch();
                        if ($keyRow) {
                            $apiKey = $keyRow['api_key'];
                        }
                    }

                    $importedModels = [];
                    $baseUrl = rtrim($provider['base_url'], '/');

                    $endpointsToTry = [
                        $baseUrl . '/v1/models',
                        $baseUrl . '/models'
                    ];

                    if (substr($baseUrl, -3) === '/v1') {
                        $baseWithoutV1 = substr($baseUrl, 0, -3);
                        array_unshift($endpointsToTry, $baseWithoutV1 . '/v1/models');
                        array_unshift($endpointsToTry, $baseWithoutV1 . '/models');
                    }

                    $endpointsToTry = array_unique($endpointsToTry);
                    $fetchedData = null;

                    foreach ($endpointsToTry as $epUrl) {
                        $ch = curl_init($epUrl);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, [
                            'Authorization: Bearer ' . $apiKey,
                            'Accept: application/json',
                            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)'
                        ]);
                        $response = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        if ($httpCode === 200 && !empty($response)) {
                            $json = json_decode($response, true);
                            if (isset($json['data']) && is_array($json['data'])) {
                                $fetchedData = $json['data'];
                                break;
                            } elseif (isset($json['models']) && is_array($json['models'])) {
                                $fetchedData = $json['models'];
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

                    if (!empty($importedModels)) {
                        $savedCount = 0;
                        $stmtIns = $db->prepare("INSERT INTO provider_models (provider_id, upstream_model, name) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)");
                        foreach ($importedModels as $imp) {
                            $stmtIns->execute([$providerId, $imp['upstream'], $imp['name']]);
                            $savedCount++;
                        }
                        $msg = "⚡ Sucesso! Foram sincronizados $savedCount modelos do provedor '{$provider['name']}'! Eles já estão disponíveis no campo 'Modelo do Provedor' para você cadastrar e mascarar.";
                    } else {
                        $error = 'Não foi possível buscar automaticamente os modelos. Verifique se a Base URL e a API Key do Provedor estão cadastradas corretamente.';
                    }
                }
            }
        }
    }
}
// Buscar Provedores
$stmtP = $db->query("SELECT * FROM providers ORDER BY id ASC");
$providers = $stmtP->fetchAll();

// Mapear modelos disponíveis por provedor vindos de provider_models
$providerModelsMap = [];
foreach ($providers as $p) {
    $pId = $p['id'];
    $stmtPM = $db->prepare("SELECT upstream_model, name FROM provider_models WHERE provider_id = ? ORDER BY id ASC");
    $stmtPM->execute([$pId]);
    $pModels = $stmtPM->fetchAll(PDO::FETCH_ASSOC);

    // Fallback caso ainda não tenha sincronizado o provedor
    if (empty($pModels)) {
        if ($pId == 2 || stripos($p['name'], 'antigravity') !== false) {
            $pModels = [
                ['upstream_model' => 'gemini-3.8-flash-medium', 'name' => 'Gemini 3.8 Flash Medium'],
                ['upstream_model' => 'gemini-3.7-flash-medium', 'name' => 'Gemini 3.7 Flash Medium'],
                ['upstream_model' => 'gemini-3.6-flash-medium', 'name' => 'Gemini 3.6 Flash Medium'],
                ['upstream_model' => 'gemini-3.1-pro-low', 'name' => 'Gemini 3.1 Pro Low'],
                ['upstream_model' => 'claude-sonnet-4-6', 'name' => 'Claude Sonnet 4.6 (Thinking)'],
                ['upstream_model' => 'claude-opus-4-6-thinking', 'name' => 'Claude Opus 4.6 (Thinking)'],
                ['upstream_model' => 'gpt-oss-120b-medium', 'name' => 'GPT-OSS 120B (Medium)']
            ];
        } else {
            $pModels = [
                ['upstream_model' => 'deepseek-v4-flash', 'name' => 'DeepSeek V4 Flash'],
                ['upstream_model' => 'deepseek-v3.2', 'name' => 'DeepSeek V3.2'],
                ['upstream_model' => 'deepseek-v4-pro-202606', 'name' => 'DeepSeek V4 Pro']
            ];
        }
    }
    $providerModelsMap[$pId] = $pModels;
}

// Buscar Modelos
$stmtModels = $db->query("SELECT m.*, p.name as provider_name FROM models m LEFT JOIN providers p ON m.provider_id = p.id ORDER BY m.id ASC");
$modelsList = $stmtModels->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="glass-card p-4 sm:p-5 rounded-2xl border border-gray-800 space-y-4 w-full min-w-0 overflow-hidden">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 border-b border-gray-800/80 pb-3">
        <div>
            <h3 class="text-base font-bold text-white flex items-center"><i class="fa-solid fa-microchip text-purple-400 mr-2"></i> Modelos de IA & Pré-Prompts</h3>
            <p class="text-xs text-gray-400 mt-0.5">Associe cada modelo a um provedor específico e configure permissões e Realtime.</p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="openSyncModal()" class="px-3.5 py-2 text-xs rounded-xl bg-purple-600/20 text-purple-300 border border-purple-500/30 hover:bg-purple-600/30 font-bold whitespace-nowrap transition flex items-center">
                <i class="fa-solid fa-cloud-arrow-down mr-1.5"></i> Sincronizar
            </button>
            <button onclick="openModelModal()" class="px-3.5 py-2 text-xs rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-bold whitespace-nowrap shadow-lg shadow-purple-600/30 transition">
                <i class="fa-solid fa-plus mr-1"></i> Novo Modelo
            </button>
        </div>
    </div>

    <div class="overflow-x-auto custom-scrollbar w-full rounded-xl border border-gray-800/80">
        <table class="w-full text-left text-xs whitespace-nowrap">
            <thead>
                <tr class="text-gray-400 bg-gray-900/50 border-b border-gray-800 uppercase font-mono tracking-wider text-[11px]">
                    <th class="py-2.5 px-3">Chave Modelo</th>
                    <th class="py-2.5 px-3">Nome</th>
                    <th class="py-2.5 px-3">Provedor</th>
                    <th class="py-2.5 px-3">Upstream / Rota</th>
                    <th class="py-2.5 px-3 text-center">Multiplicador</th>
                    <th class="py-2.5 px-3">Pré-Prompt</th>
                    <th class="py-2.5 px-3 text-center">Acesso</th>
                    <th class="py-2.5 px-3 text-center">Realtime</th>
                    <th class="py-2.5 px-3 text-center">Status</th>
                    <th class="py-2.5 px-3 text-center">Ações</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-800/60 font-mono">
                <?php foreach ($modelsList as $m): ?>
                    <tr class="hover:bg-gray-900/40 transition">
                        <td class="py-2.5 px-3 font-bold text-cyan-300">
                            <?= htmlspecialchars($m['model_key']) ?>
                        </td>
                        <td class="py-2.5 px-3 font-sans font-semibold text-white">
                            <?= htmlspecialchars($m['name']) ?>
                        </td>
                        <td class="py-2.5 px-3 font-sans text-indigo-300 font-medium">
                            <?= htmlspecialchars($m['provider_name'] ?: 'Padrão') ?>
                        </td>
                        <td class="py-2.5 px-3">
                            <?php if (!empty($m['is_smart_routing'])): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                    ⚡ Inteligente
                                </span>
                            <?php else: ?>
                                <span class="px-2 py-0.5 rounded bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[11px]">
                                    <?= htmlspecialchars($m['upstream_model']) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="py-2.5 px-3 font-mono text-center">
                            <?php if (!empty($m['is_smart_routing'])): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-500/10 text-amber-400 border border-amber-500/20" title="Consumo dinâmico baseado no modelo de destino (Fast 1x, Medium 1.5x, Heavy 2.5x+)">
                                    ⚡ Dinâmico
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold <?= (float)($m['multiplier'] ?? 1.0) > 1.5 ? 'bg-purple-500/20 text-purple-300 border border-purple-500/30' : 'bg-cyan-500/10 text-cyan-400 border border-cyan-500/20' ?>">
                                    <?= number_format((float)($m['multiplier'] ?? 1.0), 1) ?>x
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="py-2.5 px-3 font-sans max-w-[180px] truncate text-gray-400 text-[11px]">
                            <?= htmlspecialchars($m['pre_prompt'] ?: 'Global') ?>
                        </td>
                        <td class="py-2.5 px-3 font-sans text-center">
                            <?php if (!empty($m['is_admin_only'])): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-purple-500/20 text-purple-300 border border-purple-500/30 text-[10px] font-bold">
                                    <i class="fa-solid fa-lock mr-1 text-[9px]"></i> Só Admin
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 text-[10px] font-bold">
                                    <i class="fa-solid fa-globe mr-1 text-[9px]"></i> Todos
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="py-2.5 px-3 font-sans text-center">
                            <?php if (!empty($m['is_realtime'])): ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-cyan-500/20 text-cyan-300 border border-cyan-500/30 text-[10px] font-bold">
                                    <i class="fa-solid fa-waveform-lines mr-1 text-[9px]"></i> Sim
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-gray-800 text-gray-400 border border-gray-700 text-[10px] font-semibold">
                                    Não
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="py-2.5 px-3 font-sans text-center">
                            <form method="POST" action="models.php" class="inline">
                                <input type="hidden" name="action" value="toggle_model_status">
                                <input type="hidden" name="model_id" value="<?= $m['id'] ?>">
                                <input type="hidden" name="current_status" value="<?= $m['status'] ?>">
                                <?php if ($m['status'] === 'active'): ?>
                                    <button type="submit" title="Clique para desativar" class="px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-400 font-semibold text-[10px]">
                                        ● Ativo
                                    </button>
                                <?php else: ?>
                                    <button type="submit" title="Clique para ativar" class="px-2 py-0.5 rounded-full bg-gray-800 text-gray-400 font-semibold text-[10px]">
                                        ○ Inativo
                                    </button>
                                <?php endif; ?>
                            </form>
                        </td>
                        <td class="py-2.5 px-3 font-sans text-center">
                            <div class="flex items-center justify-center space-x-1.5">
                                <button type="button" data-model="<?= htmlspecialchars(json_encode($m, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>" onclick="onEditBtnClick(this)" class="text-[11px] px-2.5 py-1 bg-indigo-600 hover:bg-indigo-500 text-white font-semibold rounded-lg transition">
                                    Editar
                                </button>
                                <form method="POST" action="models.php" onsubmit="return confirm('Excluir este modelo?');" class="inline">
                                    <input type="hidden" name="action" value="delete_model">
                                    <input type="hidden" name="model_id" value="<?= $m['id'] ?>">
                                    <button type="submit" class="text-[11px] px-2 py-1 text-red-400 hover:text-red-300 font-semibold">Excluir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal de Criar/Editar Modelo -->
<div id="modelModal" class="fixed inset-0 bg-black/80 backdrop-blur-md flex items-center justify-center p-3 sm:p-4 z-50 hidden">
    <div class="glass-card max-w-lg w-full max-h-[92vh] flex flex-col rounded-2xl border border-gray-800 shadow-2xl relative overflow-hidden bg-gray-950">
        
        <!-- Header do Modal (Fixo) -->
        <div class="px-5 py-3.5 border-b border-gray-800/80 flex items-center justify-between shrink-0 bg-gray-900/70">
            <div>
                <h3 class="text-base font-bold text-white flex items-center" id="modelModalTitle">
                    <i class="fa-solid fa-microchip text-purple-400 mr-2"></i> Criar Novo Modelo
                </h3>
                <p class="text-[11px] text-gray-400">Configure os parâmetros, provedor e disponibilidade do modelo</p>
            </div>
            <button type="button" onclick="closeModelModal()" class="w-8 h-8 rounded-lg bg-gray-800/60 hover:bg-gray-700 text-gray-400 hover:text-white flex items-center justify-center transition">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
        </div>

        <form method="POST" action="models.php" class="flex flex-col flex-1 min-h-0 text-xs">
            <input type="hidden" name="action" value="save_model">
            <input type="hidden" name="model_id" id="model_id" value="0">

            <!-- Corpo Rolável Interno -->
            <div class="overflow-y-auto p-4 sm:p-5 space-y-3.5 flex-1 custom-scrollbar">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">Chave do Modelo (ID Cliente)</label>
                        <input type="text" name="model_key" id="model_key" required placeholder="ex: claude-3-5-sonnet" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono">
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">Nome Exibido</label>
                        <input type="text" name="name" id="model_name" required placeholder="ex: Claude 3.5 Sonnet" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">Provedor Atribuído</label>
                        <select name="provider_id" id="model_provider_id" onchange="onProviderChange()" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono text-xs">
                            <option value="">Padrão (Sem provedor)</option>
                            <?php foreach ($providers as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">Modelo Real no Upstream</label>
                        <select id="model_upstream_select" onchange="onUpstreamSelectChange()" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-cyan-300 font-mono text-xs focus:outline-none focus:border-cyan-500">
                            <option value="">-- Selecione o modelo --</option>
                        </select>
                        <input type="hidden" name="upstream_model" id="model_upstream_model" value="">
                    </div>
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1">
                        <label class="block font-semibold text-gray-300">Pré-Prompt Customizado deste Modelo</label>
                        <button type="button" onclick="loadFablePrompt('model_pre_prompt')" class="px-2 py-0.5 rounded bg-purple-600/20 hover:bg-purple-600/40 text-purple-300 border border-purple-500/30 text-[10px] font-medium transition flex items-center gap-1">
                            <i class="fa-solid fa-wand-magic-sparkles text-amber-300"></i> Carregar Fable 5.1
                        </button>
                    </div>
                    <textarea name="pre_prompt" id="model_pre_prompt" rows="3" placeholder="Ex: Deixe em branco para usar o Pré-Prompt Global ou insira um personalizado..." class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono text-xs focus:outline-none focus:border-purple-500"></textarea>
                </div>

                <!-- SEÇÃO DE ROTEAMENTO INTELIGENTE -->
                <div class="p-3 rounded-xl bg-gradient-to-b from-purple-950/20 to-gray-900/40 border border-purple-800/30 space-y-2">
                    <div class="flex items-center justify-between">
                        <div>
                            <span class="font-bold text-white text-xs flex items-center gap-1.5">
                                <i class="fa-solid fa-bolt text-amber-400"></i> Roteamento Inteligente
                            </span>
                            <p class="text-[10px] text-gray-400">Alterna automaticamente o modelo com base no tamanho do prompt</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer ml-3">
                            <input type="checkbox" name="is_smart_routing" id="model_is_smart_routing" value="1" onchange="toggleSmartRoutingUI()" class="sr-only peer">
                            <div class="w-9 h-5 bg-gray-800 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-purple-600"></div>
                        </label>
                    </div>

                    <div id="smart_routing_fields" class="space-y-3 pt-2 border-t border-gray-800/80 hidden">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 items-center">
                            <div class="sm:col-span-2">
                                <label class="block text-[10px] font-bold text-emerald-400 mb-0.5">1. Leve / Rápido</label>
                                <select id="select_routing_fast" onchange="document.getElementById('model_routing_fast_model').value = this.value" class="w-full p-2 rounded-xl bg-gray-950 border border-gray-800 text-emerald-300 font-mono text-[11px]">
                                    <option value="">-- Selecione --</option>
                                </select>
                                <input type="hidden" name="routing_fast_model" id="model_routing_fast_model">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-emerald-400 mb-0.5">Multiplicador</label>
                                <input type="number" step="0.1" min="0.1" max="50" name="routing_fast_multiplier" id="model_routing_fast_multiplier" value="1.0" class="w-full p-2 rounded-xl bg-gray-950 border border-gray-800 text-emerald-300 font-mono text-[11px]" title="Multiplicador cobrado quando a pergunta for leve">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 items-center">
                            <div class="sm:col-span-2">
                                <label class="block text-[10px] font-bold text-yellow-400 mb-0.5">2. Médio / Análise</label>
                                <select id="select_routing_medium" onchange="document.getElementById('model_routing_medium_model').value = this.value" class="w-full p-2 rounded-xl bg-gray-950 border border-gray-800 text-yellow-300 font-mono text-[11px]">
                                    <option value="">-- Selecione --</option>
                                </select>
                                <input type="hidden" name="routing_medium_model" id="model_routing_medium_model">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-yellow-400 mb-0.5">Multiplicador</label>
                                <input type="number" step="0.1" min="0.1" max="50" name="routing_medium_multiplier" id="model_routing_medium_multiplier" value="1.5" class="w-full p-2 rounded-xl bg-gray-950 border border-gray-800 text-yellow-300 font-mono text-[11px]" title="Multiplicador cobrado quando a pergunta for de complexidade média">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 items-center">
                            <div class="sm:col-span-2">
                                <label class="block text-[10px] font-bold text-purple-400 mb-0.5">3. Pesado / Código</label>
                                <select id="select_routing_heavy" onchange="document.getElementById('model_routing_heavy_model').value = this.value" class="w-full p-2 rounded-xl bg-gray-950 border border-gray-800 text-purple-300 font-mono text-[11px]">
                                    <option value="">-- Selecione --</option>
                                </select>
                                <input type="hidden" name="routing_heavy_model" id="model_routing_heavy_model">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-purple-400 mb-0.5">Multiplicador</label>
                                <input type="number" step="0.1" min="0.1" max="50" name="routing_heavy_multiplier" id="model_routing_heavy_multiplier" value="3.0" class="w-full p-2 rounded-xl bg-gray-950 border border-gray-800 text-purple-300 font-mono text-[11px]" title="Multiplicador cobrado quando a pergunta for código ou muito pesada (ex: Opus 3x)">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">
                            <i class="fa-solid fa-gauge-high text-cyan-400 mr-1"></i> Multiplicador
                        </label>
                        <input type="number" step="0.1" min="0.1" max="50" name="multiplier" id="model_multiplier" value="1.0" required class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-cyan-300 font-mono text-xs">
                        <span class="text-[9px] text-gray-500 mt-0.5 block">Ex: 1.0x, 2.0x</span>
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">Disponibilidade</label>
                        <select name="is_admin_only" id="model_is_admin_only" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono text-xs">
                            <option value="0">🌐 Para Todos</option>
                            <option value="1">🔒 Só Admin</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">
                            <i class="fa-solid fa-waveform-lines text-purple-400 mr-1"></i> Realtime (Voz)
                        </label>
                        <select name="is_realtime" id="model_is_realtime" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono text-xs">
                            <option value="1">🎙️ Sim (Ativo)</option>
                            <option value="0">❌ Não (Só Chat)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block font-semibold text-gray-300 mb-1">Status</label>
                        <select name="status" id="model_status" class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white text-xs">
                            <option value="active">Ativo</option>
                            <option value="inactive">Inativo</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Rodapé do Modal com Botão Salvar Fixo -->
            <div class="px-5 py-3 border-t border-gray-800/80 bg-gray-900/80 shrink-0 flex items-center gap-2">
                <button type="button" onclick="closeModelModal()" class="px-4 py-2.5 rounded-xl bg-gray-800 hover:bg-gray-700 text-gray-300 font-semibold text-xs transition">
                    Cancelar
                </button>
                <button type="submit" class="flex-1 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-bold text-xs shadow-lg shadow-purple-600/30 transition flex items-center justify-center gap-2">
                    <i class="fa-solid fa-floppy-disk"></i> Salvar Modelo
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de Sincronização Automática de Modelos -->
<div id="syncModal" class="fixed inset-0 bg-black/80 backdrop-blur-md flex items-center justify-center p-4 z-50 hidden">
    <div class="glass-card max-w-md w-full max-h-[90vh] overflow-y-auto custom-scrollbar p-6 rounded-2xl border border-gray-800 space-y-5 relative bg-gray-950">
        <button onclick="closeSyncModal()" class="absolute top-4 right-4 text-gray-400 hover:text-white"><i class="fa-solid fa-xmark text-lg"></i></button>

        <div>
            <h3 class="text-base font-bold text-white flex items-center">
                <i class="fa-solid fa-cloud-arrow-down text-purple-400 mr-2"></i> Importar Modelos do Provedor
            </h3>
            <p class="text-xs text-gray-400 mt-1">Selecione qualquer provedor cadastrado para consultar a API e importar os modelos automaticamente.</p>
        </div>

        <form method="POST" action="models.php" class="space-y-4 text-xs" onsubmit="this.querySelector('button[type=submit]').innerHTML='<i class=\'fa-solid fa-spinner fa-spin mr-1\'></i> Consultando API...';">
            <input type="hidden" name="action" value="sync_provider_models">

            <div>
                <label class="block font-semibold text-gray-300 mb-1">Selecione o Provedor</label>
                <select name="provider_id" required class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white">
                    <?php foreach ($providers as $p): ?>
                        <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['base_url']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block font-semibold text-gray-300 mb-1">API Key Opcional (Caso não haja chave ativa salva)</label>
                <input type="password" name="api_key" placeholder="sk-..." class="w-full p-2.5 rounded-xl bg-gray-950 border border-gray-800 text-white font-mono">
            </div>

            <button type="submit" class="w-full py-3.5 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 hover:from-purple-500 hover:to-indigo-500 text-white font-bold text-sm shadow-xl shadow-purple-600/30 transition">
                ⚡ Buscar & Cadastrar Modelos
            </button>
        </form>
    </div>
</div>

<script>
    function onEditBtnClick(btn) {
        try {
            const raw = btn.getAttribute("data-model");
            if (!raw) return;
            const model = JSON.parse(raw);
            editModel(model);
        } catch(e) {
            console.error("Erro ao carregar dados do modelo:", e);
            alert("Erro ao carregar dados para edição: " + e.message);
        }
    }

    const providerModelsMap = <?= json_encode($providerModelsMap) ?>;

    const providersList = <?= json_encode($providers) ?>;

    function populateRoutingSelects(provId) {
        const selects = [
            document.getElementById("select_routing_fast"),
            document.getElementById("select_routing_medium"),
            document.getElementById("select_routing_heavy")
        ];

        selects.forEach(sel => {
            if (!sel) return;
            const currentVal = sel.value;
            sel.innerHTML = '<option value="">-- Selecione um Modelo --</option>';

            // Agrupa modelos por provedor usando <optgroup> para poder escolher de qualquer provedor!
            providersList.forEach(p => {
                const pModels = providerModelsMap[p.id] || [];
                if (pModels.length > 0) {
                    const group = document.createElement("optgroup");
                    group.label = "Provedor: " + p.name;
                    
                    pModels.forEach(m => {
                        const opt = document.createElement("option");
                        opt.value = m.upstream_model;
                        opt.textContent = `${m.name} (${m.upstream_model})`;
                        group.appendChild(opt);
                    });
                    sel.appendChild(group);
                }
            });

            if (currentVal) sel.value = currentVal;
        });
    }

    function onProviderChange() {
        const provId = document.getElementById("model_provider_id").value;
        const selectEl = document.getElementById("model_upstream_select");
        selectEl.innerHTML = '<option value="">-- Selecione o modelo do provedor --</option>';

        if (provId && providerModelsMap[provId] && providerModelsMap[provId].length > 0) {
            providerModelsMap[provId].forEach(m => {
                const opt = document.createElement("option");
                opt.value = m.upstream_model;
                opt.textContent = `${m.name} (${m.upstream_model})`;
                selectEl.appendChild(opt);
            });
            selectEl.classList.remove("hidden");
        } else {
            // Mostra modelos de todos os provedores agrupados se nenhum provedor for filtrado
            providersList.forEach(p => {
                const pModels = providerModelsMap[p.id] || [];
                if (pModels.length > 0) {
                    const group = document.createElement("optgroup");
                    group.label = "Provedor: " + p.name;
                    pModels.forEach(m => {
                        const opt = document.createElement("option");
                        opt.value = m.upstream_model;
                        opt.textContent = `${m.name} (${m.upstream_model})`;
                        selectEl.appendChild(opt);
                    });
                    selectEl.appendChild(group);
                }
            });
            selectEl.classList.remove("hidden");
        }

        populateRoutingSelects(provId);
    }

    function onUpstreamSelectChange() {
        const selectEl = document.getElementById("model_upstream_select");
        const inputEl = document.getElementById("model_upstream_model");
        const keyEl = document.getElementById("model_key");
        const nameEl = document.getElementById("model_name");
        const isNew = document.getElementById("model_id").value === "0";

        if (selectEl.value) {
            inputEl.value = selectEl.value;

            // Se for novo modelo e ainda não definiu nome/chave, preenche como sugestão
            if (isNew) {
                if (!keyEl.value) {
                    keyEl.value = selectEl.value;
                }
                if (!nameEl.value) {
                    const selectedOpt = selectEl.options[selectEl.selectedIndex];
                    nameEl.value = selectedOpt ? selectedOpt.textContent.split(" (")[0] : selectEl.value;
                }
            }
        }
    }

    function toggleSmartRoutingUI() {
        const checked = document.getElementById("model_is_smart_routing").checked;
        const container = document.getElementById("smart_routing_fields");
        if (checked) {
            container.classList.remove("hidden");
        } else {
            container.classList.add("hidden");
        }
    }

    function openModelModal() {
        document.getElementById("model_id").value = "0";
        document.getElementById("model_key").value = "";
        document.getElementById("model_name").value = "";
        document.getElementById("model_provider_id").value = "";
        document.getElementById("model_upstream_model").value = "";
        document.getElementById("model_multiplier").value = "1.0";
        document.getElementById("model_pre_prompt").value = "";
        document.getElementById("model_is_admin_only").value = "0";
        document.getElementById("model_is_realtime").value = "1";
        document.getElementById("model_status").value = "active";
        document.getElementById("model_is_smart_routing").checked = false;
        document.getElementById("model_routing_fast_model").value = "gemini-3.8-flash-medium";
        document.getElementById("model_routing_fast_multiplier").value = "1.0";
        document.getElementById("model_routing_medium_model").value = "gemini-3.8-flash-medium";
        document.getElementById("model_routing_medium_multiplier").value = "1.5";
        document.getElementById("model_routing_heavy_model").value = "claude-opus-4-6-thinking";
        document.getElementById("model_routing_heavy_multiplier").value = "3.0";
        toggleSmartRoutingUI();
        document.getElementById("modelModalTitle").innerHTML = '<i class="fa-solid fa-microchip text-purple-400 mr-2"></i> Criar Novo Modelo';
        onProviderChange();
        document.getElementById("modelModal").classList.remove("hidden");
    }

    function editModel(model) {
        document.getElementById("model_id").value = model.id;
        document.getElementById("model_key").value = model.model_key;
        document.getElementById("model_name").value = model.name;
        document.getElementById("model_provider_id").value = model.provider_id || "";
        document.getElementById("model_upstream_model").value = model.upstream_model;
        document.getElementById("model_multiplier").value = model.multiplier || "1.0";
        document.getElementById("model_pre_prompt").value = model.pre_prompt || "";
        document.getElementById("model_is_admin_only").value = model.is_admin_only ? "1" : "0";
        document.getElementById("model_is_realtime").value = (model.is_realtime == 1 || model.is_realtime === undefined) ? "1" : "0";
        document.getElementById("model_status").value = model.status;
        document.getElementById("model_is_smart_routing").checked = (model.is_smart_routing == 1);
        let fastVal = model.routing_fast_model || "gemini-3.8-flash-medium";
        let medVal = model.routing_medium_model || "gemini-3.8-flash-medium";
        if (fastVal.includes("2.5") || fastVal.includes("3.5") || fastVal.includes("3.6") || fastVal.includes("3.7")) fastVal = "gemini-3.8-flash-medium";
        if (medVal.includes("2.5") || medVal.includes("3.5") || medVal.includes("3.6") || medVal.includes("3.7")) medVal = "gemini-3.8-flash-medium";
        const heavyVal = model.routing_heavy_model || "claude-opus-4-6-thinking";
        document.getElementById("model_routing_fast_model").value = fastVal;
        document.getElementById("model_routing_fast_multiplier").value = model.routing_fast_multiplier || "1.0";
        document.getElementById("model_routing_medium_model").value = medVal;
        document.getElementById("model_routing_medium_multiplier").value = model.routing_medium_multiplier || "1.5";
        document.getElementById("model_routing_heavy_model").value = heavyVal;
        document.getElementById("model_routing_heavy_multiplier").value = model.routing_heavy_multiplier || "3.0";
        toggleSmartRoutingUI();
        document.getElementById("modelModalTitle").innerHTML = '<i class="fa-solid fa-pen-to-square text-purple-400 mr-2"></i> Editar Modelo: ' + model.model_key;
        onProviderChange();
        const upSelect = document.getElementById("model_upstream_select");
        if (model.upstream_model) {
            let found = false;
            for (let opt of upSelect.options) {
                if (opt.value === model.upstream_model) {
                    found = true;
                    break;
                }
            }
            if (!found) {
                const opt = document.createElement("option");
                opt.value = model.upstream_model;
                opt.textContent = model.upstream_model;
                upSelect.appendChild(opt);
            }
            upSelect.value = model.upstream_model;
            document.getElementById("model_upstream_model").value = model.upstream_model;
        }
        if (document.getElementById("select_routing_fast")) document.getElementById("select_routing_fast").value = fastVal;
        if (document.getElementById("select_routing_medium")) document.getElementById("select_routing_medium").value = medVal;
        if (document.getElementById("select_routing_heavy")) document.getElementById("select_routing_heavy").value = heavyVal;
        document.getElementById("modelModal").classList.remove("hidden");
    }

    function closeModelModal() {
        document.getElementById("modelModal").classList.add("hidden");
    }

    function openSyncModal() {
        document.getElementById("syncModal").classList.remove("hidden");
    }

    function closeSyncModal() {
        document.getElementById("syncModal").classList.add("hidden");
    }

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
