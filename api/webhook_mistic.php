<?php
/**
 * Webhook para Notificação Automática de Pagamento Mistic Pay (PIX)
 * Protegido contra condições de corrida (Race Conditions) e validação de token seguro
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/whatsapp.php';

// Verificação de token de segurança configurável
$configuredSecret = trim(getSetting('mistic_webhook_secret', ''));
if (!empty($configuredSecret)) {
    $receivedToken = $_GET['token'] ?? ($_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
    if (!hash_equals($configuredSecret, (string)$receivedToken)) {
        http_response_code(401);
        echo json_encode(['error' => 'Não autorizado: Token de webhook inválido.']);
        exit;
    }
}

$rawPayload = file_get_contents('php://input');
$data = json_decode($rawPayload, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload JSON inválido.']);
    exit;
}

$txId = trim($data['transactionId'] ?? $data['transaction_id'] ?? $data['tx_id'] ?? '');
$status = strtoupper(trim($data['status'] ?? ''));

if (!$txId) {
    http_response_code(400);
    echo json_encode(['error' => 'transactionId ausente.']);
    exit;
}

$approved = in_array($status, ['PAID', 'APPROVED', 'COMPLETO', 'CONCLUIDO'], true);

if ($approved) {
    $db = getDB();

    try {
        $db->beginTransaction();

        // Busca com FOR UPDATE para evitar concorrência
        $stmt = $db->prepare("SELECT * FROM payments WHERE transaction_id = ? FOR UPDATE");
        $stmt->execute([$txId]);
        $payment = $stmt->fetch();

        if ($payment && $payment['status'] === 'pending') {
            // Atualiza para 'paid' somente se continuar 'pending'
            $stmtUpd = $db->prepare("UPDATE payments SET status = 'paid', updated_at = NOW() WHERE id = ? AND status = 'pending'");
            $stmtUpd->execute([$payment['id']]);

            if ($stmtUpd->rowCount() > 0) {
                // Credita tokens ao usuário
                $stmtUser = $db->prepare("UPDATE users SET balance_tokens = balance_tokens + ? WHERE id = ?");
                $stmtUser->execute([$payment['tokens_amount'], $payment['user_id']]);

                $db->commit();

                // Notificação WhatsApp automática
                try {
                    $stmtUserRow = $db->prepare("SELECT name, whatsapp FROM users WHERE id = ?");
                    $stmtUserRow->execute([$payment['user_id']]);
                    $usr = $stmtUserRow->fetch();
                    if ($usr && !empty($usr['whatsapp'])) {
                        whatsapp_send_pix_approved($usr['whatsapp'], $usr['name'], (int)$payment['tokens_amount'], (float)$payment['amount']);
                    }
                } catch (Exception $e) {}

                echo json_encode(['success' => true, 'message' => 'Pagamento processado e tokens creditados com sucesso.']);
                exit;
            }
        }

        $db->commit();
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(500);
        echo json_encode(['error' => 'Erro interno ao processar transação: ' . $e->getMessage()]);
        exit;
    }
}

echo json_encode(['success' => true, 'message' => 'Notificação recebida.']);
