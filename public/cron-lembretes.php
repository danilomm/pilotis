<?php
/**
 * Endpoint para processamento automatico de lembretes via GitHub Actions.
 *
 * Seguro para rodar N vezes: cada lembrete e marcado como enviado ANTES
 * do envio, entao reexecucoes nao duplicam emails.
 *
 * Uso: GET /cron-lembretes.php?token=CRON_TOKEN
 */

// Carrega config para ter acesso ao env()
chdir(__DIR__);
require_once __DIR__ . "/src/config.php";

// Verifica token (obrigatorio via .env, sem fallback)
$token_esperado = env("CRON_TOKEN", "");
if (empty($token_esperado)) {
    http_response_code(500);
    header("Content-Type: application/json");
    die(json_encode(["erro" => "CRON_TOKEN nao configurado no .env"]));
}

$token = $_GET["token"] ?? "";
if (!hash_equals($token_esperado, $token)) {
    http_response_code(403);
    header("Content-Type: application/json");
    die(json_encode(["erro" => "Acesso negado"]));
}
require_once __DIR__ . "/src/db.php";

// ---------------------------------------------------------------------------
// Manutencao: este arquivo NAO passa pelo index.php, entao a checagem tem de
// estar aqui.
//
// Ate 31/08/2026 nao estava: com o site "fora do ar", os endpoints avulsos
// continuavam respondendo — o de verificar pagamentos escreve no banco e manda
// email a cada 15 minutos. "Fora do ar" nao queria dizer "banco parado", e o
// interruptor dava a impressao de que sim.
//
// 503 e o codigo certo: diz ao GitHub Actions que a chamada nao valeu e que ele
// pode tentar de novo, em vez de registrar sucesso sobre trabalho nao feito.
// ---------------------------------------------------------------------------
if (function_exists('em_manutencao') && em_manutencao()) {
    http_response_code(503);
    header('Retry-After: 1800');
    header('Content-Type: application/json');
    echo json_encode(['status' => 'manutencao', 'motivo' => 'Sistema em manutencao; nada foi processado.']);
    exit;
}
require_once SRC_DIR . "/Services/LembreteService.php";

header("Content-Type: application/json; charset=utf-8");

try {
    // 1. Lembretes normais (sem limite de cota — sao poucos)
    $normais = LembreteService::processar(50, 'outros');

    // 2. Lembretes de ultima chance (limite 280/dia pela cota Brevo)
    $ultima = LembreteService::processar(280, 'ultima_chance');

    $resposta = [
        "ok" => true,
        "normais" => $normais["enviados"],
        "ultima_chance" => $ultima["enviados"],
        "erros" => $normais["erros"] + $ultima["erros"],
        "pulados" => $normais["pulados"] + $ultima["pulados"],
        "timestamp" => date("c"),
    ];

    $total_env = $normais["enviados"] + $ultima["enviados"];
    $total_err = $normais["erros"] + $ultima["erros"];
    registrar_log("cron_lembretes", null,
        "Cron lembretes: {$normais['enviados']} normais + {$ultima['enviados']} ultima_chance enviados, $total_err erros"
    );

    echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "erro" => $e->getMessage(),
        "timestamp" => date("c"),
    ]);
}
