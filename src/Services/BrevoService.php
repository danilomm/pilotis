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
        curl_close($ch);

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
}
