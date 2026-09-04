<?php
/**
 * Pilotis — Evolucao da estrutura do banco, versionada.
 *
 * Roda so quando SCHEMA_VERSION nao bate com a marca gravada em
 * configuracoes. Trocar a constante sempre que init_extra_tables() mudar,
 * senao a coluna nova nao nasce.
 *
 * Extraido de src/db.php em 29/08/2026. O db.php continua existindo e
 * incluindo este arquivo, entao todo require antigo segue valendo.
 */

/**
 * Versao do schema que ESTE codigo espera. Trocar sempre que init_extra_tables()
 * mudar (coluna nova, indice novo, view refeita).
 */
const SCHEMA_VERSION = '2026-09-04c';

/**
 * Acrescenta uma coluna SE ela ainda nao existir.
 *
 * POR QUE existe: ate 29/08/2026 havia 31 ALTER TABLE, cada um assim —
 *
 *     garantir_coluna($db, 'x', 'y', 'TEXT');
 *
 * — que e excecao usada como controle de fluxo: "tenta criar; se falhar,
 * presume que ja existe". Esse catch nao distingue "coluna ja existe" de
 * "banco travado", "disco cheio" ou "a tabela nao existe". E desde que a
 * migracao passou a rodar UMA vez por versao (garantir_schema), um ALTER que
 * falhe por outro motivo nao volta a ser tentado: a coluna simplesmente nunca
 * nasce, e o erro que explicaria isso foi jogado fora.
 *
 * A funcao certa para perguntar antes ja existia no mesmo arquivo —
 * colunas_da_tabela() — e era usada em 3 lugares contra 31 que faziam as cegas.
 *
 * Agora: pergunta, age so se precisar, e deixa a excecao subir se o ALTER
 * falhar de verdade. Tabela inexistente devolve false sem erro, porque a ordem
 * do arquivo garante que o CREATE vem antes e um banco novo nao precisa da
 * coluna avulsa.
 */
function garantir_coluna(PDO $db, string $tabela, string $coluna, string $tipo): bool {
    $existentes = colunas_da_tabela($db, $tabela);
    if (!$existentes) {
        return false;
    }
    if (in_array($coluna, $existentes, true)) {
        return false;
    }
    $db->exec("ALTER TABLE $tabela ADD COLUMN $coluna $tipo");
    return true;
}

/**
 * Roda a migracao so quando o banco esta atras do codigo.
 *
 * POR QUE: init_extra_tables() era chamada em TODA abertura de conexao, isto e,
 * em toda requisicao — ~93 comandos, e nem todos de leitura. Havia DROP VIEW
 * seguido de CREATE VIEW, dois UPDATE em filiacoes e um INSERT em campanhas.
 * Consequencias: toda visita abria transacao de escrita no SQLite, e existia
 * uma janela real entre o DROP e o CREATE em que outra requisicao consultando
 * autocomplete_valores levava 500.
 *
 * Com a marca em configuracoes, isso acontece UMA vez por deploy. A janela
 * sobrevive apenas nesse primeiro acesso — que e exatamente o cuidado que o
 * CLAUDE.md ja pede ("abrir uma pagina sozinho antes de divulgar").
 *
 * A leitura e tolerante de proposito: banco novo, ou anterior a esta marca, cai
 * no caminho de migrar. Errar para o lado de migrar de novo e barato; errar
 * para o lado de pular deixa o sistema sem coluna.
 */
function garantir_schema(PDO $db): void {
    // O banco e o que se espera? `pessoas` vem do schema.sql, nunca daqui.
    //
    // POR QUE ISTO EXISTE: `new PDO("sqlite:...")` **cria arquivo vazio em
    // silencio**. Se o DATABASE_PATH do .env sair errado no deploy — caminho
    // relativo, pasta trocada, upload parcial — o sistema passa a falar com um
    // banco vazio e todas as paginas quebram, inclusive a pagina do evento, que
    // tem a URL impressa no cartaz. Sem esta checagem o sintoma e "no such
    // table" no meio da migracao, que manda o diagnostico para o lado errado.
    if (!colunas_da_tabela($db, 'pessoas')) {
        throw new RuntimeException(
            'Banco sem a tabela `pessoas`: o arquivo existe mas nao foi instalado, '
            . 'ou DATABASE_PATH aponta para o lugar errado (' . DATABASE_PATH . '). '
            . 'Instalacao nova: rodar `php scripts/install.php`, que aplica o schema.sql. '
            . 'Instalacao existente: conferir o caminho no .env — o PDO cria arquivo '
            . 'vazio em silencio quando o caminho esta errado.'
        );
    }

    $db->exec("CREATE TABLE IF NOT EXISTS configuracoes (
        chave TEXT PRIMARY KEY, valor TEXT, updated_at DATETIME
    )");

    // ATENCAO ao `closeCursor()`: sem ele o statement fica VIVO, com cursor de
    // leitura aberto, durante toda a `init_extra_tables()` — e o SQLite recusa
    // `DROP TABLE` enquanto ha query pendente na mesma conexao, devolvendo
    // SQLITE_LOCKED, que o `busy_timeout` nao retenta.
    //
    // O `fetch()` de uma linha nao esgota o statement: so quando a linha NAO
    // existe e que ele se encerra sozinho. Por isso o defeito era invisivel —
    // em banco sem `schema_version` (o de producao em 31/08, e o que o
    // `tests/migracao.php` cria) tudo passa; com a linha gravada, qualquer
    // migracao que precise reconstruir tabela falha em TODA requisicao, para
    // sempre, porque a marca de versao so e escrita no fim.
    //
    // Achado pela quarta revisao, 31/08/2026, com reproducao deterministica.
    $versao = null;
    try {
        $st = $db->query("SELECT valor FROM configuracoes WHERE chave = 'schema_version'");
        if ($st) {
            $row = $st->fetch();
            $st->closeCursor();
            $versao = $row['valor'] ?? null;
        }
    } catch (PDOException $e) {
        $versao = null;
    }

    if ($versao === SCHEMA_VERSION) {
        return;
    }

    init_extra_tables($db);

    $st = $db->prepare("INSERT INTO configuracoes (chave, valor, updated_at)
        VALUES ('schema_version', ?, datetime('now','localtime'))
        ON CONFLICT(chave) DO UPDATE SET valor = excluded.valor, updated_at = excluded.updated_at");
    $st->execute([SCHEMA_VERSION]);
}

function init_extra_tables(PDO $db): void {
    // Tabela de log
    $db->exec("
        CREATE TABLE IF NOT EXISTS log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp DATETIME DEFAULT (datetime('now','localtime')),
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
            created_at DATETIME DEFAULT (datetime('now','localtime'))
        );
    ");

    // Cria campanhas para anos que já têm filiações (se não existirem).
    //
    // Guardado por colunas_da_tabela(): `filiacoes` NAO e criada aqui — vem do
    // schema.sql, pelo install.php. Sem a guarda, um banco sem ela fazia esta
    // linha lancar PDOException de dentro de garantir_schema(), que roda em
    // get_db(), que roda em TODA requisicao: o site inteiro respondia 500.
    if (colunas_da_tabela($db, 'filiacoes')) {
        $db->exec("
            INSERT OR IGNORE INTO campanhas (ano, status)
            SELECT DISTINCT ano,
                -- CAST obrigatorio: `ano` e INTEGER e strftime devolve TEXT, e
                -- no SQLite INTEGER sempre ordena antes de TEXT. Sem o CAST,
                -- `2026 < '2026'` da 1 e o CASE caia SEMPRE em 'fechada'.
                -- Mesma armadilha ja documentada no CLAUDE.md para o
                -- CAST(? AS INTEGER) do autocomplete.
                CASE WHEN ano < CAST(strftime('%Y', 'now') AS INTEGER)
                     THEN 'fechada' ELSE 'aberta' END
            FROM filiacoes
            WHERE ano IS NOT NULL
        ");
    }

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
            created_at DATETIME DEFAULT (datetime('now','localtime')),
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

    // O grupo de teste NASCE VAZIO e se preenche no admin.
    //
    // Ate 29/08/2026 esta migracao trazia os sete emails pessoais dos
    // dirigentes escritos aqui — e src/db.php e versionado no repositorio
    // publico. Era a mesma falha do enviar_planilhas_nucleos.py: dado que o
    // sistema ja guarda, copiado para dentro do codigo. E numa instalacao de
    // terceiro (o projeto e GPL) a migracao plantava os dirigentes do Docomomo
    // na base de outra associacao.
    //
    // Tirar a linha nao apaga nada: era INSERT OR IGNORE, e quem ja tem a
    // configuracao gravada continua com ela.

    // Tabela de pedidos PagBank (rastreio de order_id por filiação)
    $db->exec("
        CREATE TABLE IF NOT EXISTS pagbank_pedidos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filiacao_id INTEGER NOT NULL,
            order_id TEXT NOT NULL,
            metodo TEXT,
            created_at DATETIME DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (filiacao_id) REFERENCES filiacoes(id) ON DELETE CASCADE
        )
    ");

    // Adiciona colunas de valores por campanha
    garantir_coluna($db, 'campanhas', 'valor_estudante', 'INTEGER');

    garantir_coluna($db, 'campanhas', 'valor_profissional', 'INTEGER');

    garantir_coluna($db, 'campanhas', 'valor_internacional', 'INTEGER');

    garantir_coluna($db, 'campanhas', 'data_fim', 'DATE');

    garantir_coluna($db, 'campanhas', 'emails_enviados', 'INTEGER DEFAULT 0');

    garantir_coluna($db, 'campanhas', 'data_fim_internacional', 'DATE');

    // Adiciona colunas extras na filiacoes se não existirem
    garantir_coluna($db, 'filiacoes', 'status', "TEXT DEFAULT 'pendente'");

    garantir_coluna($db, 'filiacoes', 'pagbank_order_id', 'TEXT');

    garantir_coluna($db, 'filiacoes', 'pagbank_charge_id', 'TEXT');

    garantir_coluna($db, 'filiacoes', 'pagbank_boleto_link', 'TEXT');

    garantir_coluna($db, 'filiacoes', 'pagbank_boleto_barcode', 'TEXT');

    garantir_coluna($db, 'filiacoes', 'data_vencimento', 'TEXT');

    garantir_coluna($db, 'filiacoes', 'status_at', 'TEXT');

    // Atualiza status baseado em data_pagamento
    $db->exec("UPDATE filiacoes SET status = 'pago' WHERE data_pagamento IS NOT NULL AND status IS NULL");
    $db->exec("UPDATE filiacoes SET status = 'pendente' WHERE data_pagamento IS NULL AND status IS NULL");

    // Tabela de lembretes agendados (envio individual, idempotente)
    $db->exec("
        CREATE TABLE IF NOT EXISTS lembretes_agendados (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            filiacao_id INTEGER NOT NULL,
            tipo TEXT NOT NULL,
            data_agendada DATE NOT NULL,
            enviado INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT (datetime('now','localtime')),
            enviado_at DATETIME,
            FOREIGN KEY (filiacao_id) REFERENCES filiacoes(id) ON DELETE CASCADE
        )
    ");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_lembretes_data ON lembretes_agendados(data_agendada, enviado)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_lembretes_filiacao ON lembretes_agendados(filiacao_id)");
    // === Módulo de Eventos ===

    $db->exec("
        CREATE TABLE IF NOT EXISTS eventos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nome TEXT NOT NULL,
            slug TEXT NOT NULL UNIQUE,
            descricao TEXT,
            organizador TEXT,
            data_inicio DATE,
            data_fim DATE,
            prazo_inscricao DATE,
            status TEXT DEFAULT 'rascunho',
            created_at DATETIME DEFAULT (datetime('now','localtime'))
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS evento_categorias (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            evento_id INTEGER NOT NULL,
            nome TEXT NOT NULL,
            valor INTEGER NOT NULL DEFAULT 0,
            verifica_adimplencia INTEGER DEFAULT 0,
            requer_comprovante INTEGER DEFAULT 0,
            ordem INTEGER DEFAULT 0,
            FOREIGN KEY (evento_id) REFERENCES eventos(id) ON DELETE CASCADE
        )
    ");

    $db->exec("
        CREATE TABLE IF NOT EXISTS inscricoes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            pessoa_id INTEGER NOT NULL,
            evento_id INTEGER NOT NULL,
            categoria_id INTEGER,
            status TEXT DEFAULT 'enviado',
            valor INTEGER,
            comprovante_path TEXT,
            metodo TEXT,
            data_pagamento DATETIME,
            data_vencimento TEXT,
            status_at DATETIME,
            pagbank_order_id TEXT,
            pagbank_charge_id TEXT,
            pagbank_boleto_link TEXT,
            pagbank_boleto_barcode TEXT,
            telefone TEXT,
            endereco TEXT,
            cep TEXT,
            cidade TEXT,
            estado TEXT,
            pais TEXT,
            profissao TEXT,
            instituicao TEXT,
            created_at DATETIME DEFAULT (datetime('now','localtime')),
            FOREIGN KEY (pessoa_id) REFERENCES pessoas(id) ON DELETE CASCADE,
            FOREIGN KEY (evento_id) REFERENCES eventos(id) ON DELETE CASCADE,
            UNIQUE(pessoa_id, evento_id)
        )
    ");
    // Colunas cadastrais (para bancos onde a tabela foi criada antes desta versao)
    foreach (['telefone','endereco','cep','cidade','estado','pais','profissao','instituicao'] as $col) {
        garantir_coluna($db, 'inscricoes', $col, 'TEXT');
    }

    // Preco em duas faixas ("valor reduzido ate DD/MM, valor cheio depois").
    // A data de virada e do EVENTO (vale para todas as categorias); o valor
    // cheio e por categoria. valor_cheio NULL/0 = preco unico (valor).
    garantir_coluna($db, 'evento_categorias', 'valor_cheio', 'INTEGER');

    // Categoria restrita a uma lista de CPFs (isentos, convidados, palestrantes).
    // Vazio = aberta a todos. Sem isto, uma categoria gratuita fica a um clique
    // de qualquer visitante: ele escolhe "Convidado" e a inscricao sai
    // confirmada de graca, porque o sistema nao tem como saber que nao devia.
    // Nome como a pessoa quer aparecer no cracha. O cadastro guarda o nome de
    // registro — mais da metade tem 4 palavras ou mais, e o maior tem 50
    // caracteres — enquanto o cracha quer o nome de uso. Nao da para derivar
    // com seguranca: "Marta Cristina Ferreira Buarque Guimaraes" assina
    // "Marta Guimaraes", que nao e primeiro mais ultimo.
    garantir_coluna($db, 'inscricoes', 'nome_cracha', 'TEXT');

    // Presenca no evento. COLUNA PROPRIA, nao status (restricao 2 do ROADMAP).
    //
    // Presenca e ortogonal ao pagamento: da para ter pago e nao ter ido, e para
    // ter ido sem ter pago (inscricao tardia). Um status `participou` no mesmo
    // eixo dos outros quebraria as contagens do painel — as mesmas que foram
    // corrigidas em 29/08/2026, quando "Nao pagos" somava tres dos seis status
    // e deixava 1308 filiacoes fora da conta.
    //
    // A coluna nasce agora porque o evento e em 12-13/11 e ela precisa existir
    // antes; a tela que a usa e da etapa 2. Ver Eventos/Inscricoes.php.
    garantir_coluna($db, 'inscricoes', 'presenca_em', 'DATETIME');

    // Quem marcou a presenca. Em coluna, e nao so no texto do log: a restricao 3
    // do ROADMAP exige que acao da organizacao tenha autor, e log e recuperavel
    // mas nao consultavel — a tela da mesa vai querer mostrar isso na linha.
    garantir_coluna($db, 'inscricoes', 'presenca_por', 'TEXT');
    garantir_coluna($db, 'evento_categorias', 'cpfs_liberados', 'TEXT');

    // Categoria que vale para todo mundo, filiado ou nao (acompanhante,
    // visitante, mesa redonda avulsa). Sem esta marca, uma categoria que nao
    // exige adimplencia e entendida como "preco cheio de nao filiado" e some
    // para quem tem direito ao desconto — o que estaria errado nesses casos.
    garantir_coluna($db, 'evento_categorias', 'independe_filiacao', 'INTEGER DEFAULT 0');

    // Apoio e patrocinio. Duas colunas porque sao duas coisas: a faixa de
    // logotipos, que e como isso costuma vir pronto da organizacao, e os nomes
    // por extenso — que a faixa nao da (imagem nao e texto: nao vai para busca,
    // nem para leitor de tela, e some se o arquivo se perder).
    // Painel de leitura da organizacao do evento. O acesso e por email
    // autorizado, com link de 30 minutos enviado na hora — nao por senha
    // compartilhada, que vaza uma vez e vive para sempre num grupo de
    // conversa, sem deixar saber quem entrou. Aqui o registro diz quem abriu,
    // e tirar alguem e apagar uma linha da lista.
    garantir_coluna($db, 'eventos', 'emails_organizacao', 'TEXT');
    garantir_coluna($db, 'eventos', 'organizacao_expira_em', 'DATE');

    // Documento de quem NAO tem CPF — estrangeiro filiado, tipicamente com
    // passaporte. Colunas a parte, e nao um `cpf` polivalente, porque o CPF e
    // IDENTIDADE neste sistema: entra em 19 pontos de busca e no indice unico
    // parcial de `pessoas(cpf)`. Misturar os dois na mesma coluna faria a busca
    // por CPF encontrar passaporte e o indice unico recusar cadastro legitimo.
    //
    // `documento_tipo` existe porque numero sem saber o que e nao serve para
    // nada. 'passaporte' e o caso previsto; ha tambem a RNM (Registro Nacional
    // Migratorio, ex-RNE), que e a identidade de estrangeiro emitida no Brasil,
    // e DNI/NIE para quem vem da Argentina ou da Espanha. Texto livre de
    // proposito — enumerar paises aqui envelheceria mal.
    //
    // SEM indice unico, e de proposito: nao ha formato para validar e dois
    // paises podem repetir numeracao. Um unico aqui recusaria cadastro
    // legitimo, que e pior do que aceitar duplicata que alguem confere depois.
    //
    // ADITIVA: nenhum fluxo publico mudou. O formulario de filiacao continua
    // exigindo CPF, e a busca por pessoa continua sendo por CPF. Isto e o LUGAR
    // do dado, para que ele deixe de se perder — quem preenche hoje e a
    // tesouraria, pelo /admin, ao cadastrar a mao um filiado estrangeiro.
    // O passo seguinte (campo no formulario publico, busca pelo documento) fica
    // para a campanha de 2027, que tem meses; ver CLAUDE.md.
    // "Completar Inscricao" no email de acesso ao evento prometia o fim quando
    // aquele e o comeco: depois do clique vem o formulario inteiro, a categoria,
    // as vezes o comprovante de matricula, e o pagamento.
    //
    // Troca por FRAGMENTO, e nao comparando o HTML inteiro: o corpo do template
    // carrega as cores do `.env` (ORG_COR_PRIMARIA), entao uma comparacao
    // exata nao casaria em outra instalacao. So mexe quando os TRES trechos
    // antigos estao presentes — se o tesoureiro editou o texto, algum deles ja
    // nao esta, e nada e sobrescrito.
    $tpl = $db->query("SELECT html FROM email_templates WHERE tipo = 'evento_acesso'")->fetchColumn();
    if (is_string($tpl) && $tpl !== '') {
        $trocas = [
            '<p>Você solicitou inscrição no evento <strong>{{evento}}</strong>.</p>'
                => '<p>Você pediu para se inscrever no evento <strong>{{evento}}</strong>.</p>',
            '<p>Clique no botão abaixo para completar sua inscrição:</p>'
                => '<p>No formulário você informa seus dados, escolhe a categoria e '
                 . 'faz o pagamento. Leva alguns minutos.</p>',
            '>Completar Inscrição<' => '>Preencher minha inscrição<',
        ];
        $todos = true;
        foreach ($trocas as $de => $para) {
            if (strpos($tpl, $de) === false) { $todos = false; break; }
        }
        if ($todos) {
            $novo = strtr($tpl, $trocas);
            $st = $db->prepare("UPDATE email_templates SET html = ? WHERE tipo = 'evento_acesso'");
            $st->execute([$novo]);
            error_log("Pilotis: template evento_acesso atualizado (botao 'Preencher minha inscricao')");
        }
    }

    // O template do comprovante trazia "CPF {{cpf}}" com o rotulo FIXO no
    // texto. Com `pessoas.documento`, isso passou a produzir "CPF Passaporte
    // XX0000000" — e ja produzia "CPF ," para quem nao tinha CPF. A frase certa
    // usa {{documento}}, que traz a propria virgula e some quando nao ha nada.
    //
    // Troca SO se o texto gravado for identico ao semeado. Template e editavel
    // pelo /admin, e sobrescrever o que o tesoureiro escreveu seria pior do que
    // o defeito — quem tiver editado corrige a mao, e a variavel nova esta
    // listada na tela.
    $antigo_comprovante = "<p>Declaramos para os devidos fins que <strong>{{nome}}</strong>, "
        . "CPF {{cpf}}, está inscrito(a) no evento <strong>{{evento}}</strong>, "
        . "na categoria <strong>{{categoria}}</strong>.</p>"
        . "<p>Inscrição no valor de <strong>{{valor}}</strong>, com pagamento "
        . "confirmado em <strong>{{data_pagamento}}</strong> por <strong>{{metodo}}</strong>.</p>";
    $novo_comprovante = str_replace(
        '<strong>{{nome}}</strong>, CPF {{cpf}}, está',
        '<strong>{{nome}}</strong>{{documento}} está',
        $antigo_comprovante
    );
    $st = $db->prepare("UPDATE email_templates SET html = ? WHERE tipo = 'evento_comprovante' AND html = ?");
    $st->execute([$novo_comprovante, $antigo_comprovante]);
    if ($st->rowCount() > 0) {
        error_log("Pilotis: template evento_comprovante migrado para {{documento}}");
    }

    // O botao dos emails quebrava em duas linhas na tela do celular.
    //
    // Era um `<a>` inline com padding vertical: o padding nao empurra a linha,
    // e o fundo verde e pintado por PEDACO de linha. Com o rotulo cabendo numa
    // linha ninguem via; quebrando em duas, saem dois retangulos deslocados com
    // o texto por fora. Chegou assim a comissao organizadora do sdrj05 em
    // 04/09/2026 — foram eles que mandaram a foto.
    //
    // A correcao ACRESCENTA ao style e nao reescreve o template: eles sao
    // editaveis pelo /admin, e sobrescrever o que o tesoureiro escreveu seria
    // pior que o defeito. Por isso um REPLACE na substring do botao, guardado
    // por NOT LIKE — quem ja tem `inline-block` fica como esta. Sao 14
    // templates, dos dois modulos, porque todos nascem do mesmo `$btn`.
    $st = $db->prepare(
        "UPDATE email_templates
            SET html = REPLACE(html,
                    'text-decoration: none; border-radius: 5px;',
                    'text-decoration: none; border-radius: 5px; display: inline-block; line-height: 1.35;')
          WHERE html LIKE '%padding: 15px 30px%'
            AND html NOT LIKE '%inline-block%'"
    );
    $st->execute();
    if ($st->rowCount() > 0) {
        error_log("Pilotis: botao de {$st->rowCount()} template(s) de email virou inline-block");
    }

    // Data, local e modalidade no comprovante: as pessoas o usam para pedir
    // dispensa de ponto no trabalho. Acrescenta {{quando_onde}} logo apos a
    // frase de abertura, e so se o trecho anterior estiver intacto.
    // A guarda e por IGUALDADE com o texto que a migracao ANTERIOR produz, e nao
    // "o fragmento existe e ainda nao ha {{quando_onde}}". Com a guarda fraca,
    // tirar a frase pelo /admin era desfeito no deploy seguinte, sem aviso: a
    // migracao a reinseria toda vez, contra a politica que este arquivo enuncia.
    $tpl = $db->query("SELECT html FROM email_templates WHERE tipo = 'evento_comprovante'")->fetchColumn();
    if (is_string($tpl) && $tpl === $novo_comprovante) {
        $de = 'na categoria <strong>{{categoria}}</strong>.</p>';
        $st = $db->prepare("UPDATE email_templates SET html = ? WHERE tipo = 'evento_comprovante'");
        $st->execute([str_replace($de, $de . '{{quando_onde}}', $tpl)]);
        error_log("Pilotis: template evento_comprovante ganhou {{quando_onde}}");
    }

    // Link para os anais do evento, publicados DEPOIS que ele acontece.
    //
    // O sistema de anais existe a parte (anais.docomomobrasil.com, Hugo, PDFs
    // no Zenodo com DOI) e o SLUG e a chave comum aos dois — sdrj04 la e
    // sdrj04 aqui. Mesmo assim o link e GRAVADO, e nao deduzido do slug: o
    // padrao de URL e do outro sistema, e um link montado por adivinhacao leva
    // a lugar nenhum no dia em que ele mudar.
    //
    // Vazio nao mostra nada. E o caso de todo evento que ainda nao aconteceu.
    garantir_coluna($db, 'eventos', 'url_anais', 'TEXT');

    // Modalidade do evento: presencial, online ou hibrido.
    //
    // Existe por causa do COMPROVANTE. Ele e usado para pedir dispensa de ponto
    // no trabalho, e para isso o papel tem de dizer que o evento e presencial e
    // quando e onde acontece — senao nao prova deslocamento nenhum. Sem coluna,
    // a alternativa seria fixar "presencial" no codigo, e o proximo evento
    // online sairia com um comprovante que afirma o contrario.
    //
    // NULL vale como 'presencial': e o caso dominante e o dos eventos ja
    // cadastrados, que ninguem vai reeditar so por isso.
    garantir_coluna($db, 'eventos', 'modalidade', 'TEXT');

    // Consentimento com o aviso de privacidade: QUAL versao e QUANDO.
    //
    // Fica no ato (filiacao, inscricao) e nao na pessoa, porque e por ato que
    // ela consente: quem se filiou em 2026 leu o texto de 2026, e uma inscricao
    // em evento coleta coisas que a filiacao nao coleta (nome no cracha,
    // presenca). Guardar so na pessoa apagaria essa distincao.
    //
    // Registros ANTERIORES ficam com NULL, e e o certo: ninguem consentiu com
    // um texto que nao existia. Preencher retroativamente seria inventar o
    // consentimento — exatamente o que o registro existe para impedir.
    garantir_coluna($db, 'filiacoes', 'consentimento_versao', 'TEXT');
    garantir_coluna($db, 'filiacoes', 'consentimento_em', 'DATETIME');
    garantir_coluna($db, 'inscricoes', 'consentimento_versao', 'TEXT');
    garantir_coluna($db, 'inscricoes', 'consentimento_em', 'DATETIME');

    garantir_coluna($db, 'pessoas', 'documento', 'TEXT');
    garantir_coluna($db, 'pessoas', 'documento_tipo', 'TEXT');

    garantir_coluna($db, 'eventos', 'apoiadores', 'TEXT');
    garantir_coluna($db, 'eventos', 'imagem_apoiadores', 'TEXT');

    // Logotipo de quem ORGANIZA, distinto da faixa de quem apoia. Coluna
    // propria porque o organizador muda a cada evento — o proximo seminario
    // pode ser de outro nucleo, e fixar a marca no codigo faria sair a errada.
    garantir_coluna($db, 'eventos', 'imagem_organizador', 'TEXT');
    garantir_coluna($db, 'eventos', 'data_valor_cheio', 'DATE');

    // Data em que as inscricoes ABREM. Anuncio, nao trava.
    //
    // Decisao do tesoureiro em 04/09/2026, quando a comissao do sdrj05 pediu
    // para anunciar 07/09: fechar de verdade barraria tambem quem ja tem
    // cobranca gerada, porque `evento_inscricoes_abertas()` guarda o formulario
    // E as tres telas de pagamento — duas pessoas estavam no meio do teste com
    // pagamento real. Entao a coluna so INFORMA, e quem quiser entrar antes
    // entra.
    //
    // Vazia, nada muda: a tela continua dizendo apenas ate quando vai.
    garantir_coluna($db, 'eventos', 'data_inicio_inscricao', 'DATE');

    // Cartaz/banner do evento (arquivo PUBLICO, dentro de assets/img/eventos/)
    garantir_coluna($db, 'eventos', 'imagem_path', 'TEXT');

    // Quem assina o recibo DESTE evento (um por linha). E do evento, nao da
    // entidade: cada seminario tem sua coordenacao.
    garantir_coluna($db, 'eventos', 'assinantes', 'TEXT');

    // Contato do evento (ex: "Comissao organizadora <contato@evento.org>"). E o
    // endereco que aparece em primeiro plano no comprovante: quem tira duvida
    // sobre o evento e a organizacao dele, nao a tesouraria nacional.
    garantir_coluna($db, 'eventos', 'email_contato', 'TEXT');

    // A pagina do Pilotis E a pagina do evento. O Docomomo-RJ nao fez site do
    // V Seminario, e o cartaz vai impresso com esta URL: o sistema deixa de so
    // processar inscricao e passa a publicar.
    //
    // Sao dois campos porque sao dois usos. `descricao` e a linha de chamada —
    // aparece na lista de eventos e vai para a meta tag que o WhatsApp mostra.
    // `conteudo` e o corpo da pagina: apresentacao, eixos tematicos,
    // programacao, comissoes. Um nao serve de resumo do outro.
    garantir_coluna($db, 'eventos', 'conteudo', 'TEXT');

    // Programacao do evento, em PDF. A coluna nasce no primeiro deploy ainda que
    // o arquivo so exista semanas depois: sem ela, publicar a programacao em
    // outubro exigiria outro deploy por FTP. Ver salvar_programa_evento().
    garantir_coluna($db, 'eventos', 'programa_path', 'TEXT');

    // Onde acontece, por extenso, com endereco. Fica no evento e nao no texto
    // porque tambem vai para o cabecalho da pagina e para o cracha.
    garantir_coluna($db, 'eventos', 'local', 'TEXT');
    $db->exec("CREATE INDEX IF NOT EXISTS idx_inscricoes_evento ON inscricoes(evento_id, status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_inscricoes_pessoa ON inscricoes(pessoa_id)");

    // CPF: normaliza formato (so digitos) e garante unicidade via indice parcial.
    // Quem nao tem CPF (NULL ou '') fica fora do indice. Os pontos que gravam CPF
    // fazem pre-checagem (cpf_pertence_a_outra_pessoa) para nunca estourar aqui;
    // o indice e o backstop contra caminhos esquecidos.
    try {
        $db->exec("
            UPDATE pessoas
            SET cpf = REPLACE(REPLACE(REPLACE(REPLACE(cpf,'.',''),'-',''),'/',''),' ','')
            WHERE cpf IS NOT NULL AND cpf != ''
            AND (cpf LIKE '%.%' OR cpf LIKE '%-%' OR cpf LIKE '%/%' OR cpf LIKE '% %')
        ");
        // Zero a esquerda comido por planilha. Sao 41 cadastros (36 com 10
        // digitos, 4 com 9, 1 com 8), todos com filiacao em 2025 ou 2026 — ou
        // seja, gente que pode ir pagar. O CPF truncado sai para o PagBank como
        // esta e volta `must be a valid CPF or CNPJ`, em ingles, na tela de
        // pagamento; foi assim que seis pessoas bateram 30 vezes nessa recusa.
        //
        // So corrige quando o numero completado a esquerda PASSA no digito
        // verificador. E o que distingue recuperar de inventar: dos 41, os 41
        // passam. Qualquer outro fica como esta, para alguem olhar.
        //
        // Roda em PHP e nao em SQL porque o digito verificador nao se calcula
        // em SQLite sem funcao propria. Sao dezenas de linhas, uma vez so.
        $curtos = $db->query("
            SELECT id, cpf FROM pessoas
            WHERE cpf IS NOT NULL AND TRIM(cpf) <> '' AND LENGTH(cpf) < 11
        ")->fetchAll(PDO::FETCH_ASSOC);

        if ($curtos) {
            $upd = $db->prepare("UPDATE pessoas SET cpf = ? WHERE id = ?");
            $corrigidos = 0;
            foreach ($curtos as $p) {
                $d = preg_replace('/\D/', '', (string)$p['cpf']);
                if ($d === '' || strlen($d) >= 11) continue;
                $completo = str_pad($d, 11, '0', STR_PAD_LEFT);
                if (!cpf_valido($completo)) continue;

                // Nao atropela cadastro alheio: se o completo ja e de outra
                // pessoa, sao duas linhas para consolidar a mao, e o indice
                // unico recusaria de qualquer forma.
                $ja = $db->prepare("SELECT id FROM pessoas WHERE cpf = ? AND id <> ? LIMIT 1");
                $ja->execute([$completo, $p['id']]);
                if ($ja->fetchColumn()) {
                    error_log("Pilotis: CPF truncado da pessoa {$p['id']} colide com outro cadastro ao completar; deixado como esta");
                    continue;
                }

                $upd->execute([$completo, $p['id']]);
                $corrigidos++;
            }
            if ($corrigidos) {
                error_log("Pilotis: $corrigidos CPF(s) truncado(s) recuperados com zero a esquerda");
            }
        }

        $db->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS idx_pessoas_cpf_unico
            ON pessoas(cpf) WHERE cpf IS NOT NULL AND cpf != ''
        ");
    } catch (PDOException $e) {
        // Se houver duplicatas pre-existentes o indice nao e criado; sistema segue
        // funcionando e o caso deve ser consolidado manualmente (ver logs)
        error_log("Pilotis: indice unico de CPF nao criado: " . $e->getMessage());
    }

    // pagbank_pedidos: pedidos de evento têm filiacao_id NULL e inscricao_id preenchido.
    // A tabela original tinha filiacao_id NOT NULL — rebuild único para relaxar.
    // A decisao de reconstruir e RECONFERIDA dentro do lock. Lida antes do
    // BEGIN IMMEDIATE, ela e uma leitura suja: dois processos veem a tabela
    // velha, os dois entram, e o segundo reconstroi sobre a tabela que o
    // primeiro acabou de criar. Aqui a pergunta e feita ja com a escrita
    // reservada, e quem chega depois desiste sem tocar em nada.
    $colunas = colunas_da_tabela($db, 'pagbank_pedidos');
    if ($colunas && !in_array('inscricao_id', $colunas, true)) {
        $db->exec("BEGIN IMMEDIATE");
        $colunas = colunas_da_tabela($db, 'pagbank_pedidos');
        if (in_array('inscricao_id', $colunas, true)) {
            $db->exec("ROLLBACK");
        } else {
        try {
            $db->exec("
                CREATE TABLE pagbank_pedidos_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    filiacao_id INTEGER,
                    inscricao_id INTEGER,
                    order_id TEXT NOT NULL,
                    metodo TEXT,
                    created_at DATETIME DEFAULT (datetime('now','localtime')),
                    FOREIGN KEY (filiacao_id) REFERENCES filiacoes(id) ON DELETE CASCADE,
                    FOREIGN KEY (inscricao_id) REFERENCES inscricoes(id) ON DELETE CASCADE
                )
            ");
            $db->exec("
                INSERT INTO pagbank_pedidos_new (id, filiacao_id, order_id, metodo, created_at)
                SELECT id, filiacao_id, order_id, metodo, created_at FROM pagbank_pedidos
            ");
            $db->exec("DROP TABLE pagbank_pedidos");
            $db->exec("ALTER TABLE pagbank_pedidos_new RENAME TO pagbank_pedidos");
            // O DROP levou os indices junto, e o CREATE TABLE IF NOT EXISTS la
            // de cima nunca mais roda — sem isto eles some para sempre, em
            // silencio, e a tabela cresce a cada PIX e cada boleto.
            $db->exec("CREATE INDEX IF NOT EXISTS idx_pagbank_pedidos_filiacao ON pagbank_pedidos(filiacao_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_pagbank_pedidos_order ON pagbank_pedidos(order_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_pagbank_pedidos_inscricao ON pagbank_pedidos(inscricao_id)");
            $db->exec("COMMIT");
        } catch (PDOException $e) {
            // O SQLite faz rollback sozinho em SQLITE_FULL, SQLITE_IOERR e
            // SQLITE_NOMEM. Nesses casos a transacao ja nao esta ativa e o
            // ROLLBACK lanca a PROPRIA excecao de dentro do catch — era ela
            // que subia, e o log dizia "cannot rollback - no transaction is
            // active" em vez de "database or disk is full". Sem SSH, isso manda
            // o diagnostico para o lado errado.
            try { $db->exec("ROLLBACK"); } catch (PDOException $ignorado) {}
            throw $e;
        }
        }
    }

    // lembretes_agendados: mesmo caso (lembretes de inscrição têm filiacao_id NULL)
    // A decisao de reconstruir e RECONFERIDA dentro do lock. Lida antes do
    // BEGIN IMMEDIATE, ela e uma leitura suja: dois processos veem a tabela
    // velha, os dois entram, e o segundo reconstroi sobre a tabela que o
    // primeiro acabou de criar. Aqui a pergunta e feita ja com a escrita
    // reservada, e quem chega depois desiste sem tocar em nada.
    $colunas = colunas_da_tabela($db, 'lembretes_agendados');
    if ($colunas && !in_array('inscricao_id', $colunas, true)) {
        $db->exec("BEGIN IMMEDIATE");
        $colunas = colunas_da_tabela($db, 'lembretes_agendados');
        if (in_array('inscricao_id', $colunas, true)) {
            $db->exec("ROLLBACK");
        } else {
        try {
            $db->exec("
                CREATE TABLE lembretes_agendados_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    filiacao_id INTEGER,
                    inscricao_id INTEGER,
                    tipo TEXT NOT NULL,
                    data_agendada DATE NOT NULL,
                    enviado INTEGER DEFAULT 0,
                    created_at DATETIME DEFAULT (datetime('now','localtime')),
                    enviado_at DATETIME,
                    FOREIGN KEY (filiacao_id) REFERENCES filiacoes(id) ON DELETE CASCADE,
                    FOREIGN KEY (inscricao_id) REFERENCES inscricoes(id) ON DELETE CASCADE
                )
            ");
            $db->exec("
                INSERT INTO lembretes_agendados_new (id, filiacao_id, tipo, data_agendada, enviado, created_at, enviado_at)
                SELECT id, filiacao_id, tipo, data_agendada, enviado, created_at, enviado_at FROM lembretes_agendados
            ");
            $db->exec("DROP TABLE lembretes_agendados");
            $db->exec("ALTER TABLE lembretes_agendados_new RENAME TO lembretes_agendados");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_lembretes_data ON lembretes_agendados(data_agendada, enviado)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_lembretes_filiacao ON lembretes_agendados(filiacao_id)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_lembretes_inscricao ON lembretes_agendados(inscricao_id)");
            $db->exec("COMMIT");
        } catch (PDOException $e) {
            // O SQLite faz rollback sozinho em SQLITE_FULL, SQLITE_IOERR e
            // SQLITE_NOMEM. Nesses casos a transacao ja nao esta ativa e o
            // ROLLBACK lanca a PROPRIA excecao de dentro do catch — era ela
            // que subia, e o log dizia "cannot rollback - no transaction is
            // active" em vez de "database or disk is full". Sem SSH, isso manda
            // o diagnostico para o lado errado.
            try { $db->exec("ROLLBACK"); } catch (PDOException $ignorado) {}
            throw $e;
        }
        }
    }

    // Conta as tentativas de envio: o lembrete volta para a fila quando o Brevo
    // recusa, e esta coluna e o que impede o retorno virar laco infinito.
    //
    // DEPOIS da reconstrucao acima, e nao antes. O SQLite nao acrescenta coluna
    // com chave estrangeira, entao a tabela e refeita por copia — e a copia
    // lista as colunas uma a uma. Enquanto este ALTER vinha antes, a coluna era
    // criada e a reconstrucao a jogava fora no mesmo `get_db()`. Em producao,
    // que ainda nao tem `inscricao_id`, a reconstrucao roda no primeiro acesso
    // apos o deploy: `tentativas` nasceria e morreria ali, e o LembreteService
    // quebraria ao gravar. O ALTER cego nao dizia nada; `tests/migracao.php`
    // pegou.
    garantir_coluna($db, 'lembretes_agendados', 'tentativas', 'INTEGER DEFAULT 0');

    // View para autocomplete (valores únicos de todos os anos).
    //
    // DROP + CREATE roda a cada get_db(), em autocommit. Com duas requisicoes
    // simultaneas — o tesoureiro abrindo a pagina enquanto o cron dispara — a
    // que perder a corrida recebe "view autocomplete_valores already exists" e
    // uma pagina de 500. Medido: falha em 2 de 15 com 8 processos. Nao ha perda
    // de dado, a requisicao seguinte refaz; mas o 500 assusta e, logo depois de
    // um deploy, manda o diagnostico para o lado errado.
    //
    // O try/catch resolve porque o unico jeito de isso falhar e outro processo
    // ter acabado de criar a MESMA view, com a mesma definicao.
    try {
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
    } catch (PDOException $e) {
        error_log('Pilotis: recriacao de autocomplete_valores ignorada (corrida): ' . $e->getMessage());
    }
}
