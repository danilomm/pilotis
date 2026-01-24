-- Pilotis - Schema do banco de dados SQLite
-- Execute: sqlite3 dados/data/pilotis.db < schema.sql

-- Tabela de pessoas (cadastros)
CREATE TABLE IF NOT EXISTS pessoas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT,
    cpf TEXT,
    token TEXT UNIQUE,
    ativo INTEGER DEFAULT 1,
    notas TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME
);

-- Tabela de emails (uma pessoa pode ter varios)
CREATE TABLE IF NOT EXISTS emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pessoa_id INTEGER NOT NULL,
    email TEXT NOT NULL,
    principal INTEGER DEFAULT 0,
    FOREIGN KEY (pessoa_id) REFERENCES pessoas(id) ON DELETE CASCADE,
    UNIQUE(pessoa_id, email)
);

-- Tabela de filiacoes (uma por ano por pessoa)
CREATE TABLE IF NOT EXISTS filiacoes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pessoa_id INTEGER NOT NULL,
    ano INTEGER NOT NULL,
    categoria TEXT,
    valor INTEGER,
    status TEXT DEFAULT 'pendente',
    data_pagamento DATETIME,
    metodo TEXT,
    pagbank_id TEXT,
    pagbank_order_id TEXT,
    pagbank_charge_id TEXT,
    pagbank_boleto_link TEXT,
    pagbank_boleto_barcode TEXT,
    data_vencimento TEXT,
    telefone TEXT,
    endereco TEXT,
    cep TEXT,
    cidade TEXT,
    estado TEXT,
    pais TEXT DEFAULT 'Brasil',
    profissao TEXT,
    formacao TEXT,
    instituicao TEXT,
    seminario INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pessoa_id) REFERENCES pessoas(id) ON DELETE CASCADE,
    UNIQUE(pessoa_id, ano)
);

-- Tabela de campanhas (controle de envio de emails)
CREATE TABLE IF NOT EXISTS campanhas (
    ano INTEGER PRIMARY KEY,
    status TEXT DEFAULT 'aberta',
    valor_estudante INTEGER,
    valor_profissional INTEGER,
    valor_internacional INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Tabela de templates de email
CREATE TABLE IF NOT EXISTS email_templates (
    tipo TEXT PRIMARY KEY,
    assunto TEXT NOT NULL,
    html TEXT NOT NULL,
    descricao TEXT,
    variaveis TEXT,
    updated_at DATETIME
);

-- Tabela de lotes de envio (historico)
CREATE TABLE IF NOT EXISTS envios_lotes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    tipo TEXT NOT NULL,
    ano INTEGER NOT NULL,
    assunto_snapshot TEXT,
    html_snapshot TEXT,
    total_enviados INTEGER DEFAULT 0,
    total_sucesso INTEGER DEFAULT 0,
    total_falha INTEGER DEFAULT 0
);

-- Tabela de destinatarios por lote
CREATE TABLE IF NOT EXISTS envios_destinatarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lote_id INTEGER NOT NULL,
    email TEXT NOT NULL,
    nome TEXT,
    sucesso INTEGER DEFAULT 1,
    FOREIGN KEY (lote_id) REFERENCES envios_lotes(id) ON DELETE CASCADE
);

-- Tabela de log (auditoria)
CREATE TABLE IF NOT EXISTS log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    tipo TEXT NOT NULL,
    pessoa_id INTEGER,
    mensagem TEXT
);

-- Indices para performance
CREATE INDEX IF NOT EXISTS idx_emails_email ON emails(email);
CREATE INDEX IF NOT EXISTS idx_emails_pessoa ON emails(pessoa_id);
CREATE INDEX IF NOT EXISTS idx_filiacoes_pessoa ON filiacoes(pessoa_id);
CREATE INDEX IF NOT EXISTS idx_filiacoes_ano ON filiacoes(ano);
CREATE INDEX IF NOT EXISTS idx_filiacoes_status ON filiacoes(status);
CREATE INDEX IF NOT EXISTS idx_pessoas_token ON pessoas(token);
CREATE INDEX IF NOT EXISTS idx_envios_lotes_ano ON envios_lotes(ano);
CREATE INDEX IF NOT EXISTS idx_envios_dest_lote ON envios_destinatarios(lote_id);

-- View para autocomplete (valores unicos de todos os anos)
CREATE VIEW IF NOT EXISTS autocomplete_valores AS
SELECT 'instituicao' as campo, instituicao as valor, COUNT(*) as qtd
FROM filiacoes WHERE instituicao IS NOT NULL AND instituicao <> '' GROUP BY instituicao
UNION ALL
SELECT 'cidade', cidade, COUNT(*) FROM filiacoes WHERE cidade IS NOT NULL AND cidade <> '' GROUP BY cidade
UNION ALL
SELECT 'estado', estado, COUNT(*) FROM filiacoes WHERE estado IS NOT NULL AND estado <> '' GROUP BY estado
UNION ALL
SELECT 'profissao', profissao, COUNT(*) FROM filiacoes WHERE profissao IS NOT NULL AND profissao <> '' GROUP BY profissao;

-- Campanha inicial (ano atual)
INSERT OR IGNORE INTO campanhas (ano, status) VALUES (strftime('%Y', 'now'), 'aberta');
