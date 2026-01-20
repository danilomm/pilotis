<?php
/**
 * Pilotis - Conexão com banco de dados SQLite
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

        // Inicializa schema se banco novo
        init_schema($_db);
    }

    return $_db;
}

/**
 * Inicializa schema do banco
 */
function init_schema(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS cadastrados (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            email TEXT NOT NULL,
            cpf TEXT,
            telefone TEXT,
            endereco TEXT,
            cep TEXT,
            cidade TEXT,
            estado TEXT CHECK(length(estado) <= 2 OR estado IS NULL),
            pais TEXT DEFAULT 'Brasil',
            profissao TEXT,
            formacao TEXT,
            instituicao TEXT,
            categoria TEXT CHECK(categoria IN ('estudante', 'profissional_nacional', 'profissional_internacional', 'participante_seminario', 'cadastrado')),
            seminario_2025 BOOLEAN DEFAULT 0,
            data_cadastro DATETIME DEFAULT CURRENT_TIMESTAMP,
            data_atualizacao DATETIME,
            token TEXT UNIQUE,
            token_expira DATETIME,
            observacoes TEXT,
            observacoes_filiado TEXT
        );

        CREATE TABLE IF NOT EXISTS pagamentos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cadastrado_id INTEGER NOT NULL REFERENCES cadastrados(id),
            ano INTEGER NOT NULL,
            valor DECIMAL(10,2) NOT NULL,
            status TEXT DEFAULT 'pendente' CHECK(status IN ('pendente', 'pago', 'cancelado', 'expirado')),
            metodo TEXT CHECK(metodo IN ('pix', 'boleto', 'cartao', 'manual')),
            pagbank_order_id TEXT,
            pagbank_charge_id TEXT,
            pagbank_boleto_link TEXT,
            pagbank_boleto_barcode TEXT,
            data_criacao DATETIME DEFAULT CURRENT_TIMESTAMP,
            data_pagamento DATETIME,
            data_vencimento DATETIME,
            UNIQUE(cadastrado_id, ano)
        );

        CREATE TABLE IF NOT EXISTS log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            tipo TEXT NOT NULL,
            cadastrado_id INTEGER,
            mensagem TEXT
        );

        CREATE INDEX IF NOT EXISTS idx_pagamentos_status ON pagamentos(status);
        CREATE INDEX IF NOT EXISTS idx_pagamentos_ano ON pagamentos(ano);
        CREATE INDEX IF NOT EXISTS idx_cadastrados_email ON cadastrados(email);
        CREATE INDEX IF NOT EXISTS idx_cadastrados_token ON cadastrados(token);
    ");
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
function registrar_log(string $tipo, ?int $cadastrado_id = null, string $mensagem = ''): void {
    db_execute(
        "INSERT INTO log (tipo, cadastrado_id, mensagem) VALUES (?, ?, ?)",
        [$tipo, $cadastrado_id, $mensagem]
    );
}

// === Funções de busca ===

/**
 * Busca cadastrado por email (pode ter múltiplos separados por ;)
 */
function buscar_cadastrado_por_email(string $email): ?array {
    $email = strtolower(trim($email));

    // Busca exata
    $cadastrado = db_fetch_one(
        "SELECT * FROM cadastrados WHERE LOWER(email) = ?",
        [$email]
    );

    if ($cadastrado) {
        return $cadastrado;
    }

    // Busca se o email está em uma lista de emails
    return db_fetch_one(
        "SELECT * FROM cadastrados WHERE LOWER(email) LIKE ?",
        ["%$email%"]
    );
}

/**
 * Busca cadastrado por token
 */
function buscar_cadastrado_por_token(string $token): ?array {
    return db_fetch_one(
        "SELECT * FROM cadastrados WHERE token = ?",
        [$token]
    );
}

/**
 * Busca pagamento por cadastrado e ano
 */
function buscar_pagamento(int $cadastrado_id, int $ano): ?array {
    return db_fetch_one(
        "SELECT * FROM pagamentos WHERE cadastrado_id = ? AND ano = ?",
        [$cadastrado_id, $ano]
    );
}

/**
 * Lista filiados pagos de um ano
 */
function listar_filiados(int $ano): array {
    return db_fetch_all("
        SELECT c.nome, c.categoria, c.cidade, c.estado
        FROM cadastrados c
        JOIN pagamentos p ON c.id = p.cadastrado_id
        WHERE p.ano = ? AND p.status = 'pago'
        ORDER BY c.nome
    ", [$ano]);
}
