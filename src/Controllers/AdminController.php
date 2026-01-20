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

        // Estatisticas
        $stats = db_fetch_one("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pago' THEN 1 ELSE 0 END) as pagos,
                SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) as pendentes,
                SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) as arrecadado
            FROM pagamentos WHERE ano = ?
        ", [$ano]);

        // Pagamentos recentes
        $pagamentos = db_fetch_all("
            SELECT p.id, p.cadastrado_id, c.nome, c.email, p.valor, p.status, p.metodo,
                   p.data_criacao, p.data_pagamento
            FROM pagamentos p
            JOIN cadastrados c ON c.id = p.cadastrado_id
            WHERE p.ano = ?
            ORDER BY
                CASE p.status WHEN 'pendente' THEN 0 ELSE 1 END,
                p.data_criacao DESC
            LIMIT 100
        ", [$ano]);

        $titulo = "Admin - Painel";

        ob_start();
        require SRC_DIR . '/Views/admin/painel.php';
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
                SELECT c.id, c.nome, c.email, c.categoria, c.token,
                       GROUP_CONCAT(p.ano || ':' || p.status, ', ') as pagamentos
                FROM cadastrados c
                LEFT JOIN pagamentos p ON p.cadastrado_id = c.id
                WHERE c.email LIKE ? OR c.nome LIKE ?
                GROUP BY c.id
                ORDER BY c.nome
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

        $pessoa = db_fetch_one("SELECT * FROM cadastrados WHERE id = ?", [(int)$id]);

        if (!$pessoa) {
            flash('error', 'Pessoa nao encontrada.');
            redirect('/admin');
            return;
        }

        $pagamentos = db_fetch_all("
            SELECT * FROM pagamentos
            WHERE cadastrado_id = ?
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

        $pessoa = db_fetch_one("SELECT id FROM cadastrados WHERE id = ?", [(int)$id]);
        if (!$pessoa) {
            flash('error', 'Pessoa nao encontrada.');
            redirect('/admin');
            return;
        }

        $nome = trim($_POST['nome'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $cpf = trim($_POST['cpf'] ?? '') ?: null;
        $telefone = trim($_POST['telefone'] ?? '') ?: null;
        $categoria = trim($_POST['categoria'] ?? '') ?: null;
        $endereco = trim($_POST['endereco'] ?? '') ?: null;
        $cep = trim($_POST['cep'] ?? '') ?: null;
        $cidade = trim($_POST['cidade'] ?? '') ?: null;
        $estado = strtoupper(substr(trim($_POST['estado'] ?? ''), 0, 2)) ?: null;
        $pais = trim($_POST['pais'] ?? '') ?: null;
        $profissao = trim($_POST['profissao'] ?? '') ?: null;
        $formacao = trim($_POST['formacao'] ?? '') ?: null;
        $instituicao = trim($_POST['instituicao'] ?? '') ?: null;
        $observacoes = trim($_POST['observacoes'] ?? '') ?: null;
        $observacoes_filiado = trim($_POST['observacoes_filiado'] ?? '') ?: null;

        db_execute("
            UPDATE cadastrados SET
                nome = ?, email = ?, cpf = ?, telefone = ?, categoria = ?,
                endereco = ?, cep = ?, cidade = ?, estado = ?, pais = ?,
                profissao = ?, formacao = ?, instituicao = ?,
                observacoes = ?, observacoes_filiado = ?,
                data_atualizacao = CURRENT_TIMESTAMP
            WHERE id = ?
        ", [
            $nome, $email, $cpf, $telefone, $categoria,
            $endereco, $cep, $cidade, $estado, $pais,
            $profissao, $formacao, $instituicao,
            $observacoes, $observacoes_filiado,
            (int)$id
        ]);

        registrar_log('edicao_admin', (int)$id, 'Dados editados via admin');

        flash('success', 'Dados salvos com sucesso.');
        redirect("/admin/pessoa/$id?salvo=1");
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
     * Cria novo cadastro + pagamento
     */
    public static function novoSalvar(): void {
        self::exigirLogin();

        $nome = trim($_POST['nome'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $categoria = trim($_POST['categoria'] ?? '');
        $ano = (int)($_POST['ano'] ?? date('Y'));
        $cpf = trim($_POST['cpf'] ?? '') ?: null;
        $telefone = trim($_POST['telefone'] ?? '') ?: null;

        if (empty($nome) || empty($email) || empty($categoria)) {
            flash('error', 'Preencha todos os campos obrigatorios.');
            redirect('/admin/novo');
            return;
        }

        // Verifica se ja existe
        $existente = db_fetch_one("SELECT id FROM cadastrados WHERE email = ?", [$email]);

        if ($existente) {
            $cadastrado_id = $existente['id'];
            // Atualiza categoria se necessario
            db_execute("UPDATE cadastrados SET categoria = ? WHERE id = ?", [$categoria, $cadastrado_id]);
        } else {
            $cadastrado_id = db_insert("
                INSERT INTO cadastrados (nome, email, cpf, telefone, categoria, token)
                VALUES (?, ?, ?, ?, ?, ?)
            ", [$nome, $email, $cpf, $telefone, $categoria, gerar_token()]);
        }

        // Verifica se ja tem pagamento para o ano
        $pag_existe = db_fetch_one(
            "SELECT id FROM pagamentos WHERE cadastrado_id = ? AND ano = ?",
            [$cadastrado_id, $ano]
        );

        if ($pag_existe) {
            // Marca como pago
            db_execute("
                UPDATE pagamentos
                SET status = 'pago', metodo = 'manual', data_pagamento = CURRENT_TIMESTAMP
                WHERE id = ?
            ", [$pag_existe['id']]);
        } else {
            // Cria pagamento
            $valor = valor_por_categoria($categoria);
            db_insert("
                INSERT INTO pagamentos (cadastrado_id, ano, valor, status, metodo, data_pagamento)
                VALUES (?, ?, ?, 'pago', 'manual', CURRENT_TIMESTAMP)
            ", [$cadastrado_id, $ano, $valor]);
        }

        registrar_log('cadastro_manual', $cadastrado_id, 'Cadastro e pagamento criados via admin');

        flash('success', 'Cadastro criado com sucesso.');
        redirect("/admin/pessoa/$cadastrado_id");
    }

    /**
     * Marca pagamento como pago
     */
    public static function marcarPago(string $pagamento_id): void {
        self::exigirLogin();

        $pag = db_fetch_one(
            "SELECT cadastrado_id, ano FROM pagamentos WHERE id = ?",
            [(int)$pagamento_id]
        );

        if (!$pag) {
            flash('error', 'Pagamento nao encontrado.');
            redirect('/admin');
            return;
        }

        db_execute("
            UPDATE pagamentos
            SET status = 'pago',
                metodo = COALESCE(metodo, 'manual'),
                data_pagamento = CURRENT_TIMESTAMP
            WHERE id = ?
        ", [(int)$pagamento_id]);

        registrar_log('pagamento_manual', $pag['cadastrado_id'], "Pagamento $pagamento_id marcado como pago via admin");

        flash('success', 'Pagamento marcado como pago.');
        redirect("/admin?ano={$pag['ano']}");
    }

    /**
     * Exclui um pagamento
     */
    public static function excluirPagamento(string $pagamento_id): void {
        self::exigirLogin();

        $pag = db_fetch_one(
            "SELECT cadastrado_id, ano FROM pagamentos WHERE id = ?",
            [(int)$pagamento_id]
        );

        if (!$pag) {
            flash('error', 'Pagamento nao encontrado.');
            redirect('/admin');
            return;
        }

        db_execute("DELETE FROM pagamentos WHERE id = ?", [(int)$pagamento_id]);
        registrar_log('exclusao', $pag['cadastrado_id'], "Pagamento $pagamento_id excluido via admin");

        flash('success', 'Pagamento excluido.');
        redirect("/admin/pessoa/{$pag['cadastrado_id']}");
    }

    /**
     * Exclui uma pessoa e todos os seus pagamentos
     */
    public static function excluirPessoa(string $pessoa_id): void {
        self::exigirLogin();

        $pessoa = db_fetch_one("SELECT nome FROM cadastrados WHERE id = ?", [(int)$pessoa_id]);

        if (!$pessoa) {
            flash('error', 'Pessoa nao encontrada.');
            redirect('/admin');
            return;
        }

        db_execute("DELETE FROM pagamentos WHERE cadastrado_id = ?", [(int)$pessoa_id]);
        db_execute("DELETE FROM cadastrados WHERE id = ?", [(int)$pessoa_id]);

        registrar_log('exclusao', null, "Pessoa $pessoa_id ({$pessoa['nome']}) excluida via admin");

        flash('success', 'Pessoa excluida.');
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
            SELECT c.nome, c.email, c.cpf, c.telefone, c.categoria,
                   c.endereco, c.cep, c.cidade, c.estado, c.pais,
                   c.profissao, c.instituicao,
                   p.valor, p.metodo, p.status, p.data_pagamento
            FROM cadastrados c
            JOIN pagamentos p ON p.cadastrado_id = c.id
            WHERE p.ano = ?
            ORDER BY p.status DESC, c.nome
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
