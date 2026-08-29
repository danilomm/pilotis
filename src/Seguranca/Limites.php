<?php
/**
 * Pilotis — Trava de pedidos, contada no proprio log.
 *
 * A chave TEM de aparecer entre colchetes na mensagem do log da tentativa,
 * senao o contador conta zero para sempre e a trava nunca dispara. E conta-
 * se TENTATIVA, nao envio bem-sucedido.
 *
 * Extraido de src/db.php em 29/08/2026. O db.php continua existindo e
 * incluindo este arquivo, entao todo require antigo segue valendo.
 */

function excedeu_limite_pedidos(string $tipo_log, string $chave, int $maximo = 5, string $janela = '-1 hour'): bool {
    $r = db_fetch_one("
        SELECT COUNT(*) AS n FROM log
        WHERE tipo = ?
        AND mensagem LIKE ?
        AND timestamp >= datetime('now','localtime',?)
    ", [$tipo_log, '%[' . $chave . ']%', $janela]);
    return (int)($r['n'] ?? 0) >= $maximo;
}
