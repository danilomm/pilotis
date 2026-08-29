<?php
/**
 * Pilotis — Cadastro de pessoas, filiacoes e pagamentos manuais.
 *
 * Toda tela aqui mostra dado pessoal completo — CPF, telefone, endereco. E a
 * diferenca em relacao ao painel da organizacao, que ve nome e contato e
 * mais nada.
 *
 * Extraido do AdminController em 29/08/2026. Estende-o para herdar
 * exigirLogin(); as rotas em public/index.php apontam para esta classe.
 */

// A base define exigirLogin(). Exigida AQUI, e nao so no index.php: assim o
// arquivo funciona em qualquer ordem de carregamento — inclusive nos testes.
require_once __DIR__ . '/AdminController.php';

class AdminPessoasController extends AdminController {

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

}