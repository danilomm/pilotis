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
     * Envia email de confirmacao de filiacao com declaracao em anexo
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

        // Template HTML
        $html = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background-color: #4a8c4a; padding: 20px; text-align: center;'>
                <h1 style='color: white; margin: 0;'>Filiacao Confirmada!</h1>
            </div>
            <div style='padding: 20px; background-color: #f9f9f9;'>
                <p>Ola <strong>$nome</strong>,</p>
                <p>Sua filiacao ao <strong>Docomomo Brasil</strong> para o ano de <strong>$ano</strong> esta confirmada!</p>
                <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                    <tr>
                        <td style='padding: 10px; border-bottom: 1px solid #ddd;'><strong>Categoria:</strong></td>
                        <td style='padding: 10px; border-bottom: 1px solid #ddd;'>$categoria_nome</td>
                    </tr>
                    <tr>
                        <td style='padding: 10px; border-bottom: 1px solid #ddd;'><strong>Valor:</strong></td>
                        <td style='padding: 10px; border-bottom: 1px solid #ddd;'>$valor_formatado</td>
                    </tr>
                </table>
                <p>Em anexo, enviamos sua declaracao de filiacao.</p>
                <p>Obrigado por fazer parte do Docomomo Brasil!</p>
            </div>
            <div style='padding: 15px; background-color: #4a8c4a; color: white; text-align: center; font-size: 12px;'>
                Associacao de Colaboradores do Docomomo Brasil<br>
                @docomomobrasil
            </div>
        </div>
        ";

        $anexos = [];
        if ($pdf_declaracao) {
            $anexos[] = self::prepararAnexoPdf("declaracao_docomomo_$ano.pdf", $pdf_declaracao);
        }

        return self::enviarEmail(
            $email,
            "Filiacao Docomomo Brasil $ano - Confirmada!",
            $html,
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
        $urgencia = $dias_restantes <= 0 ? 'ULTIMO AVISO: ' : '';

        $html = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background-color: #f0ad4e; padding: 20px; text-align: center;'>
                <h1 style='color: white; margin: 0;'>{$urgencia}Lembrete de Pagamento</h1>
            </div>
            <div style='padding: 20px; background-color: #f9f9f9;'>
                <p>Ola <strong>$nome</strong>,</p>
                <p>Identificamos que sua filiacao ao Docomomo Brasil para $ano ainda esta pendente de pagamento.</p>
                <p><strong>Valor:</strong> $valor_formatado</p>
                " . ($dias_restantes <= 0 ? "<p style='color: red;'><strong>Seu PIX expira hoje!</strong></p>" : "<p>Restam $dias_restantes dias para o vencimento.</p>") . "
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='$link' style='background-color: #4a8c4a; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px;'>Realizar Pagamento</a>
                </p>
                <p><small>Se ja realizou o pagamento, por favor desconsidere este email.</small></p>
            </div>
            <div style='padding: 15px; background-color: #4a8c4a; color: white; text-align: center; font-size: 12px;'>
                Associacao de Colaboradores do Docomomo Brasil<br>
                @docomomobrasil
            </div>
        </div>
        ";

        $assunto = "{$urgencia}Filiacao Docomomo Brasil $ano - Pagamento Pendente";

        return self::enviarEmail($email, $assunto, $html);
    }

    /**
     * Envia email de campanha para filiados existentes (renovacao)
     */
    public static function enviarCampanhaRenovacao(
        string $email,
        string $nome,
        int $ano,
        string $token
    ): bool {
        $link = BASE_URL . "/filiacao/$ano/$token";

        $html = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background-color: #4a8c4a; padding: 20px; text-align: center;'>
                <h1 style='color: white; margin: 0;'>Renove sua Filiacao</h1>
            </div>
            <div style='padding: 20px; background-color: #f9f9f9;'>
                <p>Ola <strong>$nome</strong>,</p>
                <p>E hora de renovar sua filiacao ao Docomomo Brasil!</p>
                <p><strong>Beneficios da filiacao:</strong></p>
                <ul>
                    <li>Descontos em eventos do Docomomo Brasil e nucleos regionais</li>
                    <li>Acesso a rede de profissionais e pesquisadores</li>
                    <li>Para internacional: Docomomo Journal, Member Card, descontos em museus</li>
                </ul>
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='$link' style='background-color: #4a8c4a; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px;'>Renovar Filiacao</a>
                </p>
            </div>
            <div style='padding: 15px; background-color: #4a8c4a; color: white; text-align: center; font-size: 12px;'>
                Associacao de Colaboradores do Docomomo Brasil<br>
                @docomomobrasil
            </div>
        </div>
        ";

        return self::enviarEmail($email, "Renove sua Filiacao - Docomomo Brasil $ano", $html);
    }

    /**
     * Envia email de campanha para novos (convite a filiacao)
     */
    public static function enviarCampanhaConvite(
        string $email,
        string $nome,
        int $ano,
        string $token
    ): bool {
        $link = BASE_URL . "/filiacao/$ano/$token";

        $html = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background-color: #4a8c4a; padding: 20px; text-align: center;'>
                <h1 style='color: white; margin: 0;'>Convite para Filiacao</h1>
            </div>
            <div style='padding: 20px; background-color: #f9f9f9;'>
                <p>Ola <strong>$nome</strong>,</p>
                <p>Gostariamos de convidar voce a se filiar ao <strong>Docomomo Brasil</strong>!</p>
                <p>O Docomomo (Documentation and Conservation of buildings, sites and neighbourhoods of the Modern Movement)
                e uma organizacao internacional dedicada a documentacao e conservacao do patrimonio moderno.</p>
                <p><strong>Beneficios da filiacao:</strong></p>
                <ul>
                    <li>Descontos em eventos do Docomomo Brasil e nucleos regionais</li>
                    <li>Acesso a rede de profissionais e pesquisadores</li>
                    <li>Participacao nas atividades e publicacoes</li>
                </ul>
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='$link' style='background-color: #4a8c4a; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px;'>Filiar-se Agora</a>
                </p>
            </div>
            <div style='padding: 15px; background-color: #4a8c4a; color: white; text-align: center; font-size: 12px;'>
                Associacao de Colaboradores do Docomomo Brasil<br>
                @docomomobrasil
            </div>
        </div>
        ";

        return self::enviarEmail($email, "Convite para Filiacao - Docomomo Brasil $ano", $html);
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

        $html = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <div style='background-color: #4a8c4a; padding: 20px; text-align: center;'>
                <h1 style='color: white; margin: 0;'>Filiacao Docomomo Brasil</h1>
            </div>
            <div style='padding: 20px; background-color: #f9f9f9;'>
                <p>Ola <strong>$nome</strong>,</p>
                <p>Obrigado por sua participacao no <strong>16o Seminario Docomomo Brasil</strong>!</p>
                <p>Convidamos voce a se filiar ao Docomomo Brasil e fortalecer nossa rede de documentacao
                e conservacao da arquitetura, urbanismo e paisagismo modernos.</p>
                <p style='text-align: center; margin: 30px 0;'>
                    <a href='$link' style='background-color: #4a8c4a; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px;'>Filiar-se Agora</a>
                </p>
            </div>
            <div style='padding: 15px; background-color: #4a8c4a; color: white; text-align: center; font-size: 12px;'>
                Associacao de Colaboradores do Docomomo Brasil<br>
                @docomomobrasil
            </div>
        </div>
        ";

        return self::enviarEmail($email, "Filiacao Docomomo Brasil $ano - Participante do 16o Seminario", $html);
    }
}
