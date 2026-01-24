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

    // Tabela de templates de email
    $db->exec("
        CREATE TABLE IF NOT EXISTS email_templates (
            tipo TEXT PRIMARY KEY,
            assunto TEXT NOT NULL,
            html TEXT NOT NULL,
            descricao TEXT,
            variaveis TEXT,
            updated_at DATETIME
        );
    ");

    // Seed de templates padrão (insere os que faltam)
    seed_email_templates($db);

    // Tabela de lotes de envio (um registro por batch)
    $db->exec("
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
    ");

    // Tabela de destinatários por lote
    $db->exec("
        CREATE TABLE IF NOT EXISTS envios_destinatarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lote_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            nome TEXT,
            sucesso INTEGER DEFAULT 1,
            FOREIGN KEY (lote_id) REFERENCES envios_lotes(id) ON DELETE CASCADE
        );
    ");

    // Tabela de configurações (chave-valor)
    $db->exec("
        CREATE TABLE IF NOT EXISTS configuracoes (
            chave TEXT PRIMARY KEY,
            valor TEXT,
            updated_at DATETIME
        );
    ");

    // Seed grupo de teste
    $db->exec("
        INSERT OR IGNORE INTO configuracoes (chave, valor) VALUES (
            'grupo_teste',
            'marta@martapeixoto.com.br,mcereto@ufam.edu.br,jcnery19@yahoo.com.br,manuella.andrade@fau.ufal.br,ivogiroto@usp.br,suelypuppi@uol.com.br,correio@danilo.arq.br'
        )
    ");

    // Adiciona colunas de valores por campanha
    try {
        $db->exec("ALTER TABLE campanhas ADD COLUMN valor_estudante INTEGER");
    } catch (PDOException $e) {}

    try {
        $db->exec("ALTER TABLE campanhas ADD COLUMN valor_profissional INTEGER");
    } catch (PDOException $e) {}

    try {
        $db->exec("ALTER TABLE campanhas ADD COLUMN valor_internacional INTEGER");
    } catch (PDOException $e) {}

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

    // View para autocomplete (valores únicos de todos os anos)
    $db->exec("DROP VIEW IF EXISTS autocomplete_valores");
    $db->exec("
        CREATE VIEW autocomplete_valores AS
        SELECT 'instituicao' as campo, instituicao as valor, COUNT(*) as qtd
        FROM filiacoes WHERE instituicao IS NOT NULL AND instituicao <> '' GROUP BY instituicao
        UNION ALL
        SELECT 'cidade', cidade, COUNT(*) FROM filiacoes WHERE cidade IS NOT NULL AND cidade <> '' GROUP BY cidade
        UNION ALL
        SELECT 'estado', estado, COUNT(*) FROM filiacoes WHERE estado IS NOT NULL AND estado <> '' GROUP BY estado
        UNION ALL
        SELECT 'profissao', profissao, COUNT(*) FROM filiacoes WHERE profissao IS NOT NULL AND profissao <> '' GROUP BY profissao
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
 * Usa view autocomplete_valores (criada em init_extra_tables)
 */
function obter_autocomplete(): array {
    $campos = [
        'instituicao' => ['chave' => 'instituicoes', 'limite' => 500],
        'cidade'      => ['chave' => 'cidades',      'limite' => 200],
        'estado'      => ['chave' => 'estados',      'limite' => 50],
        'profissao'   => ['chave' => 'profissoes',   'limite' => 100],
    ];

    $resultado = [];
    foreach ($campos as $campo => $config) {
        $valores = db_fetch_all(
            "SELECT valor FROM autocomplete_valores WHERE campo = ? ORDER BY qtd DESC LIMIT ?",
            [$campo, $config['limite']]
        );
        $resultado[$config['chave']] = array_column($valores, 'valor');
    }

    return $resultado;
}

/**
 * Carrega template de email do banco
 * Substitui variáveis no formato {{variavel}}
 */
function carregar_template(string $tipo, array $vars = []): ?array {
    $tpl = db_fetch_one("SELECT assunto, html FROM email_templates WHERE tipo = ?", [$tipo]);
    if (!$tpl) return null;

    $assunto = $tpl['assunto'];
    $html = $tpl['html'];

    foreach ($vars as $key => $val) {
        $assunto = str_replace('{{' . $key . '}}', $val, $assunto);
        $html = str_replace('{{' . $key . '}}', $val, $html);
    }

    return ['assunto' => $assunto, 'html' => $html];
}

/**
 * Seed de templates padrão
 */
function seed_email_templates(PDO $db): void {
    $header = "<div style='background-color: " . ORG_COR_PRIMARIA . "; padding: 20px; text-align: center;'><h1 style='color: white; margin: 0;'>{{titulo}}</h1></div>";
    $footer_links = '';
    if (ORG_SITE_URL) {
        $site_display = preg_replace('#^https?://(www\.)?#', '', ORG_SITE_URL);
        $footer_links .= "<a href='" . ORG_SITE_URL . "' style='color: white;'>$site_display</a>";
    }
    if (ORG_INSTAGRAM) {
        if ($footer_links) $footer_links .= ' · ';
        $footer_links .= "<a href='https://www.instagram.com/" . ORG_INSTAGRAM . "' style='color: white;'>@" . ORG_INSTAGRAM . "</a>";
    }
    $footer_content = ORG_NOME . ($footer_links ? "<br>$footer_links" : '');
    $footer = "<div style='padding: 15px; background-color: " . ORG_COR_PRIMARIA . "; color: white; text-align: center; font-size: 12px;'>$footer_content</div>";
    $wrap = fn($titulo, $body) => "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>" . str_replace('{{titulo}}', $titulo, $header) . "<div style='padding: 20px; background-color: #f9f9f9;'>$body</div>$footer</div>";
    $btn = fn($texto, $var) => "<p style='text-align: center; margin: 30px 0;'><a href='{{" . $var . "}}' style='background-color: " . ORG_COR_PRIMARIA . "; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px;'>$texto</a></p>";

    $templates = [
        [
            'tipo' => 'confirmacao',
            'assunto' => 'Filiação ' . ORG_NOME . ' {{ano}} - Confirmada!',
            'descricao' => 'Enviado após confirmação de pagamento',
            'variaveis' => 'nome, ano, categoria, valor',
            'html' => $wrap('Filiação Confirmada!',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>Sua filiação ao <strong>" . ORG_NOME . "</strong> para o ano de <strong>{{ano}}</strong> está confirmada!</p>" .
                "<table style='width: 100%; border-collapse: collapse; margin: 20px 0;'><tr><td style='padding: 10px; border-bottom: 1px solid #ddd;'><strong>Categoria:</strong></td><td style='padding: 10px; border-bottom: 1px solid #ddd;'>{{categoria}}</td></tr><tr><td style='padding: 10px; border-bottom: 1px solid #ddd;'><strong>Valor:</strong></td><td style='padding: 10px; border-bottom: 1px solid #ddd;'>{{valor}}</td></tr></table>" .
                "<p>Em anexo, enviamos sua declaração de filiação.</p>" .
                "<p>Obrigado por fazer parte do " . ORG_NOME . "!</p>"
            ),
        ],
        [
            'tipo' => 'lembrete',
            'assunto' => '{{urgencia}}Filiação ' . ORG_NOME . ' {{ano}} - Pagamento Pendente',
            'descricao' => 'Lembrete de pagamento pendente',
            'variaveis' => 'nome, ano, valor, link, urgencia, dias_info',
            'html' => $wrap('{{urgencia}}Lembrete de Pagamento',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>Identificamos que sua filiação ao " . ORG_NOME . " para {{ano}} ainda está pendente de pagamento.</p>" .
                "<p><strong>Valor:</strong> {{valor}}</p>" .
                "<p>{{dias_info}}</p>" .
                $btn('Realizar Pagamento', 'link') .
                "<p><small>Se já realizou o pagamento, por favor desconsidere este email.</small></p>"
            ),
        ],
        [
            'tipo' => 'renovacao',
            'assunto' => 'Renove sua Filiação - ' . ORG_NOME . ' {{ano}}',
            'descricao' => 'Campanha para filiados de anos anteriores',
            'variaveis' => 'nome, ano, link',
            'html' => $wrap('Renove sua Filiação',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>É hora de renovar sua filiação ao " . ORG_NOME . "!</p>" .
                "<p><strong>Benefícios da filiação:</strong></p>" .
                "<ul><li>Descontos em eventos do " . ORG_NOME . " e núcleos regionais</li><li>Acesso à rede de profissionais e pesquisadores</li><li>Para internacional: " . ORG_SIGLA . " Journal, Member Card, descontos em museus</li></ul>" .
                $btn('Renovar Filiação', 'link')
            ),
        ],
        [
            'tipo' => 'convite',
            'assunto' => 'Convite para Filiação - ' . ORG_NOME . ' {{ano}}',
            'descricao' => 'Campanha para novos contatos',
            'variaveis' => 'nome, ano, link',
            'html' => $wrap('Convite para Filiação',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>Gostaríamos de convidar você a se filiar ao <strong>" . ORG_NOME . "</strong>!</p>" .
                "<p>O " . ORG_NOME . " é uma organização dedicada à documentação e conservação do patrimônio moderno.</p>" .
                "<p><strong>Benefícios da filiação:</strong></p>" .
                "<ul><li>Descontos em eventos do " . ORG_NOME . " e núcleos regionais</li><li>Acesso à rede de profissionais e pesquisadores</li><li>Participação nas atividades e publicações</li></ul>" .
                $btn('Filiar-se Agora', 'link')
            ),
        ],
        [
            'tipo' => 'seminario',
            'assunto' => 'Filiação ' . ORG_NOME . ' {{ano}} - Participante do Seminário',
            'descricao' => 'Campanha para participantes do seminário',
            'variaveis' => 'nome, ano, link',
            'html' => $wrap('Filiação ' . ORG_NOME,
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>Obrigado por sua participação no <strong>seminário do " . ORG_NOME . "</strong>!</p>" .
                "<p>Convidamos você a se filiar ao " . ORG_NOME . " e fortalecer nossa rede de documentação e conservação da arquitetura, urbanismo e paisagismo modernos.</p>" .
                $btn('Filiar-se Agora', 'link')
            ),
        ],
        [
            'tipo' => 'acesso',
            'assunto' => 'Acesso à Filiação ' . ORG_NOME . ' {{ano}}',
            'descricao' => 'Link de acesso ao formulário (segurança)',
            'variaveis' => 'nome, ano, link',
            'html' => $wrap('Acesso à Filiação',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>Você solicitou acesso ao formulário de filiação do <strong>" . ORG_NOME . "</strong> para o ano de <strong>{{ano}}</strong>.</p>" .
                "<p>Clique no botão abaixo para acessar seu formulário:</p>" .
                $btn('Acessar Formulário', 'link') .
                "<p><small>Se você não solicitou este acesso, ignore este email.</small></p>" .
                "<p><small>Este link é pessoal e intransferível.</small></p>"
            ),
        ],
    ];

    // Template da declaração PDF
    $templates[] = [
        'tipo' => 'declaracao',
        'assunto' => 'Declaração de Filiação {{ano}}',
        'descricao' => 'Texto da declaração PDF enviada ao filiado',
        'variaveis' => 'nome, ano, categoria, valor',
        'html' => "<p>Declaramos para os devidos fins que <strong>{{nome}}</strong> " .
            "é filiado(a) ao <strong>" . ORG_NOME . "</strong> na categoria <strong>{{categoria}}</strong>, " .
            "com anuidade de <strong>{{valor}}</strong> referente ao ano de <strong>{{ano}}</strong>, " .
            "devidamente quitada.</p>" .
            "<p>O " . ORG_NOME . " é uma organização dedicada à documentação e conservação do patrimônio moderno.</p>" .
            "<p style='margin-top: 60px; text-align: center;'>" .
            "<strong>Marta Peixoto</strong><br>" .
            "Coordenadora do " . ORG_NOME . "<br>" .
            "Gestão 2026-2027</p>",
    ];

    $stmt = $db->prepare("INSERT OR IGNORE INTO email_templates (tipo, assunto, html, descricao, variaveis) VALUES (?, ?, ?, ?, ?)");
    foreach ($templates as $t) {
        $stmt->execute([$t['tipo'], $t['assunto'], $t['html'], $t['descricao'], $t['variaveis']]);
    }
}

/**
 * Registra um lote de envio de emails
 * Retorna o ID do lote criado
 */
function registrar_envio_lote(string $tipo, int $ano, string $assunto, string $html, array $destinatarios): int {
    $total = count($destinatarios);
    $sucesso = count(array_filter($destinatarios, fn($d) => $d['sucesso']));
    $falha = $total - $sucesso;

    $lote_id = db_insert(
        "INSERT INTO envios_lotes (tipo, ano, assunto_snapshot, html_snapshot, total_enviados, total_sucesso, total_falha) VALUES (?, ?, ?, ?, ?, ?, ?)",
        [$tipo, $ano, $assunto, $html, $total, $sucesso, $falha]
    );

    $stmt = get_db()->prepare("INSERT INTO envios_destinatarios (lote_id, email, nome, sucesso) VALUES (?, ?, ?, ?)");
    foreach ($destinatarios as $d) {
        $stmt->execute([$lote_id, $d['email'], $d['nome'] ?? '', $d['sucesso'] ? 1 : 0]);
    }

    return $lote_id;
}
