<?php
/**
 * Pilotis — Campanhas: valores, grupos de envio, lotes e lembretes.
 *
 * O envio por lote e o ponto mais sensivel do sistema — foi um cron
 * disparando tres vezes que gerou 870 emails para 258 pessoas em 25/01/2026.
 * As travas vivem aqui e no cron-campanha.php.
 *
 * Extraido do AdminController em 29/08/2026. Estende-o para herdar
 * exigirLogin(); as rotas em public/index.php apontam para esta classe.
 */

// A base define exigirLogin(). Exigida AQUI, e nao so no index.php: assim o
// arquivo funciona em qualquer ordem de carregamento — inclusive nos testes.
require_once __DIR__ . '/AdminController.php';

class AdminCampanhaController extends AdminController {

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

}