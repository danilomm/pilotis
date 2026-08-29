<?php
/**
 * Endpoint one-shot para fechar a campanha em data especifica.
 * So executa se GET['data'] bater com hoje (evita re-execucao em anos futuros).
 *
 * Uso: GET /fechar-campanha.php?token=CRON_TOKEN&ano=2026&data=2026-07-20
 */

chdir(__DIR__);
require_once __DIR__ . "/src/config.php";

header("Content-Type: application/json; charset=utf-8");

$token_esperado = env("CRON_TOKEN", "");
if (empty($token_esperado)) {
    http_response_code(500);
    die(json_encode(["erro" => "CRON_TOKEN nao configurado"]));
}

$token = $_GET["token"] ?? "";
if (!hash_equals($token_esperado, $token)) {
    http_response_code(403);
    die(json_encode(["erro" => "Acesso negado"]));
}

$data_esperada = $_GET["data"] ?? "";
$hoje = date("Y-m-d");
if ($data_esperada !== $hoje) {
    echo json_encode([
        "status" => "pulado",
        "motivo" => "data_esperada nao bate com hoje",
        "hoje" => $hoje,
        "esperada" => $data_esperada,
    ]);
    exit;
}

$ano = (int)($_GET["ano"] ?? date("Y"));

require_once __DIR__ . "/src/db.php";

$campanha = db_fetch_one("SELECT status FROM campanhas WHERE ano = ?", [$ano]);
if (!$campanha) {
    http_response_code(404);
    die(json_encode(["erro" => "Campanha $ano nao encontrada"]));
}

$rows = db_execute("UPDATE campanhas SET status='fechada' WHERE ano=?", [$ano]);

registrar_log('campanha_encerrada', null, "Campanha $ano encerrada automaticamente");

echo json_encode([
    "status" => "ok",
    "ano" => $ano,
    "status_anterior" => $campanha['status'],
    "novo_status" => "fechada",
    "rows" => $rows,
    "timestamp" => date("c"),
]);
