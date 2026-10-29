<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/MisticPayClient.php';

$db = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? 'create_pix';

// Check Status do Pagamento
if ($action === 'check_status') {
    $txId = trim($_GET['tx_id'] ?? $_POST['tx_id'] ?? '');
    if (!$txId) {
        echo json_encode(['success' => false, 'error' => 'ID de transação não informado.']);
        exit;
    }

    $stmt = $db->prepare("SELECT * FROM payments WHERE transaction_id = ? LIMIT 1");
    $stmt->execute([$txId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        echo json_encode(['success' => false, 'error' => 'Pagamento não encontrado.']);
        exit;
    }

    if ($payment['status'] === 'paid') {
        echo json_encode(['success' => true, 'paid' => true, 'message' => 'Pagamento confirmado! Tokens adicionados.']);
        exit;
    }

    // Tenta verificar status na Mistic Pay se ainda estiver pendente
    $misticId = getSetting('mistic_client_id', '');
    $misticSec = getSetting('mistic_client_secret', '');

    if ($misticId && $misticSec && $payment['status'] === 'pending') {
        $mistic = new MisticPayClient($misticId, $misticSec);
        $apiCheck = $mistic->checkStatus($txId);
        $st = strtoupper(trim($apiCheck['status'] ?? $apiCheck['data']['status'] ?? ''));

        if (in_array($st, ['COMPLETO', 'PAID', 'APPROVED', 'CONCLUIDO'], true)) {
            // Aprova e libera tokens de forma estritamente atômica (anti race-condition)
            $stmtUpd = $db->prepare("UPDATE payments SET status = 'paid', updated_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmtUpd->execute([$payment['id']]);

            if ($stmtUpd->rowCount() > 0) {
                $db->prepare("UPDATE users SET balance_tokens = balance_tokens + ? WHERE id = ?")->execute([$payment['tokens_amount'], $payment['user_id']]);
            }

            echo json_encode(['success' => true, 'paid' => true, 'message' => 'Pagamento aprovado e tokens creditados!']);
            exit;
        }
    }

    echo json_encode(['success' => true, 'paid' => false, 'status' => $payment['status']]);
    exit;
}

// Criar Cobrança PIX
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido.']);
    exit;
}

requireLogin();

$rawInput = file_get_contents('php://input');
$body = json_decode($rawInput, true) ?: $_POST;

$amount = floatval($body['amount'] ?? 149.00);
$tokensAmount = (int)($body['tokens_amount'] ?? 50000000); // 50 Milhões de tokens
$planName = trim($body['plan_name'] ?? 'Plano Pro Dev Ilimitado');

$userId = $_SESSION['user_id'];
$userName = $_SESSION['user_name'];
$userWhatsapp = $_SESSION['user_whatsapp'] ?? '5511999999999';

$misticId = getSetting('mistic_client_id', '');
$misticSec = getSetting('mistic_client_secret', '');

if (!$misticId || !$misticSec) {
    echo json_encode(['success' => false, 'error' => 'Gateway Mistic Pay PIX não configurado pelo administrador no painel.']);
    exit;
}

$mistic = new MisticPayClient($misticId, $misticSec);

$txId = 'CLAUDE-' . time() . '-' . rand(1000, 9999);
$description = "Assinatura {$planName} - Claude API ({$userName})";

$userDoc = preg_replace('/\D/', '', $body['document'] ?? $body['cpf'] ?? '');
if (empty($userDoc) || strlen($userDoc) !== 11) {
    // Se não enviado pelo formulário, usa CPF padrão do administrador ou fallback seguro
    $userDoc = getSetting('pix_default_cpf', '04868204599');
}

$payerData = [
    'name' => $userName,
    'document' => $userDoc,
    'phone' => preg_replace('/\D/', '', $userWhatsapp)
];

$apiResp = $mistic->createPix($amount, $payerData, $txId, $description);

if (($apiResp['status'] ?? '') === 'error' || empty($apiResp['success'])) {
    $errMessage = $apiResp['message'] ?? 'Falha ao se comunicar com a gateway de pagamento.';
    echo json_encode(['success' => false, 'error' => 'Erro ao gerar PIX: ' . $errMessage]);
    exit;
}

$data = $apiResp['data'] ?? $apiResp;
$qrCode = $data['copyPaste'] ?? $data['pixCopyPaste'] ?? $data['qr_code'] ?? $data['qrcode'] ?? '';
$qrCodeBase64 = $data['qrCodeBase64'] ?? $data['qr_code_base64'] ?? $data['pixQrCodeImage'] ?? '';

if (empty($qrCode)) {
    echo json_encode(['success' => false, 'error' => 'Não foi possível obter o código PIX Copia e Cola.']);
    exit;
}

// Salva pagamento pendente no banco (popula tanto qr_code quanto pix_copia_cola para compatibilidade)
$stmt = $db->prepare("
    INSERT INTO payments (user_id, transaction_id, amount, tokens_amount, plan_name, status, payment_method, qr_code, qr_code_base64, pix_copia_cola, pix_qr_code_base64)
    VALUES (?, ?, ?, ?, ?, 'pending', 'pix', ?, ?, ?, ?)
");
$stmt->execute([$userId, $txId, $amount, $tokensAmount, $planName, $qrCode, $qrCodeBase64, $qrCode, $qrCodeBase64]);

echo json_encode([
    'success' => true,
    'transaction_id' => $txId,
    'amount' => number_format($amount, 2, ',', '.'),
    'plan_name' => $planName,
    'qr_code' => $qrCode,
    'qr_code_base64' => $qrCodeBase64
]);
