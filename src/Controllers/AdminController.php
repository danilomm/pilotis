<?php
/**
 * Pilotis - Controller Administrativo
 */

class AdminController {

    /**
     * Verifica se usuario esta logado
     */
    private static function verificarSessao(): bool {
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
    private static function exigirLogin(): void {
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

        // Compara senha (suporta texto plano e hash SHA256)
        $senha_correta = false;
        if (strpos(ADMIN_PASSWORD, 'sha256:') === 0) {
            $hash_esperado = substr(ADMIN_PASSWORD, 7);
            $hash_fornecido = hash('sha256', $senha);
            $senha_correta = hash_equals($hash_esperado, $hash_fornecido);
        } else {
            $senha_correta = hash_equals(ADMIN_PASSWORD, $senha);
        }

        if (!$senha_correta) {
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

        // Estatisticas (exclui nao_filiado)
        $stats = db_fetch_one("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pago' THEN 1 ELSE 0 END) as pagos,
                SUM(CASE WHEN status = 'pendente' OR status = 'enviado' OR status = 'acesso' THEN 1 ELSE 0 END) as pendentes,
                SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) as arrecadado
            FROM filiacoes WHERE ano = ? AND categoria <> 'nao_filiado'
        ", [$ano]);

        // Ordenação
        $order_by = match($ordem) {
            'nome' => 'p.nome ASC',
            'categoria' => 'f.categoria ASC, p.nome ASC',
            'status' => 'f.status ASC, p.nome ASC',
            default => 'f.data_pagamento DESC, f.created_at DESC'
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
                   f.created_at, f.data_pagamento, f.categoria
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

        // Ordenação
        $order_by = match($ordem) {
            'ultima' => 'ultima_filiacao DESC, p.nome ASC',
            default => 'p.nome ASC'
        };

        // Todos os contatos com última filiação paga
        $contatos = db_fetch_all("
            SELECT p.id, p.nome,
                   (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email,
                   (SELECT MAX(f.ano) FROM filiacoes f
                    WHERE f.pessoa_id = p.id AND f.status = 'pago' AND f.categoria <> 'nao_filiado'
                   ) as ultima_filiacao
            FROM pessoas p
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
    public static function buscar(): void {
        self::exigirLogin();

        $q = trim($_GET['q'] ?? '');
        $resultados = [];

        if ($q) {
            $resultados = db_fetch_all("
                SELECT p.id, p.nome, e.email, p.token,
                       GROUP_CONCAT(f.ano || ':' || f.status, ', ') as filiacoes
                FROM pessoas p
                LEFT JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
                LEFT JOIN filiacoes f ON f.pessoa_id = p.id
                WHERE e.email LIKE ? OR p.nome LIKE ?
                GROUP BY p.id
                ORDER BY p.nome
                LIMIT 50
            ", ["%$q%", "%$q%"]);
        }

        $titulo = "Admin - Buscar";

        ob_start();
        require SRC_DIR . '/Views/admin/buscar.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Detalhes de uma pessoa
     */
    public static function pessoa(string $id): void {
        self::exigirLogin();

        $pessoa = db_fetch_one("
            SELECT p.*, e.email
            FROM pessoas p
            LEFT JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
            WHERE p.id = ?
        ", [(int)$id]);

        if (!$pessoa) {
            flash('error', 'Pessoa nao encontrada.');
            redirect('/admin');
            return;
        }

        // Se não tem email principal, pega qualquer um
        if (!$pessoa['email']) {
            $email_row = db_fetch_one("SELECT email FROM emails WHERE pessoa_id = ? LIMIT 1", [(int)$id]);
            $pessoa['email'] = $email_row['email'] ?? '';
        }

        $filiacoes = db_fetch_all("
            SELECT * FROM filiacoes
            WHERE pessoa_id = ?
            ORDER BY ano DESC
        ", [(int)$id]);

        $salvo = isset($_GET['salvo']);
        $titulo = "Admin - " . ($pessoa['nome'] ?: 'Pessoa');

        ob_start();
        require SRC_DIR . '/Views/admin/pessoa.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Salva alteracoes de uma pessoa
     */
    public static function salvarPessoa(string $id): void {
        self::exigirLogin();

        $pessoa = db_fetch_one("SELECT id FROM pessoas WHERE id = ?", [(int)$id]);
        if (!$pessoa) {
            flash('error', 'Pessoa nao encontrada.');
            redirect('/admin');
            return;
        }

        $nome = trim($_POST['nome'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $cpf = trim($_POST['cpf'] ?? '') ?: null;
        $notas = trim($_POST['notas'] ?? '') ?: null;

        // Atualiza pessoa
        db_execute("
            UPDATE pessoas SET
                nome = ?, cpf = ?, notas = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ", [$nome, $cpf, $notas, (int)$id]);

        // Atualiza email principal
        if ($email) {
            $email_existe = db_fetch_one(
                "SELECT id FROM emails WHERE pessoa_id = ? AND principal = 1",
                [(int)$id]
            );
            if ($email_existe) {
                db_execute(
                    "UPDATE emails SET email = ? WHERE id = ?",
                    [$email, $email_existe['id']]
                );
            } else {
                db_execute(
                    "INSERT INTO emails (pessoa_id, email, principal) VALUES (?, ?, 1)",
                    [(int)$id, $email]
                );
            }
        }

        registrar_log('edicao_admin', (int)$id, 'Dados editados via admin');

        flash('success', 'Dados salvos com sucesso.');
        redirect("/admin/pessoa/$id?salvo=1");
    }

    /**
     * Editar filiação
     */
    public static function filiacao(string $id): void {
        self::exigirLogin();

        $filiacao = db_fetch_one("
            SELECT f.*, p.nome as pessoa_nome
            FROM filiacoes f
            JOIN pessoas p ON p.id = f.pessoa_id
            WHERE f.id = ?
        ", [(int)$id]);

        if (!$filiacao) {
            flash('error', 'Filiação não encontrada.');
            redirect('/admin');
            return;
        }

        $salvo = isset($_GET['salvo']);
        $titulo = "Admin - Filiação {$filiacao['ano']}";

        ob_start();
        require SRC_DIR . '/Views/admin/filiacao.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Salvar alterações de filiação
     */
    public static function salvarFiliacao(string $id): void {
        self::exigirLogin();

        $filiacao = db_fetch_one("SELECT id, pessoa_id FROM filiacoes WHERE id = ?", [(int)$id]);
        if (!$filiacao) {
            flash('error', 'Filiação não encontrada.');
            redirect('/admin');
            return;
        }

        $categoria = trim($_POST['categoria'] ?? '');
        $valor = (int)($_POST['valor'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $metodo = trim($_POST['metodo'] ?? '') ?: null;
        $data_pagamento = trim($_POST['data_pagamento'] ?? '') ?: null;
        $telefone = trim($_POST['telefone'] ?? '') ?: null;
        $endereco = trim($_POST['endereco'] ?? '') ?: null;
        $cep = trim($_POST['cep'] ?? '') ?: null;
        $cidade = trim($_POST['cidade'] ?? '') ?: null;
        $estado = trim($_POST['estado'] ?? '') ?: null;
        $pais = trim($_POST['pais'] ?? '') ?: null;
        $profissao = trim($_POST['profissao'] ?? '') ?: null;
        $formacao = trim($_POST['formacao'] ?? '') ?: null;
        $instituicao = trim($_POST['instituicao'] ?? '') ?: null;

        db_execute("
            UPDATE filiacoes SET
                categoria = ?, valor = ?, status = ?, metodo = ?, data_pagamento = ?,
                telefone = ?, endereco = ?, cep = ?, cidade = ?, estado = ?, pais = ?,
                profissao = ?, formacao = ?, instituicao = ?
            WHERE id = ?
        ", [
            $categoria, $valor, $status, $metodo, $data_pagamento,
            $telefone, $endereco, $cep, $cidade, $estado, $pais,
            $profissao, $formacao, $instituicao, (int)$id
        ]);

        registrar_log('edicao_filiacao', $filiacao['pessoa_id'], "Filiação $id editada via admin");

        flash('success', 'Filiação salva com sucesso.');
        redirect("/admin/filiacao/$id?salvo=1");
    }

    /**
     * Formulario de novo cadastro
     */
    public static function novoForm(): void {
        self::exigirLogin();

        $ano = (int)date('Y');
        $titulo = "Admin - Novo Cadastro";

        ob_start();
        require SRC_DIR . '/Views/admin/novo.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Cria novo cadastro + filiação
     */
    public static function novoSalvar(): void {
        self::exigirLogin();

        $nome = trim($_POST['nome'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $categoria = trim($_POST['categoria'] ?? '');
        $ano = (int)($_POST['ano'] ?? date('Y'));
        $cpf = trim($_POST['cpf'] ?? '') ?: null;

        if (empty($nome) || empty($email) || empty($categoria)) {
            flash('error', 'Preencha todos os campos obrigatorios.');
            redirect('/admin/novo');
            return;
        }

        // Verifica se ja existe por email
        $existente = buscar_pessoa_por_email($email);

        if ($existente) {
            $pessoa_id = $existente['id'];
            // Atualiza nome e CPF se necessário
            db_execute("UPDATE pessoas SET nome = ?, cpf = ? WHERE id = ?", [$nome, $cpf, $pessoa_id]);
        } else {
            // Cria pessoa
            $pessoa_id = db_insert("
                INSERT INTO pessoas (nome, cpf, token, created_at)
                VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ", [$nome, $cpf, gerar_token()]);

            // Cria email
            db_execute("
                INSERT INTO emails (pessoa_id, email, principal)
                VALUES (?, ?, 1)
            ", [$pessoa_id, $email]);
        }

        // Verifica se ja tem filiação para o ano
        $filiacao_existe = db_fetch_one(
            "SELECT id FROM filiacoes WHERE pessoa_id = ? AND ano = ?",
            [$pessoa_id, $ano]
        );

        if ($filiacao_existe) {
            // Marca como pago
            db_execute("
                UPDATE filiacoes
                SET status = 'pago', metodo = 'manual', data_pagamento = CURRENT_TIMESTAMP, categoria = ?
                WHERE id = ?
            ", [$categoria, $filiacao_existe['id']]);
        } else {
            // Cria filiação
            $valor = valor_por_categoria($categoria);
            db_insert("
                INSERT INTO filiacoes (pessoa_id, ano, categoria, valor, status, metodo, data_pagamento, created_at)
                VALUES (?, ?, ?, ?, 'pago', 'manual', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
            ", [$pessoa_id, $ano, $categoria, $valor]);
        }

        registrar_log('cadastro_manual', $pessoa_id, 'Cadastro e filiação criados via admin');

        flash('success', 'Cadastro criado com sucesso.');
        redirect("/admin/pessoa/$pessoa_id");
    }

    /**
     * Marca filiação como paga
     */
    public static function marcarPago(string $filiacao_id): void {
        self::exigirLogin();

        $filiacao = db_fetch_one(
            "SELECT pessoa_id, ano FROM filiacoes WHERE id = ?",
            [(int)$filiacao_id]
        );

        if (!$filiacao) {
            flash('error', 'Filiação não encontrada.');
            redirect('/admin');
            return;
        }

        db_execute("
            UPDATE filiacoes
            SET status = 'pago',
                metodo = COALESCE(metodo, 'manual'),
                data_pagamento = CURRENT_TIMESTAMP
            WHERE id = ?
        ", [(int)$filiacao_id]);

        registrar_log('pagamento_manual', $filiacao['pessoa_id'], "Filiação $filiacao_id marcada como paga via admin");

        flash('success', 'Filiação marcada como paga.');
        redirect("/admin?ano={$filiacao['ano']}");
    }

    /**
     * Envia email de filiação para pessoa
     */
    public static function enviarEmail(string $filiacao_id): void {
        self::exigirLogin();

        $filiacao = db_fetch_one("
            SELECT f.*, p.nome, p.token, e.email
            FROM filiacoes f
            JOIN pessoas p ON p.id = f.pessoa_id
            LEFT JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
            WHERE f.id = ?
        ", [(int)$filiacao_id]);

        if (!$filiacao) {
            flash('error', 'Filiação não encontrada.');
            redirect('/admin');
            return;
        }

        if (empty($filiacao['email'])) {
            flash('error', 'Pessoa não tem email cadastrado.');
            redirect("/admin/pessoa/{$filiacao['pessoa_id']}");
            return;
        }

        // Carrega o serviço de email
        require_once SRC_DIR . '/Services/BrevoService.php';

        // Envia email de renovação/filiação
        $resultado = BrevoService::enviarCampanhaRenovacao(
            $filiacao['email'],
            $filiacao['nome'],
            $filiacao['ano'],
            $filiacao['token']
        );

        if ($resultado) {
            // Atualiza status para 'enviado' se ainda não estava
            if ($filiacao['status'] === 'enviado' || empty($filiacao['status'])) {
                db_execute("UPDATE filiacoes SET status = 'enviado' WHERE id = ?", [(int)$filiacao_id]);
            }
            registrar_log('email_enviado', $filiacao['pessoa_id'], "Email de filiação {$filiacao['ano']} enviado via admin");
            flash('success', 'Email enviado com sucesso.');
        } else {
            flash('error', 'Erro ao enviar email.');
        }

        redirect("/admin/pessoa/{$filiacao['pessoa_id']}");
    }

    /**
     * Exclui uma filiação
     */
    public static function excluirPagamento(string $filiacao_id): void {
        self::exigirLogin();

        $filiacao = db_fetch_one(
            "SELECT pessoa_id, ano FROM filiacoes WHERE id = ?",
            [(int)$filiacao_id]
        );

        if (!$filiacao) {
            flash('error', 'Filiação não encontrada.');
            redirect('/admin');
            return;
        }

        db_execute("DELETE FROM filiacoes WHERE id = ?", [(int)$filiacao_id]);
        registrar_log('exclusao', $filiacao['pessoa_id'], "Filiação $filiacao_id excluída via admin");

        flash('success', 'Filiação excluída.');
        redirect("/admin/pessoa/{$filiacao['pessoa_id']}");
    }

    /**
     * Exclui uma pessoa e todos os seus dados
     */
    public static function excluirPessoa(string $pessoa_id): void {
        self::exigirLogin();

        $pessoa = db_fetch_one("SELECT nome FROM pessoas WHERE id = ?", [(int)$pessoa_id]);

        if (!$pessoa) {
            flash('error', 'Pessoa não encontrada.');
            redirect('/admin');
            return;
        }

        db_execute("DELETE FROM filiacoes WHERE pessoa_id = ?", [(int)$pessoa_id]);
        db_execute("DELETE FROM emails WHERE pessoa_id = ?", [(int)$pessoa_id]);
        db_execute("DELETE FROM pessoas WHERE id = ?", [(int)$pessoa_id]);

        registrar_log('exclusao', null, "Pessoa $pessoa_id ({$pessoa['nome']}) excluída via admin");

        flash('success', 'Pessoa excluída.');
        redirect('/admin');
    }

    /**
     * Download do arquivo do banco de dados
     */
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
            fputcsv($output, [
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
            ], ';');
        }

        fclose($output);
        exit;
    }
}
