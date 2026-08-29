<?php
/**
 * Pilotis — As quatro formas de falar com o banco.
 *
 * Todo acesso do sistema passa por aqui. Nenhuma regra de negocio nestas
 * funcoes: elas nao sabem o que e uma filiacao.
 *
 * Extraido de src/db.php em 29/08/2026. O db.php continua existindo e
 * incluindo este arquivo, entao todo require antigo segue valendo.
 */

/**
 * Executa query e retorna uma linha
 */
function db_fetch_one(string $sql, array $params = []): ?array {
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch();
    return $result ?: null;
}

/**
 * Executa query e retorna todas as linhas
 */
function db_fetch_all(string $sql, array $params = []): array {
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Executa query de modificação (INSERT, UPDATE, DELETE)
 */
function db_execute(string $sql, array $params = []): int {
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}

/**
 * Insere registro e retorna o ID
 */
function db_insert(string $sql, array $params = []): int {
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return (int) get_db()->lastInsertId();
}
