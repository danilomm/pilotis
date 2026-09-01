<?php
/**
 * Pilotis — Eventos: cadastro, categorias, inscritos, convites e exportacao.
 *
 * inscritos_do_evento() e a consulta unica desta tela, dos dois CSV e do
 * painel da organizacao. Se divergirem, alguem cobra a pessoa errada.
 *
 * Extraido do AdminController em 29/08/2026. Estende-o para herdar
 * exigirLogin(); as rotas em public/index.php apontam para esta classe.
 */

// A base define exigirLogin(). Exigida AQUI, e nao so no index.php: assim o
// arquivo funciona em qualquer ordem de carregamento — inclusive nos testes.
require_once __DIR__ . '/../Controllers/AdminController.php';

class AdminEventosController extends AdminController {

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
        require SRC_DIR . '/Eventos/Views/admin/eventos.php';
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
        require SRC_DIR . '/Eventos/Views/admin/evento_novo.php';
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
            trim($_POST['modalidade'] ?? '') ?: 'presencial',
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
        require SRC_DIR . '/Eventos/Views/admin/evento.php';
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

        // Logotipo de quem organiza (opcional), mesma logica da faixa.
        $imagem_org = null;
        if (!empty($_FILES['imagem_organizador']) && $_FILES['imagem_organizador']['error'] === UPLOAD_ERR_OK) {
            $imagem_org = salvar_imagem_evento($_FILES['imagem_organizador'], $slug, 'organizacao');
            if ($imagem_org === null) {
                flash('error', 'Não foi possível salvar o logotipo do organizador. Use JPG, PNG ou WebP de até 10MB.');
                redirect("/admin/eventos/$id");
                return;
            }
        }

        // Programacao em PDF (opcional). Mesma logica dos tres acima.
        $programa = null;
        $erro_prog = $_FILES['programa']['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($erro_prog === UPLOAD_ERR_OK) {
            $programa = salvar_programa_evento($_FILES['programa'], $slug);
            if ($programa === null) {
                flash('error', 'Não foi possível salvar a programação. Use um PDF de até 10MB.');
                redirect("/admin/eventos/$id");
                return;
            }
        } elseif ($erro_prog !== UPLOAD_ERR_NO_FILE) {
            flash('error', 'O arquivo da programação não chegou inteiro. Tente de novo.');
            redirect("/admin/eventos/$id");
            return;
        }

        db_execute("
            UPDATE eventos SET nome = ?, slug = ?, descricao = ?, conteudo = ?,
                local = ?, modalidade = ?, organizador = ?,
                data_inicio = ?, data_fim = ?, prazo_inscricao = ?, data_valor_cheio = ?,
                email_contato = ?, assinantes = ?, apoiadores = ?,
                emails_organizacao = ?, organizacao_expira_em = ?,
                imagem_path = COALESCE(?, imagem_path),
                imagem_apoiadores = COALESCE(?, imagem_apoiadores),
                imagem_organizador = COALESCE(?, imagem_organizador),
                programa_path = COALESCE(?, programa_path)
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
            $imagem_org,
            $programa,
            (int)$id,
        ]);

        flash('success', 'Evento salvo.');
        redirect("/admin/eventos/$id");
    }

    /**
     * Muda status do evento (rascunho / publicado / pausado / encerrado)
     *
     * `pausado` existe porque a pagina do evento NAO PODE SAIR DO AR: ela e a
     * unica pagina oficial dele, e a URL vai impressa no cartaz. Quando o
     * tesoureiro precisa de tempo para corrigir um texto ou um defeito, o que
     * ele quer e parar de RECEBER inscricao — nao apagar o evento da internet.
     *
     * `rascunho` some da web (404) e serve so a quem esta montando. `encerrado`
     * diz que o evento acabou. Nenhum dos dois descreve "volto em dez minutos".
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
        if (!in_array($novo, ['rascunho', 'publicado', 'pausado', 'encerrado'])) {
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
        require SRC_DIR . '/Eventos/Views/admin/evento_inscritos.php';
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
        require SRC_DIR . '/Eventos/Views/admin/evento_convites.php';
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
     * Reenvia a confirmacao de uma INSCRICAO em evento.
     *
     * POR QUE: existia para filiacao e nao para inscricao. Como o status vira
     * 'pago' ANTES de o email sair, uma falha do Brevo naquele instante era
     * definitiva — a reentrega do webhook cai em "ja processado" e nao havia
     * caminho nenhum para remandar o comprovante. A pessoa pagava e nunca
     * recebia o PDF.
     */
    /**
     * Lanca a mao o pagamento de uma inscricao, e dispara a confirmacao.
     *
     * Existe para o caso que o PagBank nao atende: quem se inscreveu **sem
     * CPF** (estrangeiro com passaporte) nao tem cobranca online, combina o
     * pagamento com a tesouraria e alguem precisa registrar isso. Serve tambem
     * a transferencia, dinheiro na mesa de credenciamento e qualquer pagamento
     * fora do sistema.
     *
     * Ate 30/08/2026 nao havia ESSA acao para inscricao — so para filiacao
     * (`AdminPessoasController::marcarPago`). Sem ela, uma inscricao sem CPF
     * ficaria pendente para sempre, e a pessoa que preencheu tudo sumiria.
     *
     * Passa pelo mesmo `processarInscricaoConfirmada()` do webhook: o
     * comprovante em PDF e o email saem iguais aos de quem pagou online. Um
     * caminho paralelo aqui produziria documento diferente para a mesma coisa.
     */
    public static function marcarInscricaoPaga(string $inscricao_id): void {
        self::exigirLogin();

        $inscricao = db_fetch_one(
            "SELECT id, pessoa_id, evento_id, status, valor FROM inscricoes WHERE id = ?",
            [(int)$inscricao_id]
        );
        if (!$inscricao) {
            flash('error', 'Inscrição não encontrada.');
            redirect('/admin/eventos');
            return;
        }
        if (in_array($inscricao['status'], ['pago', 'gratuita_confirmada'], true)) {
            flash('error', 'Esta inscrição já está confirmada.');
            redirect("/admin/eventos/{$inscricao['evento_id']}/inscritos");
            return;
        }

        $metodo = trim($_POST['metodo'] ?? '') ?: 'Manual';

        db_execute(
            "UPDATE inscricoes SET status = 'pago', metodo = ?,
                    data_pagamento = COALESCE(data_pagamento, datetime('now','localtime'))
             WHERE id = ?",
            [$metodo, (int)$inscricao['id']]
        );

        // Cancela os lembretes de cobranca: a pessoa pagou, e continuar
        // avisando dela seria constrangedor.
        db_execute("DELETE FROM lembretes_agendados WHERE inscricao_id = ? AND enviado = 0", [(int)$inscricao['id']]);

        registrar_log('inscricao_paga_manual', (int)$inscricao['pessoa_id'],
            "Inscricao {$inscricao['id']} lancada como paga pelo admin (metodo: $metodo, "
            . formatar_valor((int)$inscricao['valor']) . ")");

        require_once SRC_DIR . '/Controllers/WebhookController.php';
        try {
            WebhookController::processarInscricaoConfirmada((int)$inscricao['id']);
            flash('success', 'Inscrição lançada como paga e confirmação enviada.');
        } catch (Throwable $e) {
            // O pagamento JA foi registrado; o que falhou foi o aviso. Dizer
            // "erro" sem mais nada faria a pessoa lancar de novo.
            registrar_log('erro_confirmacao_inscricao', (int)$inscricao['pessoa_id'],
                "Inscricao {$inscricao['id']} paga, mas a confirmacao falhou: " . $e->getMessage());
            flash('error', 'Pagamento registrado, mas o email de confirmação falhou. '
                . 'Use "Reenviar confirmação" na lista.');
        }
        redirect("/admin/eventos/{$inscricao['evento_id']}/inscritos");
    }

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

}