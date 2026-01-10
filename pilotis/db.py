import sqlite3
from pathlib import Path
from contextlib import contextmanager

from .config import settings, BASE_DIR

SCHEMA = """
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
    categoria TEXT CHECK(categoria IN ('estudante', 'profissional', 'profissional_internacional', 'participante_seminario')),
    data_cadastro DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao DATETIME,
    token TEXT UNIQUE,
    token_expira DATETIME,
    observacoes TEXT
);

CREATE TABLE IF NOT EXISTS pagamentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cadastrado_id INTEGER NOT NULL REFERENCES cadastrados(id),
    ano INTEGER NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    status TEXT DEFAULT 'pendente' CHECK(status IN ('pendente', 'pago', 'cancelado', 'expirado')),
    metodo TEXT CHECK(metodo IN ('pix', 'boleto', 'cartao')),
    pagbank_order_id TEXT,
    pagbank_charge_id TEXT,
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

CREATE VIEW IF NOT EXISTS filiados AS
SELECT c.* FROM cadastrados c
JOIN pagamentos p ON c.id = p.cadastrado_id
WHERE p.ano = strftime('%Y', 'now') AND p.status = 'pago';
"""


def get_db_path() -> Path:
    """Retorna o caminho absoluto do banco de dados."""
    db_path = Path(settings.DATABASE_PATH)
    if not db_path.is_absolute():
        db_path = BASE_DIR / db_path
    return db_path


def init_db() -> None:
    """Inicializa o banco de dados com o schema."""
    db_path = get_db_path()
    db_path.parent.mkdir(parents=True, exist_ok=True)

    conn = sqlite3.connect(db_path)
    try:
        conn.executescript(SCHEMA)
        conn.commit()
        print(f"Banco inicializado em {db_path}")
    finally:
        conn.close()


@contextmanager
def get_connection():
    """Context manager para conexão com o banco."""
    db_path = get_db_path()
    conn = sqlite3.connect(db_path)
    conn.row_factory = sqlite3.Row
    try:
        yield conn
    finally:
        conn.close()


def execute(sql: str, params: tuple = ()) -> sqlite3.Cursor:
    """Executa uma query e retorna o cursor."""
    with get_connection() as conn:
        cursor = conn.execute(sql, params)
        conn.commit()
        return cursor


def fetchone(sql: str, params: tuple = ()) -> sqlite3.Row | None:
    """Executa uma query e retorna uma linha."""
    with get_connection() as conn:
        cursor = conn.execute(sql, params)
        return cursor.fetchone()


def fetchall(sql: str, params: tuple = ()) -> list[sqlite3.Row]:
    """Executa uma query e retorna todas as linhas."""
    with get_connection() as conn:
        cursor = conn.execute(sql, params)
        return cursor.fetchall()


def registrar_log(tipo: str, cadastrado_id: int | None = None, mensagem: str = "") -> None:
    """Registra uma entrada no log."""
    execute(
        "INSERT INTO log (tipo, cadastrado_id, mensagem) VALUES (?, ?, ?)",
        (tipo, cadastrado_id, mensagem),
    )
