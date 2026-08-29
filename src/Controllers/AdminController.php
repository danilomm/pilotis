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

                // Emails enviados: destinatarios unicos (sucesso) em lotes de campanha do ano
                $emails_enviados = (int)(db_fetch_one("
                    SELECT COUNT(DISTINCT LOWER(TRIM(ed.email))) as total
                    FROM envios_destinatarios ed
                    JOIN envios_lotes el ON el.id = ed.lote_id
                    WHERE el.tipo = 'campanha' AND el.ano = ? AND ed.sucesso = 1
                ", [$ano])['total'] ?? 0);

                // Cadastros incompletos: pessoa criada via formulario publico mas sem nome preenchido,
                // com filiacao do ano (qualquer status que nao seja 'pago').
                $cadastros_incompletos = (int)(db_fetch_one("
                    SELECT COUNT(*) as total
                    FROM filiacoes f
                    JOIN pessoas p ON p.id = f.pessoa_id
                    WHERE f.ano = ?
                    AND f.status != 'pago'
                    AND (p.nome IS NULL OR TRIM(p.nome) = '')
                ", [$ano])['total'] ?? 0);

                // Converte para arrays indexados por categoria
                $metricas = [
                    'emails_enviados' => $emails_enviados,
                    'cadastros_incompletos' => $cadastros_incompletos,
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
                'data_fim_internacional' => $c['data_fim_internacional'] ?? null,
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

        // Flag de campanha iniciada (por ano)
        $campanha_iniciada = [];
        foreach ($campanhas as $c) {
            $flag = db_fetch_one("SELECT valor FROM configuracoes WHERE chave = ?", ["campanha_iniciada_{$c['ano']}"]);
            $campanha_iniciada[$c['ano']] = $flag && $flag['valor'] === '1';
        }

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
     * Salva datas de término da campanha (internacional e geral)
     */
    public static function salvarDataFim(): void {
        self::exigirLogin();

        $ano = (int)($_POST['ano'] ?? 0);
        $data_fim_raw = trim($_POST['data_fim'] ?? '');
        $data_fim_int_raw = trim($_POST['data_fim_internacional'] ?? '');

        if ($ano < 2020 || $ano > 2100) {
            flash('error', 'Ano inválido.');
            redirect('/admin/campanha');
            return;
        }

        // Converte dd/mm/aaaa para Y-m-d (aceita ambos os formatos)
        $data_fim = self::parseDateBR($data_fim_raw);
        $data_fim_internacional = self::parseDateBR($data_fim_int_raw);

        db_execute("UPDATE campanhas SET data_fim = ?, data_fim_internacional = ? WHERE ano = ?",
            [$data_fim, $data_fim_internacional, $ano]);

        // Agenda lembretes de ultima chance
        require_once SRC_DIR . '/Services/LembreteService.php';
        if ($data_fim_internacional) {
            LembreteService::agendarUltimaChance($ano, $data_fim_internacional, 'ultima_chance_internacional');
        }
        if ($data_fim) {
            LembreteService::agendarUltimaChance($ano, $data_fim, 'ultima_chance');
        }

        // Monta mensagem de confirmação
        $msgs = [];
        if ($data_fim_internacional) {
            $msgs[] = "Internacional: " . date('d/m/Y', strtotime($data_fim_internacional));
        }
        if ($data_fim) {
            $msgs[] = "Geral: " . date('d/m/Y', strtotime($data_fim));
        }

        flash('success', $msgs
            ? "Prazos da campanha $ano atualizados. " . implode(' | ', $msgs)
            : "Datas de término da campanha $ano removidas.");
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

        db_execute("UPDATE configuracoes SET valor = ?, updated_at = datetime('now','localtime') WHERE chave = 'grupo_teste'", [$emails]);

        flash('success', 'Grupo de teste atualizado.');
        redirect('/admin/campanha');
    }

    /**
     * Envia campanha para o grupo de teste
     */
    public static function enviarGrupoTeste(): void {
        self::exigirLogin();

        $ano = (int)($_POST['ano'] ?? date('Y'));

        // Verifica se campanha esta aberta
        $campanha = db_fetch_one("SELECT status FROM campanhas WHERE ano = ?", [$ano]);
        if (!$campanha || $campanha['status'] !== 'aberta') {
            flash('error', 'Campanha nao esta aberta.');
            redirect('/admin/campanha');
            return;
        }

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
                        INSERT INTO filiacoes (pessoa_id, ano, categoria, status, created_at)
                        VALUES (?, ?, '', 'enviado', datetime('now','localtime'))
                    ", [$d['id'], $ano]);
                }
            } else {
                $erros++;
            }
        }

        // Grava lote (try/catch para não perder o resultado se falhar)
        try {
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
        } catch (Exception $e) {
            registrar_log('erro_registro_lote', null, "Grupo teste $ano enviou $enviados emails mas falhou ao registrar lote: " . $e->getMessage());
        }

        flash('success', "Grupo de teste: $enviados enviados, $erros erros.");
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
            WHERE DATE(el.created_at, '+3 hours') = DATE('now') AND ed.sucesso = 1
        ")['total'] ?? 0;

        json_response([
            'grupos' => $resultado,
            'enviados_hoje' => (int)$enviados_hoje,
            'limite_diario' => 290,
        ]);
    }

    /**
     * Inicia a campanha (seta flag que libera o cron automatico)
     */
    public static function iniciarCampanha(): void {
        self::exigirLogin();

        $ano = (int)($_POST['ano'] ?? date('Y'));

        // Verifica se campanha esta aberta
        $campanha = db_fetch_one("SELECT status FROM campanhas WHERE ano = ?", [$ano]);
        if (!$campanha || $campanha['status'] !== 'aberta') {
            flash('error', 'Campanha nao esta aberta.');
            redirect('/admin/campanha');
            return;
        }

        // Seta flag que libera o cron
        $chave = "campanha_iniciada_{$ano}";
        db_execute("INSERT OR REPLACE INTO configuracoes (chave, valor, updated_at) VALUES (?, '1', datetime('now','localtime'))", [$chave]);

        registrar_log('campanha_iniciada', null, "Campanha $ano iniciada manualmente (cron liberado)");
        flash('success', "Campanha $ano iniciada! O envio automatico diario esta liberado.");
        redirect('/admin/campanha');
    }

    /**
     * Envia um lote de emails da campanha (AJAX JSON)
     */
    public static function enviarLote(): void {
        self::exigirLogin();

        $ano = (int)($_POST['ano'] ?? date('Y'));
        $limite_lote = 50;

        // Verifica se campanha esta aberta
        $campanha = db_fetch_one("SELECT status FROM campanhas WHERE ano = ?", [$ano]);
        if (!$campanha || $campanha['status'] !== 'aberta') {
            json_response([
                'erro' => 'Campanha nao esta aberta. Status: ' . ($campanha['status'] ?? 'inexistente'),
            ]);
            return;
        }

        require_once SRC_DIR . '/Services/BrevoService.php';

        // Verifica limite diario (290)
        $enviados_hoje = (int)(db_fetch_one("
            SELECT COALESCE(SUM(ed.sucesso), 0) as total
            FROM envios_destinatarios ed
            JOIN envios_lotes el ON el.id = ed.lote_id
            WHERE DATE(el.created_at, '+3 hours') = DATE('now') AND ed.sucesso = 1
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

            // Se ja enviou algum neste lote, nao cruza para o proximo grupo
            if ($total_enviados > 0) break;

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

                // Marca como 'enviado' ANTES de enviar (INSERT OR IGNORE evita crash por UNIQUE)
                $filiacao = db_fetch_one(
                    "SELECT id FROM filiacoes WHERE pessoa_id = ? AND ano = ?",
                    [$d['id'], $ano]
                );
                $filiacao_criada = false;
                if (!$filiacao) {
                    db_execute("
                        INSERT OR IGNORE INTO filiacoes (pessoa_id, ano, categoria, status, created_at)
                        VALUES (?, ?, '', 'enviado', datetime('now','localtime'))
                    ", [$d['id'], $ano]);
                    $filiacao_criada = true;
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
                        // Reverte filiacao criada se email nao foi enviado
                        if ($filiacao_criada) {
                            db_execute("DELETE FROM filiacoes WHERE pessoa_id = ? AND ano = ? AND status = 'enviado'", [$d['id'], $ano]);
                        }
                    }
                } catch (Exception $e) {
                    $total_erros++;
                    // Reverte filiacao criada se email nao foi enviado
                    if ($filiacao_criada) {
                        db_execute("DELETE FROM filiacoes WHERE pessoa_id = ? AND ano = ? AND status = 'enviado'", [$d['id'], $ano]);
                    }
                    $log_destinatarios[] = [
                        'email' => $d['email'],
                        'nome' => $d['nome'] ?? '',
                        'sucesso' => false,
                    ];
                }

                usleep(100000); // 100ms entre envios
            }
        }

        // Grava lote de envio (try/catch para não perder o resultado se falhar)
        try {
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
        } catch (Exception $e) {
            registrar_log('erro_registro_lote', null, "Lote campanha $ano enviou $total_enviados emails mas falhou ao registrar lote: " . $e->getMessage());
        }

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

        // Conta ex-filiados para dividir em duas metades
        // Usa status='pago' (nao qualquer filiacao) para manter $metade_ex estavel
        // durante envios em lotes (evita recalculo que divide por 2 a cada lote)
        $total_ex = (int)(db_fetch_one("
            SELECT COUNT(DISTINCT p.id) as total
            FROM pessoas p
            WHERE p.ativo = 1
            AND EXISTS (SELECT 1 FROM filiacoes WHERE pessoa_id = p.id AND status = 'pago')
            AND NOT EXISTS (SELECT 1 FROM filiacoes WHERE pessoa_id = p.id AND ano = ? AND status = 'pago')
            AND NOT EXISTS (SELECT 1 FROM filiacoes WHERE pessoa_id = p.id AND ano = ? AND status = 'pago')
        ", [$ano_anterior, $ano])['total'] ?? 0);
        $metade_ex = (int)ceil($total_ex / 2);

        // Grupo 0: Teste - monta query dinamicamente com os emails
        $emails_teste = self::obterEmailsGrupoTeste();
        $placeholders_teste = !empty($emails_teste) ? implode(',', array_fill(0, count($emails_teste), '?')) : "''";
        $params_teste = array_merge($emails_teste, [$ano]);

        return [
            // Grupo 0: Teste (lista fixa da configuracao)
            [
                'nome' => 'Grupo teste',
                'template' => 'renovacao',
                'query' => "
                    SELECT p.id, p.nome, p.token,
                           (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
                    FROM pessoas p
                    JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
                    WHERE p.ativo = 1
                    AND e.email IN ($placeholders_teste)
                    AND p.id NOT IN (SELECT pessoa_id FROM filiacoes WHERE ano = ?)
                ",
                'params' => $params_teste,
            ],
            // Grupo 1: Adimplentes ano anterior
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
                    AND p.id NOT IN (SELECT pessoa_id FROM filiacoes WHERE ano = ?)
                ",
                'params' => [$ano_anterior, $ano],
            ],
            // Grupo 2a: Ex-filiados (metade mais recente, por ultimo ano pago DESC)
            [
                'nome' => 'Ex-filiados (recentes)',
                'template' => 'renovacao',
                'query' => "
                    SELECT p.id, p.nome, p.token,
                           (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email,
                           (SELECT MAX(ano) FROM filiacoes WHERE pessoa_id = p.id AND status = 'pago') as ultimo_ano
                    FROM pessoas p
                    WHERE p.ativo = 1
                    AND EXISTS (SELECT 1 FROM filiacoes WHERE pessoa_id = p.id AND status = 'pago')
                    AND NOT EXISTS (SELECT 1 FROM filiacoes WHERE pessoa_id = p.id AND ano = ? AND status = 'pago')
                    AND NOT EXISTS (SELECT 1 FROM filiacoes WHERE pessoa_id = p.id AND ano = ?)
                    ORDER BY ultimo_ano DESC
                    LIMIT ?
                ",
                'params' => [$ano_anterior, $ano, $metade_ex],
            ],
            // Grupo 2b: Ex-filiados (metade mais antiga, por ultimo ano pago DESC)
            [
                'nome' => 'Ex-filiados (antigos)',
                'template' => 'renovacao',
                'query' => "
                    SELECT p.id, p.nome, p.token,
                           (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email,
                           (SELECT MAX(ano) FROM filiacoes WHERE pessoa_id = p.id AND status = 'pago') as ultimo_ano
                    FROM pessoas p
                    WHERE p.ativo = 1
                    AND EXISTS (SELECT 1 FROM filiacoes WHERE pessoa_id = p.id AND status = 'pago')
                    AND NOT EXISTS (SELECT 1 FROM filiacoes WHERE pessoa_id = p.id AND ano = ? AND status = 'pago')
                    AND NOT EXISTS (SELECT 1 FROM filiacoes WHERE pessoa_id = p.id AND ano = ?)
                    ORDER BY ultimo_ano DESC
                    LIMIT ? OFFSET ?
                ",
                'params' => [$ano_anterior, $ano, $metade_ex, $metade_ex],
            ],
            // Grupo 3: Seminario (nunca pagou)
            [
                'nome' => 'Seminario (nunca pagou)',
                'template' => 'seminario',
                'query' => "
                    SELECT DISTINCT p.id, p.nome, p.token,
                           (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
                    FROM pessoas p
                    WHERE p.ativo = 1
                    AND EXISTS (SELECT 1 FROM filiacoes WHERE pessoa_id = p.id AND seminario = 1)
                    AND NOT EXISTS (SELECT 1 FROM filiacoes WHERE pessoa_id = p.id AND status = 'pago')
                    AND NOT EXISTS (SELECT 1 FROM filiacoes WHERE pessoa_id = p.id AND ano = ?)
                ",
                'params' => [$ano],
            ],
            // Grupo 4: Novos (nunca pagou, nunca seminario)
            [
                'nome' => 'Novos contatos',
                'template' => 'convite',
                'query' => "
                    SELECT p.id, p.nome, p.token,
                           (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
                    FROM pessoas p
                    WHERE p.ativo = 1
                    AND NOT EXISTS (SELECT 1 FROM filiacoes WHERE pessoa_id = p.id AND status = 'pago')
                    AND NOT EXISTS (SELECT 1 FROM filiacoes WHERE pessoa_id = p.id AND seminario = 1)
                    AND NOT EXISTS (SELECT 1 FROM filiacoes WHERE pessoa_id = p.id AND ano = ?)
                ",
                'params' => [$ano],
            ],
        ];
    }

    /**
     * Retorna lista de emails do grupo de teste
     */
    /**
     * Converte data dd/mm/aaaa para Y-m-d. Aceita também Y-m-d direto.
     * Retorna null se vazio ou inválido.
     */
    private static function parseDateBR(string $input): ?string {
        if ($input === '') return null;
        // Já está em Y-m-d?
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $input)) return $input;
        // dd/mm/aaaa
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $input, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        return null;
    }

    private static function obterEmailsGrupoTeste(): array {
        $config = db_fetch_one("SELECT valor FROM configuracoes WHERE chave = 'grupo_teste'");
        if (!$config || empty($config['valor'])) {
            return [];
        }
        return array_filter(array_map('trim', explode(',', $config['valor'])));
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
     * Retorna contagem de lembretes (AJAX JSON, somente leitura)
     */
    public static function contarLembretes(): void {
        self::exigirLogin();

        $ano = (int)($_POST['ano'] ?? date('Y'));

        require_once SRC_DIR . '/Services/LembreteService.php';

        $contagem = LembreteService::contarPendentes($ano);

        json_response($contagem);
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
                       GROUP_CONCAT(DISTINCT f.ano || ':' || f.status) as filiacoes
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
            $email_row = db_fetch_one("SELECT email FROM emails WHERE pessoa_id = ? ORDER BY principal DESC, id DESC LIMIT 1", [(int)$id]);
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

        // CPF normalizado; bloqueia se pertence a outra pessoa (índice único)
        if ($cpf) {
            $cpf = preg_replace('/\D/', '', $cpf);
            $dono = cpf_pertence_a_outra_pessoa($cpf, (int)$id);
            if ($dono) {
                flash('error', "CPF já cadastrado para {$dono['nome']} (id {$dono['id']}). Consolide os cadastros em vez de duplicar.");
                redirect("/admin/pessoa/$id");
                return;
            }
        }

        // Atualiza pessoa
        db_execute("
            UPDATE pessoas SET
                nome = ?, cpf = ?, notas = ?, ativo = ?,
                updated_at = datetime('now','localtime')
            WHERE id = ?
        ", [$nome, $cpf, $notas, $ativo, (int)$id]);

        // Atualiza email principal.
        //
        // Duas armadilhas aqui, as duas corrigidas em 28/08/2026:
        //
        // 1. emails.email e UNIQUE. Digitar um endereco que ja pertence a outro
        //    cadastro lancava PDOException -> 500, DEPOIS de o UPDATE da pessoa
        //    ja ter rodado (sem transacao): o admin via tela de erro sem saber
        //    que nome, CPF e notas foram gravados e o email nao. O caminho do
        //    CPF tinha essa guarda; o do email nao.
        //
        // 2. O UPDATE sobrescrevia o endereco anterior. Corrigir um typo e
        //    registrar um endereco novo eram a mesma acao, e o antigo sumia —
        //    contra a regra "manter todos os emails da pessoa", e justamente a
        //    chave que casa pagamento avulso do PagBank. Agora o anterior vira
        //    SECUNDARIO em vez de ser destruido.
        if ($email) {
            $de_outra = db_fetch_one(
                "SELECT e.pessoa_id, p.nome FROM emails e JOIN pessoas p ON p.id = e.pessoa_id
                 WHERE LOWER(TRIM(e.email)) = LOWER(TRIM(?)) AND e.pessoa_id <> ?",
                [$email, (int)$id]
            );
            if ($de_outra) {
                flash('error', "Email já cadastrado para {$de_outra['nome']} (id {$de_outra['pessoa_id']})."
                    . " Consolide os cadastros em vez de duplicar. Os demais campos foram salvos.");
                redirect("/admin/pessoa/$id");
                return;
            }

            $principal = db_fetch_one(
                "SELECT id, email FROM emails WHERE pessoa_id = ? AND principal = 1",
                [(int)$id]
            );

            if (!$principal) {
                db_execute("INSERT INTO emails (pessoa_id, email, principal) VALUES (?, ?, 1)", [(int)$id, $email]);
            } elseif (strtolower(trim($principal['email'])) !== strtolower(trim($email))) {
                // O novo ja existe como secundario desta mesma pessoa? So promove.
                $ja_secundario = db_fetch_one(
                    "SELECT id FROM emails WHERE pessoa_id = ? AND LOWER(TRIM(email)) = LOWER(TRIM(?))",
                    [(int)$id, $email]
                );
                db_execute("UPDATE emails SET principal = 0 WHERE id = ?", [$principal['id']]);
                if ($ja_secundario) {
                    db_execute("UPDATE emails SET principal = 1 WHERE id = ?", [$ja_secundario['id']]);
                } else {
                    db_execute("INSERT INTO emails (pessoa_id, email, principal) VALUES (?, ?, 1)", [(int)$id, $email]);
                }
                registrar_log('email_principal_trocado', (int)$id,
                    "Email principal passou a ser $email; o anterior ({$principal['email']}) virou secundario");
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

        // status, categoria e status_at entram aqui porque as guardas abaixo
        // preservam o valor atual quando o POST traz algo fora da lista.
        $filiacao = db_fetch_one(
            "SELECT id, pessoa_id, status, categoria, status_at FROM filiacoes WHERE id = ?",
            [(int)$id]
        );
        if (!$filiacao) {
            flash('error', 'Filiação não encontrada.');
            redirect('/admin');
            return;
        }

        // O <select> da tela so oferece enviado/acesso/pendente/pago. Registro
        // em 'nao_pago' (1308 em 2026, gravados por fecharCampanha) ou
        // 'cancelado' (gravado pelo webhook) nao casa com nenhuma <option>, e o
        // navegador manda a PRIMEIRA — 'enviado'. Abrir um registro so para
        // corrigir a instituicao e salvar ressuscitava a filiacao: voltava a
        // contar como pendente e a ser elegivel para lembrete de campanha
        // encerrada. Se o status recebido nao esta na lista, PRESERVA o atual.
        $status_validos = ['enviado', 'acesso', 'pendente', 'pago', 'nao_pago', 'cancelado'];
        $status = trim($_POST['status'] ?? '');
        if (!in_array($status, $status_validos, true)) {
            $status = $filiacao['status'];
        }

        // Mesma armadilha: categoria vazia nao casa com nenhuma <option> e
        // virava 'estudante' — que exige comprovante de matricula.
        $categoria = trim($_POST['categoria'] ?? '');
        $cat_validas = array_keys(CATEGORIAS_FILIACAO);
        if (!in_array($categoria, $cat_validas, true)) {
            $categoria = $filiacao['categoria'];
        }

        $valor = (int)($_POST['valor'] ?? 0);
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
                profissao = ?, formacao = ?, instituicao = ?, status_at = ?
            WHERE id = ?
        ", [
            $categoria, $valor, $status, $metodo, $data_pagamento,
            $telefone, $endereco, $cep, $cidade, $estado, $pais,
            $profissao, $formacao, $instituicao,
            // So carimba status_at quando o status REALMENTE mudou: reescrever
            // a cada gravacao fazia o registro saltar para o topo do painel por
            // uma correcao de instituicao.
            ($status !== $filiacao['status']) ? date('Y-m-d H:i:s') : $filiacao['status_at'],
            (int)$id
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

        // CPF normalizado; bloqueia duplicata (índice único)
        if ($cpf) {
            $cpf = preg_replace('/\D/', '', $cpf);
            $dono = cpf_pertence_a_outra_pessoa($cpf, (int)($existente['id'] ?? 0));
            if ($dono) {
                flash('error', "CPF já cadastrado para {$dono['nome']} (id {$dono['id']}). Use o cadastro existente.");
                redirect('/admin/novo');
                return;
            }
        }

        if ($existente) {
            $pessoa_id = $existente['id'];
            // NUNCA apagar dado que ja existe. O CPF e opcional neste
            // formulario: deixa-lo em branco apagava o que a pessoa preencheu
            // no formulario publico — e sem CPF ela perde a entrada por CPF no
            // evento, a conferencia de adimplencia e o casamento com o extrato
            // do PagBank. O nome so e substituido quando o digitado e MAIS
            // COMPLETO, conforme a regra de consolidacao do CLAUDE.md.
            $nome_atual = trim((string)($existente['nome'] ?? ''));
            $nome_final = (mb_strlen($nome) > mb_strlen($nome_atual)) ? $nome : $nome_atual;
            if ($nome_final !== $nome_atual) {
                registrar_log('nome_atualizado', $pessoa_id, "Nome trocado de \"$nome_atual\" para \"$nome_final\" via /admin/novo");
            }
            db_execute(
                "UPDATE pessoas SET nome = ?, cpf = COALESCE(NULLIF(?, ''), cpf) WHERE id = ?",
                [$nome_final, $cpf, $pessoa_id]
            );
        } else {
            // Cria pessoa
            $pessoa_id = db_insert("
                INSERT INTO pessoas (nome, cpf, token, created_at)
                VALUES (?, ?, ?, datetime('now','localtime'))
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
            // Marca como pago. O valor tem de ir junto: o ramo de baixo (criar
            // filiacao nova) chama valor_por_categoria e este esquecia — a
            // filiacao vinda do envio de campanha tem valor NULL, e o
            // tesoureiro acabou de escolher a categoria numa lista que mostra o
            // preco. A declaracao saia com R$ 0,00.
            $valor_existente = valor_por_categoria($categoria, $ano);
            db_execute("
                UPDATE filiacoes
                SET status = 'pago', metodo = 'manual', data_pagamento = datetime('now','localtime'),
                    status_at = datetime('now','localtime'), categoria = ?, valor = ?
                WHERE id = ?
            ", [$categoria, $valor_existente, $filiacao_existe['id']]);
        } else {
            // Cria filiação
            $valor = valor_por_categoria($categoria, $ano);
            db_insert("
                INSERT INTO filiacoes (pessoa_id, ano, categoria, valor, status, metodo, data_pagamento, created_at, status_at)
                VALUES (?, ?, ?, ?, 'pago', 'manual', datetime('now','localtime'), datetime('now','localtime'), datetime('now','localtime'))
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
            "SELECT pessoa_id, ano, categoria, valor, status FROM filiacoes WHERE id = ?",
            [(int)$filiacao_id]
        );

        if (!$filiacao) {
            flash('error', 'Filiação não encontrada.');
            redirect('/admin');
            return;
        }

        if ($filiacao['status'] === 'pago') {
            flash('error', 'Esta filiação já está marcada como paga.');
            redirect("/admin/pessoa/{$filiacao['pessoa_id']}");
            return;
        }

        // Filiacao criada pelo envio de campanha nasce so com pessoa_id, ano,
        // categoria='' e status='enviado' — VALOR fica NULL. Marcar paga sem
        // preencher gerava declaracao em PDF com "R$ 0,00" e categoria em
        // branco, e a filiacao sumia do arrecadado do painel.
        $categoria = $filiacao['categoria'] ?: '';
        $valor = (int)($filiacao['valor'] ?? 0);

        if ($categoria === '') {
            $categoria = 'profissional_nacional';
        }
        if ($valor <= 0) {
            $valor = valor_por_categoria($categoria, (int)$filiacao['ano']);
        }
        if ($categoria !== ($filiacao['categoria'] ?? '') || $valor !== (int)($filiacao['valor'] ?? 0)) {
            registrar_log('pagamento_manual_preenchido', $filiacao['pessoa_id'],
                "Filiacao $filiacao_id nao tinha categoria/valor: gravado $categoria, "
                . formatar_valor($valor) . " ao marcar como paga");
        }

        db_execute("
            UPDATE filiacoes
            SET status = 'pago',
                categoria = ?,
                valor = ?,
                metodo = COALESCE(metodo, 'manual'),
                data_pagamento = datetime('now','localtime'),
                status_at = datetime('now','localtime')
            WHERE id = ? AND status != 'pago'
        ", [$categoria, $valor, (int)$filiacao_id]);

        // Cancela lembretes pendentes
        require_once SRC_DIR . '/Services/LembreteService.php';
        LembreteService::cancelar((int)$filiacao_id);

        registrar_log('pagamento_manual', $filiacao['pessoa_id'], "Filiação $filiacao_id marcada como paga via admin");

        // Envia email de confirmação com PDF
        require_once SRC_DIR . '/Controllers/WebhookController.php';
        WebhookController::processarPagamentoConfirmado($filiacao['pessoa_id'], $filiacao['ano']);

        flash('success', 'Filiação marcada como paga. Email de confirmação enviado.');
        redirect("/admin/pessoa/{$filiacao['pessoa_id']}");
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
                db_execute("UPDATE filiacoes SET status = 'enviado', status_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), (int)$filiacao_id]);
            }
            registrar_log('email_enviado', $filiacao['pessoa_id'], "Email de filiação {$filiacao['ano']} enviado via admin");
            flash('success', 'Email enviado com sucesso.');
        } else {
            flash('error', 'Erro ao enviar email.');
        }

        redirect("/admin/pessoa/{$filiacao['pessoa_id']}");
    }

    /**
     * Envia (ou reenvia) email de confirmação com PDF para filiação paga
     */
    public static function enviarConfirmacao(string $filiacao_id): void {
        self::exigirLogin();

        $filiacao = db_fetch_one(
            "SELECT pessoa_id, ano, status FROM filiacoes WHERE id = ?",
            [(int)$filiacao_id]
        );

        if (!$filiacao) {
            flash('error', 'Filiação não encontrada.');
            redirect('/admin');
            return;
        }

        if ($filiacao['status'] !== 'pago') {
            flash('error', 'Filiação não está marcada como paga.');
            redirect("/admin/pessoa/{$filiacao['pessoa_id']}");
            return;
        }

        require_once SRC_DIR . '/Controllers/WebhookController.php';
        WebhookController::processarPagamentoConfirmado($filiacao['pessoa_id'], $filiacao['ano']);

        flash('success', 'Email de confirmação enviado.');
        redirect("/admin/pessoa/{$filiacao['pessoa_id']}");
    }

    /**
     * Reenvia a confirmacao de uma INSCRICAO em evento.
     *
     * POR QUE: existia para filiacao e nao para inscricao. Como o status vira
     * 'pago' ANTES de o email sair, uma falha do Brevo naquele instante era
     * definitiva — a reentrega do webhook cai em "ja processado" e nao havia
     * caminho nenhum para remandar o comprovante. A pessoa pagava e nunca
     * recebia o PDF.
     */
    public static function enviarConfirmacaoInscricao(string $inscricao_id): void {
        self::exigirLogin();

        $inscricao = db_fetch_one(
            "SELECT i.id, i.pessoa_id, i.evento_id, i.status
             FROM inscricoes i WHERE i.id = ?", [(int)$inscricao_id]
        );

        if (!$inscricao) {
            flash('error', 'Inscrição não encontrada.');
            redirect('/admin/eventos');
            return;
        }

        if (!in_array($inscricao['status'], ['pago', 'gratuita_confirmada'], true)) {
            flash('error', 'Inscrição não está confirmada.');
            redirect("/admin/eventos/{$inscricao['evento_id']}/inscritos");
            return;
        }

        require_once SRC_DIR . '/Controllers/WebhookController.php';
        try {
            WebhookController::processarInscricaoConfirmada((int)$inscricao['id']);
            registrar_log('evento_confirmacao_reenviada', (int)$inscricao['pessoa_id'],
                "Confirmacao da inscricao {$inscricao['id']} reenviada pelo admin");
            flash('success', 'Email de confirmação enviado.');
        } catch (Throwable $e) {
            registrar_log('evento_erro_confirmacao', (int)$inscricao['pessoa_id'],
                "Falha ao reenviar confirmacao da inscricao {$inscricao['id']}: " . $e->getMessage());
            flash('error', 'Não foi possível enviar: ' . $e->getMessage());
        }
        redirect("/admin/eventos/{$inscricao['evento_id']}/inscritos");
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

        // PRAGMA foreign_keys nunca e ligado nesta conexao, entao o
        // ON DELETE CASCADE do schema e decorativo: a cascata e esta, escrita a
        // mao. Faltavam inscricoes e lembretes — a inscricao ficava apontando
        // para um id inexistente e, como inscritos_do_evento() faz JOIN
        // pessoas, sumia da tela de inscritos, da planilha e do painel da
        // organizacao, enquanto os totais de /admin/eventos continuavam
        // contando. Inscricao PAGA desaparecia com o dinheiro ja recebido.
        $inscricoes = db_fetch_all("SELECT id, status FROM inscricoes WHERE pessoa_id = ?", [(int)$pessoa_id]);
        foreach ($inscricoes as $i) {
            if (in_array($i['status'], ['pago', 'gratuita_confirmada'], true)) {
                registrar_log('exclusao_inscricao_confirmada', null,
                    "Pessoa $pessoa_id excluida tinha inscricao {$i['id']} em situacao {$i['status']}: conferir no extrato antes de fechar as contas do evento");
            }
            db_execute("DELETE FROM lembretes_agendados WHERE inscricao_id = ?", [(int)$i['id']]);
        }
        db_execute("DELETE FROM inscricoes WHERE pessoa_id = ?", [(int)$pessoa_id]);

        foreach (db_fetch_all("SELECT id FROM filiacoes WHERE pessoa_id = ?", [(int)$pessoa_id]) as $f) {
            db_execute("DELETE FROM lembretes_agendados WHERE filiacao_id = ?", [(int)$f['id']]);
        }
        db_execute("DELETE FROM filiacoes WHERE pessoa_id = ?", [(int)$pessoa_id]);
        db_execute("DELETE FROM emails WHERE pessoa_id = ?", [(int)$pessoa_id]);
        db_execute("DELETE FROM pessoas WHERE id = ?", [(int)$pessoa_id]);

        registrar_log('exclusao', null, "Pessoa $pessoa_id ({$pessoa['nome']}) excluída via admin");

        flash('success', 'Pessoa excluída.');
        redirect('/admin');
    }

    /**
     * Mostra detalhes de um envio (email enviado + destinatários)
     */
    public static function verEnvio(string $id): void {
        self::exigirLogin();

        $id = (int)$id;
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

    // ==================== MÓDULO DE EVENTOS ====================

    /**
     * Converte valor em reais ("240", "240,00", "R$ 240,00") para centavos
     */
    private static function parseValorReais(string $input): ?int {
        $limpo = trim(str_replace(['R$', ' ', '.'], '', $input));
        $limpo = str_replace(',', '.', $limpo);
        if ($limpo === '' || !is_numeric($limpo)) return null;
        return (int)round((float)$limpo * 100);
    }

    /**
     * Valida slug de evento: minúsculas, números e hífen; não pode ser palavra reservada
     */
    private static function validarSlugEvento(string $slug): ?string {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,80}$/', $slug)) {
            return null;
        }
        if (in_array($slug, ['inscrever', 'novo', 'admin'])) {
            return null;
        }
        return $slug;
    }

    /**
     * Evento tem alguma inscrição paga? (trava de edição de valores — decisão G)
     */
    private static function eventoTemPagos(int $evento_id): bool {
        $r = db_fetch_one(
            "SELECT COUNT(*) as n FROM inscricoes WHERE evento_id = ? AND status = 'pago'",
            [$evento_id]
        );
        return (int)($r['n'] ?? 0) > 0;
    }

    /**
     * Listagem de eventos
     */
    public static function eventos(): void {
        self::exigirLogin();

        $eventos = db_fetch_all("
            SELECT e.*,
                (SELECT COUNT(*) FROM inscricoes i WHERE i.evento_id = e.id) as total_inscricoes,
                (SELECT COUNT(*) FROM inscricoes i WHERE i.evento_id = e.id AND i.status IN ('pago','gratuita_confirmada')) as confirmadas,
                (SELECT COALESCE(SUM(valor),0) FROM inscricoes i WHERE i.evento_id = e.id AND i.status = 'pago') as arrecadado
            FROM eventos e
            ORDER BY e.created_at DESC
        ");

        $titulo = "Admin - Eventos";
        ob_start();
        require SRC_DIR . '/Views/admin/eventos.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Formulário de novo evento
     */
    public static function eventoNovoForm(): void {
        self::exigirLogin();

        $titulo = "Admin - Novo Evento";
        ob_start();
        require SRC_DIR . '/Views/admin/evento_novo.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Cria evento (como rascunho)
     */
    public static function eventoNovoSalvar(): void {
        self::exigirLogin();

        $nome = trim($_POST['nome'] ?? '');
        $slug = self::validarSlugEvento($_POST['slug'] ?? '');

        if (empty($nome) || $slug === null) {
            flash('error', 'Nome é obrigatório e o slug deve ter só letras minúsculas, números e hífens (não pode ser "inscrever").');
            redirect('/admin/eventos/novo');
            return;
        }

        $existe = db_fetch_one("SELECT id FROM eventos WHERE slug = ?", [$slug]);
        if ($existe) {
            flash('error', "Já existe um evento com o slug \"$slug\".");
            redirect('/admin/eventos/novo');
            return;
        }

        $id = db_insert("
            INSERT INTO eventos (nome, slug, descricao, organizador, data_inicio, data_fim, prazo_inscricao, data_valor_cheio, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'rascunho')
        ", [
            $nome,
            $slug,
            trim($_POST['descricao'] ?? '') ?: null,
            trim($_POST['conteudo'] ?? '') ?: null,
            trim($_POST['local'] ?? '') ?: null,
            trim($_POST['organizador'] ?? '') ?: null,
            ($_POST['data_inicio'] ?? '') ?: null,
            ($_POST['data_fim'] ?? '') ?: null,
            ($_POST['prazo_inscricao'] ?? '') ?: null,
            ($_POST['data_valor_cheio'] ?? '') ?: null,
        ]);

        registrar_log('evento_criado', null, "Evento $id ($slug) criado como rascunho");
        flash('success', 'Evento criado como rascunho. Adicione as categorias antes de publicar.');
        redirect("/admin/eventos/$id");
    }

    /**
     * Página de edição do evento (dados + categorias)
     */
    public static function evento(string $id): void {
        self::exigirLogin();

        $evento = db_fetch_one("SELECT * FROM eventos WHERE id = ?", [(int)$id]);
        if (!$evento) {
            flash('error', 'Evento não encontrado.');
            redirect('/admin/eventos');
            return;
        }

        $categorias = db_fetch_all(
            "SELECT ec.*,
                (SELECT COUNT(*) FROM inscricoes i WHERE i.categoria_id = ec.id) as usos
             FROM evento_categorias ec WHERE ec.evento_id = ? ORDER BY ec.ordem, ec.id",
            [(int)$id]
        );
        $tem_pagos = self::eventoTemPagos((int)$id);

        $titulo = "Admin - Evento: " . $evento['nome'];
        ob_start();
        require SRC_DIR . '/Views/admin/evento.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Salva dados do evento (com travas da decisão G)
     */
    public static function eventoSalvar(string $id): void {
        self::exigirLogin();

        $evento = db_fetch_one("SELECT * FROM eventos WHERE id = ?", [(int)$id]);
        if (!$evento) {
            flash('error', 'Evento não encontrado.');
            redirect('/admin/eventos');
            return;
        }

        $nome = trim($_POST['nome'] ?? '');
        if (empty($nome)) {
            flash('error', 'Nome é obrigatório.');
            redirect("/admin/eventos/$id");
            return;
        }

        // Slug só pode mudar enquanto rascunho (links enviados usam o slug)
        $slug = $evento['slug'];
        if ($evento['status'] === 'rascunho') {
            $slug_novo = self::validarSlugEvento($_POST['slug'] ?? '');
            if ($slug_novo === null) {
                flash('error', 'Slug inválido.');
                redirect("/admin/eventos/$id");
                return;
            }
            $duplicado = db_fetch_one("SELECT id FROM eventos WHERE slug = ? AND id != ?", [$slug_novo, (int)$id]);
            if ($duplicado) {
                flash('error', "Já existe um evento com o slug \"$slug_novo\".");
                redirect("/admin/eventos/$id");
                return;
            }
            $slug = $slug_novo;
        }

        // Cartaz do evento (opcional). COALESCE mantem o atual quando nao veio arquivo.
        $imagem = null;
        if (!empty($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
            $imagem = salvar_imagem_evento($_FILES['imagem'], $slug);
            if ($imagem === null) {
                flash('error', 'Não foi possível salvar o cartaz. Use JPG, PNG ou WebP de até 10MB.');
                redirect("/admin/eventos/$id");
                return;
            }
        }

        // Faixa de apoiadores/patrocinadores (opcional), mesma logica do cartaz.
        $imagem_apoio = null;
        if (!empty($_FILES['imagem_apoiadores']) && $_FILES['imagem_apoiadores']['error'] === UPLOAD_ERR_OK) {
            $imagem_apoio = salvar_imagem_evento($_FILES['imagem_apoiadores'], $slug, 'apoio');
            if ($imagem_apoio === null) {
                flash('error', 'Não foi possível salvar a faixa de apoiadores. Use JPG, PNG ou WebP de até 10MB.');
                redirect("/admin/eventos/$id");
                return;
            }
        }

        db_execute("
            UPDATE eventos SET nome = ?, slug = ?, descricao = ?, conteudo = ?,
                local = ?, organizador = ?,
                data_inicio = ?, data_fim = ?, prazo_inscricao = ?, data_valor_cheio = ?,
                email_contato = ?, assinantes = ?, apoiadores = ?,
                emails_organizacao = ?, organizacao_expira_em = ?,
                imagem_path = COALESCE(?, imagem_path),
                imagem_apoiadores = COALESCE(?, imagem_apoiadores)
            WHERE id = ?
        ", [
            $nome,
            $slug,
            trim($_POST['descricao'] ?? '') ?: null,
            trim($_POST['conteudo'] ?? '') ?: null,
            trim($_POST['local'] ?? '') ?: null,
            trim($_POST['organizador'] ?? '') ?: null,
            ($_POST['data_inicio'] ?? '') ?: null,
            ($_POST['data_fim'] ?? '') ?: null,
            ($_POST['prazo_inscricao'] ?? '') ?: null,
            ($_POST['data_valor_cheio'] ?? '') ?: null,
            trim($_POST['email_contato'] ?? '') ?: null,
            trim($_POST['assinantes'] ?? '') ?: null,
            trim($_POST['apoiadores'] ?? '') ?: null,
            trim($_POST['emails_organizacao'] ?? '') ?: null,
            ($_POST['organizacao_expira_em'] ?? '') ?: null,
            $imagem,
            $imagem_apoio,
            (int)$id,
        ]);

        flash('success', 'Evento salvo.');
        redirect("/admin/eventos/$id");
    }

    /**
     * Muda status do evento (rascunho / publicado / encerrado)
     */
    public static function eventoStatus(string $id): void {
        self::exigirLogin();

        $evento = db_fetch_one("SELECT * FROM eventos WHERE id = ?", [(int)$id]);
        if (!$evento) {
            flash('error', 'Evento não encontrado.');
            redirect('/admin/eventos');
            return;
        }

        $novo = $_POST['status'] ?? '';
        if (!in_array($novo, ['rascunho', 'publicado', 'encerrado'])) {
            flash('error', 'Status inválido.');
            redirect("/admin/eventos/$id");
            return;
        }

        // Publicar exige pelo menos 1 categoria e prazo de inscrição definido
        if ($novo === 'publicado') {
            $n_cat = db_fetch_one("SELECT COUNT(*) as n FROM evento_categorias WHERE evento_id = ?", [(int)$id]);
            if ((int)$n_cat['n'] === 0) {
                flash('error', 'Adicione pelo menos uma categoria antes de publicar.');
                redirect("/admin/eventos/$id");
                return;
            }
            if (empty($evento['prazo_inscricao'])) {
                flash('error', 'Defina o prazo de inscrição antes de publicar.');
                redirect("/admin/eventos/$id");
                return;
            }
        }

        // Voltar para rascunho só sem inscrições
        if ($novo === 'rascunho') {
            $n_insc = db_fetch_one("SELECT COUNT(*) as n FROM inscricoes WHERE evento_id = ?", [(int)$id]);
            if ((int)$n_insc['n'] > 0) {
                flash('error', 'Evento com inscrições não pode voltar a rascunho. Use "encerrado".');
                redirect("/admin/eventos/$id");
                return;
            }
        }

        db_execute("UPDATE eventos SET status = ? WHERE id = ?", [$novo, (int)$id]);
        registrar_log('evento_status', null, "Evento {$evento['slug']}: {$evento['status']} -> $novo");
        flash('success', "Status alterado para \"$novo\".");
        redirect("/admin/eventos/$id");
    }

    /**
     * Exclui evento (só sem inscrições)
     */
    public static function eventoExcluir(string $id): void {
        self::exigirLogin();

        $evento = db_fetch_one("SELECT * FROM eventos WHERE id = ?", [(int)$id]);
        if (!$evento) {
            flash('error', 'Evento não encontrado.');
            redirect('/admin/eventos');
            return;
        }

        $n_insc = db_fetch_one("SELECT COUNT(*) as n FROM inscricoes WHERE evento_id = ?", [(int)$id]);
        if ((int)$n_insc['n'] > 0) {
            flash('error', 'Evento com inscrições não pode ser excluído. Encerre-o.');
            redirect("/admin/eventos/$id");
            return;
        }

        db_execute("DELETE FROM evento_categorias WHERE evento_id = ?", [(int)$id]);
        db_execute("DELETE FROM eventos WHERE id = ?", [(int)$id]);
        registrar_log('evento_excluido', null, "Evento {$evento['slug']} excluído");
        flash('success', 'Evento excluído.');
        redirect('/admin/eventos');
    }

    /**
     * Adiciona ou atualiza categoria do evento
     */
    public static function eventoCategoriaSalvar(string $id): void {
        self::exigirLogin();

        $evento = db_fetch_one("SELECT * FROM eventos WHERE id = ?", [(int)$id]);
        if (!$evento) {
            flash('error', 'Evento não encontrado.');
            redirect('/admin/eventos');
            return;
        }

        $nome = trim($_POST['nome'] ?? '');
        $valor = self::parseValorReais($_POST['valor'] ?? '');
        if (empty($nome) || $valor === null || $valor < 0) {
            flash('error', 'Categoria precisa de nome e valor (use 0 para gratuita).');
            redirect("/admin/eventos/$id");
            return;
        }

        // Valor cheio (segunda faixa). Vazio = preco unico.
        $valor_cheio = trim((string)($_POST['valor_cheio'] ?? ''));
        $valor_cheio = $valor_cheio === '' ? null : self::parseValorReais($valor_cheio);
        if ($valor_cheio !== null && $valor_cheio < 0) {
            flash('error', 'Valor cheio inválido.');
            redirect("/admin/eventos/$id");
            return;
        }

        // Lista da categoria restrita: CPF ou email, um por linha. Guarda
        // normalizado (CPF so com digitos, email em minusculas) para a
        // conferencia no fluxo publico nao depender de como foi digitado aqui.
        // O que nao for CPF de 11 digitos nem email valido e descartado, para
        // uma linha mal digitada nao virar isencao que nunca casa com ninguem.
        $liberados = [];
        foreach (preg_split('/[\s,;]+/', (string)($_POST['cpfs_liberados'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
            if (strpos($item, '@') !== false) {
                $e = strtolower(trim($item));
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) $liberados[] = $e;
                continue;
            }
            $d = preg_replace('/\D/', '', $item);
            if (strlen($d) === 11) $liberados[] = $d;
        }
        $cpfs_liberados = $liberados ? implode("\n", array_unique($liberados)) : null;

        $cat_id = (int)($_POST['categoria_id'] ?? 0);
        $verifica = !empty($_POST['verifica_adimplencia']) ? 1 : 0;
        $comprovante = !empty($_POST['requer_comprovante']) ? 1 : 0;
        $independe = !empty($_POST['independe_filiacao']) ? 1 : 0;
        $ordem = (int)($_POST['ordem'] ?? 0);

        if ($cat_id) {
            // Edição: com inscrição paga no evento, valor fica travado (decisão G)
            $cat = db_fetch_one("SELECT * FROM evento_categorias WHERE id = ? AND evento_id = ?", [$cat_id, (int)$id]);
            if (!$cat) {
                flash('error', 'Categoria não encontrada.');
                redirect("/admin/eventos/$id");
                return;
            }
            if (self::eventoTemPagos((int)$id) && (int)$cat['valor'] !== $valor) {
                flash('error', 'Evento com inscrições pagas: o valor das categorias existentes não pode mudar.');
                redirect("/admin/eventos/$id");
                return;
            }
            if (self::eventoTemPagos((int)$id) && (int)($cat['valor_cheio'] ?? 0) !== (int)$valor_cheio) {
                flash('error', 'Evento com inscrições pagas: o valor das categorias existentes não pode mudar.');
                redirect("/admin/eventos/$id");
                return;
            }
            db_execute("
                UPDATE evento_categorias SET nome = ?, valor = ?, valor_cheio = ?, verifica_adimplencia = ?, requer_comprovante = ?, ordem = ?, cpfs_liberados = ?, independe_filiacao = ?
                WHERE id = ?
            ", [$nome, $valor, $valor_cheio, $verifica, $comprovante, $ordem, $cpfs_liberados, $independe, $cat_id]);
            flash('success', 'Categoria atualizada.');
        } else {
            db_insert("
                INSERT INTO evento_categorias (evento_id, nome, valor, valor_cheio, verifica_adimplencia, requer_comprovante, ordem, cpfs_liberados, independe_filiacao)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ", [(int)$id, $nome, $valor, $valor_cheio, $verifica, $comprovante, $ordem, $cpfs_liberados, $independe]);
            flash('success', 'Categoria adicionada.');
        }

        redirect("/admin/eventos/$id");
    }

    /**
     * Lista de inscritos de um evento (fase 5).
     *
     * Sem esta tela o modulo era cego para a tesouraria: dava para convidar,
     * inscrever e receber pagamento, mas nao para saber quem se inscreveu,
     * conferir comprovante de matricula ou mandar a lista a organizacao.
     */
    public static function eventoInscritos(string $id): void {
        self::exigirLogin();

        $evento = db_fetch_one("SELECT * FROM eventos WHERE id = ?", [(int)$id]);
        if (!$evento) {
            flash('error', 'Evento não encontrado.');
            redirect('/admin/eventos');
            return;
        }

        $filtro = (string)($_GET['status'] ?? '');
        $busca = trim((string)($_GET['q'] ?? ''));

        $inscritos = inscritos_do_evento((int)$id, $filtro, $busca);

        // Totais do evento inteiro, independentes do filtro: sao o retrato que
        // a tesouraria confere, e mudariam de significado se seguissem a busca.
        $totais = db_fetch_one("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN status IN ('pago','gratuita_confirmada') THEN 1 ELSE 0 END) AS confirmadas,
                   SUM(CASE WHEN status = 'pago' THEN 1 ELSE 0 END) AS pagas,
                   SUM(CASE WHEN status = 'gratuita_confirmada' THEN 1 ELSE 0 END) AS isentas,
                   SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) AS pendentes,
                   SUM(CASE WHEN status IN ('enviado','acesso') THEN 1 ELSE 0 END) AS sem_resposta,
                   COALESCE(SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END), 0) AS arrecadado
            FROM inscricoes WHERE evento_id = ?
        ", [(int)$id]);

        $titulo = 'Inscritos — ' . $evento['nome'];
        ob_start();
        require SRC_DIR . '/Views/admin/evento_inscritos.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * CSV dos inscritos — o que a tesouraria manda a organizacao.
     * Respeita o filtro da tela, para exportar exatamente o que se esta vendo.
     */
    public static function eventoInscritosXlsx(string $id): void { self::exportarInscritos($id, 'xlsx'); }
    public static function eventoInscritosCsv(string $id): void { self::exportarInscritos($id, 'csv'); }

    /**
     * Exporta os inscritos no formato pedido, respeitando o filtro da tela.
     *
     * Planilha e a opcao padrao: quem recebe a lista e a organizacao do
     * evento, e CSV abre torto no Excel em portugues. O CSV fica para quem vai
     * processar o arquivo em outro programa.
     */
    private static function exportarInscritos(string $id, string $formato): void {
        self::exigirLogin();

        $evento = db_fetch_one("SELECT * FROM eventos WHERE id = ?", [(int)$id]);
        if (!$evento) {
            flash('error', 'Evento não encontrado.');
            redirect('/admin/eventos');
            return;
        }

        $rows = inscritos_do_evento((int)$id, (string)($_GET['status'] ?? ''), trim((string)($_GET['q'] ?? '')));

        $cabecalho = [
            'Nome', 'Email', 'CPF', 'Telefone', 'Categoria', 'Valor', 'Situação',
            'Forma de pagamento', 'Data do pagamento', 'Instituição', 'Cidade', 'Estado', 'País',
            'Comprovante de matrícula',
        ];

        $linhas = [];
        foreach ($rows as $r) {
            $exige = !empty($r['requer_comprovante']);
            $linhas[] = [
                $r['nome'],
                $r['email'] ?? '',
                $r['cpf'] ? formatar_cpf($r['cpf']) : '',
                $r['telefone'] ?? '',
                $r['categoria_nome'] ?? '',
                $r['valor'] !== null ? formatar_valor((int)$r['valor']) : '',
                self::situacaoInscricao($r['status']),
                self::metodoInscricao($r['metodo'] ?? ''),
                $r['data_pagamento'] ? date('d/m/Y', strtotime($r['data_pagamento'])) : '',
                $r['instituicao'] ?? '',
                $r['cidade'] ?? '',
                $r['estado'] ?? '',
                $r['pais'] ?? '',
                $exige ? (tem_comprovante_evento((int)$evento['id'], (int)$r['pessoa_id']) ? 'enviado' : 'FALTA') : 'não exigido',
            ];
        }

        exportar_planilha($formato, 'inscritos_' . $evento['slug'], 'Inscritos', $cabecalho, $linhas);
    }

    private static function situacaoInscricao(?string $status): string {
        return [
            'pago' => 'Pago', 'gratuita_confirmada' => 'Isento confirmado',
            'pendente' => 'Aguardando pagamento', 'acesso' => 'Abriu o formulário',
            'enviado' => 'Link enviado',
        ][$status ?? ''] ?? (string)$status;
    }

    private static function metodoInscricao(?string $metodo): string {
        return ['pix' => 'PIX', 'boleto' => 'Boleto', 'cartao' => 'Cartão'][$metodo ?? ''] ?? '';
    }

    /**
     * Abre o comprovante de matricula enviado numa inscricao.
     */
    public static function eventoComprovante(string $id, string $pessoa_id): void {
        self::exigirLogin();

        $caminho = obter_comprovante_evento((int)$id, (int)$pessoa_id);
        if (!$caminho || !file_exists($caminho)) {
            flash('error', 'Comprovante não encontrado.');
            redirect("/admin/eventos/$id/inscritos");
            return;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $caminho);
        finfo_close($finfo);

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="comprovante_evento_' . (int)$id . '_' . (int)$pessoa_id . '.' . pathinfo($caminho, PATHINFO_EXTENSION) . '"');
        header('Content-Length: ' . filesize($caminho));
        header('Cache-Control: private, max-age=0, must-revalidate');
        readfile($caminho);
        exit;
    }

    /**
     * Tela de convites: mostra a lista resolvida ANTES de disparar.
     *
     * Existe porque convidar cria cadastro para quem ainda nao existe, e
     * cadastro criado as cegas vira duplicata. Aqui a tesouraria ve, linha a
     * linha, quem ja esta na base, quem e novo e quem ja resolveu a inscricao,
     * e escolhe o que enviar.
     */
    public static function eventoConvitesForm(string $id, string $cat_id): void {
        self::exigirLogin();

        $evento = db_fetch_one("SELECT * FROM eventos WHERE id = ?", [(int)$id]);
        $cat = db_fetch_one("SELECT * FROM evento_categorias WHERE id = ? AND evento_id = ?", [(int)$cat_id, (int)$id]);
        if (!$evento || !$cat) {
            flash('error', 'Categoria não encontrada.');
            redirect("/admin/eventos/$id");
            return;
        }

        $destinos = self::resolverConvidados($cat, (int)$evento['id']);

        $titulo = 'Convites — ' . $cat['nome'];
        ob_start();
        require SRC_DIR . '/Views/admin/evento_convites.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Resolve cada linha da lista em uma pessoa e uma situacao.
     * Nao altera nada: e so retrato.
     */
    private static function resolverConvidados(array $cat, int $evento_id): array {
        $destinos = [];
        foreach (itens_liberados_categoria($cat)['pares'] as $par) {
            $destinos[] = self::retratoConvidado($par, $evento_id);
        }
        return $destinos;
    }

    /**
     * Acha a pessoa de um par (CPF, email). O CPF manda: e a chave que a
     * pessoa nao troca. O email so e usado quando nao ha CPF, ou quando o CPF
     * ainda nao esta em cadastro nenhum.
     */
    private static function pessoaDoConvidado(array $par): ?array {
        if (!empty($par['cpf'])) {
            $p = buscar_pessoa_por_cpf($par['cpf']);
            if ($p) return $p;
        }
        if (!empty($par['email'])) {
            return buscar_pessoa_por_email($par['email']);
        }
        return null;
    }

    private static function retratoConvidado(array $par, int $evento_id): array {
        $pessoa = self::pessoaDoConvidado($par);
        $inscricao = $pessoa ? buscar_inscricao((int)$pessoa['id'], $evento_id) : null;
        $status = $inscricao['status'] ?? null;

        $entrada = trim(($par['cpf'] ? formatar_cpf($par['cpf']) : '') . ' ' . ($par['email'] ?? ''));

        // Para onde o convite vai: o endereco que a organizacao passou, se
        // passou; senao o do cadastro.
        $email = $par['email'] ?: (string)($pessoa['email'] ?? '');

        // O ganho de pedir os dois: cadastro achado pelo CPF que ainda nao tem
        // aquele email GANHA o email, em vez de nascer um cadastro paralelo.
        $acrescenta_email = false;
        if ($pessoa && !empty($par['email'])) {
            $ja_tem = db_fetch_one("SELECT 1 FROM emails WHERE pessoa_id = ? AND LOWER(TRIM(email)) = ?",
                                   [(int)$pessoa['id'], $par['email']]);
            $acrescenta_email = !$ja_tem;
        }
        $acrescenta_cpf = $pessoa && !empty($par['cpf']) && empty($pessoa['cpf'])
                          && !cpf_pertence_a_outra_pessoa($par['cpf'], (int)$pessoa['id']);

        // Homonimo em OUTRO cadastro: sinal de que esta pessoa ja pode existir
        // na base com outro email. Nao decide nada — so avisa quem vai enviar.
        $homonimos = [];
        if ($pessoa && trim((string)$pessoa['nome']) !== '') {
            $homonimos = db_fetch_all("
                SELECT id, nome FROM pessoas
                WHERE id <> ? AND LOWER(TRIM(nome)) = LOWER(TRIM(?))
            ", [(int)$pessoa['id'], $pessoa['nome']]);
        }

        // CPF e email da lista apontando para pessoas DIFERENTES: quase sempre
        // e um digito trocado no CPF. Nao da para adivinhar qual dos dois esta
        // certo, entao a tela mostra e o envio recusa.
        $divergencia = null;
        if ($pessoa && $email !== '') {
            $dono = db_fetch_one("SELECT p.id, p.nome FROM emails e JOIN pessoas p ON p.id = e.pessoa_id
                                  WHERE LOWER(TRIM(e.email)) = ? AND e.pessoa_id <> ?",
                                 [$email, (int)$pessoa['id']]);
            if ($dono) {
                $divergencia = $dono;
            }
        }

        if ($divergencia) {
            $situacao = 'divergente';
        } elseif (in_array($status, ['pago', 'gratuita_confirmada'], true)) {
            $situacao = 'ja_confirmada';
        } elseif ($email === '') {
            $situacao = 'sem_email';
        } elseif (!$pessoa) {
            $situacao = 'nova';
        } elseif ($status !== null) {
            $situacao = 'ja_convidada';
        } else {
            $situacao = 'cadastrada';
        }

        return [
            'entrada' => $entrada,
            'par' => $par,
            'pessoa' => $pessoa,
            'email' => $email,
            'situacao' => $situacao,
            'divergencia' => $divergencia,
            'homonimos' => $homonimos,
            'acrescenta_email' => $acrescenta_email,
            'acrescenta_cpf' => $acrescenta_cpf,
            'enviavel' => !in_array($situacao, ['ja_confirmada', 'sem_email', 'divergente'], true),
        ];
    }

    /**
     * Envia convites de uma categoria restrita.
     *
     * Para cada linha da lista de liberados (CPF ou email), acha ou cria a
     * pessoa, garante token e inscricao, e manda o link pronto. Assim o
     * convidado nao passa pela entrada por CPF nem digita email — some a
     * chance de ele informar um endereco diferente do que a organizacao
     * passou, que era a ambiguidade do convite feito por fora.
     *
     * Idempotente no que importa: reenviar nao duplica pessoa nem inscricao.
     * Quem ja pagou ou ja teve a inscricao confirmada nao recebe de novo.
     */
    public static function eventoEnviarConvites(string $id, string $cat_id): void {
        self::exigirLogin();
        require_once SRC_DIR . '/Services/BrevoService.php';

        $evento = db_fetch_one("SELECT * FROM eventos WHERE id = ?", [(int)$id]);
        $cat = db_fetch_one("SELECT * FROM evento_categorias WHERE id = ? AND evento_id = ?", [(int)$cat_id, (int)$id]);
        if (!$evento || !$cat) {
            flash('error', 'Categoria não encontrada.');
            redirect("/admin/eventos/$id");
            return;
        }
        if (!categoria_restrita($cat)) {
            flash('error', 'Esta categoria não tem lista de liberados — convite só faz sentido em categoria restrita.');
            redirect("/admin/eventos/$id");
            return;
        }

        // O botao vive dentro do formulario de edicao da categoria, entao a
        // lista pode ter sido alterada na tela e ainda nao salva. Enviar a
        // versao antiga sem avisar seria pior do que recusar.
        if (isset($_POST['cpfs_liberados'])) {
            $normaliza = function (?string $bruto): string {
                $itens = [];
                foreach (preg_split('/[\s,;]+/', (string)$bruto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
                    if (strpos($item, '@') !== false) {
                        $e = strtolower(trim($item));
                        if (filter_var($e, FILTER_VALIDATE_EMAIL)) $itens[] = $e;
                        continue;
                    }
                    $d = preg_replace('/\D/', '', $item);
                    if (strlen($d) === 11) $itens[] = $d;
                }
                $itens = array_unique($itens);
                sort($itens);
                return implode(',', $itens);
            };
            if ($normaliza($_POST['cpfs_liberados']) !== $normaliza($cat['cpfs_liberados'] ?? '')) {
                flash('error', 'A lista na tela está diferente da salva. Clique em "Salvar" antes de enviar os convites.');
                redirect("/admin/eventos/$id");
                return;
            }
        }

        $enviados = 0;
        $sem_email = [];
        $falhas = 0;
        $ja_convidados = 0;
        $divergentes = [];

        // Por padrao NAO reconvida quem ja recebeu. Antes, acrescentar um nome
        // a lista e clicar "Enviar convites" mandava o segundo convite a todos
        // os que ainda nao tinham pago — o convidar() so pulava quem ja estava
        // pago ou isento. A previa rotula cada um, mas nada no servidor
        // impedia, enquanto o CLAUDE.md afirmava "envio idempotente".
        //
        // A caixa "reenviar" na tela cobre o caso legitimo: o convite se perdeu
        // e a pessoa pede de novo.
        $reenviar = !empty($_POST['reenviar']);

        foreach (itens_liberados_categoria($cat)['pares'] as $par) {
            $pessoa = self::pessoaDoConvidado($par);

            if (!$reenviar && $pessoa) {
                $retrato = self::retratoConvidado($par, (int)$evento['id']);
                if (($retrato['situacao'] ?? '') === 'ja_convidada') {
                    $ja_convidados++;
                    continue;
                }
            }

            // Sem cadastro e sem email na lista, nao ha para onde mandar. Sao
            // os convidados so por CPF que ainda nao estao na base: eles se
            // inscrevem pela pagina publica, entrando com o proprio CPF.
            if (!$pessoa && empty($par['email'])) {
                $sem_email[] = formatar_cpf((string)$par['cpf']);
                continue;
            }

            if (!$pessoa) {
                criar_pessoa($par['email']);
                $pessoa = buscar_pessoa_por_email($par['email']);
            }

            // Completa o cadastro com o que a organizacao informou. E aqui que
            // pedir CPF e email evita duplicata: o email novo entra como
            // secundario da pessoa certa, em vez de fundar outro cadastro.
            if ($pessoa && !empty($par['email'])) {
                $ja_tem = db_fetch_one("SELECT 1 FROM emails WHERE pessoa_id = ? AND LOWER(TRIM(email)) = ?",
                                       [(int)$pessoa['id'], $par['email']]);
                // O email pode pertencer a OUTRA pessoa. Acontece quando o CPF
                // da lista tem um digito trocado: ele acha o cadastro errado, e
                // o email do convidado seria pendurado nele. Alem do dado
                // trocado, emails.email e UNIQUE — o INSERT estourava numa
                // PDOException nao capturada, isto e, 500 na cara de quem
                // enviava, no meio do lote.
                $de_outra = db_fetch_one("SELECT pessoa_id FROM emails WHERE LOWER(TRIM(email)) = ? AND pessoa_id <> ?",
                                         [$par['email'], (int)$pessoa['id']]);
                if ($de_outra) {
                    registrar_log('convite_identificadores_divergentes', (int)$pessoa['id'],
                        "CPF da lista aponta para a pessoa {$pessoa['id']}, mas o email {$par['email']} e da pessoa"
                        . " {$de_outra['pessoa_id']}. Convite NAO enviado — conferir o CPF da lista ({$evento['slug']}).");
                    $divergentes[] = $par['email'];
                    continue;
                }
                if (!$ja_tem) {
                    db_execute("INSERT INTO emails (pessoa_id, email, principal) VALUES (?, ?, 0)",
                               [(int)$pessoa['id'], $par['email']]);
                    registrar_log('email_secundario_adicionado', (int)$pessoa['id'],
                        "Email da lista de convidados acrescentado ao cadastro ({$evento['slug']})");
                }
            }
            if ($pessoa && !empty($par['cpf']) && empty($pessoa['cpf'])
                && !cpf_pertence_a_outra_pessoa($par['cpf'], (int)$pessoa['id'])) {
                db_execute("UPDATE pessoas SET cpf = ? WHERE id = ?", [$par['cpf'], (int)$pessoa['id']]);
                $pessoa['cpf'] = $par['cpf'];
            }

            $destino = $par['email'] ?: (string)($pessoa['email'] ?? '');
            if ($destino === '') {
                $sem_email[] = formatar_cpf((string)$par['cpf']);
                continue;
            }
            if (self::convidar($pessoa, $destino, $evento, $cat)) $enviados++; else $falhas++;
        }

        $msg = "$enviados convite(s) enviado(s).";
        if ($ja_convidados) {
            $msg .= " $ja_convidados ja tinha(m) sido convidado(s) e foi(ram) pulado(s)"
                . " — marque \"reenviar\" para mandar de novo.";
        }
        if ($divergentes) {
            $msg .= ' NAO enviados por CPF e email apontarem para pessoas diferentes (conferir o CPF na lista): '
                . implode(', ', $divergentes) . '.';
        }
        if ($falhas) $msg .= " $falhas falharam (ver log).";
        if ($sem_email) {
            $msg .= ' Sem email, não foi possível convidar: ' . implode(', ', $sem_email) .
                    '. Essas pessoas conseguem se inscrever sozinhas pela página do evento, entrando com o CPF.';
        }
        flash($enviados ? 'success' : 'error', $msg);
        redirect("/admin/eventos/$id");
    }

    /**
     * Garante token e inscricao e dispara o convite. Devolve se o email saiu.
     */
    private static function convidar(?array $pessoa, string $email, array $evento, array $cat): bool {
        if (!$pessoa) return false;

        $inscricao = buscar_inscricao((int)$pessoa['id'], (int)$evento['id']);
        if ($inscricao && in_array($inscricao['status'], ['pago', 'gratuita_confirmada'], true)) {
            return false; // ja resolvida: nao reconvidar
        }

        $token = $pessoa['token'];
        if (!$token) {
            $token = gerar_token();
            db_execute("UPDATE pessoas SET token = ? WHERE id = ?", [$token, (int)$pessoa['id']]);
        }

        if (!$inscricao) {
            criar_inscricao((int)$pessoa['id'], (int)$evento['id'], 'enviado', 'convite_admin');
        }

        try {
            $ok = BrevoService::enviarConviteEvento(
                $email, $pessoa['nome'] ?? '', $evento['nome'], $cat['nome'], $evento['slug'], $token
            );
            registrar_log($ok ? 'evento_convite_enviado' : 'evento_convite_falhou', (int)$pessoa['id'],
                "Convite para '{$cat['nome']}' no evento {$evento['slug']}");
            return $ok;
        } catch (Exception $e) {
            registrar_log('evento_convite_falhou', (int)$pessoa['id'], 'Erro no convite: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Exclui categoria (só sem inscrições que a referenciem)
     */
    public static function eventoCategoriaExcluir(string $id, string $cat_id): void {
        self::exigirLogin();

        $cat = db_fetch_one(
            "SELECT * FROM evento_categorias WHERE id = ? AND evento_id = ?",
            [(int)$cat_id, (int)$id]
        );
        if (!$cat) {
            flash('error', 'Categoria não encontrada.');
            redirect("/admin/eventos/$id");
            return;
        }

        $usos = db_fetch_one("SELECT COUNT(*) as n FROM inscricoes WHERE categoria_id = ?", [(int)$cat_id]);
        if ((int)$usos['n'] > 0) {
            flash('error', 'Categoria com inscrições não pode ser excluída.');
            redirect("/admin/eventos/$id");
            return;
        }

        db_execute("DELETE FROM evento_categorias WHERE id = ?", [(int)$cat_id]);
        flash('success', 'Categoria excluída.');
        redirect("/admin/eventos/$id");
    }
}
