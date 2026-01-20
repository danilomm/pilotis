<?php
/**
 * Pilotis - Controller de Filiacao
 */

class FiliacaoController {

    /**
     * Tela de entrada (pede email)
     */
    public static function entrada(string $ano): void {
        $titulo = "Filiacao $ano";
        $mensagem = null;

        ob_start();
        require SRC_DIR . '/Views/filiacao/entrada.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Processa email e redireciona para formulario
     */
    public static function processarEntrada(string $ano): void {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Por favor, informe um email valido.');
            redirect("/filiacao/$ano");
            return;
        }

        // Busca cadastrado pelo email
        $cadastrado = buscar_cadastrado_por_email($email);

        if ($cadastrado) {
            // Ja existe, usa token existente ou gera novo
            $token = $cadastrado['token'];
            if (!$token) {
                $token = gerar_token();
                db_execute(
                    "UPDATE cadastrados SET token = ? WHERE id = ?",
                    [$token, $cadastrado['id']]
                );
            }
            registrar_log('entrada_email', $cadastrado['id'], "Entrada pelo email para $ano");
        } else {
            // Novo cadastrado
            $token = gerar_token();
            $cadastrado_id = db_insert(
                "INSERT INTO cadastrados (nome, email, token, data_cadastro) VALUES (?, ?, ?, ?)",
                ['', $email, $token, date('Y-m-d H:i:s')]
            );
            registrar_log('novo_cadastro', $cadastrado_id, "Novo cadastro via entrada $ano");
        }

        redirect("/filiacao/$ano/$token");
    }

    /**
     * Formulario de filiacao pre-preenchido
     */
    public static function formulario(string $ano, string $token): void {
        $cadastrado = buscar_cadastrado_por_token($token);

        if (!$cadastrado) {
            flash('error', 'Token invalido ou expirado.');
            redirect("/filiacao/$ano");
            return;
        }

        // Monta lista de categorias
        $categorias = [];
        foreach (CATEGORIAS_FILIACAO as $valor => $info) {
            $categorias[] = [
                'valor' => $valor,
                'label' => $info['nome'] . ' - ' . formatar_valor($info['valor']),
                'selecionada' => ($cadastrado['categoria'] ?? '') === $valor,
            ];
        }

        // Verifica se ja existe pagamento para este ano
        $pagamento_existente = buscar_pagamento($cadastrado['id'], (int)$ano);

        registrar_log('acesso_formulario', $cadastrado['id'], "Acesso ao formulario $ano");

        $titulo = "Filiacao $ano";

        ob_start();
        require SRC_DIR . '/Views/filiacao/formulario.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Salva dados e cria pagamento
     */
    public static function salvar(string $ano, string $token): void {
        $cadastrado = buscar_cadastrado_por_token($token);

        if (!$cadastrado) {
            flash('error', 'Token invalido.');
            redirect("/filiacao/$ano");
            return;
        }

        // Obtem dados do formulario
        $nome = trim($_POST['nome'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
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
        $observacoes_filiado = trim($_POST['observacoes_filiado'] ?? '');

        // Validacoes
        if (empty($nome)) {
            flash('error', 'Nome e obrigatorio.');
            redirect("/filiacao/$ano/$token");
            return;
        }

        if (!isset(CATEGORIAS_FILIACAO[$categoria])) {
            flash('error', 'Categoria invalida.');
            redirect("/filiacao/$ano/$token");
            return;
        }

        // Atualiza cadastrado
        db_execute("
            UPDATE cadastrados SET
                nome = ?, email = ?, cpf = ?, telefone = ?, endereco = ?,
                cep = ?, cidade = ?, estado = ?, pais = ?, profissao = ?,
                formacao = ?, instituicao = ?, categoria = ?,
                observacoes_filiado = ?, data_atualizacao = ?
            WHERE id = ?
        ", [
            $nome, $email, $cpf ?: null, $telefone ?: null, $endereco ?: null,
            $cep ?: null, $cidade ?: null, $estado ?: null, $pais, $profissao ?: null,
            $formacao ?: null, $instituicao ?: null, $categoria,
            $observacoes_filiado ?: null, date('Y-m-d H:i:s'),
            $cadastrado['id']
        ]);

        registrar_log('dados_atualizados', $cadastrado['id'], "Dados atualizados para filiacao $ano");

        // Verifica pagamento existente
        $pagamento = buscar_pagamento($cadastrado['id'], (int)$ano);
        $valor = valor_por_categoria($categoria);

        if ($pagamento) {
            if ($pagamento['status'] === 'pago') {
                // Ja pagou, mostra confirmacao
                $titulo = "Filiacao Confirmada";
                $mensagem = "Sua filiacao ja esta confirmada!";

                ob_start();
                require SRC_DIR . '/Views/filiacao/confirmacao.php';
                $content = ob_get_clean();
                require SRC_DIR . '/Views/layout.php';
                return;
            }

            // Atualiza valor se categoria mudou
            db_execute(
                "UPDATE pagamentos SET valor = ? WHERE id = ?",
                [$valor, $pagamento['id']]
            );
        } else {
            // Cria novo pagamento pendente
            db_insert(
                "INSERT INTO pagamentos (cadastrado_id, ano, valor, status, metodo) VALUES (?, ?, ?, 'pendente', 'pix')",
                [$cadastrado['id'], (int)$ano, $valor]
            );
        }

        registrar_log('pagamento_criado', $cadastrado['id'], "Pagamento criado para $ano: " . formatar_valor($valor));

        redirect("/filiacao/$ano/$token/pagamento");
    }

    /**
     * Tela de pagamento com QR Code PIX
     */
    public static function pagamento(string $ano, string $token): void {
        require_once SRC_DIR . '/Services/PagBankService.php';

        $cadastrado = buscar_cadastrado_por_token($token);

        if (!$cadastrado) {
            flash('error', 'Token invalido.');
            redirect("/filiacao/$ano");
            return;
        }

        $pagamento = buscar_pagamento($cadastrado['id'], (int)$ano);

        if (!$pagamento) {
            redirect("/filiacao/$ano/$token");
            return;
        }

        if ($pagamento['status'] === 'pago') {
            $titulo = "Filiacao Confirmada";
            $mensagem = "Sua filiacao ja esta confirmada!";

            ob_start();
            require SRC_DIR . '/Views/filiacao/confirmacao.php';
            $content = ob_get_clean();
            require SRC_DIR . '/Views/layout.php';
            return;
        }

        $valor_centavos = (int)$pagamento['valor'];
        $pix_data = null;
        $boleto_data = null;
        $erro_pagbank = null;

        // Se ainda nao tem order_id, cria cobranca PIX
        if (empty($pagamento['pagbank_order_id'])) {
            try {
                $pix_data = PagBankService::criarCobrancaPix(
                    $cadastrado['id'],
                    (int)$ano,
                    $cadastrado['nome'],
                    $cadastrado['email'],
                    $cadastrado['cpf'],
                    $valor_centavos,
                    3 // dias expiracao
                );

                // Salva order_id e data de vencimento
                db_execute("
                    UPDATE pagamentos SET
                        pagbank_order_id = ?,
                        data_vencimento = ?,
                        metodo = 'pix'
                    WHERE id = ?
                ", [$pix_data['order_id'], $pix_data['expiration_date'], $pagamento['id']]);

                registrar_log('pix_gerado', $cadastrado['id'], "PIX gerado: " . $pix_data['order_id']);

            } catch (Exception $e) {
                $erro_pagbank = $e->getMessage();
                registrar_log('erro_pagbank', $cadastrado['id'], "Erro ao criar PIX: $erro_pagbank");
            }
        } else {
            // Ja tem order_id, busca dados do PIX
            try {
                $order_data = PagBankService::consultarPedido($pagamento['pagbank_order_id']);
                $qr_codes = $order_data['qr_codes'] ?? [];
                if (!empty($qr_codes)) {
                    $qr = $qr_codes[0];
                    $pix_data = [
                        'order_id' => $pagamento['pagbank_order_id'],
                        'qr_code' => $qr['text'] ?? '',
                        'qr_code_link' => !empty($qr['links']) ? $qr['links'][0]['href'] : '',
                        'expiration_date' => $pagamento['data_vencimento'],
                    ];
                }
            } catch (Exception $e) {
                $erro_pagbank = $e->getMessage();
            }
        }

        // Dados de boleto se existir
        if (!empty($pagamento['pagbank_boleto_link'])) {
            $boleto_data = [
                'boleto_link' => $pagamento['pagbank_boleto_link'],
                'barcode' => $pagamento['pagbank_boleto_barcode'] ?? '',
                'due_date' => $pagamento['data_vencimento'] ?? '',
            ];
        }

        // Chave publica para criptografia de cartao
        $pagbank_public_key = PagBankService::obterChavePublica();

        $titulo = "Pagamento - Filiacao $ano";
        $valor_formatado = formatar_valor($valor_centavos);

        ob_start();
        require SRC_DIR . '/Views/filiacao/pagamento.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Gera cobranca PIX
     */
    public static function gerarPix(string $ano, string $token): void {
        require_once SRC_DIR . '/Services/PagBankService.php';

        $cadastrado = buscar_cadastrado_por_token($token);
        if (!$cadastrado) {
            redirect("/filiacao/$ano");
            return;
        }

        $pagamento = buscar_pagamento($cadastrado['id'], (int)$ano);
        if (!$pagamento || $pagamento['status'] === 'pago') {
            redirect("/filiacao/$ano/$token/pagamento");
            return;
        }

        $valor_centavos = (int)$pagamento['valor'];

        try {
            $pix_data = PagBankService::criarCobrancaPix(
                $cadastrado['id'],
                (int)$ano,
                $cadastrado['nome'],
                $cadastrado['email'],
                $cadastrado['cpf'],
                $valor_centavos,
                3
            );

            db_execute("
                UPDATE pagamentos SET
                    pagbank_order_id = ?,
                    data_vencimento = ?,
                    metodo = 'pix'
                WHERE id = ?
            ", [$pix_data['order_id'], $pix_data['expiration_date'], $pagamento['id']]);

            registrar_log('pix_gerado', $cadastrado['id'], "PIX gerado: " . $pix_data['order_id']);

        } catch (Exception $e) {
            registrar_log('erro_pagbank', $cadastrado['id'], "Erro ao criar PIX: " . $e->getMessage());
        }

        redirect("/filiacao/$ano/$token/pagamento");
    }

    /**
     * Gera cobranca por boleto
     */
    public static function gerarBoleto(string $ano, string $token): void {
        require_once SRC_DIR . '/Services/PagBankService.php';

        $cadastrado = buscar_cadastrado_por_token($token);
        if (!$cadastrado) {
            redirect("/filiacao/$ano");
            return;
        }

        $pagamento = buscar_pagamento($cadastrado['id'], (int)$ano);
        if (!$pagamento || $pagamento['status'] === 'pago') {
            redirect("/filiacao/$ano/$token/pagamento");
            return;
        }

        $valor_centavos = (int)$pagamento['valor'];

        // Monta endereco
        $endereco = [
            'street' => $cadastrado['endereco'] ?: 'Nao informado',
            'number' => 'S/N',
            'locality' => $cadastrado['cidade'] ?: 'Nao informado',
            'city' => $cadastrado['cidade'] ?: 'Nao informado',
            'region_code' => $cadastrado['estado'] ?: 'DF',
            'postal_code' => str_replace('-', '', $cadastrado['cep'] ?: '70000000'),
        ];

        try {
            $boleto_data = PagBankService::criarCobrancaBoleto(
                $cadastrado['id'],
                (int)$ano,
                $cadastrado['nome'],
                $cadastrado['email'],
                $cadastrado['cpf'],
                $valor_centavos,
                $endereco,
                3
            );

            db_execute("
                UPDATE pagamentos SET
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
                $pagamento['id']
            ]);

            registrar_log('boleto_gerado', $cadastrado['id'], "Boleto gerado: " . $boleto_data['order_id']);

        } catch (Exception $e) {
            registrar_log('erro_pagbank', $cadastrado['id'], "Erro ao criar boleto: " . $e->getMessage());
        }

        redirect("/filiacao/$ano/$token/pagamento");
    }

    /**
     * Processa pagamento com cartao de credito
     */
    public static function pagarCartao(string $ano, string $token): void {
        require_once SRC_DIR . '/Services/PagBankService.php';

        $cadastrado = buscar_cadastrado_por_token($token);
        if (!$cadastrado) {
            redirect("/filiacao/$ano");
            return;
        }

        $pagamento = buscar_pagamento($cadastrado['id'], (int)$ano);
        if (!$pagamento || $pagamento['status'] === 'pago') {
            redirect("/filiacao/$ano/$token/pagamento");
            return;
        }

        $card_encrypted = $_POST['card_encrypted'] ?? '';
        $holder_name = $_POST['holder_name'] ?? '';

        if (empty($card_encrypted) || empty($holder_name)) {
            flash('error', 'Dados do cartao incompletos.');
            redirect("/filiacao/$ano/$token/pagamento");
            return;
        }

        $valor_centavos = (int)$pagamento['valor'];

        try {
            $cartao_data = PagBankService::criarCobrancaCartao(
                $cadastrado['id'],
                (int)$ano,
                $cadastrado['nome'],
                $cadastrado['email'],
                $cadastrado['cpf'],
                $valor_centavos,
                $card_encrypted,
                $holder_name
            );

            db_execute("
                UPDATE pagamentos SET
                    pagbank_order_id = ?,
                    pagbank_charge_id = ?,
                    metodo = 'cartao'
                WHERE id = ?
            ", [$cartao_data['order_id'], $cartao_data['charge_id'], $pagamento['id']]);

            // Se pagamento aprovado imediatamente
            if ($cartao_data['status'] === 'PAID') {
                db_execute(
                    "UPDATE pagamentos SET status = 'pago', data_pagamento = ? WHERE id = ?",
                    [date('Y-m-d H:i:s'), $pagamento['id']]
                );
                registrar_log('pagamento_cartao', $cadastrado['id'], "Pagamento com cartao aprovado: " . $cartao_data['order_id']);

                // Envia email de confirmacao com PDF
                require_once SRC_DIR . '/Controllers/WebhookController.php';
                WebhookController::processarPagamentoConfirmado($cadastrado['id'], (int)$ano);

                $titulo = "Filiacao Confirmada";
                $mensagem = "Pagamento aprovado! Sua filiacao esta confirmada.";

                ob_start();
                require SRC_DIR . '/Views/filiacao/confirmacao.php';
                $content = ob_get_clean();
                require SRC_DIR . '/Views/layout.php';
                return;
            } else {
                registrar_log('cartao_pendente', $cadastrado['id'], "Cartao pendente/recusado: " . $cartao_data['status']);
            }

        } catch (Exception $e) {
            registrar_log('erro_pagbank', $cadastrado['id'], "Erro ao processar cartao: " . $e->getMessage());
            flash('error', 'Erro ao processar pagamento. Tente novamente.');
        }

        redirect("/filiacao/$ano/$token/pagamento");
    }
}
