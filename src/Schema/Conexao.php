<?php
/**
 * Pilotis — Abertura da conexao e leitura da estrutura do banco.
 *
 * Fica separado da migracao porque sao coisas diferentes: aqui e "como se
 * fala com o banco", la e "que forma o banco tem".
 *
 * Extraido de src/db.php em 29/08/2026. O db.php continua existindo e
 * incluindo este arquivo, entao todo require antigo segue valendo.
 */

/**
 * Retorna conexão PDO com o banco SQLite
 */
function get_db(): PDO {
    global $_db;

    if ($_db === null) {
        $dbPath = DATABASE_PATH;

        // Cria diretório se não existir
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $_db = new PDO("sqlite:$dbPath");
        $_db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $_db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $_db->exec("PRAGMA journal_mode = WAL");
        $_db->exec("PRAGMA busy_timeout = 5000");

        // Garante que tabelas auxiliares existam — so quando o schema mudou.
        garantir_schema($_db);
    }

    return $_db;
}

/**
 * Nomes das colunas de uma tabela, ou lista vazia se ela não existe.
 *
 * POR QUE existe: `PRAGMA table_info` de tabela inexistente devolve conjunto
 * VAZIO, não erro. Lido cru, isso vira "falta a coluna" e dispara o rebuild
 * destrutivo mais abaixo sobre uma tabela que não está lá — o INSERT..SELECT
 * falha, a transação volta atrás e a migração inteira aborta. Lista vazia aqui
 * significa "nada a migrar", que é a leitura certa.
 *
 * @return string[]
 */
function colunas_da_tabela(PDO $db, string $tabela): array {
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tabela)) {
        return [];
    }
    try {
        $res = $db->query("PRAGMA table_info($tabela)");
        if ($res === false) {
            return [];
        }
        $linhas = $res->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
    return $linhas ? array_column($linhas, 'name') : [];
}

/**
 * Cria tabelas auxiliares se não existirem
 */
