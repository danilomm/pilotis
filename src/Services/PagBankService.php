<?php
/**
 * Pilotis - Integracao com API do PagBank (PagSeguro)
 *
 * Documentacao: https://dev.pagbank.uol.com.br/reference
 */

class PagBankService {

    /**
     * Traduz a resposta de erro do PagBank em uma linha que sirva ao log.
     *
     * O PagBank devolve `error_messages` com `parameter_name`, `code` e
     * `description`. Ate 30/08/2026 so a description era guardada, e a
     * description sozinha NAO DIZ DE QUE CAMPO ELA FALA: os 80 `erro_pagbank`
     * em producao trazem "must not be null" e "size must be between 5 and 60"
     * sem nomear nada. Quem for diagnosticar isso depois nao tem por onde
     * comecar — e o log e a unica saida do servidor, que nao tem SSH.
     *
     * Guarda TODAS as mensagens, e nao a primeira: um pedido recusado costuma
     * ter mais de um campo errado, e corrigir um por vez custa uma tentativa
     * de cobranca a cada rodada.
     *
     * Sem `error_messages` (5xx, HTML de proxy, resposta vazia), cai no corpo
     * cru TRUNCADO: a resposta inteira num campo de log nao ajuda a ler e
     * pode arrastar junto o que o PagBank ecoou do pedido.
     */
    private static function descreverErro(?array $result, string $bruto): string {
        $msgs = $result['error_messages'] ?? null;
        if (!is_array($msgs) || !$msgs) {
            $bruto = trim($bruto);
            if ($bruto === '') return '(resposta vazia)';
            return mb_strimwidth($bruto, 0, 300, '…');
        }

        $partes = [];
        foreach ($msgs as $m) {
            if (!is_array($m)) continue;
            $desc  = trim((string)($m['description'] ?? '')) ?: 'erro sem descricao';
            $campo = trim((string)($m['parameter_name'] ?? ''));
            $codigo = trim((string)($m['code'] ?? ''));

            $parte = $campo !== '' ? "$campo: $desc" : $desc;
            if ($codigo !== '') $parte .= " [$codigo]";
            $partes[] = $parte;
        }

        return $partes ? implode(' | ', $partes) : '(erro sem mensagem)';
    }

    /**
     * A mesma falha, dita a quem esta tentando pagar.
     *
     * O detalhe tecnico e para o LOG, nao para a tela: quem chegou aqui quer
     * saber se pode consertar sozinho ou se e para escrever para a tesouraria.
     * Ate 30/08/2026 a tela mostrava a mensagem crua do PagBank, em ingles —
     * "must not be null", "size must be between 5 and 60" — que nao diz nem uma
     * coisa nem outra.
     *
     * Traduz so o que a PESSOA pode resolver, e manda o resto para o contato.
     * Inventar traducao para erro que ela nao pode consertar seria pior: daria
     * a entender que ha o que fazer no formulario.
     */
    public static function mensagemParaPessoa(Throwable $e): string {
        $m = $e->getMessage();
        $contato = defined('ORG_EMAIL_CONTATO') ? ORG_EMAIL_CONTATO : '';
        $escreva = $contato !== ''
            ? " Se continuar, escreva para $contato."
            : ' Se continuar, escreva para a tesouraria.';

        // O CPF e o unico campo do formulario que o PagBank valida de verdade
        // (11 ou 14 digitos, CPF ou CNPJ), e e o erro mais provavel.
        if (stripos($m, 'CPF or CNPJ') !== false || stripos($m, 'tax_id') !== false) {
            return 'O meio de pagamento não aceitou o CPF informado. '
                 . 'Volte ao formulário e confira os números.';
        }

        // Nome fora da faixa que o PagBank aceita para o cliente ou o item.
        if (stripos($m, 'size must be between') !== false) {
            return 'Algum dado do cadastro está fora do tamanho que o meio de '
                 . 'pagamento aceita. Confira o nome e tente de novo.' . $escreva;
        }

        // Sem rede, timeout, 5xx: nao ha o que a pessoa faca no formulario.
        return 'Não foi possível gerar a cobrança agora.'
             . ' Tente de novo em alguns minutos.' . $escreva;
    }

    /**
     * Documento de quem paga, no formato que o PagBank aceita.
     *
     * A exigencia e DELE, nao nossa, e foi conferida em 30/08/2026 por duas
     * vias independentes:
     *
     * - a documentacao do objeto `customer` marca `tax_id` como obrigatorio,
     *   11 ou 14 digitos, CPF ou CNPJ, sem alternativa para estrangeiro
     *   (developer.pagbank.com.br/reference/objeto-order);
     * - o log de producao traz recusas reais:
     *   `Erro PagBank (400): must be a valid CPF or CNPJ`.
     *
     * Ou seja, nao basta mandar alguma coisa: o valor e VALIDADO. Passaporte
     * ali volta 400. Por isso `pessoas.documento` (ver CLAUDE.md) resolve o
     * cadastro do filiado estrangeiro e NAO resolve a cobranca dele, que
     * continua sendo feita fora do sistema.
     *
     * Nota para 2027: o `tax_id` e de quem PAGA, nao necessariamente do
     * filiado — e o PagBank aceita CNPJ. Uma universidade pagando a inscricao
     * de um professor estrangeiro e caso legitimo que hoje o sistema nao
     * permite, porque o formulario amarra o CPF ao cadastro da pessoa. Isso e
     * limitacao NOSSA, e nao do provedor.
     */
    private static function getTaxId(?string $cpf): string {
        if ($cpf) {
            return preg_replace('/\D/', '', $cpf);
        }
        throw new Exception('CPF é obrigatório para gerar pagamento. Volte ao formulário e preencha.');
    }

    /**
     * Retorna headers para autenticacao
     */
    private static function getHeaders(): array {
        return [
            'Authorization: Bearer ' . PAGBANK_TOKEN,
            'Content-Type: application/json',
            'Accept: application/json',
        ];
    }

    /**
     * Faz requisicao HTTP
     */
    private static function request(string $method, string $endpoint, ?array $data = null): array {
        $url = PAGBANK_API_URL . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, self::getHeaders());
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Exception("Erro cURL: $error");
        }

        $result = json_decode($response, true);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("Erro PagBank ($httpCode): " . self::descreverErro($result, $response));
        }

        return $result ?? [];
    }

    /**
     * Cria cobranca PIX no PagBank
     */
    public static function criarCobrancaPix(
        string $reference_id,
        string $descricao,
        string $nome,
        string $email,
        ?string $cpf,
        int $valor_centavos,
        int $dias_expiracao = 3
    ): array {
        $expiration = date('Y-m-d\T23:59:59-03:00', strtotime("+$dias_expiracao days"));

        $payload = [
            'reference_id' => $reference_id,
            'customer' => [
                'name' => $nome,
                'email' => $email,
            ],
            'items' => [[
                'reference_id' => strtolower($reference_id),
                'name' => mb_substr($descricao, 0, 100),
                'quantity' => 1,
                'unit_amount' => $valor_centavos,
            ]],
            'qr_codes' => [[
                'amount' => ['value' => $valor_centavos],
                'expiration_date' => $expiration,
            ]],
        ];

        // Adiciona CPF (obrigatório no PagBank)
        $payload['customer']['tax_id'] = self::getTaxId($cpf);

        // Adiciona webhook se nao for localhost
        if (strpos(BASE_URL, 'localhost') === false) {
            $payload['notification_urls'] = [BASE_URL . '/webhook/pagbank'];
        }

        $data = self::request('POST', '/orders', $payload);

        $qr_codes = $data['qr_codes'] ?? [];
        $qr_code_data = $qr_codes[0] ?? [];

        return [
            'order_id' => $data['id'] ?? '',
            'reference_id' => $reference_id,
            'qr_code' => $qr_code_data['text'] ?? '',
            'qr_code_link' => !empty($qr_code_data['links']) ? $qr_code_data['links'][0]['href'] : '',
            'expiration_date' => $expiration,
        ];
    }

    /**
     * Cria cobranca por boleto no PagBank
     */
    public static function criarCobrancaBoleto(
        string $reference_id,
        string $descricao,
        string $nome,
        string $email,
        ?string $cpf,
        int $valor_centavos,
        array $endereco,
        int $dias_vencimento = 7
    ): array {
        $due_date = date('Y-m-d', strtotime("+$dias_vencimento days"));

        $payload = [
            'reference_id' => $reference_id,
            'customer' => [
                'name' => $nome,
                'email' => $email,
            ],
            'items' => [[
                'reference_id' => strtolower($reference_id),
                'name' => mb_substr($descricao, 0, 100),
                'quantity' => 1,
                'unit_amount' => $valor_centavos,
            ]],
            'charges' => [[
                'reference_id' => $reference_id,
                'description' => mb_substr($descricao, 0, 100),
                'amount' => [
                    'value' => $valor_centavos,
                    'currency' => 'BRL',
                ],
                'payment_method' => [
                    'type' => 'BOLETO',
                    'boleto' => [
                        'due_date' => $due_date,
                        'instruction_lines' => [
                            'line_1' => mb_substr($descricao, 0, 80),
                            'line_2' => mb_substr(ORG_NOME, 0, 80),
                        ],
                        'holder' => [
                            'name' => $nome,
                            'tax_id' => $cpf ? preg_replace('/\D/', '', $cpf) : '',
                            'email' => $email,
                            'address' => [
                                'street' => substr($endereco['street'] ?? '', 0, 60),
                                'number' => substr($endereco['number'] ?? 'S/N', 0, 8),
                                'locality' => substr($endereco['locality'] ?? '', 0, 60),
                                'city' => substr($endereco['city'] ?? '', 0, 60),
                                'region' => substr($endereco['region_code'] ?? 'DF', 0, 2),
                                'region_code' => substr($endereco['region_code'] ?? 'DF', 0, 2),
                                'country' => 'BRA',
                                'postal_code' => substr(preg_replace('/\D/', '', $endereco['postal_code'] ?? ''), 0, 8),
                            ],
                        ],
                    ],
                ],
            ]],
        ];

        // Adiciona CPF (obrigatório no PagBank)
        $payload['customer']['tax_id'] = self::getTaxId($cpf);

        // Adiciona webhook se nao for localhost
        if (strpos(BASE_URL, 'localhost') === false) {
            $payload['notification_urls'] = [BASE_URL . '/webhook/pagbank'];
        }

        $data = self::request('POST', '/orders', $payload);

        $charges = $data['charges'] ?? [];
        $charge_data = $charges[0] ?? [];
        $payment_method = $charge_data['payment_method'] ?? [];
        $boleto = $payment_method['boleto'] ?? [];

        // Procura link do PDF
        $boleto_link = '';
        foreach ($charge_data['links'] ?? [] as $link) {
            if (($link['media'] ?? '') === 'application/pdf') {
                $boleto_link = $link['href'] ?? '';
                break;
            }
        }

        return [
            'order_id' => $data['id'] ?? '',
            'charge_id' => $charge_data['id'] ?? '',
            'reference_id' => $reference_id,
            'boleto_link' => $boleto_link,
            'barcode' => $boleto['barcode'] ?? '',
            'due_date' => $due_date,
        ];
    }

    /**
     * Cria cobranca por cartao de credito no PagBank
     */
    public static function criarCobrancaCartao(
        string $reference_id,
        string $descricao,
        string $nome,
        string $email,
        ?string $cpf,
        int $valor_centavos,
        string $card_encrypted,
        string $holder_name
    ): array {

        $payload = [
            'reference_id' => $reference_id,
            'customer' => [
                'name' => $nome,
                'email' => $email,
            ],
            'items' => [[
                'reference_id' => strtolower($reference_id),
                'name' => mb_substr($descricao, 0, 100),
                'quantity' => 1,
                'unit_amount' => $valor_centavos,
            ]],
            'charges' => [[
                'reference_id' => $reference_id,
                'description' => mb_substr($descricao, 0, 100),
                'amount' => [
                    'value' => $valor_centavos,
                    'currency' => 'BRL',
                ],
                'payment_method' => [
                    'type' => 'CREDIT_CARD',
                    'installments' => 1,
                    'capture' => true,
                    'card' => [
                        'encrypted' => $card_encrypted,
                        'holder' => [
                            'name' => $holder_name,
                        ],
                    ],
                ],
            ]],
        ];

        // Adiciona CPF (obrigatório no PagBank)
        $payload['customer']['tax_id'] = self::getTaxId($cpf);

        // Adiciona webhook se nao for localhost
        if (strpos(BASE_URL, 'localhost') === false) {
            $payload['notification_urls'] = [BASE_URL . '/webhook/pagbank'];
        }

        $data = self::request('POST', '/orders', $payload);

        $charges = $data['charges'] ?? [];
        $charge_data = $charges[0] ?? [];

        return [
            'order_id' => $data['id'] ?? '',
            'charge_id' => $charge_data['id'] ?? '',
            'reference_id' => $reference_id,
            'status' => $charge_data['status'] ?? '',
        ];
    }

    /**
     * Obtem chave publica para criptografia de cartao
     */
    public static function obterChavePublica(): string {
        if (PAGBANK_SANDBOX) {
            // Chave publica padrao do sandbox
            return 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAr+ZqgD892U9/HXsa7XqBZUayPquAfh9xx4iwUbTSUAvTlmiXFQNTp0Bvt/5vK2FhMj39qSv1zi2OuBjvW38q1E374nzx6NNBL5JosV0+SDINTlCG0cmigHuBOyWzYmjgca+mtQu4WczCaApNaSuVqgb8u7Bd9GCOL4YJotvV5+81frlSwQXralhwRzGhj/A57CGPgGKiuPT+AOGmykIGEZsSD9RKkyoKIoc0OS8CPIzdBOtTQCIwrLn2FxI83Clcg55W8gkFSOS6rWNbG5qFZWMll6yl02HtunalHmUlRUL66YeGXdMDC2PuRcmZbGO5a/2tbVppW6mfSWG3NPRpgwIDAQAB';
        }

        try {
            $data = self::request('POST', '/public-keys', ['type' => 'card']);
            return $data['public_key'] ?? '';
        } catch (Exception $e) {
            return '';
        }
    }

    /**
     * Consulta status de um pedido
     */
    public static function consultarPedido(string $order_id): array {
        return self::request('GET', "/orders/$order_id");
    }

    /**
     * Monta o reference_id de uma filiacao: PILOTIS-{pessoa_id}-{ano}
     */
    public static function referenciaFiliacao(int $pessoa_id, int $ano): string {
        return "PILOTIS-$pessoa_id-$ano";
    }

    /**
     * Monta o reference_id de uma inscricao em evento: PILOTIS-EVT-{inscricao_id}
     */
    public static function referenciaInscricao(int $inscricao_id): string {
        return "PILOTIS-EVT-$inscricao_id";
    }

    /**
     * Processa payload do webhook do PagBank
     *
     * Reconhece os dois formatos de reference_id:
     *   PILOTIS-EVT-{inscricao_id}   -> tipo 'evento'
     *   PILOTIS-{pessoa_id}-{ano}    -> tipo 'filiacao'
     * O padrao de evento e testado PRIMEIRO: 'PILOTIS-EVT-9' quebrado por
     * explode('-') daria (int)'EVT' = 0 no formato antigo.
     */
    /**
     * Reconfere o pagamento na API do PagBank em vez de acreditar no corpo
     * recebido.
     *
     * POR QUE: a rota /webhook/pagbank e um POST publico, sem assinatura nem
     * filtro de IP. Ate 28/08/2026 o status vinha do proprio corpo, entao um
     * curl com reference_id adivinhado (os ids sao inteiros pequenos) e
     * status PAID marcava a filiacao como paga, cancelava os lembretes e
     * disparava a declaracao em PDF — que o /validar/{codigo} confirmava como
     * valida, porque le o banco.
     *
     * Consultar a API fecha isso sem depender de o PagBank documentar header
     * de assinatura: quem manda o POST nao controla o que o PagBank responde.
     *
     * Devolve o payload da API, ou null se nao der para confirmar — e nao dar
     * para confirmar significa NAO processar. Um webhook legitimo perdido
     * assim e recuperado pelo cron-verificar-pagamentos.php.
     */
    public static function confirmarPedido(?string $order_id, ?bool &$transitorio = null): ?array {
        $transitorio = false;

        if (!$order_id) {
            return null;
        }

        try {
            $dados = self::consultarPedido($order_id);
        } catch (Exception $e) {
            $msg = $e->getMessage();
            // 404 e resposta do PagBank: o pedido nao existe, ponto — nao
            // adianta reenviar. Rede fora do ar ou 5xx e outra coisa: e falha
            // nossa de consultar, e o webhook merece nova tentativa.
            $transitorio = (strpos($msg, '(404)') === false);
            registrar_log('webhook_confirmacao_falhou', null,
                "Nao foi possivel confirmar o pedido $order_id"
                . ($transitorio ? ' (transitorio)' : ' (pedido inexistente)') . ": $msg");
            return null;
        }

        // Resposta sem reference_id nao e um pedido.
        if (empty($dados['reference_id'])) {
            registrar_log('webhook_confirmacao_falhou', null,
                "Pedido $order_id nao existe no PagBank");
            return null;
        }

        return $dados;
    }

    public static function parseWebhookPayload(array $payload): array {
        $reference_id = $payload['reference_id'] ?? '';

        $tipo = null;
        $cadastrado_id = null;
        $ano = null;
        $inscricao_id = null;

        if (preg_match('/^PILOTIS-EVT-(\d+)$/', $reference_id, $m)) {
            $tipo = 'evento';
            $inscricao_id = (int)$m[1];
        } elseif (preg_match('/^PILOTIS-(\d+)-(\d{4})$/', $reference_id, $m)) {
            $tipo = 'filiacao';
            $cadastrado_id = (int)$m[1];
            $ano = (int)$m[2];
        }

        // Verifica status das charges
        $charges = $payload['charges'] ?? [];
        $status = !empty($charges) ? ($charges[0]['status'] ?? '') : '';

        return [
            'reference_id' => $reference_id,
            'tipo' => $tipo,
            'status' => $status,
            'paid' => $status === 'PAID',
            'cadastrado_id' => $cadastrado_id,
            'ano' => $ano,
            'inscricao_id' => $inscricao_id,
            'order_id' => $payload['id'] ?? null,
            'charge_id' => !empty($charges) ? ($charges[0]['id'] ?? null) : null,
            // Quanto o PagBank diz que ENTROU, em centavos. Ate 29/08/2026 o
            // sistema so lia o valor do proprio registro e nunca comparava:
            // gerar cobranca barata, trocar de categoria e pagar o QR antigo
            // registrava o valor caro, com comprovante valido.
            'valor_pago' => !empty($charges) ? (int)($charges[0]['amount']['value'] ?? 0) : 0,
        ];
    }
}
