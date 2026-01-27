#!/usr/bin/env php
<?php
/**
 * Script de teste para homologacao PagBank
 *
 * Faz requisicoes no sandbox e gera log no formato exigido pela homologacao:
 * Request JSON + Response JSON para cada operacao.
 *
 * Uso:
 *   php scripts/teste_sandbox_pagbank.php
 *   php scripts/teste_sandbox_pagbank.php --token TOKEN_SANDBOX
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';

$opts = getopt('', ['token:', 'help']);
if (isset($opts['help'])) {
    echo "Uso: php scripts/teste_sandbox_pagbank.php [--token TOKEN]\n";
    exit(0);
}

$token = $opts['token'] ?? PAGBANK_TOKEN;
$api_url = 'https://sandbox.api.pagseguro.com';
$log = '';
$webhook_url = rtrim(BASE_URL, '/') . '/webhook/pagbank';

if (empty($token)) {
    echo "ERRO: Token nao configurado. Use --token TOKEN\n";
    exit(1);
}

echo "=== Teste de Homologacao PagBank (Sandbox) ===\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n";
echo "API URL: $api_url\n\n";

function pagbank_request(string $method, string $endpoint, ?array $data = null): array {
    global $api_url, $token, $log;

    $url = $api_url . $endpoint;
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
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

    // Log no formato exigido
    $log .= "=====================================\n";
    $log .= "$method $endpoint\n";
    $log .= "=====================================\n\n";

    $log .= "Request\n\n";
    if ($data) {
        $log .= json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        $log .= "(sem body)\n";
    }

    $log .= "\n\nRESPONSE (HTTP $httpCode)\n\n";
    $result = json_decode($response, true);
    $log .= json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    $log .= "\n\n";

    if ($httpCode < 200 || $httpCode >= 300) {
        $errorMsg = $result['error_messages'][0]['description']
            ?? $result['error_messages'][0]['message']
            ?? $response;
        throw new Exception("Erro PagBank ($httpCode): $errorMsg");
    }

    return $result ?? [];
}

// Dados de teste
$nome = 'Jose da Silva';
$email = 'email@test.com';
$cpf = '12345678909';
$valor = 5000; // R$ 50,00

$pix_order_id = null;

// ============================================================
// TESTE 1: PIX
// ============================================================
echo "1. PIX... ";
try {
    $expiration = date('Y-m-d\T23:59:59-03:00', strtotime('+3 days'));
    $data = pagbank_request('POST', '/orders', [
        'reference_id' => 'PILOTIS-TEST-PIX',
        'customer' => [
            'name'   => $nome,
            'email'  => $email,
            'tax_id' => $cpf,
            'phones' => [[
                'country' => '55',
                'area'    => '11',
                'number'  => '999999999',
                'type'    => 'MOBILE',
            ]],
        ],
        'items' => [[
            'reference_id' => 'filiacao-2026',
            'name'         => 'Filiacao Docomomo Brasil 2026',
            'quantity'     => 1,
            'unit_amount'  => $valor,
        ]],
        'qr_codes' => [[
            'amount' => ['value' => $valor],
            'expiration_date' => $expiration,
        ]],
        'notification_urls' => [$webhook_url],
    ]);
    $pix_order_id = $data['id'] ?? '';
    echo "OK (Order: $pix_order_id)\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

// ============================================================
// TESTE 2: BOLETO
// ============================================================
echo "2. Boleto... ";
try {
    $due_date = date('Y-m-d', strtotime('+3 days'));
    $data = pagbank_request('POST', '/orders', [
        'reference_id' => 'PILOTIS-TEST-BOLETO',
        'customer' => [
            'name'   => $nome,
            'email'  => $email,
            'tax_id' => $cpf,
            'phones' => [[
                'country' => '55',
                'area'    => '11',
                'number'  => '999999999',
                'type'    => 'MOBILE',
            ]],
        ],
        'items' => [[
            'reference_id' => 'filiacao-2026',
            'name'         => 'Filiacao Docomomo Brasil 2026',
            'quantity'     => 1,
            'unit_amount'  => $valor,
        ]],
        'notification_urls' => [$webhook_url],
        'charges' => [[
            'reference_id' => 'PILOTIS-TEST-BOLETO',
            'description'  => 'Filiacao Docomomo Brasil 2026',
            'amount' => [
                'value'    => $valor,
                'currency' => 'BRL',
            ],
            'payment_method' => [
                'type' => 'BOLETO',
                'boleto' => [
                    'due_date' => $due_date,
                    'instruction_lines' => [
                        'line_1' => 'Filiacao Docomomo Brasil',
                        'line_2' => 'Ano: 2026',
                    ],
                    'holder' => [
                        'name'   => $nome,
                        'tax_id' => $cpf,
                        'email'  => $email,
                        'address' => [
                            'street'      => 'Avenida Brigadeiro Faria Lima',
                            'number'      => '1384',
                            'complement'  => 'apto 12',
                            'locality'    => 'Pinheiros',
                            'city'        => 'Sao Paulo',
                            'region'      => 'SP',
                            'region_code' => 'SP',
                            'country'     => 'BRA',
                            'postal_code' => '01452002',
                        ],
                    ],
                ],
            ],
        ]],
    ]);
    $order_id = $data['id'] ?? '';
    echo "OK (Order: $order_id)\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

// ============================================================
// TESTE 3: CARTAO DE CREDITO
// ============================================================
echo "3. Cartao... ";
try {
    $data = pagbank_request('POST', '/orders', [
        'reference_id' => 'PILOTIS-TEST-CARTAO',
        'customer' => [
            'name'   => $nome,
            'email'  => $email,
            'tax_id' => $cpf,
            'phones' => [[
                'country' => '55',
                'area'    => '11',
                'number'  => '999999999',
                'type'    => 'MOBILE',
            ]],
        ],
        'items' => [[
            'reference_id' => 'filiacao-2026',
            'name'         => 'Filiacao Docomomo Brasil 2026',
            'quantity'     => 1,
            'unit_amount'  => $valor,
        ]],
        'notification_urls' => [$webhook_url],
        'charges' => [[
            'reference_id' => 'PILOTIS-TEST-CARTAO',
            'description'  => 'Filiacao Docomomo Brasil 2026',
            'amount' => [
                'value'    => $valor,
                'currency' => 'BRL',
            ],
            'payment_method' => [
                'type'         => 'CREDIT_CARD',
                'installments' => 1,
                'capture'      => true,
                'card' => [
                    'number'        => '4539620659922097',
                    'exp_month'     => '12',
                    'exp_year'      => '2026',
                    'security_code' => '123',
                    'holder' => [
                        'name'   => $nome,
                        'tax_id' => $cpf,
                    ],
                ],
            ],
        ]],
    ]);
    $charge = $data['charges'][0] ?? [];
    $status = $charge['status'] ?? '?';
    echo "OK (Status: $status)\n";
} catch (Exception $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

// ============================================================
// TESTE 4: CONSULTAR PEDIDO
// ============================================================
if ($pix_order_id) {
    echo "4. Consulta... ";
    try {
        $data = pagbank_request('GET', "/orders/$pix_order_id");
        echo "OK\n";
    } catch (Exception $e) {
        echo "ERRO: " . $e->getMessage() . "\n";
    }
}

// Salvar log
$log_file = __DIR__ . '/../dados/log-homologacao-pagbank.txt';
file_put_contents($log_file, $log);
echo "\nLog salvo em: $log_file\n";
