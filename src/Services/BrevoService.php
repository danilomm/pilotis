<?php
/**
 * Pilotis - Servico de envio de emails via Brevo (ex-Sendinblue)
 *
 * Documentacao: https://developers.brevo.com/reference/sendtransacemail
 * Limite gratuito: 300 emails/dia
 */

class BrevoService {

    /**
     * Envia email via API Brevo
     */
    public static function enviarEmail(
        $para,
        string $assunto,
        string $html,
        ?array $anexos = null
    ): bool {
        if (is_string($para)) {
            $para = [$para];
        }

        // Prepara destinatarios
        $to_list = [];
        foreach ($para as $email) {
            $email = trim($email);
            if ($email) {
                $to_list[] = ['email' => $email];
            }
        }

        if (empty($to_list)) {
            return false;
        }

        // Extrai email do remetente
        $from_email = EMAIL_FROM;
        if (preg_match('/<([^>]+)>/', EMAIL_FROM, $matches)) {
            $from_email = $matches[1];
        }

        $payload = [
            'sender' => [
                'name' => EMAIL_FROM_NAME,
                'email' => $from_email,
            ],
            'replyTo' => [
                'name' => EMAIL_FROM_NAME,
                'email' => $from_email,
            ],
            'to' => $to_list,
            'subject' => $assunto,
            'htmlContent' => $html,
        ];

        if ($anexos) {
            $payload['attachment'] = $anexos;
        }

        // Modo ensaio: nada sai daqui. Registra o que TERIA sido enviado e
        // devolve sucesso, para o fluxo poder ser percorrido ate o fim no
        // ambiente de teste.
        if (EMAIL_DRY_RUN) {
            $destinos = implode(', ', array_column($to_list, 'email'));
            $anexos_info = $anexos ? ' [+' . count($anexos) . ' anexo(s)]' : '';
            registrar_log('email_ensaio', null,
                "DRY RUN — nao enviado. Para [$destinos]: \"$assunto\"$anexos_info"
            );
            return true;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.brevo.com/v3/smtp/email');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'api-key: ' . BREVO_API_KEY,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 201) {
            $destinos = implode(', ', array_column($to_list, 'email'));
            $detalhes = $curlError ?: $response;
            registrar_log('erro_brevo', null,
                "Brevo HTTP $httpCode para [$destinos]: $detalhes"
            );
        }

        return $httpCode === 201;
    }

    /**
     * Prepara anexo PDF para envio
     */
    public static function prepararAnexoPdf(string $nome_arquivo, string $conteudo_bytes): array {
        return [
            'name' => $nome_arquivo,
            'content' => base64_encode($conteudo_bytes),
            'contentType' => 'application/pdf',
        ];
    }

    /**
     * Envia email de confirmação de filiação com declaração em anexo
     */
    public static function enviarConfirmacaoFiliacao(
        string $email,
        string $nome,
        string $categoria,
        int $ano,
        int $valor_centavos,
        ?string $pdf_declaracao = null
    ): bool {
        $categoria_nome = CATEGORIAS_DISPLAY[$categoria] ?? $categoria;
        $valor_formatado = formatar_valor($valor_centavos);

        $tpl = carregar_template('confirmacao', [
            'nome' => $nome,
            'ano' => $ano,
            'categoria' => $categoria_nome,
            'valor' => $valor_formatado,
        ]);

        if (!$tpl) return false;

        $anexos = [];
        if ($pdf_declaracao) {
            $anexos[] = self::prepararAnexoPdf("declaracao_" . strtolower(str_replace(' ', '_', ORG_SIGLA)) . "_$ano.pdf", $pdf_declaracao);
        }

        return self::enviarEmail(
            $email,
            $tpl['assunto'],
            $tpl['html'],
            $anexos ?: null
        );
    }

    /**
     * Envia lembrete de pagamento pendente
     */
    public static function enviarLembretePagamento(
        string $email,
        string $nome,
        int $ano,
        string $token,
        int $dias_restantes,
        int $valor_centavos
    ): bool {
        $valor_formatado = formatar_valor($valor_centavos);
        $link = BASE_URL . "/filiacao/$ano/$token/pagamento";
        $urgencia = $dias_restantes <= 0 ? 'ÚLTIMO AVISO: ' : '';
        $dias_info = $dias_restantes <= 0
            ? "<span style='color: red;'><strong>Seu PIX expira hoje!</strong></span>"
            : "Restam $dias_restantes dias para o vencimento.";

        $tpl = carregar_template('lembrete', [
            'nome' => $nome,
            'ano' => $ano,
            'valor' => $valor_formatado,
            'link' => $link,
            'urgencia' => $urgencia,
            'dias_info' => $dias_info,
        ]);

        if (!$tpl) return false;

        return self::enviarEmail($email, $tpl['assunto'], $tpl['html']);
    }

    /**
     * Envia email de campanha para filiados existentes (renovação)
     */
    public static function enviarCampanhaRenovacao(
        string $email,
        string $nome,
        int $ano,
        string $token
    ): bool {
        $link = BASE_URL . "/filiacao/$ano/$token";

        $tpl = carregar_template('renovacao', [
            'nome' => $nome,
            'ano' => $ano,
            'link' => $link,
            'prazo' => prazo_campanha($ano),
        ]);

        if (!$tpl) return false;

        return self::enviarEmail($email, $tpl['assunto'], $tpl['html']);
    }

    /**
     * Envia email de campanha para novos (convite à filiação)
     */
    public static function enviarCampanhaConvite(
        string $email,
        string $nome,
        int $ano,
        string $token
    ): bool {
        $link = BASE_URL . "/filiacao/$ano/$token";

        $tpl = carregar_template('convite', [
            'nome' => $nome,
            'ano' => $ano,
            'link' => $link,
            'prazo' => prazo_campanha($ano),
        ]);

        if (!$tpl) return false;

        return self::enviarEmail($email, $tpl['assunto'], $tpl['html']);
    }

    /**
     * Envia email de campanha para participantes do seminario
     */
    public static function enviarCampanhaSeminario(
        string $email,
        string $nome,
        int $ano,
        string $token
    ): bool {
        $link = BASE_URL . "/filiacao/$ano/$token";

        $tpl = carregar_template('seminario', [
            'nome' => $nome,
            'ano' => $ano,
            'link' => $link,
            'prazo' => prazo_campanha($ano),
        ]);

        if (!$tpl) return false;

        return self::enviarEmail($email, $tpl['assunto'], $tpl['html']);
    }

    /**
     * Envia link de acesso ao formulário de filiação
     * (Segurança: só quem tem acesso ao email pode preencher o formulário)
     */
    public static function enviarLinkAcesso(
        string $email,
        string $nome,
        int $ano,
        string $token
    ): bool {
        $link = BASE_URL . "/filiacao/$ano/$token";
        $nome_display = $nome ?: 'filiado(a)';

        $tpl = carregar_template('acesso', [
            'nome' => $nome_display,
            'ano' => $ano,
            'link' => $link,
        ]);

        if (!$tpl) return false;

        return self::enviarEmail($email, $tpl['assunto'], $tpl['html']);
    }

    /**
     * Manda, para o email do cadastro ANTIGO, o link que confirma a fusao.
     *
     * E a prova de posse: quem pede a fusao pode ser qualquer um (nome e email
     * do cadastro antigo sao publicos); quem ABRE esta caixa e a pessoa.
     *
     * Sem template editavel de proposito. O texto e curto, tem consequencia
     * juridica pratica (funde dois cadastros e move filiacoes) e nao muda de
     * evento para evento.
     */
    public static function enviarConfirmacaoVinculo(
        string $email,
        string $nome,
        string $link,
        string $descricao_novo
    ): bool {
        $nome_display = $nome ?: 'filiado(a)';
        $assunto = 'Confirme a unificação do seu cadastro — Docomomo Brasil';
        $html = '<p>Olá, ' . e($nome_display) . '.</p>'
            . '<p>Alguém preencheu um formulário no sistema do Docomomo Brasil '
            . 'informando dados que batem com o seu cadastro (' . e($descricao_novo) . ').</p>'
            . '<p>Se foi você, confirme aqui para unificar os dois cadastros e '
            . 'manter o seu histórico de filiações:</p>'
            . '<p><a href="' . e($link) . '">Confirmar a unificação</a></p>'
            . '<p>O link vale por 24 horas.</p>'
            . '<p><strong>Se não foi você, ignore este email.</strong> Nada muda '
            . 'no seu cadastro enquanto o link não for aberto.</p>';

        return self::enviarEmail($email, $assunto, $html);
    }

    /**
     * Envia link de acesso ao formulário de inscrição em evento
     */
    public static function enviarLinkInscricao(
        string $email,
        string $nome,
        string $evento_nome,
        string $slug,
        string $token
    ): bool {
        $link = BASE_URL . "/eventos/$slug/$token";
        $nome_display = $nome ?: 'participante';

        $tpl = carregar_template('evento_acesso', [
            'nome' => $nome_display,
            'evento' => $evento_nome,
            'link' => $link,
        ]);

        if (!$tpl) return false;

        return self::enviarEmail($email, $tpl['assunto'], $tpl['html']);
    }

    /**
     * Link de acesso ao painel de acompanhamento do evento.
     * So sai para email que esta na lista de autorizados do evento.
     */
    public static function enviarAcessoPainel(string $email, string $evento_nome, string $link): bool {
        $tpl = carregar_template('painel_acesso', [
            'evento' => $evento_nome,
            'link' => $link,
        ]);

        if (!$tpl) return false;

        return self::enviarEmail($email, $tpl['assunto'], $tpl['html']);
    }

    /**
     * Convite nominal a uma categoria restrita (isento, palestrante, convidado).
     *
     * Vai com o link pronto: quem recebe nao passa pela entrada por CPF nem
     * digita email, de modo que nao ha como divergir do endereco que a
     * organizacao informou na lista.
     */
    public static function enviarConviteEvento(
        string $email,
        string $nome,
        string $evento_nome,
        string $categoria_nome,
        string $slug,
        string $token
    ): bool {
        $tpl = carregar_template('evento_convite', [
            'nome' => $nome ?: 'participante',
            'evento' => $evento_nome,
            'categoria' => $categoria_nome,
            'link' => BASE_URL . "/eventos/$slug/$token",
        ]);

        if (!$tpl) return false;

        return self::enviarEmail($email, $tpl['assunto'], $tpl['html']);
    }

    /**
     * Envia confirmação de inscrição em evento (paga ou gratuita)
     */
    public static function enviarConfirmacaoInscricao(
        string $email,
        string $nome,
        string $evento_nome,
        string $categoria_nome,
        int $valor_centavos,
        ?string $pdf_bytes = null
    ): bool {
        $tpl = carregar_template('evento_confirmacao', [
            'nome' => $nome ?: 'participante',
            'evento' => $evento_nome,
            'categoria' => $categoria_nome,
            'valor' => $valor_centavos > 0 ? formatar_valor($valor_centavos) : 'Gratuita',
        ]);

        if (!$tpl) return false;

        $anexos = null;
        if ($pdf_bytes !== null) {
            $slug_nome = preg_replace('/[^a-z0-9]+/', '-', strtolower($nome ?: 'participante'));
            $anexos = [self::prepararAnexoPdf("comprovante-inscricao-$slug_nome.pdf", $pdf_bytes)];
        }

        return self::enviarEmail($email, $tpl['assunto'], $tpl['html'], $anexos);
    }
}
