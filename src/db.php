<?php
/**
 * Pilotis - Conexão com banco de dados SQLite
 *
 * Schema existente:
 * - pessoas (id, nome, cpf, token, ativo, notas, created_at, updated_at)
 * - emails (id, pessoa_id, email, principal)
 * - filiacoes (id, pessoa_id, ano, categoria, valor, data_pagamento, metodo, pagbank_id, ...)
 */

require_once __DIR__ . '/config.php';

// Conexão singleton
$_db = null;

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

        // Garante que tabelas auxiliares existam
        init_extra_tables($_db);
    }

    return $_db;
}

/**
 * Cria tabelas auxiliares se não existirem
 */
function init_extra_tables(PDO $db): void {
    // Tabela de log
    $db->exec("
        CREATE TABLE IF NOT EXISTS log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            tipo TEXT NOT NULL,
            pessoa_id INTEGER,
            mensagem TEXT
        );
    ");

    // Tabela de campanhas
    $db->exec("
        CREATE TABLE IF NOT EXISTS campanhas (
            ano INTEGER PRIMARY KEY,
            status TEXT DEFAULT 'aberta',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    // Cria campanhas para anos que já têm filiações (se não existirem)
    $db->exec("
        INSERT OR IGNORE INTO campanhas (ano, status)
        SELECT DISTINCT ano,
            CASE WHEN ano < strftime('%Y', 'now') THEN 'fechada' ELSE 'aberta' END
        FROM filiacoes
        WHERE ano IS NOT NULL
    ");

    // Adiciona colunas extras na filiacoes se não existirem
    try {
        $db->exec("ALTER TABLE filiacoes ADD COLUMN status TEXT DEFAULT 'pendente'");
    } catch (PDOException $e) {}

    try {
        $db->exec("ALTER TABLE filiacoes ADD COLUMN pagbank_order_id TEXT");
    } catch (PDOException $e) {}

    try {
        $db->exec("ALTER TABLE filiacoes ADD COLUMN pagbank_charge_id TEXT");
    } catch (PDOException $e) {}

    try {
        $db->exec("ALTER TABLE filiacoes ADD COLUMN pagbank_boleto_link TEXT");
    } catch (PDOException $e) {}

    try {
        $db->exec("ALTER TABLE filiacoes ADD COLUMN pagbank_boleto_barcode TEXT");
    } catch (PDOException $e) {}

    try {
        $db->exec("ALTER TABLE filiacoes ADD COLUMN data_vencimento TEXT");
    } catch (PDOException $e) {}

    // Atualiza status baseado em data_pagamento
    $db->exec("UPDATE filiacoes SET status = 'pago' WHERE data_pagamento IS NOT NULL AND status IS NULL");
    $db->exec("UPDATE filiacoes SET status = 'pendente' WHERE data_pagamento IS NULL AND status IS NULL");
}

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

/**
 * Registra entrada no log
 */
function registrar_log(string $tipo, ?int $pessoa_id = null, string $mensagem = ''): void {
    db_execute(
        "INSERT INTO log (tipo, cadastrado_id, mensagem) VALUES (?, ?, ?)",
        [$tipo, $pessoa_id, $mensagem]
    );
}

// === Funções de busca ===

/**
 * Busca pessoa por email
 * Dados cadastrais são buscados da última filiação que tenha dados preenchidos
 */
function buscar_pessoa_por_email(string $email): ?array {
    $email = strtolower(trim($email));

    // Busca na tabela emails
    $result = db_fetch_one("
        SELECT p.*, e.email
        FROM pessoas p
        JOIN emails e ON e.pessoa_id = p.id
        WHERE LOWER(e.email) = ?
    ", [$email]);

    if ($result) {
        // Busca última filiação COM dados cadastrais preenchidos
        // (evita herdar de registros vazios criados pelo envio de campanha)
        $filiacao = db_fetch_one("
            SELECT telefone, endereco, cep, cidade, estado, pais,
                   profissao, formacao, instituicao, categoria
            FROM filiacoes
            WHERE pessoa_id = ?
            AND (telefone IS NOT NULL OR endereco IS NOT NULL OR cidade IS NOT NULL
                 OR profissao IS NOT NULL OR instituicao IS NOT NULL)
            ORDER BY ano DESC
            LIMIT 1
        ", [$result['id']]);

        if ($filiacao) {
            $result = array_merge($result, $filiacao);
        }
    }

    return $result;
}

/**
 * Busca pessoa por token
 * Dados cadastrais são buscados da última filiação que tenha dados preenchidos
 */
function buscar_pessoa_por_token(string $token): ?array {
    $result = db_fetch_one("
        SELECT p.*, e.email
        FROM pessoas p
        LEFT JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
        WHERE p.token = ?
    ", [$token]);

    if ($result) {
        // Se não tem email principal, pega qualquer um
        if (!$result['email']) {
            $email = db_fetch_one("SELECT email FROM emails WHERE pessoa_id = ? LIMIT 1", [$result['id']]);
            $result['email'] = $email['email'] ?? '';
        }

        // Busca última filiação COM dados cadastrais preenchidos
        // (evita herdar de registros vazios criados pelo envio de campanha)
        $filiacao = db_fetch_one("
            SELECT telefone, endereco, cep, cidade, estado, pais,
                   profissao, formacao, instituicao, categoria
            FROM filiacoes
            WHERE pessoa_id = ?
            AND (telefone IS NOT NULL OR endereco IS NOT NULL OR cidade IS NOT NULL
                 OR profissao IS NOT NULL OR instituicao IS NOT NULL)
            ORDER BY ano DESC
            LIMIT 1
        ", [$result['id']]);

        if ($filiacao) {
            $result = array_merge($result, $filiacao);
        }
    }

    return $result;
}

/**
 * Busca filiação por pessoa e ano
 */
function buscar_filiacao(int $pessoa_id, int $ano): ?array {
    return db_fetch_one(
        "SELECT * FROM filiacoes WHERE pessoa_id = ? AND ano = ?",
        [$pessoa_id, $ano]
    );
}

/**
 * Lista filiados pagos de um ano
 */
function listar_filiados(int $ano): array {
    return db_fetch_all("
        SELECT p.nome, f.categoria, f.cidade, f.estado
        FROM pessoas p
        JOIN filiacoes f ON p.id = f.pessoa_id
        WHERE f.ano = ? AND (f.data_pagamento IS NOT NULL OR f.status = 'pago')
        ORDER BY p.nome
    ", [$ano]);
}

/**
 * Cria nova pessoa com email
 */
function criar_pessoa(string $email, string $nome = ''): int {
    $email = strtolower(trim($email));
    $token = gerar_token();

    // Cria pessoa
    $pessoa_id = db_insert(
        "INSERT INTO pessoas (nome, token, created_at) VALUES (?, ?, ?)",
        [$nome, $token, date('Y-m-d H:i:s')]
    );

    // Cria email principal
    db_insert(
        "INSERT INTO emails (pessoa_id, email, principal) VALUES (?, ?, 1)",
        [$pessoa_id, $email]
    );

    return $pessoa_id;
}

/**
 * Atualiza dados da pessoa e filiação
 */
function atualizar_pessoa_filiacao(
    int $pessoa_id,
    int $ano,
    array $dados
): void {
    // Atualiza pessoa
    db_execute(
        "UPDATE pessoas SET nome = ?, cpf = ?, updated_at = ? WHERE id = ?",
        [$dados['nome'], $dados['cpf'] ?: null, date('Y-m-d H:i:s'), $pessoa_id]
    );

    // Verifica se filiação existe
    $filiacao = buscar_filiacao($pessoa_id, $ano);

    if ($filiacao) {
        // Atualiza filiação existente
        db_execute("
            UPDATE filiacoes SET
                categoria = ?, valor = ?, telefone = ?, endereco = ?,
                cep = ?, cidade = ?, estado = ?, pais = ?,
                profissao = ?, formacao = ?, instituicao = ?
            WHERE pessoa_id = ? AND ano = ?
        ", [
            $dados['categoria'],
            $dados['valor'],
            $dados['telefone'] ?: null,
            $dados['endereco'] ?: null,
            $dados['cep'] ?: null,
            $dados['cidade'] ?: null,
            $dados['estado'] ?: null,
            $dados['pais'] ?: 'Brasil',
            $dados['profissao'] ?: null,
            $dados['formacao'] ?: null,
            $dados['instituicao'] ?: null,
            $pessoa_id,
            $ano
        ]);
    } else {
        // Cria nova filiação
        db_insert("
            INSERT INTO filiacoes (
                pessoa_id, ano, categoria, valor, status,
                telefone, endereco, cep, cidade, estado, pais,
                profissao, formacao, instituicao, created_at
            ) VALUES (?, ?, ?, ?, 'pendente', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [
            $pessoa_id,
            $ano,
            $dados['categoria'],
            $dados['valor'],
            $dados['telefone'] ?: null,
            $dados['endereco'] ?: null,
            $dados['cep'] ?: null,
            $dados['cidade'] ?: null,
            $dados['estado'] ?: null,
            $dados['pais'] ?: 'Brasil',
            $dados['profissao'] ?: null,
            $dados['formacao'] ?: null,
            $dados['instituicao'] ?: null,
            date('Y-m-d H:i:s')
        ]);
    }
}

// === Aliases para compatibilidade ===

function buscar_cadastrado_por_email(string $email): ?array {
    return buscar_pessoa_por_email($email);
}

function buscar_cadastrado_por_token(string $token): ?array {
    return buscar_pessoa_por_token($token);
}

function buscar_pagamento(int $pessoa_id, int $ano): ?array {
    return buscar_filiacao($pessoa_id, $ano);
}

/**
 * Retorna valores únicos para autocomplete de campos do formulário
 * Busca de todas as filiações, ordenado por frequência
 */
function obter_autocomplete(): array {
    // Instituições (não vazias, ordenadas por frequência)
    $instituicoes = db_fetch_all("
        SELECT instituicao, COUNT(*) as qtd
        FROM filiacoes
        WHERE instituicao IS NOT NULL AND instituicao <> ''
        GROUP BY instituicao
        ORDER BY qtd DESC
        LIMIT 500
    ");

    // Cidades (não vazias, ordenadas por frequência)
    $cidades = db_fetch_all("
        SELECT cidade, COUNT(*) as qtd
        FROM filiacoes
        WHERE cidade IS NOT NULL AND cidade <> ''
        GROUP BY cidade
        ORDER BY qtd DESC
        LIMIT 200
    ");

    // Estados (não vazios, ordenados por frequência)
    $estados = db_fetch_all("
        SELECT estado, COUNT(*) as qtd
        FROM filiacoes
        WHERE estado IS NOT NULL AND estado <> ''
        GROUP BY estado
        ORDER BY qtd DESC
    ");

    // Profissões (não vazias, ordenadas por frequência)
    $profissoes = db_fetch_all("
        SELECT profissao, COUNT(*) as qtd
        FROM filiacoes
        WHERE profissao IS NOT NULL AND profissao <> ''
        GROUP BY profissao
        ORDER BY qtd DESC
        LIMIT 100
    ");

    return [
        'instituicoes' => array_column($instituicoes, 'instituicao'),
        'cidades' => array_column($cidades, 'cidade'),
        'estados' => array_column($estados, 'estado'),
        'profissoes' => array_column($profissoes, 'profissao'),
    ];
}
