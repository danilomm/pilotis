<?php
/**
 * Pilotis - Integracao com API do PagBank (PagSeguro)
 *
 * Documentacao: https://dev.pagbank.uol.com.br/reference
 */

class PagBankService {

    /**
     * Retorna CPF formatado ou erro se não informado
     */
    private static function getTaxId(?string $cpf): string {
        if ($cpf) {
            return preg_replace('/\D/', '', $cpf);
        }
        throw new Exception('CPF é obrigatório para gerar pagamento. Volte ao formulário e preencha.');
    }

    /**
     * Retorna headers para autenticacao
     */
    private static function getHeaders(): array {
        return [
            'Authorization: Bearer ' . PAGBANK_TOKEN,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    /**
     * Faz requisicao HTTP
     */
    private static function request(string $method, string $endpoint, ?array $data = null): array {
        $url = PAGBANK_API_URL . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, self::getHeaders());
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Erro cURL: $error");
        }

        $result = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            $errorMsg = $result['error_messages'][0]['description'] ?? $response;
            throw new Exception("Erro PagBank ($httpCode): $errorMsg");
        }

        return $result ?? [];
    }

    /**
     * Cria cobranca PIX no PagBank
     */
    public static function criarCobrancaPix(
        int $cadastrado_id,
        int $ano,
        string $nome,
        string $email,
        ?string $cpf,
        int $valor_centavos,
        int $dias_expiracao = 3
    ): array {
        $reference_id = "PILOTIS-$cadastrado_id-$ano";
        $expiration = date('Y-m-d\T23:59:59-03:00', strtotime("+$dias_expiracao days"));

        $payload = [
            'reference_id' => $reference_id,
            'customer' => [
                'name' => $nome,
                'email' => $email,
            ],
            'items' => [[
                'reference_id' => "filiacao-$ano",
                'name' => "Filiacao " . ORG_NOME . " $ano",
                'quantity' => 1,
                'unit_amount' => $valor_centavos,
            ]],
            'qr_codes' => [[
                'amount' => ['value' => $valor_centavos],
                'expiration_date' => $expiration,
            ]],
        ];

        // Adiciona CPF (obrigatório no PagBank)
        $payload['customer']['tax_id'] = self::getTaxId($cpf);

        // Adiciona webhook se nao for localhost
        if (strpos(BASE_URL, 'localhost') === false) {
            $payload['notification_urls'] = [BASE_URL . '/webhook/pagbank'];
        }

        $data = self::request('POST', '/orders', $payload);

        $qr_codes = $data['qr_codes'] ?? [];
        $qr_code_data = $qr_codes[0] ?? [];

        return [
            'order_id' => $data['id'] ?? '',
            'reference_id' => $reference_id,
            'qr_code' => $qr_code_data['text'] ?? '',
            'qr_code_link' => !empty($qr_code_data['links']) ? $qr_code_data['links'][0]['href'] : '',
            'expiration_date' => $expiration,
        ];
    }

    /**
     * Cria cobranca por boleto no PagBank
     */
    public static function criarCobrancaBoleto(
        int $cadastrado_id,
        int $ano,
        string $nome,
        string $email,
        ?string $cpf,
        int $valor_centavos,
        array $endereco,
        int $dias_vencimento = 7
    ): array {
        $reference_id = "PILOTIS-$cadastrado_id-$ano";
        $due_date = date('Y-m-d', strtotime("+$dias_vencimento days"));

        $payload = [
            'reference_id' => $reference_id,
            'customer' => [
                'name' => $nome,
                'email' => $email,
            ],
            'items' => [[
                'reference_id' => "filiacao-$ano",
                'name' => "Filiacao " . ORG_NOME . " $ano",
                'quantity' => 1,
                'unit_amount' => $valor_centavos,
            ]],
            'charges' => [[
                'reference_id' => $reference_id,
                'description' => "Filiacao " . ORG_NOME . " $ano",
                'amount' => [
                    'value' => $valor_centavos,
                    'currency' => 'BRL',
                ],
                'payment_method' => [
                    'type' => 'BOLETO',
                    'boleto' => [
                        'due_date' => $due_date,
                        'instruction_lines' => [
                            'line_1' => 'Filiacao ' . ORG_NOME,
                            'line_2' => "Ano: $ano",
                        ],
                        'holder' => [
                            'name' => $nome,
                            'tax_id' => $cpf ? preg_replace('/\D/', '', $cpf) : '',
                            'email' => $email,
                            'address' => [
                                'street' => substr($endereco['street'] ?? '', 0, 60),
                                'number' => substr($endereco['number'] ?? 'S/N', 0, 8),
                                'locality' => substr($endereco['locality'] ?? '', 0, 60),
                                'city' => substr($endereco['city'] ?? '', 0, 60),
                                'region' => substr($endereco['region_code'] ?? 'DF', 0, 2),
                                'region_code' => substr($endereco['region_code'] ?? 'DF', 0, 2),
                                'country' => 'BRA',
                                'postal_code' => substr(preg_replace('/\D/', '', $endereco['postal_code'] ?? ''), 0, 8),
                            ],
                        ],
                    ],
                ],
            ]],
        ];

        // Adiciona CPF (obrigatório no PagBank)
        $payload['customer']['tax_id'] = self::getTaxId($cpf);

        // Adiciona webhook se nao for localhost
        if (strpos(BASE_URL, 'localhost') === false) {
            $payload['notification_urls'] = [BASE_URL . '/webhook/pagbank'];
        }

        $data = self::request('POST', '/orders', $payload);

        $charges = $data['charges'] ?? [];
        $charge_data = $charges[0] ?? [];
        $payment_method = $charge_data['payment_method'] ?? [];
        $boleto = $payment_method['boleto'] ?? [];

        // Procura link do PDF
        $boleto_link = '';
        foreach ($charge_data['links'] ?? [] as $link) {
            if (($link['media'] ?? '') === 'application/pdf') {
                $boleto_link = $link['href'] ?? '';
                break;
            }
        }

        return [
            'order_id' => $data['id'] ?? '',
            'charge_id' => $charge_data['id'] ?? '',
            'reference_id' => $reference_id,
            'boleto_link' => $boleto_link,
            'barcode' => $boleto['barcode'] ?? '',
            'due_date' => $due_date,
        ];
    }

    /**
     * Cria cobranca por cartao de credito no PagBank
     */
    public static function criarCobrancaCartao(
        int $cadastrado_id,
        int $ano,
        string $nome,
        string $email,
        ?string $cpf,
        int $valor_centavos,
        string $card_encrypted,
        string $holder_name
    ): array {
        $reference_id = "PILOTIS-$cadastrado_id-$ano";

        $payload = [
            'reference_id' => $reference_id,
            'customer' => [
                'name' => $nome,
                'email' => $email,
            ],
            'items' => [[
                'reference_id' => "filiacao-$ano",
                'name' => "Filiacao " . ORG_NOME . " $ano",
                'quantity' => 1,
                'unit_amount' => $valor_centavos,
            ]],
            'charges' => [[
                'reference_id' => $reference_id,
                'description' => "Filiacao " . ORG_NOME . " $ano",
                'amount' => [
                    'value' => $valor_centavos,
                    'currency' => 'BRL',
                ],
                'payment_method' => [
                    'type' => 'CREDIT_CARD',
                    'installments' => 1,
                    'capture' => true,
                    'card' => [
                        'encrypted' => $card_encrypted,
                        'holder' => [
                            'name' => $holder_name,
                        ],
                    ],
                ],
            ]],
        ];

        // Adiciona CPF (obrigatório no PagBank)
        $payload['customer']['tax_id'] = self::getTaxId($cpf);

        // Adiciona webhook se nao for localhost
        if (strpos(BASE_URL, 'localhost') === false) {
            $payload['notification_urls'] = [BASE_URL . '/webhook/pagbank'];
        }

        $data = self::request('POST', '/orders', $payload);

        $charges = $data['charges'] ?? [];
        $charge_data = $charges[0] ?? [];

        return [
            'order_id' => $data['id'] ?? '',
            'charge_id' => $charge_data['id'] ?? '',
            'reference_id' => $reference_id,
            'status' => $charge_data['status'] ?? '',
        ];
    }

    /**
     * Obtem chave publica para criptografia de cartao
     */
    public static function obterChavePublica(): string {
        if (PAGBANK_SANDBOX) {
            // Chave publica padrao do sandbox
            return 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAr+ZqgD892U9/HXsa7XqBZUayPquAfh9xx4iwUbTSUAvTlmiXFQNTp0Bvt/5vK2FhMj39qSv1zi2OuBjvW38q1E374nzx6NNBL5JosV0+SDINTlCG0cmigHuBOyWzYmjgca+mtQu4WczCaApNaSuVqgb8u7Bd9GCOL4YJotvV5+81frlSwQXralhwRzGhj/A57CGPgGKiuPT+AOGmykIGEZsSD9RKkyoKIoc0OS8CPIzdBOtTQCIwrLn2FxI83Clcg55W8gkFSOS6rWNbG5qFZWMll6yl02HtunalHmUlRUL66YeGXdMDC2PuRcmZbGO5a/2tbVppW6mfSWG3NPRpgwIDAQAB';
        }

        try {
            $data = self::request('POST', '/public-keys', ['type' => 'card']);
            return $data['public_key'] ?? '';
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Consulta status de um pedido
     */
    public static function consultarPedido(string $order_id): array {
        return self::request('GET', "/orders/$order_id");
    }

    /**
     * Processa payload do webhook do PagBank
     */
    public static function parseWebhookPayload(array $payload): array {
        $reference_id = $payload['reference_id'] ?? '';

        // Extrai cadastrado_id e ano do reference_id
        $cadastrado_id = null;
        $ano = null;

        if (strpos($reference_id, 'PILOTIS-') === 0) {
            $parts = explode('-', $reference_id);
            if (count($parts) >= 3) {
                $cadastrado_id = (int)$parts[1];
                $ano = (int)$parts[2];
            }
        }

        // Verifica status das charges
        $charges = $payload['charges'] ?? [];
        $status = !empty($charges) ? ($charges[0]['status'] ?? '') : '';

        return [
            'reference_id' => $reference_id,
            'status' => $status,
            'paid' => $status === 'PAID',
            'cadastrado_id' => $cadastrado_id,
            'ano' => $ano,
            'order_id' => $payload['id'] ?? null,
            'charge_id' => !empty($charges) ? ($charges[0]['id'] ?? null) : null,
        ];
    }
}
