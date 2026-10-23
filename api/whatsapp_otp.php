<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config/whatsapp.php';

$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true) ?: $_POST;

$action = $_GET['action'] ?? $body['action'] ?? 'send';

if ($action === 'send') {
    $phone = trim($body['whatsapp'] ?? $body['phone'] ?? '');
    if (empty($phone)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Informe o número de WhatsApp com DDD.']);
        exit;
    }

    $res = send_whatsapp_otp($phone);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'verify') {
    $phone = trim($body['whatsapp'] ?? $body['phone'] ?? '');
    $code = trim($body['code'] ?? '');

    if (empty($phone) || empty($code)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Informe o número de WhatsApp e o código de 6 dígitos.']);
        exit;
    }

    $verified = verify_whatsapp_otp($phone, $code);

    if ($verified) {
        echo json_encode(['success' => true, 'message' => 'WhatsApp verificado com sucesso!']);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Código incorreto ou expirado. Tente novamente.']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Ação inválida.']);
