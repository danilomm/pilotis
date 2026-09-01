<?php
/**
 * Pilotis — Controller Administrativo: sessao, painel e buscas.
 *
 * Tinha 3.058 linhas e 70 metodos. Em 29/08/2026 foi dividido por assunto;
 * os outros controllers do admin ESTENDEM este, e por isso herdam
 * exigirLogin() — a checagem que toda rota administrativa faz.
 *
 * O corte foi possivel porque o grafo de chamadas era limpo: tudo dependia
 * de exigirLogin(), e os auxiliares de cada assunto so eram usados dentro
 * do proprio assunto.
 */

class AdminController {

    /**
     * Verifica se usuario esta logado
     */
    protected static function verificarSessao(): bool {
        start_session();
        $session_id = $_COOKIE['admin_session'] ?? null;
        $sessions = $_SESSION['admin_sessions'] ?? [];

        if (!$session_id || !isset($sessions[$session_id])) {
            return false;
        }

        // Sessao valida por 24 horas
        $created = $sessions[$session_id];
        if (time() - $created > 86400) {
            unset($_SESSION['admin_sessions'][$session_id]);
            return false;
        }

        return true;
    }

    /**
     * Redireciona para login se nao autenticado
     */
    protected static function exigirLogin(): void {
        if (!self::verificarSessao()) {
            redirect('/admin/login');
        }
    }

    /**
     * Pagina de login
     */
    public static function loginForm(): void {
        $titulo = "Admin - Login";
        $erro = get_flash('error');

        ob_start();
        require SRC_DIR . '/Views/admin/login.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Processa login
     */
    public static function login(): void {
        start_session();
        $senha = $_POST['senha'] ?? '';

        if (empty(ADMIN_PASSWORD)) {
            flash('error', 'Senha admin nao configurada.');
            redirect('/admin/login');
            return;
        }

        // Limite de tentativas por IP: uma senha so, sem trava, e alvo de
        // forca bruta. Mesmo padrao do painel da organizacao — contagem no
        // log por janela de tempo, sem tabela nova. A resposta ao bloqueio e
        // identica a da senha errada, para nao denunciar a trava.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        $falhas = db_fetch_one("
            SELECT COUNT(*) AS n FROM log
            WHERE tipo = 'admin_login_falha'
            AND mensagem LIKE ?
            AND timestamp >= datetime('now','localtime','-15 minutes')
        ", ['%[' . $ip . ']%']);

        if ((int)($falhas['n'] ?? 0) >= 5) {
            registrar_log('admin_login_bloqueado', null, "Login bloqueado por excesso de tentativas [$ip]");
            sleep(2);
            flash('error', 'Senha incorreta.');
            redirect('/admin/login');
            return;
        }

        // Compara senha (suporta bcrypt, SHA256 legado e texto plano)
        $senha_correta = false;
        if (strpos(ADMIN_PASSWORD, '$2y$') === 0 || strpos(ADMIN_PASSWORD, '$2b$') === 0) {
            $senha_correta = password_verify($senha, ADMIN_PASSWORD);
        } elseif (strpos(ADMIN_PASSWORD, 'sha256:') === 0) {
            $hash_esperado = substr(ADMIN_PASSWORD, 7);
            $hash_fornecido = hash('sha256', $senha);
            $senha_correta = hash_equals($hash_esperado, $hash_fornecido);
        } else {
            $senha_correta = hash_equals(ADMIN_PASSWORD, $senha);
        }

        if (!$senha_correta) {
            registrar_log('admin_login_falha', null, "Senha incorreta no admin [$ip]");
            flash('error', 'Senha incorreta.');
            redirect('/admin/login');
            return;
        }

        // Cria sessao
        $session_id = bin2hex(random_bytes(32));
        $_SESSION['admin_sessions'][$session_id] = time();

        setcookie('admin_session', $session_id, [
            'expires' => time() + 86400,
            'path' => '/',
            'httponly' => true,
            'secure' => true,
            'samesite' => 'Lax'
        ]);

        redirect('/admin');
    }

    /**
     * Logout
     */
    public static function logout(): void {
        start_session();
        $session_id = $_COOKIE['admin_session'] ?? null;

        if ($session_id && isset($_SESSION['admin_sessions'][$session_id])) {
            unset($_SESSION['admin_sessions'][$session_id]);
        }

        setcookie('admin_session', '', ['expires' => time() - 3600, 'path' => '/']);
        redirect('/admin/login');
    }

    /**
     * Painel principal
     */
    public static function painel(): void {
        self::exigirLogin();

        $ano = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');
        $ordem = $_GET['ordem'] ?? 'data';
        $status = $_GET['status'] ?? '';

        // Anos disponíveis: anos com filiações + ano atual + próximo ano
        $anos_db = db_fetch_all("SELECT DISTINCT ano FROM filiacoes ORDER BY ano DESC");
        $anos_existentes = array_column($anos_db, 'ano');
        $ano_atual = (int)date('Y');
        $anos_disponiveis = array_unique(array_merge(
            [$ano_atual + 1, $ano_atual],
            $anos_existentes
        ));
        rsort($anos_disponiveis);

        // Estatisticas (exclui nao_filiado)
        $stats = db_fetch_one("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pago' THEN 1 ELSE 0 END) as pagos,
                -- 'Nao pagos' precisa ser TODO O RESTO, senao Total != Pagos +
                -- Nao pagos. Contar so tres dos seis status deixava 1308
                -- filiacoes de 2026 (as 'nao_pago' de campanha encerrada) fora
                -- das duas colunas: a tela dizia 1643 no total, 172 pagos e 163
                -- nao pagos.
                SUM(CASE WHEN status != 'pago' THEN 1 ELSE 0 END) as nao_pagos,
                -- Quem ainda pode pagar: o funil vivo, que e outra pergunta.
                SUM(CASE WHEN status IN ('pendente','enviado','acesso') THEN 1 ELSE 0 END) as pendentes,
                SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) as arrecadado
            FROM filiacoes WHERE ano = ? AND categoria <> 'nao_filiado'
        ", [$ano]);

        // Ordenação
        $order_by = match($ordem) {
            'nome' => 'p.nome ASC',
            'categoria' => 'f.categoria ASC, p.nome ASC',
            'status' => 'f.status ASC, p.nome ASC',
            default => 'COALESCE(f.status_at, f.created_at) DESC'
        };

        // Filtro de status
        $status_filter = '';
        $params = [$ano];
        if ($status) {
            $status_filter = ' AND f.status = ?';
            $params[] = $status;
        }

        // Filiações
        $pagamentos = db_fetch_all("
            SELECT f.id, f.pessoa_id, p.nome,
                   (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email,
                   f.valor, f.status, f.metodo,
                   f.created_at, f.data_pagamento, f.categoria, f.status_at
            FROM filiacoes f
            JOIN pessoas p ON p.id = f.pessoa_id
            WHERE f.ano = ? AND f.categoria <> 'nao_filiado' $status_filter
            ORDER BY
                CASE f.status WHEN 'pendente' THEN 0 WHEN 'enviado' THEN 1 WHEN 'acesso' THEN 2 ELSE 3 END,
                $order_by
        ", $params);

        // Ordenação com suporte a acentos (quando ordenando por nome)
        if ($ordem === 'nome' && class_exists('Collator')) {
            $collator = new Collator('pt_BR');
            usort($pagamentos, fn($a, $b) => $collator->compare($a['nome'] ?? '', $b['nome'] ?? ''));
        }

        // Eventos, no topo do painel.
        //
        // Ate 31/08/2026 o /admin abria direto na filiacao do ano e os eventos
        // ficavam atras de um botao. Isso descrevia o sistema de antes, quando
        // ele so processava a campanha anual — mas em novembro a pergunta do dia
        // e "quantas inscricoes chegaram e quanto entrou", e ela estava a dois
        // cliques.
        //
        // So os PUBLICADOS: rascunho e do tesoureiro montando, e nao tem numero
        // que interesse. Ordenados pelo que acontece primeiro.
        $eventos_painel = db_fetch_all("
            SELECT e.id, e.nome, e.slug, e.data_inicio, e.data_fim, e.prazo_inscricao,
                   (SELECT COUNT(*) FROM inscricoes i WHERE i.evento_id = e.id
                     AND i.status IN ('pago','gratuita_confirmada')) AS confirmadas,
                   (SELECT COUNT(*) FROM inscricoes i WHERE i.evento_id = e.id
                     AND i.status = 'pendente') AS pendentes,
                   (SELECT COALESCE(SUM(i.valor), 0) FROM inscricoes i WHERE i.evento_id = e.id
                     AND i.status = 'pago') AS arrecadado
            FROM eventos e
            WHERE e.status = 'publicado'
            ORDER BY COALESCE(e.data_inicio, e.prazo_inscricao) ASC, e.id ASC
        ");

        $titulo = "Admin - Painel";

        ob_start();
        require SRC_DIR . '/Views/admin/painel.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Lista todos os contatos
     */
    public static function contatos(): void {
        self::exigirLogin();

        $ordem = $_GET['ordem'] ?? 'nome';
        $filtro = $_GET['filtro'] ?? 'ativos';

        // Ordenação
        $order_by = match($ordem) {
            'ultima' => 'ultima_filiacao DESC, p.nome ASC',
            default => 'p.nome ASC'
        };

        // Filtro por ativo
        $where = match($filtro) {
            'inativos' => 'WHERE p.ativo = 0',
            'todos' => '',
            default => 'WHERE p.ativo = 1',
        };

        // Contagem por status
        $total_ativos = (int)(db_fetch_one("SELECT COUNT(*) as t FROM pessoas WHERE ativo = 1")['t'] ?? 0);
        $total_inativos = (int)(db_fetch_one("SELECT COUNT(*) as t FROM pessoas WHERE ativo = 0")['t'] ?? 0);

        // Todos os contatos com última filiação paga
        $contatos = db_fetch_all("
            SELECT p.id, p.nome, p.ativo,
                   (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email,
                   (SELECT MAX(f.ano) FROM filiacoes f
                    WHERE f.pessoa_id = p.id AND f.status = 'pago' AND f.categoria <> 'nao_filiado'
                   ) as ultima_filiacao
            FROM pessoas p
            $where
            ORDER BY $order_by
        ");

        // Ordenação com suporte a acentos (quando ordenando por nome)
        if ($ordem === 'nome' && class_exists('Collator')) {
            $collator = new Collator('pt_BR');
            usort($contatos, fn($a, $b) => $collator->compare($a['nome'] ?? '', $b['nome'] ?? ''));
        }

        $titulo = "Admin - Contatos";

        ob_start();
        require SRC_DIR . '/Views/admin/contatos.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Busca cadastrados
     */

    /**
     * Tela de log, com destaque para o que pede acao.
     *
     * POR QUE EXISTE: toda a resposta a incidente construida em 28-29/08/2026
     * grava em `log` — pagamento_orfao, consolidacao_cobranca_orfa,
     * valor_divergente, csrf_recusado, lembrete_desistido. Nenhuma dessas
     * linhas tinha leitor: o servidor nao tem SSH, e a unica saida do banco era
     * o backup. Registrar sem ler e trabalho perdido; pior, da a impressao de
     * que o problema esta coberto.
     */
    public static function log(): void {
        self::exigirLogin();

        // Tipos que significam "alguem precisa olhar isto", em ordem de
        // gravidade. Sao poucos de proposito: lista longa vira ruido e ninguem
        // le. Cada um aqui representa dinheiro, dado pessoal ou aviso perdido.
        $criticos = tipos_log_criticos();

        $tipo  = trim($_GET['tipo'] ?? '');
        $q     = trim($_GET['q'] ?? '');
        $dias  = max(1, min(365, (int)($_GET['dias'] ?? 30)));

        $where  = ["timestamp >= datetime('now','localtime','-$dias days')"];
        $params = [];
        if ($tipo !== '') { $where[] = 'tipo = ?';           $params[] = $tipo; }
        if ($q !== '')    { $where[] = 'mensagem LIKE ?';    $params[] = '%' . $q . '%'; }

        $registros = db_fetch_all(
            "SELECT id, timestamp, tipo, pessoa_id, mensagem FROM log
             WHERE " . implode(' AND ', $where) . "
             ORDER BY id DESC LIMIT 300", $params);

        // Painel de pendencias: conta os criticos na janela, independente do
        // filtro da tela, para que filtrar nao esconda o que importa.
        $marcadores = implode(',', array_fill(0, count($criticos), '?'));
        $pendencias = db_fetch_all(
            "SELECT tipo, COUNT(*) AS n, MAX(timestamp) AS ultimo FROM log
             WHERE tipo IN ($marcadores)
             AND timestamp >= datetime('now','localtime','-$dias days')
             GROUP BY tipo ORDER BY n DESC", array_keys($criticos));

        $tipos = db_fetch_all(
            "SELECT tipo, COUNT(*) AS n FROM log
             WHERE timestamp >= datetime('now','localtime','-$dias days')
             GROUP BY tipo ORDER BY tipo");

        $titulo = 'Log do sistema';
        ob_start();
        require SRC_DIR . '/Views/admin/log.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    public static function buscar(): void {
        self::exigirLogin();

        $q = trim($_GET['q'] ?? '');
        $resultados = [];
        $truncado = false;

        if ($q) {
            // Separa palavras para buscar todas (AND)
            $palavras = preg_split('/\s+/', $q);
            $where_parts = [];
            $params = [];
            foreach ($palavras as $p) {
                $where_parts[] = "(p.nome LIKE ? OR busca.email LIKE ? OR REPLACE(REPLACE(IFNULL(p.cpf,''),'.',''),'-','') LIKE ?)";
                $params[] = "%$p%";
                $params[] = "%$p%";
                $digitos = preg_replace('/\D/', '', $p);
                // So procura por CPF quando ha digito: sem isso, buscar
                // "clarice" virava LIKE '%%' e trazia a base inteira.
                $params[] = $digitos !== '' ? "%$digitos%" : "\x00nunca";
            }
            $where = implode(' AND ', $where_parts);

            // A busca casava so contra o email PRINCIPAL, e ha 153 secundarios
            // na base. Colar no campo um endereco do extrato do PagBank que
            // esteja cadastrado como secundario devolvia "nenhum resultado" — e
            // a conclusao natural, criar cadastro novo, e o que produziu o caso
            // Maisa (pagamento orfao por 15 meses). Agora casa contra QUALQUER
            // email da pessoa (`busca` na subconsulta), e continua exibindo o
            // principal na coluna.
            $resultados = db_fetch_all("
                SELECT p.id, p.nome, e.email, p.token,
                       GROUP_CONCAT(DISTINCT f.ano || ':' || f.status) as filiacoes,
                       -- A busca acha a PESSOA, e ate 31/08/2026 so mostrava as
                       -- filiacoes dela: quem se inscreveu num evento e nunca se
                       -- filiou aparecia sem nada ao lado do nome, como se o
                       -- sistema nao soubesse de nada. Sao dois vinculos, e a
                       -- ferramenta e comum aos dois modulos.
                       (SELECT GROUP_CONCAT(ev.slug || ' — ' || COALESCE(c.nome, 'sem categoria'), '; ')
                          FROM inscricoes i
                          JOIN eventos ev ON ev.id = i.evento_id
                          LEFT JOIN evento_categorias c ON c.id = i.categoria_id
                         WHERE i.pessoa_id = p.id) as inscricoes
                FROM pessoas p
                LEFT JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
                LEFT JOIN emails busca ON busca.pessoa_id = p.id
                LEFT JOIN filiacoes f ON f.pessoa_id = p.id
                WHERE $where
                GROUP BY p.id
                ORDER BY p.nome
                LIMIT 51
            ", $params);

            // 51 para saber se havia mais: o corte silencioso em 50, com
            // ordenacao alfabetica, faz quem procura um sobrenome comum
            // concluir que a pessoa nao esta cadastrada.
            $truncado = count($resultados) > 50;
            if ($truncado) {
                array_pop($resultados);
            }
        }

        $titulo = "Admin - Buscar";

        ob_start();
        require SRC_DIR . '/Views/admin/buscar.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    public static function downloadBanco(): void {
        self::exigirLogin();

        $db_path = DATABASE_PATH;
        if (!file_exists($db_path)) {
            flash('error', 'Banco nao encontrado.');
            redirect('/admin');
            return;
        }

        $filename = 'pilotis_backup_' . date('Ymd_His') . '.db';

        header('Content-Type: application/x-sqlite3');
        header("Content-Disposition: attachment; filename=\"$filename\"");
        header('Content-Length: ' . filesize($db_path));
        readfile($db_path);
        exit;
    }

    /**
     * Download dos filiados em CSV
     */
    public static function downloadCsv(): void {
        self::exigirLogin();

        $ano = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');

        $rows = db_fetch_all("
            SELECT p.nome, e.email, p.cpf,
                   f.telefone, f.categoria, f.endereco, f.cep, f.cidade, f.estado, f.pais,
                   f.profissao, f.instituicao,
                   f.valor, f.metodo, f.status, f.data_pagamento
            FROM pessoas p
            JOIN filiacoes f ON f.pessoa_id = p.id
            LEFT JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
            WHERE f.ano = ?
            ORDER BY f.status DESC, p.nome
        ", [$ano]);

        $filename = "filiados_$ano.csv";

        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"$filename\"");

        $output = fopen('php://output', 'w');

        // BOM para Excel reconhecer UTF-8
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Cabecalho
        fputcsv($output, [
            'Nome', 'Email', 'CPF', 'Telefone', 'Categoria',
            'Endereco', 'CEP', 'Cidade', 'Estado', 'Pais',
            'Profissao', 'Instituicao',
            'Valor', 'Metodo', 'Status', 'Data Pagamento'
        ], ';');

        $categorias_display = [
            'estudante' => 'Estudante',
            'profissional_nacional' => 'Profissional Brasil',
            'profissional_internacional' => 'Profissional Internacional',
        ];

        foreach ($rows as $r) {
            // Mesma neutralizacao do CSV de eventos: instituicao, profissao e
            // endereco vem do formulario publico, e celula comecando por
            // = + - @ e executada como formula ao abrir no Excel.
            fputcsv($output, array_map('neutralizar_celula', [
                $r['nome'],
                $r['email'],
                $r['cpf'] ?? '',
                $r['telefone'] ?? '',
                $categorias_display[$r['categoria'] ?? ''] ?? ($r['categoria'] ?? ''),
                $r['endereco'] ?? '',
                $r['cep'] ?? '',
                $r['cidade'] ?? '',
                $r['estado'] ?? '',
                $r['pais'] ?? '',
                $r['profissao'] ?? '',
                $r['instituicao'] ?? '',
                $r['valor'] ? formatar_valor((int)$r['valor']) : '',
                $r['metodo'] ?? '',
                $r['status'] ?? '',
                $r['data_pagamento'] ?? ''
            ]), ';');
        }

        fclose($output);
        exit;
    }

}