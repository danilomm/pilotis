<?php
/**
 * Pilotis - Controller de Filiação
 */

class FiliacaoController {

    /**
     * Tela de entrada (pede email)
     */
    public static function entrada(string $ano): void {
        $titulo = "Filiação $ano";
        $mensagem = null;

        // Verifica se a campanha está aberta
        $campanha = db_fetch_one("SELECT status, data_fim, data_fim_internacional FROM campanhas WHERE ano = ?", [(int)$ano]);
        if (!$campanha || $campanha['status'] !== 'aberta') {
            ob_start();
            require SRC_DIR . '/Filiacao/Views/campanha_encerrada.php';
            $content = ob_get_clean();
            require SRC_DIR . '/Views/layout.php';
            return;
        }

        // Verifica se todas as categorias expiraram
        $todas_expiradas = true;
        foreach (CATEGORIAS_FILIACAO as $cat_key => $info) {
            if (!categoria_expirada($campanha, $cat_key)) {
                $todas_expiradas = false;
                break;
            }
        }
        if ($todas_expiradas) {
            ob_start();
            require SRC_DIR . '/Filiacao/Views/campanha_encerrada.php';
            $content = ob_get_clean();
            require SRC_DIR . '/Views/layout.php';
            return;
        }

        ob_start();
        require SRC_DIR . '/Filiacao/Views/entrada.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Processa email e envia link de acesso por email
     * (Segurança: evita que alguém veja dados de terceiros informando o email)
     */
    public static function processarEntrada(string $ano): void {
        require_once SRC_DIR . '/Services/BrevoService.php';

        // Campanha aberta: o GET ja checa, e todos os outros metodos do fluxo
        // tambem, menos este. Sem isto, um POST com a campanha fechada (link
        // antigo de email, formulario em cache) cria pessoa nova, gera token e
        // dispara email — e a pessoa cai na pagina de campanha encerrada.
        $campanha = db_fetch_one("SELECT status FROM campanhas WHERE ano = ?", [(int)$ano]);
        if (!$campanha || $campanha['status'] !== 'aberta') {
            redirect("/filiacao/$ano");
            return;
        }

        $email = strtolower(trim($_POST['email'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Por favor, informe um email válido.');
            redirect("/filiacao/$ano");
            return;
        }

        // Limite por IP: sem isto da para encher a caixa de terceiro e esgotar
        // a cota do Brevo. Resposta identica a do caminho normal.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        if (excedeu_limite_pedidos('filiacao_link_pedido', $ip, 10)) {
            registrar_log('filiacao_link_limite', null, "Limite de pedidos de link atingido [$ip]");
            flash('success', 'Se este email estiver no cadastro, o link de acesso foi enviado para ele.');
            redirect("/filiacao/$ano");
            return;
        }

        // Conta a TENTATIVA, e nao o envio: contar 'link_acesso_enviado' deixava
        // de fora todo caminho que nao chega a mandar email (falha do Brevo,
        // excecao), e nesses casos a trava nunca disparava. Aqui cada tentativa
        // ainda CRIA cadastro quando o email e novo, entao sem a contagem por
        // tentativa da para encher a base de linhas falsas.
        registrar_log('filiacao_link_pedido', null, "Pedido de link de filiacao $ano [$ip]");

        // Busca pessoa pelo email
        $pessoa = buscar_pessoa_por_email($email);

        if ($pessoa) {
            // Já existe, usa token existente ou gera novo
            $token = $pessoa['token'];
            if (!$token) {
                $token = gerar_token();
                db_execute(
                    "UPDATE pessoas SET token = ? WHERE id = ?",
                    [$token, $pessoa['id']]
                );
            }
            registrar_log('entrada_email', $pessoa['id'], "Entrada pelo email para $ano");
        } else {
            // Nova pessoa
            $pessoa_id = criar_pessoa($email);
            $pessoa = buscar_pessoa_por_email($email);
            $token = $pessoa['token'];
            registrar_log('novo_cadastro', $pessoa_id, "Novo cadastro via entrada $ano");
        }

        // Envia email com link de acesso
        $nome = $pessoa['nome'] ?? '';
        $erro_envio = null;

        try {
            $enviado = BrevoService::enviarLinkAcesso($email, $nome, (int)$ano, $token);

            if ($enviado) {
                registrar_log('link_acesso_enviado', $pessoa['id'], "Link de acesso enviado para $ano [$ip]");
            } else {
                registrar_log('erro_envio_link', $pessoa['id'], "Falha ao enviar link de acesso para $ano");
                $erro_envio = "Não foi possível enviar o email. Tente novamente.";
            }
        } catch (Exception $e) {
            registrar_log('erro_envio_link', $pessoa['id'], "Exceção ao enviar link: " . $e->getMessage());
            $erro_envio = "Erro ao enviar email: " . $e->getMessage();
        }

        // Mostra tela de confirmação de envio
        $titulo = "Verifique seu Email";

        ob_start();
        require SRC_DIR . '/Filiacao/Views/email_enviado.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Formulário de filiação pré-preenchido
     */
    public static function formulario(string $ano, string $token): void {
        // Verifica se a campanha está aberta
        $campanha = db_fetch_one("SELECT status, data_fim, data_fim_internacional FROM campanhas WHERE ano = ?", [(int)$ano]);
        if (!$campanha || $campanha['status'] !== 'aberta') {
            $titulo = "Filiação $ano";
            ob_start();
            require SRC_DIR . '/Filiacao/Views/campanha_encerrada.php';
            $content = ob_get_clean();
            require SRC_DIR . '/Views/layout.php';
            return;
        }

        $cadastrado = buscar_pessoa_por_token($token);

        if (!$cadastrado) {
            flash('error', 'Token inválido ou expirado.');
            redirect("/filiacao/$ano");
            return;
        }

        // Monta lista de categorias com valores da campanha, filtrando expiradas
        $valores_ano = valores_campanha((int)$ano);
        $categorias = [];
        $tem_selecionada = false;
        $map_valores = [
            'profissional_internacional' => $valores_ano['valor_internacional'],
            'profissional_nacional' => $valores_ano['valor_profissional'],
            'estudante' => $valores_ano['valor_estudante'],
        ];
        foreach (CATEGORIAS_FILIACAO as $valor => $info) {
            // Filtra categorias cujo prazo já expirou
            if (categoria_expirada($campanha, $valor)) {
                continue;
            }
            $selecionada = ($cadastrado['categoria'] ?? '') === $valor;
            if ($selecionada) $tem_selecionada = true;
            $categorias[] = [
                'valor' => $valor,
                'label' => $info['nome'] . ' - ' . formatar_valor($map_valores[$valor] ?? $info['valor']),
                'selecionada' => $selecionada,
            ];
        }
        // Se nenhuma selecionada, seleciona a primeira disponível
        if (!$tem_selecionada && !empty($categorias)) {
            $categorias[0]['selecionada'] = true;
        }

        // Verifica se já existe filiação para este ano
        $pagamento_existente = buscar_filiacao($cadastrado['id'], (int)$ano);

        // Atualiza status para 'acesso' se ainda estava como 'enviado'
        if ($pagamento_existente && $pagamento_existente['status'] === 'enviado') {
            db_execute(
                "UPDATE filiacoes SET status = 'acesso', status_at = ? WHERE pessoa_id = ? AND ano = ?",
                [date('Y-m-d H:i:s'), $cadastrado['id'], (int)$ano]
            );

            // Agenda lembretes de formulario incompleto
            require_once SRC_DIR . '/Services/LembreteService.php';
            LembreteService::agendarFormularioIncompleto($pagamento_existente['id']);
        }

        // Garante categoria default (primeira disponivel) na filiacao para evitar
        // valor zero ao acessar /pagamento. Se a pessoa quiser outra categoria,
        // muda no form e o salvar() atualiza.
        if ($pagamento_existente && empty($pagamento_existente['categoria']) && $pagamento_existente['status'] !== 'pago') {
            $cat_default = null;
            foreach (CATEGORIAS_FILIACAO as $valor => $info) {
                if (!categoria_expirada($campanha, $valor)) {
                    $cat_default = $valor;
                    break;
                }
            }
            if ($cat_default) {
                $valor_default = valor_por_categoria($cat_default, (int)$ano);
                db_execute(
                    "UPDATE filiacoes SET categoria = ?, valor = ? WHERE id = ? AND (categoria IS NULL OR categoria = '')",
                    [$cat_default, $valor_default, $pagamento_existente['id']]
                );
                $pagamento_existente['categoria'] = $cat_default;
                $pagamento_existente['valor'] = $valor_default;
                $cadastrado['categoria'] = $cat_default;
            }
        }

        // Dados para autocomplete
        $autocomplete = obter_autocomplete();

        // Verifica se já existe comprovante para este ano
        $comprovante_existente = tem_comprovante($cadastrado['id'], (int)$ano);

        registrar_log('acesso_formulario', $cadastrado['id'], "Acesso ao formulário $ano");

        // Versao do aviso que esta pessoa ja aceitou NESTE ano, se aceitou. A
        // caixa vem marcada quando bate com a versao em vigor; versao nova
        // reabre o aceite, que e o ponto de versionar.
        $ja = db_fetch_one(
            "SELECT consentimento_versao FROM filiacoes WHERE pessoa_id = ? AND ano = ?",
            [$cadastrado['id'], (int)$ano]
        );
        $pre_consentimento = (string)($ja['consentimento_versao'] ?? '');

        $titulo = "Filiação $ano";

        ob_start();
        require SRC_DIR . '/Filiacao/Views/formulario.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Salva dados e cria filiação
     */
    public static function salvar(string $ano, string $token): void {
        // Verifica se a campanha está aberta
        $campanha = db_fetch_one("SELECT status, data_fim, data_fim_internacional FROM campanhas WHERE ano = ?", [(int)$ano]);
        if (!$campanha || $campanha['status'] !== 'aberta') {
            flash('error', 'Campanha não encontrada.');
            redirect("/filiacao/$ano");
            return;
        }

        $cadastrado = buscar_pessoa_por_token($token);

        if (!$cadastrado) {
            flash('error', 'Token inválido.');
            redirect("/filiacao/$ano");
            return;
        }

        // Bloqueia alteracao apos pagamento confirmado
        $filiacao_existente = buscar_filiacao($cadastrado['id'], (int)$ano);
        if ($filiacao_existente && $filiacao_existente['status'] === 'pago') {
            flash('info', 'Sua filiação para ' . $ano . ' já está confirmada. Para alterações em seus dados, entre em contato com a tesouraria.');
            redirect("/filiacao/$ano/$token");
            return;
        }

        // Obtém dados do formulário
        $nome = trim($_POST['nome'] ?? '');
        $cpf = trim($_POST['cpf'] ?? '');
        $telefone = trim($_POST['telefone'] ?? '');
        $endereco = trim($_POST['endereco'] ?? '');
        $cep = trim($_POST['cep'] ?? '');
        $cidade = trim($_POST['cidade'] ?? '');
        $estado = strtoupper(trim($_POST['estado'] ?? ''));
        $pais = trim($_POST['pais'] ?? 'Brasil');
        $profissao = trim($_POST['profissao'] ?? '');
        $formacao = trim($_POST['formacao'] ?? '');
        $instituicao = trim($_POST['instituicao'] ?? '');
        $categoria = trim($_POST['categoria'] ?? '');

        // Validações dos campos obrigatórios
        $obrigatorios = [
            // A frase INTEIRA, e nao so o rotulo: "Cidade" e "Profissão" sao
            // femininos, e o texto montado com "$label é obrigatório" saia
            // "Cidade é obrigatório". Deduzir genero por heuristica erraria em
            // nome proprio; escrever a frase resolve e se le de uma vez.
            'nome' => 'Nome é obrigatório',
            'cpf' => 'CPF é obrigatório',
            'telefone' => 'Telefone é obrigatório',
            'endereco' => 'Endereço é obrigatório',
            'cep' => 'CEP é obrigatório',
            'cidade' => 'Cidade é obrigatória',
            'estado' => 'Estado é obrigatório',
            'pais' => 'País é obrigatório',
            'profissao' => 'Profissão é obrigatória',
        ];

        // Consentimento conferido NO SERVIDOR. O `required` do HTML e conforto de
        // quem preenche, nao garantia: qualquer POST direto o ignora, e e
        // justamente o registro de consentimento que nao pode depender de o
        // navegador ter colaborado. Aceita tambem quem ja consentiu nesta MESMA
        // versao — a caixa vem marcada, e reenviar o formulario nao pode exigir
        // reler o que nao mudou.
        $consentido_antes = db_fetch_one(
            "SELECT consentimento_versao FROM filiacoes WHERE pessoa_id = ? AND ano = ?",
            [$cadastrado['id'], (int)$ano]
        );
        if (empty($_POST['consentimento'])
            && (string)($consentido_antes['consentimento_versao'] ?? '') !== POLITICA_PRIVACIDADE_VERSAO) {
            flash('error', 'É preciso concordar com o aviso de privacidade para continuar.');
            redirect("/filiacao/$ano/$token");
            return;
        }

        // CPF: confere o DIGITO VERIFICADOR aqui, e nao so a presenca.
        //
        // Ate 30/08/2026 o formulario aceitava qualquer coisa e a recusa vinha
        // do PagBank, em ingles, la na tela de pagamento. Producao registra 30
        // ocorrencias de `must be a valid CPF or CNPJ` para SEIS pessoas — uma
        // delas tentou 10 vezes em um minuto, outra 9, outra 7. Em todos os
        // casos o cadastro foi corrigido um ou dois minutos depois do ultimo
        // erro: era digitacao, e a pessoa descobriu sozinha, no lugar errado.
        // Duas das seis nunca chegaram a pagar.
        //
        // O `cpf_valido()` ja existia desde sempre — aplicado no PdfService,
        // para decidir se imprimia o numero. Guardava o papel, nao a pessoa.
        if ($cpf !== '' && !cpf_valido($cpf)) {
            flash('error', 'CPF inválido: confira os números digitados.');
            redirect("/filiacao/$ano/$token");
            return;
        }

        foreach ($obrigatorios as $campo => $frase) {
            if (empty($$campo)) {
                flash('error', "$frase.");
                redirect("/filiacao/$ano/$token");
                return;
            }
        }

        if (!isset(CATEGORIAS_FILIACAO[$categoria])) {
            flash('error', 'Categoria inválida.');
            redirect("/filiacao/$ano/$token");
            return;
        }

        // Verifica se a categoria não expirou
        if (categoria_expirada($campanha, $categoria)) {
            $data_limite = data_fim_por_categoria($campanha, $categoria);
            $data_fmt = $data_limite ? date('d/m/Y', strtotime($data_limite)) : '';
            $cat_nome = CATEGORIAS_DISPLAY[$categoria] ?? $categoria;
            flash('error', "O prazo para a categoria $cat_nome encerrou em $data_fmt. Por favor, escolha outra categoria.");
            redirect("/filiacao/$ano/$token");
            return;
        }

        // Se categoria é estudante, comprovante é obrigatório (exceto se já enviou antes)
        $comprovante_path = null;
        if ($categoria === 'estudante') {
            $comprovante_existente = tem_comprovante($cadastrado['id'], (int)$ano);

            if (!$comprovante_existente && (empty($_FILES['comprovante']) || $_FILES['comprovante']['error'] === UPLOAD_ERR_NO_FILE)) {
                flash('error', 'Para a categoria Estudante, é obrigatório enviar o comprovante de matrícula.');
                redirect("/filiacao/$ano/$token");
                return;
            }

            // Processa upload se enviou arquivo
            $erro_upload = $_FILES['comprovante']['error'] ?? UPLOAD_ERR_NO_FILE;

            if ($erro_upload === UPLOAD_ERR_OK) {
                $comprovante_path = salvar_comprovante($_FILES['comprovante'], $cadastrado['id'], (int)$ano);

                if ($comprovante_path === null) {
                    flash('error', 'Erro ao processar comprovante. Verifique se o arquivo é PDF, JPG ou PNG e tem no máximo 5MB.');
                    redirect("/filiacao/$ano/$token");
                    return;
                }
            } elseif ($erro_upload !== UPLOAD_ERR_NO_FILE) {
                // Mesmo vao do fluxo de evento: todo erro que nao fosse "nao
                // mandou arquivo" passava em silencio. Ver EventosController.
                $motivos = [
                    UPLOAD_ERR_INI_SIZE   => 'O arquivo é maior do que o servidor aceita. Envie até 2 MB.',
                    UPLOAD_ERR_FORM_SIZE  => 'O arquivo é maior do que o formulário aceita.',
                    UPLOAD_ERR_PARTIAL    => 'O envio foi interrompido. Tente de novo.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Falha no servidor ao receber o arquivo. Avise a tesouraria.',
                    UPLOAD_ERR_CANT_WRITE => 'Falha no servidor ao gravar o arquivo. Avise a tesouraria.',
                    UPLOAD_ERR_EXTENSION  => 'O envio foi bloqueado pelo servidor. Avise a tesouraria.',
                ];
                registrar_log('erro_upload_comprovante', $cadastrado['id'],
                    "Upload de comprovante falhou na filiacao $ano: codigo $erro_upload");
                flash('error', ($motivos[$erro_upload] ?? 'Não foi possível receber o arquivo.')
                    . ' Se o problema continuar, escreva para ' . ORG_EMAIL_CONTATO . '.');
                redirect("/filiacao/$ano/$token");
                return;
            }
        }

        $valor = valor_por_categoria($categoria, (int)$ano);

        // Atualiza pessoa e filiação
        $dados_filiacao = [
            'nome' => $nome,
            'cpf' => $cpf,
            'telefone' => $telefone,
            'endereco' => $endereco,
            'cep' => $cep,
            'cidade' => $cidade,
            'estado' => $estado,
            'pais' => $pais,
            'profissao' => $profissao,
            'formacao' => $formacao,
            'instituicao' => $instituicao,
            'categoria' => $categoria,
            'valor' => $valor,
        ];

        if ($comprovante_path) {
            $dados_filiacao['comprovante_path'] = $comprovante_path;
        }

        atualizar_pessoa_filiacao($cadastrado['id'], (int)$ano, $dados_filiacao);

        // Atualiza status para 'pendente' se ainda não pagou
        // Consentimento: versao e data. A data so na PRIMEIRA vez (COALESCE) —
        // o que importa e quando a pessoa aceitou aquele texto, e reenviar o
        // formulario nao e um novo consentimento.
        db_execute(
            "UPDATE filiacoes SET consentimento_versao = ?,
                    consentimento_em = COALESCE(consentimento_em, datetime('now','localtime'))
             WHERE pessoa_id = ? AND ano = ?",
            [POLITICA_PRIVACIDADE_VERSAO, $cadastrado['id'], (int)$ano]
        );

        db_execute(
            "UPDATE filiacoes SET status = 'pendente', status_at = ? WHERE pessoa_id = ? AND ano = ? AND status IN ('enviado', 'acesso')",
            [date('Y-m-d H:i:s'), $cadastrado['id'], (int)$ano]
        );

        registrar_log('dados_atualizados', $cadastrado['id'], "Dados atualizados para filiação $ano");

        // Verifica filiação
        $filiacao = buscar_filiacao($cadastrado['id'], (int)$ano);

        if ($filiacao && $filiacao['data_pagamento']) {
            // Já pagou, mostra confirmação
            $titulo = "Filiação Confirmada";
            $mensagem = "Sua filiação já está confirmada!";

            ob_start();
            require SRC_DIR . '/Filiacao/Views/confirmacao.php';
            $content = ob_get_clean();
            require SRC_DIR . '/Views/layout.php';
            return;
        }

        registrar_log('filiacao_criada', $cadastrado['id'], "Filiação criada para $ano: " . formatar_valor($valor));

        // Antes de ir pro pagamento, procura possivel cadastro antigo
        $email_form = strtolower(trim($_POST['email'] ?? '')) ?: null;
        $match = buscar_match_consolidacao($cadastrado['id'], $email_form, $cpf, $nome, (int)$ano);
        if ($match) {
            $motivo = urlencode($match['motivo']);
            $mid = (int)$match['pessoa_id'];
            registrar_log('match_oferecido', $cadastrado['id'], "Match candidato pessoa_id=$mid motivo={$match['motivo']}");
            $sig = assinar_match((int)$cadastrado['id'], $mid);
            redirect("/filiacao/$ano/$token/vincular-cadastro?match=$mid&motivo=$motivo&sig=$sig");
            return;
        }

        redirect("/filiacao/$ano/$token/pagamento");
    }

    /**
     * Tela: oferece vinculacao a cadastro antigo encontrado.
     */
    public static function vincularCadastro(string $ano, string $token): void {
        // Verifica se a campanha está aberta
        $campanha = db_fetch_one("SELECT status FROM campanhas WHERE ano = ?", [(int)$ano]);
        if (!$campanha || $campanha['status'] !== 'aberta') {
            redirect("/filiacao/$ano");
            return;
        }
        $cadastrado = buscar_pessoa_por_token($token);
        if (!$cadastrado) {
            flash('error', 'Token inválido.');
            redirect("/filiacao/$ano");
            return;
        }
        $match_id = (int)($_GET['match'] ?? 0);
        $motivo = preg_replace('/[^a-z]/', '', strtolower($_GET['motivo'] ?? ''));
        $sig = (string)($_GET['sig'] ?? '');
        if (!$match_id || !in_array($motivo, ['email','cpf','nome'])) {
            redirect("/filiacao/$ano/$token/pagamento");
            return;
        }
        // So o match que o servidor ofereceu a esta pessoa. Ver assinar_match().
        if (!match_assinado((int)$cadastrado['id'], $match_id, $sig)) {
            registrar_log('match_assinatura_invalida', $cadastrado['id'], "GET vincular-cadastro com match=$match_id nao assinado");
            redirect("/filiacao/$ano/$token/pagamento");
            return;
        }
        $antigo = db_fetch_one("
            SELECT p.id, p.nome, (SELECT email FROM emails WHERE pessoa_id=p.id AND principal=1 LIMIT 1) AS email
            FROM pessoas p WHERE p.id = ? AND p.ativo = 1
        ", [$match_id]);
        if (!$antigo) {
            redirect("/filiacao/$ano/$token/pagamento");
            return;
        }
        $ultima_paga = db_fetch_one("SELECT MAX(ano) as ano FROM filiacoes WHERE pessoa_id=? AND status='pago'", [$match_id]);

        $email_mascarado = $antigo['email'] ? mascarar_email($antigo['email']) : '';
        $primeiro_nome = explode(' ', trim($antigo['nome']))[0] ?? '';
        $titulo = "Vincular cadastro";
        ob_start();
        require SRC_DIR . '/Filiacao/Views/vincular_cadastro.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Processa decisao de vincular ou criar separado.
     */
    public static function processarVinculacao(string $ano, string $token): void {
        // Verifica se a campanha está aberta
        $campanha = db_fetch_one("SELECT status FROM campanhas WHERE ano = ?", [(int)$ano]);
        if (!$campanha || $campanha['status'] !== 'aberta') {
            redirect("/filiacao/$ano");
            return;
        }
        $cadastrado = buscar_pessoa_por_token($token);
        if (!$cadastrado) {
            flash('error', 'Token inválido.');
            redirect("/filiacao/$ano");
            return;
        }

        $decisao = $_POST['decisao'] ?? '';
        $match_id = (int)($_POST['match_id'] ?? 0);
        $motivo = $_POST['motivo'] ?? '';

        // Mesma conferencia do GET: e aqui que consolidar_pessoas() roda.
        if ($match_id && !match_assinado((int)$cadastrado['id'], $match_id, (string)($_POST['sig'] ?? ''))) {
            registrar_log('match_assinatura_invalida', $cadastrado['id'], "POST vincular-cadastro com match=$match_id nao assinado");
            redirect("/filiacao/$ano/$token/pagamento");
            return;
        }

        if ($decisao === 'nao' || !$match_id) {
            registrar_log('consolidacao_recusada', $cadastrado['id'], "Match $match_id (motivo=$motivo) recusado, cadastro segue separado");
            redirect("/filiacao/$ano/$token/pagamento");
            return;
        }

        if ($decisao === 'sim') {
            // NAO consolida aqui. O "sim" apenas PEDE a fusao; ela acontece
            // quando o link enviado ao email do cadastro antigo for aberto.
            // Ver assinar_consolidacao() no db.php para o motivo.
            $antigo = db_fetch_one("SELECT id, nome FROM pessoas WHERE id = ?", [$match_id]);
            $email_antigo = db_fetch_one(
                "SELECT email FROM emails WHERE pessoa_id = ? AND principal = 1 LIMIT 1", [$match_id]
            );
            if (!$antigo || empty($email_antigo['email'])) {
                registrar_log('erro_consolidacao', $cadastrado['id'], "Match $match_id sem email para confirmar");
                flash('error', 'Não foi possível confirmar esse cadastro. Seguindo com cadastro separado.');
                redirect("/filiacao/$ano/$token/pagamento");
                return;
            }

            // Mesma trava do fluxo de evento: o destino do email e escolhido por
            // quem clica (o match casa por nome exato, e nomes de dirigente sao
            // publicos). Aqui a campanha fechada ja limita, mas a trava vale por
            // si — cota do Brevo e restricao dura.
            $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
            $destino = (string)$email_antigo['email'];
            if (excedeu_limite_pedidos('consolidacao_pedido', $destino, 2)
                || excedeu_limite_pedidos('consolidacao_pedido', $ip, 5)) {
                registrar_log('consolidacao_limite', $cadastrado['id'],
                    "Limite de pedidos de fusao atingido [$ip]");
                flash('success', 'Enviamos um email para ' . mascarar_email($destino)
                    . ' com um link para confirmar a unificação.');
                redirect("/filiacao/$ano/$token/pagamento");
                return;
            }
            registrar_log('consolidacao_pedido', $cadastrado['id'],
                "Pedido de fusao para [$destino] [$ip]");

            require_once SRC_DIR . '/Services/BrevoService.php';

            $expira = time() + 86400;
            $sig = assinar_consolidacao((int)$cadastrado['id'], $match_id, $expira);
            $link = BASE_URL . "/filiacao/$ano/$token/confirmar-vinculo"
                  . "?match=$match_id&exp=$expira&sig=$sig";

            try {
                BrevoService::enviarConfirmacaoVinculo(
                    $email_antigo['email'], (string)$antigo['nome'], $link,
                    'filiação ' . $ano
                );
                registrar_log('consolidacao_confirmacao_enviada', $cadastrado['id'],
                    "Pedido de fusao em $match_id (motivo=$motivo): confirmacao enviada ao email do cadastro antigo");
                flash('success', 'Enviamos um email para ' . mascarar_email($email_antigo['email'])
                    . ' com um link para confirmar a unificação. Enquanto isso, seu cadastro segue separado e você pode pagar normalmente.');
            } catch (Throwable $e) {
                registrar_log('erro_consolidacao', $cadastrado['id'], "Falha ao enviar confirmacao de fusao em $match_id: " . $e->getMessage());
                flash('error', 'Não foi possível enviar o email de confirmação. Seguindo com cadastro separado.');
            }
            redirect("/filiacao/$ano/$token/pagamento");
            return;
        }

        redirect("/filiacao/$ano/$token/pagamento");
    }

    /**
     * Abre o link mandado ao email do cadastro ANTIGO e consolida.
     *
     * Este e o unico caminho que funde cadastros no fluxo publico. Chegar aqui
     * exige ler a caixa postal do cadastro antigo — que e a prova que faltava.
     */
    public static function confirmarVinculo(string $ano, string $token): void {
        $cadastrado = buscar_pessoa_por_token($token);
        if (!$cadastrado) {
            flash('error', 'Token inválido.');
            redirect("/filiacao/$ano");
            return;
        }

        $match_id = (int)($_GET['match'] ?? 0);
        $expira   = (int)($_GET['exp'] ?? 0);
        $sig      = (string)($_GET['sig'] ?? '');

        if (!consolidacao_assinada((int)$cadastrado['id'], $match_id, $expira, $sig)) {
            registrar_log('consolidacao_link_invalido', $cadastrado['id'],
                "Link de confirmacao invalido ou vencido para match=$match_id");
            flash('error', 'Este link de confirmação é inválido ou já venceu. Refaça o pedido no formulário.');
            redirect("/filiacao/$ano/$token/pagamento");
            return;
        }

        try {
            consolidar_pessoas($match_id, (int)$cadastrado['id'], (int)$ano);
            registrar_log('consolidacao_aceita', $match_id,
                "Pessoa {$cadastrado['id']} consolidada em $match_id (confirmada pelo email do cadastro antigo)");
            flash('success', 'Cadastros unificados. Seu histórico de filiações foi preservado.');
        } catch (Throwable $e) {
            registrar_log('erro_consolidacao', $cadastrado['id'], "Falha ao consolidar em $match_id: " . $e->getMessage());
            flash('error', 'Não foi possível unificar os cadastros. Seguindo com cadastro separado.');
        }
        // O token foi propagado para a pessoa antiga, entao a URL segue valida.
        redirect("/filiacao/$ano/$token/pagamento");
    }

    /**
     * Tela de pagamento com QR Code PIX
     */
    public static function pagamento(string $ano, string $token): void {
        // Verifica se a campanha está aberta
        $campanha = db_fetch_one("SELECT status, data_fim, data_fim_internacional FROM campanhas WHERE ano = ?", [(int)$ano]);
        if (!$campanha || $campanha['status'] !== 'aberta') {
            $titulo = "Filiação $ano";
            ob_start();
            require SRC_DIR . '/Filiacao/Views/campanha_encerrada.php';
            $content = ob_get_clean();
            require SRC_DIR . '/Views/layout.php';
            return;
        }

        require_once SRC_DIR . '/Services/PagBankService.php';

        $cadastrado = buscar_pessoa_por_token($token);

        if (!$cadastrado) {
            flash('error', 'Token inválido.');
            redirect("/filiacao/$ano");
            return;
        }

        $filiacao = buscar_filiacao($cadastrado['id'], (int)$ano);

        if (!$filiacao) {
            redirect("/filiacao/$ano/$token");
            return;
        }

        if ($filiacao['data_pagamento'] || $filiacao['status'] === 'pago') {
            $titulo = "Filiação Confirmada";
            $mensagem = "Sua filiação já está confirmada!";

            ob_start();
            require SRC_DIR . '/Filiacao/Views/confirmacao.php';
            $content = ob_get_clean();
            require SRC_DIR . '/Views/layout.php';
            return;
        }

        // Se a categoria expirou, redireciona para escolher outra (mesmo se ja houver pagbank_order_id)
        $categoria_filiacao = $filiacao['categoria'] ?? '';
        if ($categoria_filiacao && categoria_expirada($campanha, $categoria_filiacao)) {
            // Zera order_id para que novo PIX seja gerado com o valor da nova categoria
            if (!empty($filiacao['pagbank_order_id'])) {
                db_execute(
                    "UPDATE filiacoes SET pagbank_order_id = NULL, data_vencimento = NULL, metodo = NULL WHERE id = ?",
                    [$filiacao['id']]
                );
            }
            $cat_nome = CATEGORIAS_DISPLAY[$categoria_filiacao] ?? $categoria_filiacao;
            $data_limite = data_fim_por_categoria($campanha, $categoria_filiacao);
            $data_fmt = $data_limite ? date('d/m/Y', strtotime($data_limite)) : '';
            flash('error', "O prazo para a categoria $cat_nome encerrou em $data_fmt. Por favor, escolha outra categoria.");
            redirect("/filiacao/$ano/$token");
            return;
        }

        $valor_centavos = (int)$filiacao['valor'];
        $pagamento = $filiacao; // Alias para compatibilidade com a view
        $pix_data = null;
        $boleto_data = null;
        $erro_pagbank = null;

        // Se ainda não tem order_id, cria cobrança PIX
        if (empty($filiacao['pagbank_order_id'])) {
            try {
                $pix_data = PagBankService::criarCobrancaPix(
                    PagBankService::referenciaFiliacao((int)$cadastrado['id'], (int)$ano),
                    "Filiacao " . ORG_NOME . " $ano",
                    $cadastrado['nome'],
                    $cadastrado['email'],
                    $cadastrado['cpf'] ?? null,
                    $valor_centavos,
                    3 // dias expiração
                );

                // Salva order_id e data de vencimento
                db_execute("
                    UPDATE filiacoes SET
                        pagbank_order_id = ?,
                        data_vencimento = ?,
                        metodo = 'pix'
                    WHERE id = ?
                ", [$pix_data['order_id'], $pix_data['expiration_date'], $filiacao['id']]);

                // Registra pedido na tabela de pedidos
                db_insert("
                    INSERT INTO pagbank_pedidos (filiacao_id, order_id, metodo)
                    VALUES (?, ?, 'pix')
                ", [$filiacao['id'], $pix_data['order_id']]);

                registrar_log('pix_gerado', $cadastrado['id'], "PIX gerado: " . $pix_data['order_id']);

                // Agenda lembrete de vencimento
                require_once SRC_DIR . '/Services/LembreteService.php';
                LembreteService::agendarVencimento($filiacao['id'], $pix_data['expiration_date']);

            } catch (Exception $e) {
                // A tela recebe a frase em portugues; o LOG recebe o detalhe
                // tecnico do PagBank, com o campo recusado. Sao duas leituras
                // diferentes, e usar a mesma variavel nas duas perderia a que
                // serve para diagnosticar — o log e a unica saida do servidor.
                $erro_pagbank = PagBankService::mensagemParaPessoa($e);
                registrar_log('erro_pagbank', $cadastrado['id'], "Erro ao criar PIX: " . $e->getMessage());
            }
        } else {
            // Já tem order_id, busca dados do PIX
            try {
                $order_data = PagBankService::consultarPedido($filiacao['pagbank_order_id']);
                $qr_codes = $order_data['qr_codes'] ?? [];
                if (!empty($qr_codes)) {
                    $qr = $qr_codes[0];
                    $pix_data = [
                        'order_id' => $filiacao['pagbank_order_id'],
                        'qr_code' => $qr['text'] ?? '',
                        'qr_code_link' => !empty($qr['links']) ? $qr['links'][0]['href'] : '',
                        'expiration_date' => $filiacao['data_vencimento'],
                    ];
                }
            } catch (Exception $e) {
                // Este ramo consulta pedido que JA existe. Ate 30/08/2026 nao
                // registrava nada: a pessoa via o erro na tela e o servidor
                // ficava sem lembranca nenhuma de que a consulta falhou.
                $erro_pagbank = PagBankService::mensagemParaPessoa($e);
                registrar_log('erro_pagbank', $cadastrado['id'],
                    "Erro ao consultar PIX ja gerado: " . $e->getMessage());
            }
        }

        // Dados de boleto se existir
        if (!empty($filiacao['pagbank_boleto_link'])) {
            $boleto_data = [
                'boleto_link' => $filiacao['pagbank_boleto_link'],
                'barcode' => $filiacao['pagbank_boleto_barcode'] ?? '',
                'due_date' => $filiacao['data_vencimento'] ?? '',
            ];
        }

        // Chave pública para criptografia de cartão
        $pagbank_public_key = PagBankService::obterChavePublica();

        $titulo = "Pagamento - Filiação $ano";
        $valor_formatado = formatar_valor($valor_centavos);

        ob_start();
        require SRC_DIR . '/Filiacao/Views/pagamento.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Gera cobrança PIX
     */
    public static function gerarPix(string $ano, string $token): void {
        require_once SRC_DIR . '/Services/PagBankService.php';

        $cadastrado = buscar_pessoa_por_token($token);
        if (!$cadastrado) {
            redirect("/filiacao/$ano");
            return;
        }

        $filiacao = buscar_filiacao($cadastrado['id'], (int)$ano);
        if (!$filiacao || $filiacao['data_pagamento']) {
            redirect("/filiacao/$ano/$token/pagamento");
            return;
        }

        $valor_centavos = (int)$filiacao['valor'];

        try {
            $pix_data = PagBankService::criarCobrancaPix(
                PagBankService::referenciaFiliacao((int)$cadastrado['id'], (int)$ano),
                "Filiacao " . ORG_NOME . " $ano",
                $cadastrado['nome'],
                $cadastrado['email'],
                $cadastrado['cpf'] ?? null,
                $valor_centavos,
                3
            );

            db_execute("
                UPDATE filiacoes SET
                    pagbank_order_id = ?,
                    data_vencimento = ?,
                    metodo = 'pix',
                    status = 'pendente',
                    status_at = ?
                WHERE id = ? AND status != 'pago'
            ", [$pix_data['order_id'], $pix_data['expiration_date'], date('Y-m-d H:i:s'), $filiacao['id']]);

            // Registra pedido na tabela de pedidos
            db_insert("
                INSERT INTO pagbank_pedidos (filiacao_id, order_id, metodo)
                VALUES (?, ?, 'pix')
            ", [$filiacao['id'], $pix_data['order_id']]);

            registrar_log('pix_gerado', $cadastrado['id'], "PIX gerado: " . $pix_data['order_id']);

            // Agenda lembrete de vencimento
            require_once SRC_DIR . '/Services/LembreteService.php';
            LembreteService::agendarVencimento($filiacao['id'], $pix_data['expiration_date']);

        } catch (Exception $e) {
            registrar_log('erro_pagbank', $cadastrado['id'], "Erro ao criar PIX: " . $e->getMessage());
            // Sem isto a pessoa volta para a mesma tela sem QR e sem explicacao,
            // e conclui que o site quebrou. O pagamento por cartao ja avisava.
            flash('error', 'Não foi possível gerar o PIX agora. Tente novamente em alguns minutos ou escolha outra forma de pagamento.');
        }

        redirect("/filiacao/$ano/$token/pagamento");
    }

    /**
     * Gera cobrança por boleto
     */
    public static function gerarBoleto(string $ano, string $token): void {
        require_once SRC_DIR . '/Services/PagBankService.php';

        $cadastrado = buscar_pessoa_por_token($token);
        if (!$cadastrado) {
            redirect("/filiacao/$ano");
            return;
        }

        $filiacao = buscar_filiacao($cadastrado['id'], (int)$ano);
        if (!$filiacao || $filiacao['data_pagamento']) {
            redirect("/filiacao/$ano/$token/pagamento");
            return;
        }

        $valor_centavos = (int)$filiacao['valor'];

        // Monta endereço
        $endereco = [
            'street' => $cadastrado['endereco'] ?: 'Não informado',
            'number' => 'S/N',
            'locality' => $cadastrado['cidade'] ?: 'Não informado',
            'city' => $cadastrado['cidade'] ?: 'Não informado',
            'region_code' => $cadastrado['estado'] ?: 'DF',
            'postal_code' => str_replace('-', '', $cadastrado['cep'] ?: '70000000'),
        ];

        try {
            $boleto_data = PagBankService::criarCobrancaBoleto(
                PagBankService::referenciaFiliacao((int)$cadastrado['id'], (int)$ano),
                "Filiacao " . ORG_NOME . " $ano",
                $cadastrado['nome'],
                $cadastrado['email'],
                $cadastrado['cpf'] ?? null,
                $valor_centavos,
                $endereco,
                3
            );

            db_execute("
                UPDATE filiacoes SET
                    pagbank_order_id = ?,
                    pagbank_charge_id = ?,
                    pagbank_boleto_link = ?,
                    pagbank_boleto_barcode = ?,
                    data_vencimento = ?,
                    metodo = 'boleto',
                    status = 'pendente',
                    status_at = ?
                WHERE id = ? AND status != 'pago'
            ", [
                $boleto_data['order_id'],
                $boleto_data['charge_id'],
                $boleto_data['boleto_link'],
                $boleto_data['barcode'],
                $boleto_data['due_date'],
                date('Y-m-d H:i:s'),
                $filiacao['id']
            ]);

            // Registra pedido na tabela de pedidos
            db_insert("
                INSERT INTO pagbank_pedidos (filiacao_id, order_id, metodo)
                VALUES (?, ?, 'boleto')
            ", [$filiacao['id'], $boleto_data['order_id']]);

            registrar_log('boleto_gerado', $cadastrado['id'], "Boleto gerado: " . $boleto_data['order_id']);

            // Agenda lembrete de vencimento
            require_once SRC_DIR . '/Services/LembreteService.php';
            LembreteService::agendarVencimento($filiacao['id'], $boleto_data['due_date']);

        } catch (Exception $e) {
            registrar_log('erro_pagbank', $cadastrado['id'], "Erro ao criar boleto: " . $e->getMessage());
            flash('error', 'Não foi possível gerar o boleto agora. Tente novamente em alguns minutos ou escolha outra forma de pagamento.');
        }

        redirect("/filiacao/$ano/$token/pagamento");
    }

    /**
     * Processa pagamento com cartão de crédito
     */
    public static function pagarCartao(string $ano, string $token): void {
        require_once SRC_DIR . '/Services/PagBankService.php';

        $cadastrado = buscar_pessoa_por_token($token);
        if (!$cadastrado) {
            redirect("/filiacao/$ano");
            return;
        }

        $filiacao = buscar_filiacao($cadastrado['id'], (int)$ano);
        if (!$filiacao || $filiacao['data_pagamento']) {
            redirect("/filiacao/$ano/$token/pagamento");
            return;
        }

        $card_encrypted = $_POST['card_encrypted'] ?? '';
        $holder_name = $_POST['holder_name'] ?? '';

        if (empty($card_encrypted) || empty($holder_name)) {
            flash('error', 'Dados do cartão incompletos.');
            redirect("/filiacao/$ano/$token/pagamento");
            return;
        }

        $valor_centavos = (int)$filiacao['valor'];

        try {
            $cartao_data = PagBankService::criarCobrancaCartao(
                PagBankService::referenciaFiliacao((int)$cadastrado['id'], (int)$ano),
                "Filiacao " . ORG_NOME . " $ano",
                $cadastrado['nome'],
                $cadastrado['email'],
                $cadastrado['cpf'] ?? null,
                $valor_centavos,
                $card_encrypted,
                $holder_name
            );

            db_execute("
                UPDATE filiacoes SET
                    pagbank_order_id = ?,
                    pagbank_charge_id = ?,
                    metodo = 'cartao'
                WHERE id = ?
            ", [$cartao_data['order_id'], $cartao_data['charge_id'], $filiacao['id']]);

            // Registra pedido na tabela de pedidos
            db_insert("
                INSERT INTO pagbank_pedidos (filiacao_id, order_id, metodo)
                VALUES (?, ?, 'cartao')
            ", [$filiacao['id'], $cartao_data['order_id']]);

            // Se pagamento aprovado imediatamente
            if ($cartao_data['status'] === 'PAID') {
                // UPDATE com guarda e checagem das linhas afetadas, igual ao
                // webhook, ao cron e ao cartao de evento. Sem isso o webhook
                // do proprio cartao — que chega durante os segundos da chamada
                // ao PagBank e nao disputa o lock de sessao, porque vem sem
                // cookie — confirma primeiro, e este trecho manda a SEGUNDA
                // declaracao para o mesmo endereco.
                $agora = date('Y-m-d H:i:s');
                $rows = db_execute(
                    "UPDATE filiacoes SET status = 'pago', data_pagamento = ?, status_at = ?
                     WHERE id = ? AND status != 'pago'",
                    [$agora, $agora, $filiacao['id']]
                );

                if ($rows > 0) {
                    registrar_log('pagamento_cartao', $cadastrado['id'], "Pagamento com cartão aprovado: " . $cartao_data['order_id']);

                    // Cancela lembretes pendentes
                    require_once SRC_DIR . '/Services/LembreteService.php';
                    LembreteService::cancelar($filiacao['id']);

                    // Envia email de confirmação com PDF
                    require_once SRC_DIR . '/Controllers/WebhookController.php';
                    WebhookController::processarPagamentoConfirmado($cadastrado['id'], (int)$ano);
                }

                $titulo = "Filiação Confirmada";
                $mensagem = "Pagamento aprovado! Sua filiação está confirmada.";

                ob_start();
                require SRC_DIR . '/Filiacao/Views/confirmacao.php';
                $content = ob_get_clean();
                require SRC_DIR . '/Views/layout.php';
                return;
            } else {
                registrar_log('cartao_pendente', $cadastrado['id'], "Cartão pendente/recusado: " . $cartao_data['status']);
                flash('error', 'Pagamento com cartão recusado. Verifique os dados do cartão ou tente outra forma de pagamento.');
            }

        } catch (Exception $e) {
            registrar_log('erro_pagbank', $cadastrado['id'], "Erro ao processar cartão: " . $e->getMessage());
            flash('error', 'Erro ao processar pagamento. Tente novamente.');
        }

        redirect("/filiacao/$ano/$token/pagamento");
    }
}
