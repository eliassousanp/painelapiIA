<?php
/**
 * Mistic Pay Client — Helper de integração com a gateway Mistic Pay (PIX).
 */

class MisticPayClient
{
    private string $clientId;
    private string $clientSecret;
    private string $baseUrl = 'https://api.misticpay.com/api';

    public function __construct(string $clientId, string $clientSecret)
    {
        $this->clientId = trim($clientId);
        $this->clientSecret = trim($clientSecret);
    }

    private function request(string $endpoint, string $method = 'POST', array $data = []): array
    {
        $curl = curl_init();

        $headers = [
            'Content-Type: application/json',
            'ci: ' . $this->clientId,
            'cs: ' . $this->clientSecret
        ];

        $options = [
            CURLOPT_URL => $this->baseUrl . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
        }

        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if ($err) {
            return [
                'status' => 'error',
                'success' => false,
                'http_code' => $httpCode,
                'message' => 'Erro de conexão cURL: ' . $err,
            ];
        }

        $decoded = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return array_merge([
                'http_code' => $httpCode,
                'status' => 'success',
                'success' => true
            ], is_array($decoded) ? $decoded : ['raw' => $response]);
        }

        $errMsg = $decoded['message'] ?? $decoded['error'] ?? ('Erro HTTP ' . $httpCode);

        return [
            'status' => 'error',
            'success' => false,
            'http_code' => $httpCode,
            'message' => $errMsg,
            'response' => $decoded
        ];
    }

    /**
     * Gera cobrança PIX na Mistic Pay.
     */
    public function createPix(float $amount, array $payerData, string $transactionId, string $description): array
    {
        $doc = preg_replace('/[^0-9]/', '', $payerData['document'] ?? '');
        if (strlen($doc) !== 11 && strlen($doc) !== 14) {
            $doc = '04868204599'; // CPF válido padrão para checkout simplificado
        }

        $payload = [
            "amount" => floatval(number_format($amount, 2, '.', '')),
            "payerName" => trim(substr($payerData['name'] ?? 'Cliente', 0, 100)),
            "payerDocument" => $doc,
            "transactionId" => (string) $transactionId,
            "description" => trim(substr($description, 0, 255)),
        ];

        return $this->request('/transactions/create', 'POST', $payload);
    }

    /**
     * Verifica status de uma transação na Mistic Pay.
     */
    public function checkStatus(string $transactionId): array
    {
        return $this->request('/transactions/check', 'POST', [
            "transactionId" => (string) $transactionId
        ]);
    }
}
