<?php
/**
 * Fecha a campanha de um ano: muda `campanhas.status` para 'fechada'.
 *
 * POR QUE UM SCRIPT, e nao o botao "Fechar Campanha" do admin: o botao tambem
 * varre `filiacoes` e marca como 'nao_pago' tudo que nao esta pago. Aqui a
 * campanha 2026 ja passou por esse fechamento em julho; o que sobrou foi o
 * `status` da campanha, que voltou para 'aberta' na repescagem dos dirigentes
 * e nunca foi revertido. Reescrever os status das filiacoes de novo apagaria
 * 'acesso' e 'pendente' -- o registro de ate onde cada pessoa chegou.
 *
 * Roda por CLI ou por HTTP (o servidor nao tem SSH):
 *
 *   php scripts/manutencao/fechar-campanha.php --ano=2026
 *   php scripts/manutencao/fechar-campanha.php --ano=2026 --executar
 *
 *   https://.../fechar-campanha.php?token=7774824bfd910b2afaf01430&ano=2026
 *   https://.../fechar-campanha.php?token=7774824bfd910b2afaf01430&ano=2026&executar=1
 *
 * Padrao e SEMPRE ensaio. APAGAR do servidor depois de usar.
 */

// Token de uso unico, so deste arquivo: evita depender do .env e some junto
// com o script.
const TOKEN_MANUTENCAO = '7774824bfd910b2afaf01430';

$via_http = PHP_SAPI !== 'cli';

// No servidor o src/ fica DENTRO de www/; local ele fica acima de scripts/.
$base = __DIR__ . '/../../src/config.php';
if (!file_exists($base)) {
    $base = __DIR__ . '/src/config.php';   // layout do servidor
}
require_once $base;
require_once dirname($base) . '/db.php';

if ($via_http) {
    header('Content-Type: text/plain; charset=utf-8');
    if (!hash_equals(TOKEN_MANUTENCAO, (string)($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit("Acesso negado.\n");
    }
    $ano = (int)($_GET['ano'] ?? 0);
    $executar = !empty($_GET['executar']);
} else {
    $ano = 0;
    foreach ($argv ?? [] as $a) {
        if (preg_match('/^--ano=(\d{4})$/', $a, $m)) $ano = (int)$m[1];
    }
    $executar = in_array('--executar', $argv ?? [], true);
}

if ($ano < 2020) {
    exit("Informe o ano: --ano=2026 (CLI) ou &ano=2026 (HTTP).\n");
}

function retrato_campanha(int $ano): string {
    $c = db_fetch_one("SELECT ano, status FROM campanhas WHERE ano = ?", [$ano]);
    if (!$c) return "  campanha $ano nao existe\n";

    $out = sprintf("  campanha %d: status = %s\n", $c['ano'], $c['status']);
    foreach (db_fetch_all("SELECT status, COUNT(*) AS n FROM filiacoes WHERE ano = ? GROUP BY status ORDER BY n DESC", [$ano]) as $f) {
        $out .= sprintf("      filiacoes %-10s %4d\n", $f['status'], $f['n']);
    }
    $l = db_fetch_one("SELECT COUNT(*) AS n FROM lembretes_agendados la
                       JOIN filiacoes f ON f.id = la.filiacao_id
                       WHERE f.ano = ? AND la.enviado = 0", [$ano]);
    $out .= sprintf("      lembretes pendentes: %d\n", (int)($l['n'] ?? 0));
    return $out;
}

echo $executar ? "=== EXECUCAO REAL ===\n\n" : "=== ENSAIO (nada sera alterado) ===\n\n";
echo "ANTES\n" . retrato_campanha($ano) . "\n";

if (!$executar) {
    echo "Mudanca prevista: campanhas.status -> 'fechada' (so isso).\n";
    echo "As filiacoes NAO sao tocadas.\n\n";
    echo "Para aplicar: acrescente --executar (CLI) ou &executar=1 (HTTP).\n";
    exit;
}

$alteradas = db_execute("UPDATE campanhas SET status = 'fechada' WHERE ano = ? AND status <> 'fechada'", [$ano]);
registrar_log('campanha_fechada', null, "Campanha $ano fechada por script de manutencao (apenas status)");

echo "OK: $alteradas campanha(s) alterada(s).\n\n";
echo "DEPOIS\n" . retrato_campanha($ano) . "\n";
echo "NAO ESQUECA DE APAGAR ESTE ARQUIVO DO SERVIDOR.\n";
