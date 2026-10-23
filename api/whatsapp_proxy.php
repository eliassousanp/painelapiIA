<?php
/**
 * Proxy PHP para a API do WhatsApp (Node.js localhost).
 * Evita problemas de CORS, firewall ou portas bloqueadas na VPS.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/whatsapp.php';

requireAdmin();

$apiBase = rtrim(get_whatsapp_api_url(), '/');
$route = $_GET['route'] ?? 'instances';
$url = "{$apiBase}/" . ltrim($route, '/');

$method = $_SERVER['REQUEST_METHOD'];
$body = file_get_contents('php://input');

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

if ($method === 'POST' || $method === 'PUT') {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?: '{}');
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

http_response_code($httpCode ?: 500);

if ($error) {
    echo json_encode([
        'error' => 'Erro de comunicação no Proxy com a API Node.js do WhatsApp: ' . $error,
        'url'   => $url
    ]);
} else {
    echo $response;
}
exit;
