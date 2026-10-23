<?php
/**
 * Webhook para Notificação Automática de Pagamento Mistic Pay (PIX)
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/helpers.php';

$rawPayload = file_get_contents('php://input');
$data = json_decode($rawPayload, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload JSON inválido.']);
    exit;
}

$txId = $data['transactionId'] ?? $data['transaction_id'] ?? $data['tx_id'] ?? '';
$status = strtoupper(trim($data['status'] ?? ''));

if (!$txId) {
    http_response_code(400);
    echo json_encode(['error' => 'transactionId ausente.']);
    exit;
}

$approved = in_array($status, ['PAID', 'APPROVED', 'COMPLETO', 'CONCLUIDO'], true);

if ($approved) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM payments WHERE transaction_id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$txId]);
    $payment = $stmt->fetch();

    if ($payment) {
        // Atualiza pagamento para 'paid'
        $stmtUpd = $db->prepare("UPDATE payments SET status = 'paid', updated_at = NOW() WHERE id = ?");
        $stmtUpd->execute([$payment['id']]);

        // Credita os tokens para o usuário
        $stmtUser = $db->prepare("UPDATE users SET balance_tokens = balance_tokens + ? WHERE id = ?");
        $stmtUser->execute([$payment['tokens_amount'], $payment['user_id']]);

        echo json_encode(['success' => true, 'message' => 'Pagamento processado e tokens creditados com sucesso.']);
        exit;
    }
}

echo json_encode(['success' => true, 'message' => 'Notificação recebida.']);
