<?php
/**
 * Helper de Integração com a API do WhatsApp (Gateway Node.js na porta 3000)
 * Inclui validação inteligente de DDD brasileiro e regra do 9º dígito.
 */

require_once __DIR__ . '/helpers.php';

function get_whatsapp_api_url(): string {
    $custom = getSetting('whatsapp_api_url', '');
    if (!empty($custom) && stripos($custom, 'localhost') === false) {
        return rtrim($custom, '/');
    }
    return getGatewayInternalUrl();
}

function get_whatsapp_default_instance(): string {
    $db = getDB();
    $stmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'whatsapp_default_instance' LIMIT 1");
    $key = $stmt ? $stmt->fetchColumn() : null;
    if ($key && trim($key)) return trim($key);

    // Fallback: primeira instância conectada
    try {
        $stmtInst = $db->query("SELECT instance_key FROM whatsapp_instances WHERE status = 'connected' ORDER BY id DESC LIMIT 1");
        $instKey = $stmtInst ? $stmtInst->fetchColumn() : null;
        if ($instKey) return $instKey;

        $stmtAny = $db->query("SELECT instance_key FROM whatsapp_instances ORDER BY id DESC LIMIT 1");
        $anyKey = $stmtAny ? $stmtAny->fetchColumn() : null;
        if ($anyKey) return $anyKey;
    } catch (Exception $e) {}

    return 'inst_default';
}

/**
 * Normaliza número de telefone brasileiro com inteligência de DDD e 9º dígito
 * - DDDs 11 a 28 (SP, RJ, ES): 9º dígito é obrigatório. Se tiver 8 dígitos, adiciona o 9.
 * - DDDs > 28: Mantém e suporta fallback com/sem o 9.
 */
function format_whatsapp_number(string $phone): string {
    $clean = preg_replace('/\D/', '', $phone);
    if (empty($clean)) return '';

    if (str_starts_with($clean, '0')) {
        $clean = substr($clean, 1);
    }

    if (str_starts_with($clean, '55') && strlen($clean) > 11) {
        $clean = substr($clean, 2);
    }

    if (strlen($clean) === 10 || strlen($clean) === 11) {
        $ddd = (int)substr($clean, 0, 2);
        $rest = substr($clean, 2);

        // DDDs 11 a 28 (SP, RJ, ES) exigem estritamente o 9º dígito
        if ($ddd >= 11 && $ddd <= 28) {
            if (strlen($rest) === 8) {
                $clean = $ddd . '9' . $rest;
            }
        }
    }

    return '55' . $clean;
}

/**
 * Retorna as variantes para números que podem ter ou não o 9º dígito no WhatsApp (DDD > 28)
 */
function get_whatsapp_number_variants(string $phone): array {
    $formatted = format_whatsapp_number($phone);
    if (strlen($formatted) < 12) return [$formatted];

    $country = substr($formatted, 0, 2); // 55
    $ddd = (int)substr($formatted, 2, 2);
    $rest = substr($formatted, 4);

    $variants = [$formatted];

    // Para DDDs acima de 28, gera a versão alternativa com/sem o 9
    if ($ddd > 28) {
        if (strlen($rest) === 9 && str_starts_with($rest, '9')) {
            $variants[] = $country . $ddd . substr($rest, 1);
        } elseif (strlen($rest) === 8) {
            $variants[] = $country . $ddd . '9' . $rest;
        }
    }

    return array_unique($variants);
}

function whatsapp_api_request(string $endpoint, string $method = 'GET', array $data = []): array {
    $baseUrl = get_whatsapp_api_url();
    $url = $baseUrl . '/' . ltrim($endpoint, '/');

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    if (($method === 'POST' || $method === 'PUT') && !empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['success' => false, 'error' => "Erro de conexão com API WhatsApp: {$error}", 'http_code' => $httpCode];
    }

    $json = json_decode($response, true);
    return is_array($json) ? array_merge(['http_code' => $httpCode], $json) : ['success' => ($httpCode >= 200 && $httpCode < 300), 'raw' => $response];
}

function whatsapp_send_text(string $phone, string $message, ?string $instanceKey = null): array {
    $variants = get_whatsapp_number_variants($phone);
    if (empty($variants)) {
        return ['success' => false, 'error' => 'Número de WhatsApp inválido.'];
    }

    $instKey = $instanceKey ?: get_whatsapp_default_instance();
    $primaryNumber = $variants[0];

    $res = whatsapp_api_request("instances/{$instKey}/send", 'POST', [
        'to' => $primaryNumber . '@s.whatsapp.net',
        'message' => $message
    ]);

    // Fallback inteligente para a 2ª variante (com/sem 9) se a primeira retornar falha
    if (isset($res['success']) && !$res['success'] && count($variants) > 1) {
        $fallbackNumber = $variants[1];
        $fallbackRes = whatsapp_api_request("instances/{$instKey}/send", 'POST', [
            'to' => $fallbackNumber . '@s.whatsapp.net',
            'message' => $message
        ]);
        if (!empty($fallbackRes['success'])) {
            return $fallbackRes;
        }
    }

    return $res;
}

function send_whatsapp_otp(string $phone): array {
    $db = getDB();
    $cleanPhone = format_whatsapp_number($phone);

    if (empty($cleanPhone) || strlen($cleanPhone) < 10) {
        return ['success' => false, 'error' => 'Número de WhatsApp inválido. Digite DDD + Número.'];
    }

    $otpCode = (string) random_int(100000, 999999);
    $expiresAt = date('Y-m-d H:i:s', time() + (10 * 60)); // 10 min

    $stmt = $db->prepare("INSERT INTO whatsapp_otps (whatsapp, otp_code, expires_at) VALUES (?, ?, ?)");
    $stmt->execute([$cleanPhone, $otpCode, $expiresAt]);

    // Template customizado do banco de dados
    $template = getSetting('whatsapp_msg_otp', "🔐 *Seu Código de Confirmação BRLove API:* *{codigo}*\n\nDigite este código no site para confirmar o seu acesso.\n\n_Válido por 10 minutos._");
    $message = str_replace('{codigo}', $otpCode, $template);

    $res = whatsapp_send_text($cleanPhone, $message);

    // Nunca expor o código OTP na resposta pública por motivos críticos de segurança
    if (isset($res['success']) && !$res['success']) {
        return [
            'success' => false,
            'error' => 'Não foi possível enviar a mensagem no WhatsApp. Verifique se o número está correto e tente novamente.'
        ];
    }

    return [
        'success' => true,
        'message' => 'Código de verificação enviado para seu WhatsApp!'
    ];
}

function whatsapp_send_welcome(string $phone, string $name): array {
    $template = getSetting('whatsapp_msg_welcome', "👋 Olá, *{nome}*! Bem-vindo à plataforma *BRLove API* de Inteligência Artificial.\nSua conta foi criada com sucesso e já está pronta para uso! Acesse seu painel e confira sua chave de API.");
    $message = str_replace(['{nome}', '{whatsapp}'], [$name, $phone], $template);
    return whatsapp_send_text($phone, $message);
}

function whatsapp_send_pix_approved(string $phone, string $name, int $tokens, float $amount): array {
    $template = getSetting('whatsapp_msg_pix', "✅ *Pagamento Confirmado!*\n\nOlá *{nome}*, seu pagamento de *R$ {valor}* via PIX foi aprovado com sucesso!\nForam creditados *{tokens} tokens* em sua conta. Bons usos! 🚀");
    $tokensFormatted = number_format($tokens, 0, ',', '.');
    $amountFormatted = number_format($amount, 2, ',', '.');
    $message = str_replace(['{nome}', '{tokens}', '{valor}'], [$name, $tokensFormatted, $amountFormatted], $template);
    return whatsapp_send_text($phone, $message);
}

function whatsapp_send_low_balance(string $phone, string $name, int $balance): array {
    $template = getSetting('whatsapp_msg_low_balance', "⚠️ *Alerta de Saldo BRLove API*\n\nOlá *{nome}*, seu saldo de tokens está em *{saldo}*. Para evitar pausas nas suas aplicações de IA, faça uma recarga no seu painel.");
    $balanceFormatted = number_format($balance, 0, ',', '.');
    $message = str_replace(['{nome}', '{saldo}'], [$name, $balanceFormatted], $template);
    return whatsapp_send_text($phone, $message);
}

function verify_whatsapp_otp(string $phone, string $code): bool {
    $db = getDB();
    $cleanPhone = format_whatsapp_number($phone);

    $stmt = $db->prepare("
        SELECT id FROM whatsapp_otps
        WHERE (whatsapp = ? OR whatsapp = ?) AND otp_code = ? AND is_used = 0 AND expires_at > NOW()
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$phone, $cleanPhone, trim($code)]);
    $otp = $stmt->fetch();

    if ($otp) {
        $stmtUse = $db->prepare("UPDATE whatsapp_otps SET is_used = 1 WHERE id = ?");
        $stmtUse->execute([$otp['id']]);

        $stmtUser = $db->prepare("UPDATE users SET is_whatsapp_verified = 1 WHERE whatsapp = ? OR whatsapp = ?");
        $stmtUser->execute([$phone, $cleanPhone]);

        return true;
    }

    return false;
}
