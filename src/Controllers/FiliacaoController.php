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

        ob_start();
        require SRC_DIR . '/Views/filiacao/entrada.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Processa email e envia link de acesso por email
     * (Segurança: evita que alguém veja dados de terceiros informando o email)
     */
    public static function processarEntrada(string $ano): void {
        require_once SRC_DIR . '/Services/BrevoService.php';

        $email = strtolower(trim($_POST['email'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Por favor, informe um email válido.');
            redirect("/filiacao/$ano");
            return;
        }

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
                registrar_log('link_acesso_enviado', $pessoa['id'], "Link de acesso enviado para $ano");
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
        require SRC_DIR . '/Views/filiacao/email_enviado.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Formulário de filiação pré-preenchido
     */
    public static function formulario(string $ano, string $token): void {
        $cadastrado = buscar_pessoa_por_token($token);

        if (!$cadastrado) {
            flash('error', 'Token inválido ou expirado.');
            redirect("/filiacao/$ano");
            return;
        }

        // Monta lista de categorias com valores da campanha (Internacional primeiro como default)
        $valores_ano = valores_campanha((int)$ano);
        $categorias = [];
        $tem_selecionada = false;
        $map_valores = [
            'profissional_internacional' => $valores_ano['valor_internacional'],
            'profissional_nacional' => $valores_ano['valor_profissional'],
            'estudante' => $valores_ano['valor_estudante'],
        ];
        foreach (CATEGORIAS_FILIACAO as $valor => $info) {
            $selecionada = ($cadastrado['categoria'] ?? '') === $valor;
            if ($selecionada) $tem_selecionada = true;
            $categorias[] = [
                'valor' => $valor,
                'label' => $info['nome'] . ' - ' . formatar_valor($map_valores[$valor] ?? $info['valor']),
                'selecionada' => $selecionada,
            ];
        }
        // Se nenhuma selecionada, seleciona Internacional (primeira)
        if (!$tem_selecionada && !empty($categorias)) {
            $categorias[0]['selecionada'] = true;
        }

        // Verifica se já existe filiação para este ano
        $pagamento_existente = buscar_filiacao($cadastrado['id'], (int)$ano);

        // Dados para autocomplete
        $autocomplete = obter_autocomplete();

        registrar_log('acesso_formulario', $cadastrado['id'], "Acesso ao formulário $ano");

        $titulo = "Filiação $ano";

        ob_start();
        require SRC_DIR . '/Views/filiacao/formulario.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Salva dados e cria filiação
     */
    public static function salvar(string $ano, string $token): void {
        $cadastrado = buscar_pessoa_por_token($token);

        if (!$cadastrado) {
            flash('error', 'Token inválido.');
            redirect("/filiacao/$ano");
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
            'nome' => 'Nome',
            'cpf' => 'CPF',
            'telefone' => 'Telefone',
            'endereco' => 'Endereço',
            'cep' => 'CEP',
            'cidade' => 'Cidade',
            'estado' => 'Estado',
            'pais' => 'País',
            'profissao' => 'Profissão',
        ];

        foreach ($obrigatorios as $campo => $label) {
            if (empty($$campo)) {
                flash('error', "$label é obrigatório.");
                redirect("/filiacao/$ano/$token");
                return;
            }
        }

        if (!isset(CATEGORIAS_FILIACAO[$categoria])) {
            flash('error', 'Categoria inválida.');
            redirect("/filiacao/$ano/$token");
            return;
        }

        $valor = valor_por_categoria($categoria, (int)$ano);

        // Atualiza pessoa e filiação
        atualizar_pessoa_filiacao($cadastrado['id'], (int)$ano, [
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
        ]);

        registrar_log('dados_atualizados', $cadastrado['id'], "Dados atualizados para filiação $ano");

        // Verifica filiação
        $filiacao = buscar_filiacao($cadastrado['id'], (int)$ano);

        if ($filiacao && $filiacao['data_pagamento']) {
            // Já pagou, mostra confirmação
            $titulo = "Filiação Confirmada";
            $mensagem = "Sua filiação já está confirmada!";

            ob_start();
            require SRC_DIR . '/Views/filiacao/confirmacao.php';
            $content = ob_get_clean();
            require SRC_DIR . '/Views/layout.php';
            return;
        }

        registrar_log('filiacao_criada', $cadastrado['id'], "Filiação criada para $ano: " . formatar_valor($valor));

        redirect("/filiacao/$ano/$token/pagamento");
    }

    /**
     * Tela de pagamento com QR Code PIX
     */
    public static function pagamento(string $ano, string $token): void {
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
            require SRC_DIR . '/Views/filiacao/confirmacao.php';
            $content = ob_get_clean();
            require SRC_DIR . '/Views/layout.php';
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
                    $cadastrado['id'],
                    (int)$ano,
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

                registrar_log('pix_gerado', $cadastrado['id'], "PIX gerado: " . $pix_data['order_id']);

            } catch (Exception $e) {
                $erro_pagbank = $e->getMessage();
                registrar_log('erro_pagbank', $cadastrado['id'], "Erro ao criar PIX: $erro_pagbank");
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
                $erro_pagbank = $e->getMessage();
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
        require SRC_DIR . '/Views/filiacao/pagamento.php';
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
                $cadastrado['id'],
                (int)$ano,
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
                    metodo = 'pix'
                WHERE id = ?
            ", [$pix_data['order_id'], $pix_data['expiration_date'], $filiacao['id']]);

            registrar_log('pix_gerado', $cadastrado['id'], "PIX gerado: " . $pix_data['order_id']);

        } catch (Exception $e) {
            registrar_log('erro_pagbank', $cadastrado['id'], "Erro ao criar PIX: " . $e->getMessage());
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
                $cadastrado['id'],
                (int)$ano,
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
                    metodo = 'boleto'
                WHERE id = ?
            ", [
                $boleto_data['order_id'],
                $boleto_data['charge_id'],
                $boleto_data['boleto_link'],
                $boleto_data['barcode'],
                $boleto_data['due_date'],
                $filiacao['id']
            ]);

            registrar_log('boleto_gerado', $cadastrado['id'], "Boleto gerado: " . $boleto_data['order_id']);

        } catch (Exception $e) {
            registrar_log('erro_pagbank', $cadastrado['id'], "Erro ao criar boleto: " . $e->getMessage());
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
                $cadastrado['id'],
                (int)$ano,
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

            // Se pagamento aprovado imediatamente
            if ($cartao_data['status'] === 'PAID') {
                db_execute(
                    "UPDATE filiacoes SET status = 'pago', data_pagamento = ? WHERE id = ?",
                    [date('Y-m-d H:i:s'), $filiacao['id']]
                );
                registrar_log('pagamento_cartao', $cadastrado['id'], "Pagamento com cartão aprovado: " . $cartao_data['order_id']);

                // Envia email de confirmação com PDF
                require_once SRC_DIR . '/Controllers/WebhookController.php';
                WebhookController::processarPagamentoConfirmado($cadastrado['id'], (int)$ano);

                $titulo = "Filiação Confirmada";
                $mensagem = "Pagamento aprovado! Sua filiação está confirmada.";

                ob_start();
                require SRC_DIR . '/Views/filiacao/confirmacao.php';
                $content = ob_get_clean();
                require SRC_DIR . '/Views/layout.php';
                return;
            } else {
                registrar_log('cartao_pendente', $cadastrado['id'], "Cartão pendente/recusado: " . $cartao_data['status']);
            }

        } catch (Exception $e) {
            registrar_log('erro_pagbank', $cadastrado['id'], "Erro ao processar cartão: " . $e->getMessage());
            flash('error', 'Erro ao processar pagamento. Tente novamente.');
        }

        redirect("/filiacao/$ano/$token/pagamento");
    }
}
