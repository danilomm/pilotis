-- Pilotis - Schema do Banco de Dados
-- Sistema de gestão de filiados para associações
-- https://github.com/danilomm/pilotis

-- =============================================================================
-- TABELAS PRINCIPAIS
-- =============================================================================

-- Pessoas cadastradas
CREATE TABLE pessoas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    cpf TEXT,
    token TEXT UNIQUE,
    ativo INTEGER DEFAULT 1,
    notas TEXT,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

-- Emails das pessoas (suporta múltiplos por pessoa)
CREATE TABLE emails (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pessoa_id INTEGER NOT NULL,
    email TEXT NOT NULL UNIQUE,
    principal INTEGER DEFAULT 0,
    FOREIGN KEY (pessoa_id) REFERENCES pessoas(id) ON DELETE CASCADE
);
CREATE INDEX idx_emails_pessoa ON emails(pessoa_id);

-- Filiações por ano
CREATE TABLE filiacoes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pessoa_id INTEGER NOT NULL,
    ano INTEGER NOT NULL,
    categoria TEXT NOT NULL,
    valor INTEGER,
    status TEXT DEFAULT 'pendente',
    data_pagamento TEXT,
    metodo TEXT,
    data_vencimento TEXT,
    -- Dados de contato (copiados para histórico)
    telefone TEXT,
    endereco TEXT,
    cep TEXT,
    cidade TEXT,
    estado TEXT,
    pais TEXT,
    -- Dados profissionais
    profissao TEXT,
    formacao TEXT,
    instituicao TEXT,
    -- PagBank
    pagbank_order_id TEXT,
    pagbank_charge_id TEXT,
    pagbank_boleto_link TEXT,
    pagbank_boleto_barcode TEXT,
    -- Comprovante de matrícula (para estudantes)
    comprovante_path TEXT,
    -- Flags
    seminario INTEGER DEFAULT 0,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pessoa_id) REFERENCES pessoas(id) ON DELETE CASCADE,
    UNIQUE(pessoa_id, ano)
);
CREATE INDEX idx_filiacoes_pessoa ON filiacoes(pessoa_id);
CREATE INDEX idx_filiacoes_ano ON filiacoes(ano);

-- =============================================================================
-- TABELAS DE CAMPANHA
-- =============================================================================

-- Campanhas anuais
CREATE TABLE campanhas (
    ano INTEGER PRIMARY KEY,
    status TEXT DEFAULT 'aberta',
    emails_enviados INTEGER DEFAULT 0,
    valor_estudante INTEGER,
    valor_profissional INTEGER,
    valor_internacional INTEGER,
    data_fim DATE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Lembretes agendados (envio individual)
CREATE TABLE lembretes_agendados (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filiacao_id INTEGER NOT NULL,
    tipo TEXT NOT NULL,
    data_agendada DATE NOT NULL,
    enviado INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    enviado_at DATETIME,
    FOREIGN KEY (filiacao_id) REFERENCES filiacoes(id) ON DELETE CASCADE
);
CREATE INDEX idx_lembretes_data ON lembretes_agendados(data_agendada, enviado);
CREATE INDEX idx_lembretes_filiacao ON lembretes_agendados(filiacao_id);

-- =============================================================================
-- TABELAS DE EMAIL
-- =============================================================================

-- Templates de email editáveis
CREATE TABLE email_templates (
    tipo TEXT PRIMARY KEY,
    assunto TEXT NOT NULL,
    html TEXT NOT NULL,
    descricao TEXT,
    variaveis TEXT,
    updated_at DATETIME
);

-- Lotes de envio (histórico)
CREATE TABLE envios_lotes (
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

-- Destinatários por lote
CREATE TABLE envios_destinatarios (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    lote_id INTEGER NOT NULL,
    email TEXT NOT NULL,
    nome TEXT,
    sucesso INTEGER DEFAULT 1,
    FOREIGN KEY (lote_id) REFERENCES envios_lotes(id) ON DELETE CASCADE
);

-- =============================================================================
-- TABELAS AUXILIARES
-- =============================================================================

-- Configurações (chave-valor)
CREATE TABLE configuracoes (
    chave TEXT PRIMARY KEY,
    valor TEXT,
    updated_at DATETIME
);

-- Log de ações
CREATE TABLE log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    tipo TEXT NOT NULL,
    pessoa_id INTEGER,
    mensagem TEXT
);

-- =============================================================================
-- VIEWS
-- =============================================================================

-- Lista de filiados (para consulta rápida)
CREATE VIEW filiados AS
SELECT
    p.id,
    p.nome,
    p.cpf,
    e.email,
    f.ano,
    f.categoria,
    f.valor,
    f.data_pagamento,
    f.cidade,
    f.estado,
    f.instituicao
FROM pessoas p
JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
JOIN filiacoes f ON f.pessoa_id = p.id
WHERE f.categoria != 'nao_filiado'
  AND f.valor IS NOT NULL
  AND p.ativo = 1;

-- Valores para autocomplete
CREATE VIEW autocomplete_valores AS
SELECT 'instituicao' as campo, instituicao as valor, COUNT(*) as qtd
FROM filiacoes WHERE instituicao IS NOT NULL AND instituicao <> '' GROUP BY instituicao
UNION ALL
SELECT 'cidade', cidade, COUNT(*) FROM filiacoes WHERE cidade IS NOT NULL AND cidade <> '' GROUP BY cidade
UNION ALL
SELECT 'estado', estado, COUNT(*) FROM filiacoes WHERE estado IS NOT NULL AND estado <> '' GROUP BY estado
UNION ALL
SELECT 'profissao', profissao, COUNT(*) FROM filiacoes WHERE profissao IS NOT NULL AND profissao <> '' GROUP BY profissao;
