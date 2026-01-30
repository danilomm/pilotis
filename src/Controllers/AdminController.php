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
     * Agrupa resultados de query por categoria
     */
    private static function agruparPorCategoria(array $rows): array {
        $result = ['total' => 0, 'valor' => 0, 'por_categoria' => []];
        foreach ($rows as $row) {
            $cat = $row['categoria'] ?? 'outro';
            $qtd = (int)($row['qtd'] ?? 0);
            $valor = (int)($row['total'] ?? 0);
            $result['total'] += $qtd;
            $result['valor'] += $valor;
            $result['por_categoria'][$cat] = [
                'qtd' => $qtd,
                'valor' => $valor,
            ];
        }
        return $result;
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
     * Gestão de Campanhas
     */
    public static function campanha(): void {
        self::exigirLogin();

        // Lista todas as campanhas ordenadas por ano (mais recente primeiro)
        $campanhas_db = db_fetch_all("
            SELECT * FROM campanhas ORDER BY ano DESC
        ");

        // Carrega lembretes pendentes por campanha
        require_once SRC_DIR . '/Services/LembreteService.php';
        $lembretes_por_ano = [];
        foreach ($campanhas_db as $camp) {
            if (in_array($camp['status'], ['aberta', 'enviando', 'pausada'])) {
                $lembretes_por_ano[$camp['ano']] = LembreteService::contarPendentes($camp['ano']);
            }
        }

        // Monta array de campanhas com estatísticas
        $campanhas = [];
        foreach ($campanhas_db as $c) {
            $ano = $c['ano'];
            $ano_anterior = $ano - 1;

            // Estatísticas básicas
            $stats = db_fetch_one("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'enviado' THEN 1 ELSE 0 END) as enviados,
                    SUM(CASE WHEN status = 'acesso' THEN 1 ELSE 0 END) as acessos,
                    SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) as pendentes,
                    SUM(CASE WHEN status = 'pago' THEN 1 ELSE 0 END) as pagos,
                    SUM(CASE WHEN status = 'nao_pago' THEN 1 ELSE 0 END) as nao_pagos,
                    SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) as arrecadado
                FROM filiacoes
                WHERE ano = ?
            ", [$ano]);

            // Para campanhas fechadas: métricas detalhadas
            $metricas = null;
            if ($c['status'] === 'fechada') {
                // Novos: pagaram este ano, nunca pagaram antes
                $novos = db_fetch_all("
                    SELECT f.categoria, COUNT(*) as qtd, SUM(f.valor) as total
                    FROM filiacoes f
                    WHERE f.ano = ? AND f.status = 'pago'
                    AND NOT EXISTS (
                        SELECT 1 FROM filiacoes f2
                        WHERE f2.pessoa_id = f.pessoa_id AND f2.ano < ? AND f2.status = 'pago'
                    )
                    GROUP BY f.categoria
                ", [$ano, $ano]);

                // Retornaram: pagaram este ano, já pagaram antes, mas NÃO no ano anterior
                $retornaram = db_fetch_all("
                    SELECT f.categoria, COUNT(*) as qtd, SUM(f.valor) as total
                    FROM filiacoes f
                    WHERE f.ano = ? AND f.status = 'pago'
                    AND EXISTS (
                        SELECT 1 FROM filiacoes f2
                        WHERE f2.pessoa_id = f.pessoa_id AND f2.ano < ? AND f2.status = 'pago'
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM filiacoes f3
                        WHERE f3.pessoa_id = f.pessoa_id AND f3.ano = ? AND f3.status = 'pago'
                    )
                    GROUP BY f.categoria
                ", [$ano, $ano, $ano_anterior]);

                // Renovaram: pagaram este ano E pagaram no ano anterior
                $renovaram = db_fetch_all("
                    SELECT f.categoria, COUNT(*) as qtd, SUM(f.valor) as total
                    FROM filiacoes f
                    WHERE f.ano = ? AND f.status = 'pago'
                    AND EXISTS (
                        SELECT 1 FROM filiacoes f2
                        WHERE f2.pessoa_id = f.pessoa_id AND f2.ano = ? AND f2.status = 'pago'
                    )
                    GROUP BY f.categoria
                ", [$ano, $ano_anterior]);

                // Não renovaram: pagaram ano anterior, NÃO pagaram este ano
                // (usa categoria do ano anterior)
                $nao_renovaram = db_fetch_all("
                    SELECT f.categoria, COUNT(*) as qtd
                    FROM filiacoes f
                    WHERE f.ano = ? AND f.status = 'pago'
                    AND NOT EXISTS (
                        SELECT 1 FROM filiacoes f2
                        WHERE f2.pessoa_id = f.pessoa_id AND f2.ano = ? AND f2.status = 'pago'
                    )
                    GROUP BY f.categoria
                ", [$ano_anterior, $ano]);

                // Converte para arrays indexados por categoria
                $metricas = [
                    'emails_enviados' => (int)($c['emails_enviados'] ?? 0),
                    'novos' => self::agruparPorCategoria($novos),
                    'retornaram' => self::agruparPorCategoria($retornaram),
                    'renovaram' => self::agruparPorCategoria($renovaram),
                    'nao_renovaram' => self::agruparPorCategoria($nao_renovaram),
                ];
            }

            // Estatísticas por categoria (apenas pagos)
            $por_categoria = db_fetch_all("
                SELECT categoria, COUNT(*) as qtd, SUM(valor) as total
                FROM filiacoes
                WHERE ano = ? AND status = 'pago' AND categoria NOT IN ('nao_filiado', '')
                GROUP BY categoria
            ", [$ano]);

            $categorias = [];
            foreach ($por_categoria as $cat) {
                $categorias[$cat['categoria']] = [
                    'qtd' => (int)$cat['qtd'],
                    'total' => (int)$cat['total'],
                ];
            }

            // Histórico de envios
            $envios = db_fetch_all("
                SELECT id, created_at, tipo, total_enviados, total_sucesso, total_falha
                FROM envios_lotes
                WHERE ano = ?
                ORDER BY created_at DESC
            ", [$ano]);

            $campanhas[] = [
                'ano' => $ano,
                'status' => $c['status'],
                'created_at' => $c['created_at'],
                'data_fim' => $c['data_fim'] ?? null,
                'stats' => $stats,
                'categorias' => $categorias,
                'metricas' => $metricas,
                'valores' => valores_campanha($ano),
                'envios' => $envios,
                'lembretes' => $lembretes_por_ano[$ano] ?? null,
            ];
        }

        // Próximo ano disponível para criar campanha
        $ultimo_ano = !empty($campanhas) ? $campanhas[0]['ano'] : (int)date('Y') - 1;
        $proximo_ano = $ultimo_ano + 1;

        // Anos disponíveis para nova campanha (próximos 2 anos)
        $anos_disponiveis = [];
        for ($a = $proximo_ano; $a <= $proximo_ano + 1; $a++) {
            $existe = db_fetch_one("SELECT 1 FROM campanhas WHERE ano = ?", [$a]);
            if (!$existe) {
                $anos_disponiveis[] = $a;
            }
        }

        // Valores atuais
        $valores = [
            'estudante' => VALOR_ESTUDANTE,
            'profissional_nacional' => VALOR_PROFISSIONAL,
            'profissional_internacional' => VALOR_INTERNACIONAL,
        ];

        // Grupo de teste
        $grupo_teste_config = db_fetch_one("SELECT valor FROM configuracoes WHERE chave = 'grupo_teste'");
        $grupo_teste = $grupo_teste_config ? $grupo_teste_config['valor'] : '';

        $titulo = "Admin - Campanhas";

        ob_start();
        require SRC_DIR . '/Views/admin/campanha.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Salva valores de filiação de uma campanha
     */
    public static function salvarValores(): void {
        self::exigirLogin();

        $ano = (int)($_POST['ano'] ?? 0);
        $valor_estudante = (int)(floatval(str_replace(',', '.', $_POST['valor_estudante'] ?? '0')) * 100);
        $valor_profissional = (int)(floatval(str_replace(',', '.', $_POST['valor_profissional'] ?? '0')) * 100);
        $valor_internacional = (int)(floatval(str_replace(',', '.', $_POST['valor_internacional'] ?? '0')) * 100);

        if ($ano < 2020 || $ano > 2100) {
            flash('error', 'Ano inválido.');
            redirect('/admin/campanha');
            return;
        }

        if ($valor_estudante <= 0 || $valor_profissional <= 0 || $valor_internacional <= 0) {
            flash('error', 'Valores devem ser maiores que zero.');
            redirect('/admin/campanha');
            return;
        }

        db_execute("
            UPDATE campanhas
            SET valor_estudante = ?, valor_profissional = ?, valor_internacional = ?
            WHERE ano = ?
        ", [$valor_estudante, $valor_profissional, $valor_internacional, $ano]);

        registrar_log('valores_atualizados', null, "Valores campanha $ano: E=$valor_estudante P=$valor_profissional I=$valor_internacional");

        flash('success', "Valores da campanha $ano atualizados.");
        redirect('/admin/campanha');
    }

    /**
     * Salva data de término da campanha
     */
    public static function salvarDataFim(): void {
        self::exigirLogin();

        $ano = (int)($_POST['ano'] ?? 0);
        $data_fim = trim($_POST['data_fim'] ?? '');

        if ($ano < 2020 || $ano > 2100) {
            flash('error', 'Ano inválido.');
            redirect('/admin/campanha');
            return;
        }

        // Permite limpar a data
        $data_fim = $data_fim ?: null;

        db_execute("UPDATE campanhas SET data_fim = ? WHERE ano = ?", [$data_fim, $ano]);

        // Agenda lembretes de ultima chance se data foi definida
        if ($data_fim) {
            require_once SRC_DIR . '/Services/LembreteService.php';
            LembreteService::agendarUltimaChance($ano, $data_fim);
        }

        flash('success', $data_fim
            ? "Término da campanha $ano definido para " . date('d/m/Y', strtotime($data_fim)) . "."
            : "Data de término da campanha $ano removida.");
        redirect('/admin/campanha');
    }

    /**
     * Cria nova campanha
     */
    public static function criarCampanha(): void {
        self::exigirLogin();

        $ano = (int)($_POST['ano'] ?? 0);

        if ($ano < 2020 || $ano > 2100) {
            flash('error', 'Ano inválido.');
            redirect('/admin/campanha');
            return;
        }

        // Verifica se já existe
        $existe = db_fetch_one("SELECT 1 FROM campanhas WHERE ano = ?", [$ano]);
        if ($existe) {
            flash('error', "Campanha $ano já existe.");
            redirect('/admin/campanha');
            return;
        }

        // Cria a campanha com valores atuais do .env
        db_execute("
            INSERT INTO campanhas (ano, status, valor_estudante, valor_profissional, valor_internacional)
            VALUES (?, 'aberta', ?, ?, ?)
        ", [$ano, VALOR_ESTUDANTE, VALOR_PROFISSIONAL, VALOR_INTERNACIONAL]);

        registrar_log('campanha_criada', null, "Campanha $ano criada");

        flash('success', "Campanha $ano criada.");
        redirect('/admin/campanha');
    }

    /**
     * Exclui campanha (apenas se não tiver filiações)
     */
    public static function excluirCampanha(): void {
        self::exigirLogin();

        $ano = (int)($_POST['ano'] ?? 0);

        if ($ano < 2020) {
            flash('error', 'Ano inválido.');
            redirect('/admin/campanha');
            return;
        }

        // Verifica se tem filiações
        $tem_filiacoes = db_fetch_one("SELECT COUNT(*) as qtd FROM filiacoes WHERE ano = ?", [$ano]);
        if ($tem_filiacoes && $tem_filiacoes['qtd'] > 0) {
            flash('error', "Não é possível excluir campanha $ano: existem {$tem_filiacoes['qtd']} filiações associadas.");
            redirect('/admin/campanha');
            return;
        }

        // Exclui a campanha
        db_execute("DELETE FROM campanhas WHERE ano = ?", [$ano]);

        registrar_log('campanha_excluida', null, "Campanha $ano excluída");

        flash('success', "Campanha $ano excluída.");
        redirect('/admin/campanha');
    }

    /**
     * Inicia envio de emails da campanha (muda status para 'enviando')
     */
    public static function iniciarEnvio(): void {
        self::exigirLogin();

        $ano = (int)($_POST['ano'] ?? 0);
        if ($ano < 2020) {
            flash('error', 'Ano inválido.');
            redirect('/admin/campanha');
            return;
        }

        db_execute("UPDATE campanhas SET status = 'enviando' WHERE ano = ?", [$ano]);
        registrar_log('envio_iniciado', null, "Envio de campanha $ano iniciado (cron habilitado)");

        flash('success', "Envio da campanha $ano iniciado. O cron enviará até 290 emails por dia.");
        redirect('/admin/campanha');
    }

    /**
     * Pausa envio de emails (muda status para 'aberta')
     */
    public static function pausarEnvio(): void {
        self::exigirLogin();

        $ano = (int)($_POST['ano'] ?? 0);
        if ($ano < 2020) {
            flash('error', 'Ano inválido.');
            redirect('/admin/campanha');
            return;
        }

        db_execute("UPDATE campanhas SET status = 'aberta' WHERE ano = ?", [$ano]);
        registrar_log('envio_pausado', null, "Envio de campanha $ano pausado (cron desabilitado)");

        flash('success', "Envio da campanha $ano pausado. O cron não enviará emails.");
        redirect('/admin/campanha');
    }

    /**
     * Fecha campanha: marca registros não pagos
     * (Não copia dados - dados cadastrais são buscados dinamicamente no formulário)
     */
    public static function fecharCampanha(): void {
        self::exigirLogin();

        $ano = (int)($_POST['ano'] ?? 0);

        if ($ano < 2020) {
            flash('error', 'Ano inválido.');
            redirect('/admin/campanha');
            return;
        }

        // Marca todos os não pagos como 'nao_pago'
        $result = db_execute("
            UPDATE filiacoes
            SET status = 'nao_pago'
            WHERE ano = ? AND status <> 'pago'
        ", [$ano]);

        // Cancela todos os lembretes pendentes do ano
        require_once SRC_DIR . '/Services/LembreteService.php';
        LembreteService::cancelarPorAno($ano);

        // Marca campanha como fechada
        db_execute("UPDATE campanhas SET status = 'fechada' WHERE ano = ?", [$ano]);

        registrar_log('campanha_fechada', null, "Campanha $ano fechada: $result registros marcados como não pago");

        flash('success', "Campanha $ano fechada: $result registros marcados como não pago.");
        redirect('/admin/campanha');
    }

    /**
     * Salva grupo de teste
     */
    public static function salvarGrupoTeste(): void {
        self::exigirLogin();

        $emails = trim($_POST['grupo_teste'] ?? '');
        // Normaliza: um email por linha ou vírgula
        $emails = preg_replace('/[\s,]+/', ',', $emails);
        $emails = implode(',', array_filter(array_map('trim', explode(',', $emails))));

        db_execute("UPDATE configuracoes SET valor = ?, updated_at = CURRENT_TIMESTAMP WHERE chave = 'grupo_teste'", [$emails]);

        flash('success', 'Grupo de teste atualizado.');
        redirect('/admin/campanha');
    }

    /**
     * Envia campanha para o grupo de teste
     */
    public static function enviarGrupoTeste(): void {
        self::exigirLogin();

        $ano = (int)($_POST['ano'] ?? date('Y'));

        require_once SRC_DIR . '/Services/BrevoService.php';

        // Carrega grupo de teste
        $config = db_fetch_one("SELECT valor FROM configuracoes WHERE chave = 'grupo_teste'");
        $emails_teste = $config ? array_filter(array_map('trim', explode(',', $config['valor']))) : [];

        if (empty($emails_teste)) {
            flash('error', 'Grupo de teste vazio.');
            redirect('/admin/campanha');
            return;
        }

        // Busca pessoas do grupo de teste
        $placeholders = implode(',', array_fill(0, count($emails_teste), '?'));
        $destinatarios = db_fetch_all("
            SELECT DISTINCT p.id, p.nome, p.token, e.email
            FROM pessoas p
            JOIN emails e ON e.pessoa_id = p.id
            WHERE e.email IN ($placeholders)
        ", $emails_teste);

        $enviados = 0;
        $erros = 0;
        $log_destinatarios = [];

        foreach ($destinatarios as $d) {
            if (empty($d['email'])) continue;

            // Gera token se não tiver
            $token = $d['token'];
            if (!$token) {
                $token = gerar_token();
                db_execute("UPDATE pessoas SET token = ? WHERE id = ?", [$token, $d['id']]);
            }

            $resultado = BrevoService::enviarCampanhaRenovacao(
                $d['email'],
                $d['nome'] ?? 'Associado',
                $ano,
                $token
            );

            $log_destinatarios[] = [
                'email' => $d['email'],
                'nome' => $d['nome'] ?? '',
                'sucesso' => (bool)$resultado,
            ];

            if ($resultado) {
                $enviados++;
                // Cria filiação com status 'enviado'
                $filiacao = db_fetch_one(
                    "SELECT id FROM filiacoes WHERE pessoa_id = ? AND ano = ?",
                    [$d['id'], $ano]
                );
                if (!$filiacao) {
                    db_insert("
                        INSERT INTO filiacoes (pessoa_id, ano, status, created_at)
                        VALUES (?, ?, 'enviado', CURRENT_TIMESTAMP)
                    ", [$d['id'], $ano]);
                }
            } else {
                $erros++;
            }
        }

        // Grava lote
        if (!empty($log_destinatarios)) {
            $tpl_snapshot = carregar_template('renovacao', [
                'nome' => '(grupo de teste)',
                'ano' => $ano,
                'link' => BASE_URL . "/filiacao/$ano/TOKEN",
            ]);
            registrar_envio_lote(
                'grupo_teste',
                $ano,
                $tpl_snapshot['assunto'] ?? "Grupo de teste $ano",
                $tpl_snapshot['html'] ?? '',
                $log_destinatarios
            );
        }

        registrar_log('grupo_teste_enviado', null, "Grupo de teste $ano: $enviados enviados, $erros erros");
        flash('success', "Grupo de teste: $enviados enviados, $erros erros.");
        redirect('/admin/campanha');
    }

    /**
     * Envia emails da campanha
     */
    public static function enviarCampanha(): void {
        self::exigirLogin();

        $tipo = $_POST['tipo'] ?? 'todos';
        $ano = (int)($_POST['ano'] ?? date('Y'));
        $senha = $_POST['senha'] ?? '';

        // Verifica senha de administrador
        $senha_correta = false;
        if (strpos(ADMIN_PASSWORD, 'sha256:') === 0) {
            $hash_esperado = substr(ADMIN_PASSWORD, 7);
            $hash_fornecido = hash('sha256', $senha);
            $senha_correta = hash_equals($hash_esperado, $hash_fornecido);
        } else {
            $senha_correta = hash_equals(ADMIN_PASSWORD, $senha);
        }

        if (!$senha_correta) {
            flash('error', 'Senha incorreta. Envio cancelado.');
            redirect('/admin/campanha');
            return;
        }

        require_once SRC_DIR . '/Services/BrevoService.php';

        // Busca destinatários baseado no tipo
        if ($tipo === 'todos') {
            // Todos os contatos ativos
            $destinatarios = db_fetch_all("
                SELECT p.id, p.nome, p.token,
                       (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
                FROM pessoas p
                WHERE p.ativo = 1
                AND EXISTS (SELECT 1 FROM emails WHERE pessoa_id = p.id)
            ");
        } else {
            // Apenas filiados ativos do ano com status específico
            $destinatarios = db_fetch_all("
                SELECT p.id, p.nome, p.token,
                       (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
                FROM pessoas p
                JOIN filiacoes f ON f.pessoa_id = p.id
                WHERE p.ativo = 1 AND f.ano = ? AND f.status = ? AND f.categoria <> 'nao_filiado'
            ", [$ano, $tipo]);
        }

        // Snapshot do template para o log
        $tpl_snapshot = carregar_template('renovacao', [
            'nome' => '(destinatário)',
            'ano' => $ano,
            'link' => BASE_URL . "/filiacao/$ano/TOKEN",
        ]);

        $enviados = 0;
        $erros = 0;
        $log_destinatarios = [];

        foreach ($destinatarios as $d) {
            if (empty($d['email'])) continue;

            $resultado = BrevoService::enviarCampanhaRenovacao(
                $d['email'],
                $d['nome'] ?? 'Associado',
                $ano,
                $d['token']
            );

            $log_destinatarios[] = [
                'email' => $d['email'],
                'nome' => $d['nome'] ?? '',
                'sucesso' => (bool)$resultado,
            ];

            if ($resultado) {
                $enviados++;
                // Atualiza ou cria registro de filiação
                $filiacao = db_fetch_one(
                    "SELECT id FROM filiacoes WHERE pessoa_id = ? AND ano = ?",
                    [$d['id'], $ano]
                );
                if (!$filiacao) {
                    db_insert("
                        INSERT INTO filiacoes (pessoa_id, ano, categoria, status, created_at)
                        VALUES (?, ?, 'profissional_internacional', 'enviado', CURRENT_TIMESTAMP)
                    ", [$d['id'], $ano]);
                } else if ($filiacao) {
                    db_execute("
                        UPDATE filiacoes SET status = 'enviado'
                        WHERE id = ? AND status IS NULL
                    ", [$filiacao['id']]);
                }
            } else {
                $erros++;
            }
        }

        // Grava lote de envio
        if (!empty($log_destinatarios)) {
            registrar_envio_lote(
                'renovacao',
                $ano,
                $tpl_snapshot['assunto'] ?? "Campanha $ano",
                $tpl_snapshot['html'] ?? '',
                $log_destinatarios
            );
        }

        registrar_log('campanha_enviada', null, "Campanha $ano ($tipo): $enviados enviados, $erros erros");

        flash('success', "Emails enviados: $enviados. Erros: $erros.");
        redirect('/admin/campanha');
    }

    /**
     * Retorna contagens por grupo de destinatarios (AJAX JSON)
     */
    public static function previewLote(): void {
        self::exigirLogin();

        $ano = (int)($_POST['ano'] ?? date('Y'));
        $grupos = self::obterGruposCampanha($ano);

        $resultado = [];
        foreach ($grupos as $grupo) {
            $destinatarios = db_fetch_all($grupo['query'], $grupo['params']);
            $com_email = count(array_filter($destinatarios, fn($d) => !empty($d['email'])));
            $resultado[] = [
                'nome' => $grupo['nome'],
                'template' => $grupo['template'],
                'total' => $com_email,
            ];
        }

        // Enviados hoje
        $enviados_hoje = db_fetch_one("
            SELECT COALESCE(SUM(ed.sucesso), 0) as total
            FROM envios_destinatarios ed
            JOIN envios_lotes el ON el.id = ed.lote_id
            WHERE DATE(el.created_at) = DATE('now') AND ed.sucesso = 1
        ")['total'] ?? 0;

        json_response([
            'grupos' => $resultado,
            'enviados_hoje' => (int)$enviados_hoje,
            'limite_diario' => 290,
        ]);
    }

    /**
     * Envia um lote de emails da campanha (AJAX JSON)
     */
    public static function enviarLote(): void {
        self::exigirLogin();

        $ano = (int)($_POST['ano'] ?? date('Y'));
        $limite_lote = 50;

        require_once SRC_DIR . '/Services/BrevoService.php';

        // Verifica limite diario (290)
        $enviados_hoje = (int)(db_fetch_one("
            SELECT COALESCE(SUM(ed.sucesso), 0) as total
            FROM envios_destinatarios ed
            JOIN envios_lotes el ON el.id = ed.lote_id
            WHERE DATE(el.created_at) = DATE('now') AND ed.sucesso = 1
        ")['total'] ?? 0);

        if ($enviados_hoje >= 290) {
            json_response([
                'erro' => 'Limite diario atingido (290 emails)',
                'enviados_hoje' => $enviados_hoje,
            ]);
            return;
        }

        $limite_lote = min($limite_lote, 290 - $enviados_hoje);

        $grupos = self::obterGruposCampanha($ano);
        $total_enviados = 0;
        $total_erros = 0;
        $grupo_atual = '';
        $log_destinatarios = [];
        $template_usado = null;

        foreach ($grupos as $grupo) {
            if ($total_enviados >= $limite_lote) break;

            $destinatarios = db_fetch_all($grupo['query'], $grupo['params']);
            $destinatarios = array_filter($destinatarios, fn($d) => !empty($d['email']));
            $destinatarios = array_values($destinatarios);

            if (empty($destinatarios)) continue;

            $restante = $limite_lote - $total_enviados;
            $enviar_agora = array_slice($destinatarios, 0, $restante);
            $grupo_atual = $grupo['nome'];

            // Snapshot do template para log
            $tpl_snapshot = carregar_template($grupo['template'], [
                'nome' => '(destinatario)',
                'ano' => $ano,
                'link' => BASE_URL . "/filiacao/$ano/TOKEN",
            ]);
            if (!$template_usado) {
                $template_usado = $tpl_snapshot;
            }

            foreach ($enviar_agora as $d) {
                // Gera token se nao tiver
                $token = $d['token'];
                if (!$token) {
                    $token = gerar_token();
                    db_execute("UPDATE pessoas SET token = ? WHERE id = ?", [$token, $d['id']]);
                }

                // Marca como 'enviado' ANTES de enviar
                $filiacao = db_fetch_one(
                    "SELECT id FROM filiacoes WHERE pessoa_id = ? AND ano = ?",
                    [$d['id'], $ano]
                );
                if (!$filiacao) {
                    db_insert("
                        INSERT INTO filiacoes (pessoa_id, ano, status, created_at)
                        VALUES (?, ?, 'enviado', CURRENT_TIMESTAMP)
                    ", [$d['id'], $ano]);
                }

                try {
                    $enviado = false;
                    switch ($grupo['template']) {
                        case 'renovacao':
                            $enviado = BrevoService::enviarCampanhaRenovacao($d['email'], $d['nome'], $ano, $token);
                            break;
                        case 'seminario':
                            $enviado = BrevoService::enviarCampanhaSeminario($d['email'], $d['nome'], $ano, $token);
                            break;
                        case 'convite':
                            $enviado = BrevoService::enviarCampanhaConvite($d['email'], $d['nome'], $ano, $token);
                            break;
                    }

                    $log_destinatarios[] = [
                        'email' => $d['email'],
                        'nome' => $d['nome'] ?? '',
                        'sucesso' => (bool)$enviado,
                    ];

                    if ($enviado) {
                        $total_enviados++;
                    } else {
                        $total_erros++;
                    }
                } catch (Exception $e) {
                    $total_erros++;
                    $log_destinatarios[] = [
                        'email' => $d['email'],
                        'nome' => $d['nome'] ?? '',
                        'sucesso' => false,
                    ];
                }

                usleep(100000); // 100ms entre envios
            }
        }

        // Grava lote de envio
        if (!empty($log_destinatarios)) {
            registrar_envio_lote(
                'campanha',
                $ano,
                $template_usado['assunto'] ?? "Campanha $ano",
                $template_usado['html'] ?? '',
                $log_destinatarios
            );
        }

        registrar_log('lote_enviado', null, "Lote campanha $ano: $total_enviados enviados, $total_erros erros ($grupo_atual)");

        // Recalcula preview
        $grupos_preview = [];
        foreach (self::obterGruposCampanha($ano) as $g) {
            $dest = db_fetch_all($g['query'], $g['params']);
            $com_email = count(array_filter($dest, fn($d) => !empty($d['email'])));
            $grupos_preview[] = [
                'nome' => $g['nome'],
                'template' => $g['template'],
                'total' => $com_email,
            ];
        }

        json_response([
            'enviados' => $total_enviados,
            'erros' => $total_erros,
            'grupo_atual' => $grupo_atual,
            'enviados_hoje' => $enviados_hoje + $total_enviados,
            'limite_diario' => 290,
            'grupos' => $grupos_preview,
        ]);
    }

    /**
     * Retorna definicao dos grupos da campanha
     */
    private static function obterGruposCampanha(int $ano): array {
        $ano_anterior = $ano - 1;

        return [
            [
                'nome' => 'Adimplentes ' . $ano_anterior,
                'template' => 'renovacao',
                'query' => "
                    SELECT DISTINCT p.id, p.nome, p.token,
                           (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
                    FROM pessoas p
                    JOIN filiacoes f ON f.pessoa_id = p.id
                    WHERE p.ativo = 1
                    AND f.ano = ? AND f.status = 'pago'
                    AND p.id NOT IN (
                        SELECT pessoa_id FROM filiacoes WHERE ano = ?
                    )
                ",
                'params' => [$ano_anterior, $ano],
            ],
            [
                'nome' => 'Participantes seminario',
                'template' => 'seminario',
                'query' => "
                    SELECT DISTINCT p.id, p.nome, p.token,
                           (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
                    FROM pessoas p
                    JOIN filiacoes f ON f.pessoa_id = p.id
                    WHERE p.ativo = 1
                    AND f.seminario = 1
                    AND p.id NOT IN (
                        SELECT pessoa_id FROM filiacoes WHERE status = 'pago'
                    )
                    AND p.id NOT IN (
                        SELECT pessoa_id FROM filiacoes WHERE ano = ? AND status = 'enviado'
                    )
                ",
                'params' => [$ano],
            ],
            [
                'nome' => 'Ex-filiados',
                'template' => 'renovacao',
                'query' => "
                    SELECT DISTINCT p.id, p.nome, p.token,
                           (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
                    FROM pessoas p
                    JOIN filiacoes f ON f.pessoa_id = p.id
                    WHERE p.ativo = 1
                    AND f.status = 'pago'
                    AND p.id NOT IN (
                        SELECT pessoa_id FROM filiacoes WHERE ano = ? AND status = 'pago'
                    )
                    AND p.id NOT IN (
                        SELECT pessoa_id FROM filiacoes WHERE ano = ? AND status = 'enviado'
                    )
                    AND p.id NOT IN (
                        SELECT pessoa_id FROM filiacoes WHERE seminario = 1
                    )
                ",
                'params' => [$ano, $ano],
            ],
            [
                'nome' => 'Contatos sem filiacao',
                'template' => 'convite',
                'query' => "
                    SELECT p.id, p.nome, p.token,
                           (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
                    FROM pessoas p
                    WHERE p.ativo = 1
                    AND p.id NOT IN (SELECT pessoa_id FROM filiacoes)
                ",
                'params' => [],
            ],
            [
                'nome' => 'Contatos pendentes',
                'template' => 'convite',
                'query' => "
                    SELECT DISTINCT p.id, p.nome, p.token,
                           (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
                    FROM pessoas p
                    JOIN filiacoes f ON f.pessoa_id = p.id
                    WHERE p.ativo = 1
                    AND p.id NOT IN (SELECT pessoa_id FROM filiacoes WHERE status = 'pago')
                    AND p.id NOT IN (SELECT pessoa_id FROM filiacoes WHERE seminario = 1)
                    AND p.id NOT IN (
                        SELECT pessoa_id FROM filiacoes WHERE ano = ? AND status = 'enviado'
                    )
                ",
                'params' => [$ano],
            ],
        ];
    }

    /**
     * Processa lembretes pendentes (AJAX JSON, botao manual no admin)
     */
    public static function processarLembretes(): void {
        self::exigirLogin();

        require_once SRC_DIR . '/Services/LembreteService.php';

        $resultado = LembreteService::processar(50);

        json_response($resultado);
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
        $ativo = isset($_POST['ativo']) ? 1 : 0;

        // Atualiza pessoa
        db_execute("
            UPDATE pessoas SET
                nome = ?, cpf = ?, notas = ?, ativo = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = ?
        ", [$nome, $cpf, $notas, $ativo, (int)$id]);

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
        $valores_ano = valores_campanha($ano);
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
            $valor = valor_por_categoria($categoria, $ano);
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

        // Cancela lembretes pendentes
        require_once SRC_DIR . '/Services/LembreteService.php';
        LembreteService::cancelar((int)$filiacao_id);

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
    /**
     * Mostra detalhes de um envio (email enviado + destinatários)
     */
    public static function verEnvio(): void {
        self::exigirLogin();

        $id = (int)($_GET['id'] ?? 0);
        $lote = db_fetch_one("SELECT * FROM envios_lotes WHERE id = ?", [$id]);

        if (!$lote) {
            flash('error', 'Envio não encontrado.');
            redirect('/admin/campanha');
            return;
        }

        $destinatarios = db_fetch_all(
            "SELECT * FROM envios_destinatarios WHERE lote_id = ? ORDER BY nome",
            [$id]
        );

        $titulo = "Admin - Envio #$id";

        ob_start();
        require SRC_DIR . '/Views/admin/envio.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Lista templates de email para edição
     */
    public static function templates(): void {
        self::exigirLogin();

        $templates = db_fetch_all("SELECT * FROM email_templates ORDER BY tipo");

        $descricoes = [
            'confirmacao' => 'Confirmação de pagamento',
            'lembrete' => 'Lembrete de pagamento pendente',
            'renovacao' => 'Campanha de renovação',
            'convite' => 'Campanha para novos contatos',
            'seminario' => 'Campanha para participantes do seminário',
            'acesso' => 'Link de acesso ao formulário',
            'declaracao' => 'Texto da declaração PDF',
        ];

        $titulo = "Admin - Templates de Email";

        ob_start();
        require SRC_DIR . '/Views/admin/templates.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Salva alterações em um template de email
     */
    public static function salvarTemplate(): void {
        self::exigirLogin();

        $tipo = $_POST['tipo'] ?? '';
        $assunto = trim($_POST['assunto'] ?? '');
        $html = $_POST['html'] ?? '';

        if (!$tipo || !$assunto || !$html) {
            flash('error', 'Preencha todos os campos.');
            redirect('/admin/templates');
            return;
        }

        // Verifica se template existe
        $existente = db_fetch_one("SELECT tipo FROM email_templates WHERE tipo = ?", [$tipo]);
        if (!$existente) {
            flash('error', 'Template não encontrado.');
            redirect('/admin/templates');
            return;
        }

        db_execute(
            "UPDATE email_templates SET assunto = ?, html = ?, updated_at = ? WHERE tipo = ?",
            [$assunto, $html, date('Y-m-d H:i:s'), $tipo]
        );

        flash('success', "Template \"$tipo\" atualizado.");
        redirect('/admin/templates');
    }

    /**
     * Reseta um template para o valor padrão (seed)
     */
    public static function resetarTemplate(): void {
        self::exigirLogin();

        $tipo = $_POST['tipo'] ?? '';
        if (!$tipo) {
            redirect('/admin/templates');
            return;
        }

        // Remove e re-seeds o template específico
        db_execute("DELETE FROM email_templates WHERE tipo = ?", [$tipo]);

        // Re-seed todos (INSERT OR IGNORE só insere os que faltam)
        seed_email_templates(get_db());

        flash('success', "Template \"$tipo\" restaurado ao padrão.");
        redirect('/admin/templates');
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

    /**
     * Download de comprovante de matrícula
     */
    public static function downloadComprovante(string $pessoa_id, string $ano): void {
        self::exigirLogin();

        $filepath = obter_comprovante((int)$pessoa_id, (int)$ano);

        if (!$filepath || !file_exists($filepath)) {
            flash('error', 'Comprovante não encontrado.');
            redirect("/admin/pessoa/$pessoa_id");
            return;
        }

        // Determina o tipo MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $filepath);
        finfo_close($finfo);

        // Nome do arquivo para download
        $ext = pathinfo($filepath, PATHINFO_EXTENSION);
        $filename = "comprovante_{$pessoa_id}_{$ano}.{$ext}";

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($filepath));
        header('Cache-Control: private, max-age=0, must-revalidate');

        readfile($filepath);
        exit;
    }
}
