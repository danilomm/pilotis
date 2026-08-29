<?php
/**
 * Endpoint para download do banco (protegido por token do .env).
 *
 * O token NAO fica no codigo: este arquivo e versionado num repositorio
 * publico. Ate 28/08/2026 ele trazia o valor em texto, o que expos o banco
 * inteiro a quem abrisse o repositorio no GitHub. Ver revisao-2026-08-28.
 *
 * Uso: GET /backup-download.php?token=BACKUP_TOKEN
 */

// O layout local (public/ + src/ irmaos) e o do servidor (src/ dentro de www/)
// diferem; resolver os dois evita que o endpoint quebre em um deles.
$config = is_file(__DIR__ . '/src/config.php')
    ? __DIR__ . '/src/config.php'
    : __DIR__ . '/../src/config.php';
require_once $config;

$token_esperado = env('BACKUP_TOKEN', '');
if ($token_esperado === '') {
    http_response_code(500);
    error_log('Pilotis: BACKUP_TOKEN nao configurado no .env');
    die('Backup nao configurado');
}

$token = (string)($_GET['token'] ?? '');
if (!hash_equals($token_esperado, $token)) {
    http_response_code(403);
    error_log('Pilotis: tentativa de backup com token invalido de ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    die('Acesso negado');
}

$db = env('DATABASE_PATH', '');
if ($db === '' || !is_file($db)) {
    $db = '/home/pilotis/dados_privados/pilotis.db';
}
if (!is_file($db)) {
    http_response_code(404);
    die('Banco nao encontrado');
}

// Quem baixou e quando: o endpoint anterior nao deixava rastro nenhum.
error_log('Pilotis: backup baixado por ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));

// O Apache descarta o Content-Length desta resposta, e sem ele o cliente nao
// tem como saber que a transferencia foi cortada: o curl sai com codigo 0 e o
// arquivo fica truncado ou vazio. Em 29/08/2026 foi assim que o backup diario
// commitou uma mensagem de erro por cima do dump bom. O hash vai num cabecalho
// PROPRIO, que o Apache nao mexe, e quem baixa confere o que recebeu.
$hash = hash_file('sha256', $db);

// Desliga qualquer buffer/compressao herdada: em conteudo binario, alem de nao
// comprimir nada, e o que costuma zerar o Content-Length.
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename=pilotis.db');
header('Content-Length: ' . filesize($db));
header('X-Pilotis-SHA256: ' . $hash);
readfile($db);
