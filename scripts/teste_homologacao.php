<?php
/**
 * Pagina de teste para homologacao PagBank
 *
 * Acessar via navegador: https://pilotis.docomomobrasil.com/scripts/teste_homologacao.php
 *
 * Faz 3 testes:
 * 1. PIX (via PHP direto)
 * 2. Boleto (via PHP direto)
 * 3. Cartao de Credito (criptografado via SDK JavaScript)
 *
 * Gera log no formato Request/Response exigido pela homologacao.
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';

// Sempre sandbox
$token = 'DA11C90123BB49D7AB9B3306E0CB1BA0';
$api_url = 'https://sandbox.api.pagseguro.com';
$public_key = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAr+ZqgD892U9/HXsa7XqBZUayPquAfh9xx4iwUbTSUAvTlmiXFQNTp0Bvt/5vK2FhMj39qSv1zi2OuBjvW38q1E374nzx6NNBL5JosV0+SDINTlCG0cmigHuBOyWzYmjgca+mtQu4WczCaApNaSuVqgb8u7Bd9GCOL4YJotvV5+81frlSwQXralhwRzGhj/A57CGPgGKiuPT+AOGmykIGEZsSD9RKkyoKIoc0OS8CPIzdBOtTQCIwrLn2FxI83Clcg55W8gkFSOS6rWNbG5qFZWMll6yl02HtunalHmUlRUL66YeGXdMDC2PuRcmZbGO5a/2tbVppW6mfSWG3NPRpgwIDAQAB';
$webhook_url = 'https://pilotis.docomomobrasil.com/webhook/pagbank';

// ============================================================
// AJAX: Processar pagamento com cartao criptografado
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cartao') {
    header('Content-Type: application/json');

    $card_encrypted = $_POST['card_encrypted'] ?? '';
    if (empty($card_encrypted)) {
        echo json_encode(['erro' => 'Cartao nao criptografado']);
        exit;
    }

    $request_body = [
        'reference_id' => 'PILOTIS-TEST-CARTAO',
        'customer' => [
            'name'   => 'Jose da Silva',
            'email'  => 'email@test.com',
            'tax_id' => '12345678909',
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
            'unit_amount'  => 5000,
        ]],
        'notification_urls' => [$webhook_url],
        'charges' => [[
            'reference_id' => 'PILOTIS-TEST-CARTAO',
            'description'  => 'Filiacao Docomomo Brasil 2026',
            'amount' => [
                'value'    => 5000,
                'currency' => 'BRL',
            ],
            'payment_method' => [
                'type'         => 'CREDIT_CARD',
                'installments' => 1,
                'capture'      => true,
                'card' => [
                    'encrypted' => $card_encrypted,
                    'holder' => [
                        'name'   => 'Jose da Silva',
                        'tax_id' => '12345678909',
                    ],
                ],
            ],
        ]],
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url . '/orders');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_body));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $response_body = json_decode($response, true);

    echo json_encode([
        'request' => $request_body,
        'response' => $response_body,
        'http_code' => $httpCode,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================================
// AJAX: Testes PIX e Boleto (server-side)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'pix_boleto') {
    header('Content-Type: application/json');

    $log = '';
    $resultados = [];

    // Helper
    $do_request = function($method, $endpoint, $data = null) use ($api_url, $token, &$log) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url . $endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $result = json_decode($response, true);
        return ['request' => $data, 'response' => $result, 'http_code' => $httpCode];
    };

    // PIX
    $pix_data = [
        'reference_id' => 'PILOTIS-TEST-PIX',
        'customer' => [
            'name' => 'Jose da Silva', 'email' => 'email@test.com', 'tax_id' => '12345678909',
            'phones' => [['country' => '55', 'area' => '11', 'number' => '999999999', 'type' => 'MOBILE']],
        ],
        'items' => [['reference_id' => 'filiacao-2026', 'name' => 'Filiacao Docomomo Brasil 2026', 'quantity' => 1, 'unit_amount' => 5000]],
        'qr_codes' => [['amount' => ['value' => 5000], 'expiration_date' => date('Y-m-d\T23:59:59-03:00', strtotime('+3 days'))]],
        'notification_urls' => [$webhook_url],
    ];
    $pix_result = $do_request('POST', '/orders', $pix_data);
    $pix_order_id = $pix_result['response']['id'] ?? '';

    // Boleto
    $boleto_data = [
        'reference_id' => 'PILOTIS-TEST-BOLETO',
        'customer' => [
            'name' => 'Jose da Silva', 'email' => 'email@test.com', 'tax_id' => '12345678909',
            'phones' => [['country' => '55', 'area' => '11', 'number' => '999999999', 'type' => 'MOBILE']],
        ],
        'items' => [['reference_id' => 'filiacao-2026', 'name' => 'Filiacao Docomomo Brasil 2026', 'quantity' => 1, 'unit_amount' => 5000]],
        'notification_urls' => [$webhook_url],
        'charges' => [[
            'reference_id' => 'PILOTIS-TEST-BOLETO', 'description' => 'Filiacao Docomomo Brasil 2026',
            'amount' => ['value' => 5000, 'currency' => 'BRL'],
            'payment_method' => ['type' => 'BOLETO', 'boleto' => [
                'due_date' => date('Y-m-d', strtotime('+3 days')),
                'instruction_lines' => ['line_1' => 'Filiacao Docomomo Brasil', 'line_2' => 'Ano: 2026'],
                'holder' => ['name' => 'Jose da Silva', 'tax_id' => '12345678909', 'email' => 'email@test.com',
                    'address' => ['street' => 'Avenida Brigadeiro Faria Lima', 'number' => '1384', 'complement' => 'apto 12',
                        'locality' => 'Pinheiros', 'city' => 'Sao Paulo', 'region' => 'SP', 'region_code' => 'SP',
                        'country' => 'BRA', 'postal_code' => '01452002']],
            ]],
        ]],
    ];
    $boleto_result = $do_request('POST', '/orders', $boleto_data);

    // Consulta PIX
    $consulta_result = null;
    if ($pix_order_id) {
        $consulta_result = $do_request('GET', '/orders/' . $pix_order_id);
    }

    echo json_encode([
        'pix' => $pix_result,
        'boleto' => $boleto_result,
        'consulta' => $consulta_result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================================
// HTML: Pagina de teste
// ============================================================
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Teste Homologação PagBank</title>
    <style>
        body { font-family: monospace; max-width: 900px; margin: 40px auto; padding: 0 20px; background: #1a1a2e; color: #eee; }
        h1 { color: #00d4aa; }
        button { background: #00d4aa; color: #1a1a2e; border: none; padding: 12px 24px; font-size: 16px; cursor: pointer; border-radius: 4px; font-weight: bold; }
        button:disabled { background: #555; cursor: not-allowed; }
        .log { background: #0d0d1a; border: 1px solid #333; padding: 16px; margin: 16px 0; white-space: pre-wrap; font-size: 13px; max-height: 500px; overflow-y: auto; }
        .ok { color: #00d4aa; }
        .erro { color: #ff6b6b; }
        .info { color: #ffd93d; }
        #status { margin: 16px 0; }
        .copy-btn { background: #333; color: #eee; padding: 6px 12px; font-size: 12px; margin-left: 8px; }
    </style>
</head>
<body>
    <h1>Teste Homologação PagBank (Sandbox)</h1>
    <p>Data: <?= date('Y-m-d H:i:s') ?> | Servidor: <?= $_SERVER['SERVER_NAME'] ?? 'local' ?></p>

    <button id="btnTestar" onclick="executarTestes()">Executar Todos os Testes</button>
    <button class="copy-btn" onclick="copiarLog()">Copiar Log</button>

    <div id="status"></div>
    <div id="log" class="log">Clique em "Executar Todos os Testes" para iniciar...</div>

    <script src="https://assets.pagseguro.com.br/checkout-sdk-js/rc/dist/browser/pagseguro.min.js"></script>
    <script>
    const PUBLIC_KEY = '<?= $public_key ?>';
    let logCompleto = '';

    function addLog(text, cls) {
        const el = document.getElementById('log');
        if (cls) {
            el.innerHTML += '<span class="' + cls + '">' + text + '</span>\n';
        } else {
            el.innerHTML += text + '\n';
        }
        el.scrollTop = el.scrollHeight;
        logCompleto += text + '\n';
    }

    function setStatus(text) {
        document.getElementById('status').innerHTML = '<span class="info">' + text + '</span>';
    }

    function formatJSON(obj) {
        return JSON.stringify(obj, null, 4);
    }

    async function executarTestes() {
        const btn = document.getElementById('btnTestar');
        btn.disabled = true;
        document.getElementById('log').innerHTML = '';
        logCompleto = '';

        // ==========================================
        // TESTE 1 e 2: PIX e Boleto (server-side)
        // ==========================================
        setStatus('Executando testes PIX e Boleto...');
        addLog('Enviando requisições PIX e Boleto ao sandbox...', 'info');

        try {
            const resp = await fetch(window.location.href, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=pix_boleto'
            });
            const data = await resp.json();

            // PIX
            addLog('\n=====================================');
            addLog('TESTE 1: PIX - POST /orders');
            addLog('=====================================\n');
            addLog('Request\n');
            addLog(formatJSON(data.pix.request));
            addLog('\n\nRESPONSE (HTTP ' + data.pix.http_code + ')\n');
            addLog(formatJSON(data.pix.response));
            if (data.pix.http_code === 201) {
                addLog('\n>> PIX criado com sucesso!', 'ok');
            } else {
                addLog('\n>> ERRO no PIX', 'erro');
            }

            // Boleto
            addLog('\n\n=====================================');
            addLog('TESTE 2: BOLETO - POST /orders');
            addLog('=====================================\n');
            addLog('Request\n');
            addLog(formatJSON(data.boleto.request));
            addLog('\n\nRESPONSE (HTTP ' + data.boleto.http_code + ')\n');
            addLog(formatJSON(data.boleto.response));
            if (data.boleto.http_code === 201) {
                addLog('\n>> Boleto criado com sucesso!', 'ok');
            } else {
                addLog('\n>> ERRO no Boleto', 'erro');
            }

            // Consulta
            if (data.consulta) {
                addLog('\n\n=====================================');
                addLog('TESTE 4: CONSULTA - GET /orders/{id}');
                addLog('=====================================\n');
                addLog('Request\n');
                addLog('GET /orders/' + (data.pix.response?.id || ''));
                addLog('\n\nRESPONSE (HTTP ' + data.consulta.http_code + ')\n');
                addLog(formatJSON(data.consulta.response));
                if (data.consulta.http_code === 200) {
                    addLog('\n>> Consulta OK!', 'ok');
                }
            }

        } catch (e) {
            addLog('ERRO: ' + e.message, 'erro');
        }

        // ==========================================
        // TESTE 3: CARTÃO (criptografado no browser)
        // ==========================================
        setStatus('Criptografando cartão de teste...');
        addLog('\n\n=====================================');
        addLog('TESTE 3: CARTÃO DE CRÉDITO - POST /orders');
        addLog('(cartão criptografado via SDK JavaScript)');
        addLog('=====================================\n');

        try {
            // Criptografar cartao de teste
            const card = PagSeguro.encryptCard({
                publicKey: PUBLIC_KEY,
                holder: 'Jose da Silva',
                number: '4539620659922097',
                expMonth: '12',
                expYear: '2026',
                securityCode: '123'
            });

            if (card.hasErrors) {
                addLog('ERRO na criptografia: ' + JSON.stringify(card.errors), 'erro');
                btn.disabled = false;
                return;
            }

            addLog('Cartão criptografado com sucesso. Enviando ao sandbox...', 'info');
            setStatus('Enviando pagamento com cartão criptografado...');

            const resp = await fetch(window.location.href, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=cartao&card_encrypted=' + encodeURIComponent(card.encryptedCard)
            });
            const data = await resp.json();

            addLog('\nRequest\n');
            addLog(formatJSON(data.request));
            addLog('\n\nRESPONSE (HTTP ' + data.http_code + ')\n');
            addLog(formatJSON(data.response));

            const status = data.response?.charges?.[0]?.status || '?';
            if (status === 'PAID' || status === 'AUTHORIZED') {
                addLog('\n>> Cartão APROVADO! Status: ' + status, 'ok');
            } else if (data.http_code === 201) {
                addLog('\n>> Pedido criado. Status: ' + status, 'ok');
            } else {
                addLog('\n>> ERRO no cartão', 'erro');
            }

        } catch (e) {
            addLog('ERRO: ' + e.message, 'erro');
        }

        setStatus('Testes concluídos!');
        addLog('\n\n=====================================');
        addLog('TODOS OS TESTES CONCLUÍDOS');
        addLog('=====================================');
        btn.disabled = false;
    }

    function copiarLog() {
        // Get plain text from log
        const el = document.getElementById('log');
        const text = el.innerText || el.textContent;
        navigator.clipboard.writeText(text).then(() => {
            alert('Log copiado para a área de transferência!');
        });
    }
    </script>
</body>
</html>
