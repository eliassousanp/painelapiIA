<?php
require_once __DIR__ . '/../config/helpers.php';
requireAdmin();

header('Content-Type: application/json');

$gwUrl = getGatewayInternalUrl() . '/auth/antigravity/pool-metrics';
$ch = curl_init($gwUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 3);
$raw = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 && $raw) {
    echo $raw;
} else {
    // Fallback banco de dados se o gateway estiver iniciando
    $db = getDB();
    $accounts = $db->query("SELECT id, email, name, picture, is_active, status, total_requests FROM antigravity_accounts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $activeCount = count(array_filter($accounts, fn($a) => $a['is_active'] == 1));
    echo json_encode([
        'success' => true,
        'metrics' => [
            'totalAccounts' => $activeCount,
            'poolActiveRequests' => 0,
            'poolCurrentRpm' => 0,
            'accounts' => array_map(fn($a) => [
                'id' => $a['id'],
                'email' => $a['email'],
                'name' => $a['name'],
                'picture' => $a['picture'],
                'status' => $a['status'],
                'activeRequests' => 0,
                'currentRpm' => 0,
                'inCooldown' => false,
                'cooldownSecondsRemaining' => 0
            ], $accounts)
        ]
    ]);
}
