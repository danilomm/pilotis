<?php
/**
 * Pilotis - Controller de Webhooks
 */

class WebhookController {

    /**
     * Webhook do PagBank
     */
    public static function pagbank(): void {
        require_once SRC_DIR . '/Services/PagBankService.php';

        // Le payload JSON
        $json = file_get_contents('php://input');
        $payload = json_decode($json, true);

        if (!$payload) {
            json_response(['status' => 'error', 'message' => 'Invalid JSON']);
            return;
        }

        // Registra no log
        registrar_log('webhook_pagbank', null, "Payload recebido: " . json_encode($payload));

        // NAO acreditar no corpo recebido: esta rota e um POST publico, sem
        // assinatura nem filtro de IP. O status vale o que a API do PagBank
        // responder quando perguntada pelo pedido — quem manda o POST nao
        // controla isso. Ver confirmarPedido() no PagBankService.
        $confirmado = PagBankService::confirmarPedido($payload['id'] ?? null, $transitorio);
        if ($confirmado === null) {
            if ($transitorio) {
                // Falha nossa de consultar (rede, 5xx). Responder 503 faz o
                // PagBank reenviar: devolver 200 aqui descartaria um webhook
                // legitimo em silencio, e a confirmacao so viria pelo cron.
                http_response_code(503);
                json_response(['status' => 'retry', 'message' => 'Falha temporaria ao confirmar']);
                return;
            }
            // Pedido nao existe no PagBank: e forjado, ou de outra conta.
            // 200 para nao pedir reenvio de algo que nunca vai confirmar.
            json_response(['status' => 'ok', 'message' => 'Nao confirmado na API']);
            return;
        }

        // Processa o payload CONFIRMADO, nao o recebido.
        $dados = PagBankService::parseWebhookPayload($confirmado);

        // Inscricao em evento (PILOTIS-EVT-{inscricao_id})
        if ($dados['tipo'] === 'evento') {
            self::pagbankEvento($dados);
            return;
        }

        // Filiacao (PILOTIS-{pessoa_id}-{ano})
        if ($dados['tipo'] !== 'filiacao' || !$dados['cadastrado_id'] || !$dados['ano']) {
            registrar_log('webhook_pagbank', null, "Reference ID invalido: " . $dados['reference_id']);
            json_response(['status' => 'ok', 'message' => 'Reference ID invalido']);
            return;
        }

        // Busca pagamento
        $pagamento = buscar_pagamento($dados['cadastrado_id'], $dados['ano']);

        if (!$pagamento) {
            // Cobranca confirmada pela API, mas o registro nao existe mais:
            // consolidacao apagou a linha, ou o cadastro foi removido. O
            // dinheiro ENTROU e ninguem sabe. Tipo proprio no log, com
            // order_id e valor, para a conciliacao de fim de campanha achar —
            // ate 28/08/2026 isso era um 'webhook_pagbank' igual aos outros
            // milhares, e foi assim que o pagamento da Maisa ficou 15 meses
            // orfao.
            registrar_log('pagamento_orfao', $dados['cadastrado_id'],
                "PAGAMENTO SEM REGISTRO: filiacao pessoa={$dados['cadastrado_id']} ano={$dados['ano']}"
                . " nao existe. order_id={$dados['order_id']} charge={$dados['charge_id']}"
                . " status={$dados['status']}. Conferir no extrato do PagBank.");
            json_response(['status' => 'ok', 'message' => 'Pagamento nao encontrado']);
            return;
        }

        // Confere o que ENTROU contra o que era devido. Nao barra o pagamento:
        // o dinheiro ja esta na conta, e recusar o registro deixaria a pessoa
        // paga e sem declaracao — o pior dos dois mundos. Registra a
        // divergencia com os dois valores, que e o que a conciliacao de fim de
        // campanha precisa para achar.
        if ($dados['paid'] && !empty($dados['valor_pago'])) {
            $devido = (int)($pagamento['valor'] ?? 0);
            if ($devido > 0 && (int)$dados['valor_pago'] !== $devido) {
                registrar_log('valor_divergente', $dados['cadastrado_id'],
                    "Filiacao {$pagamento['id']} ({$dados['ano']}): devido "
                    . formatar_valor($devido) . ", pago " . formatar_valor((int)$dados['valor_pago'])
                    . ". order_id={$dados['order_id']}. Conferir antes de emitir declaracao.");
            }
        }

        // Atualiza status conforme retorno
        if ($dados['paid']) {
            // UPDATE atomico: WHERE status != 'pago' evita processamento duplicado
            $rows = db_execute("
                UPDATE filiacoes SET
                    status = 'pago',
                    data_pagamento = ?,
                    pagbank_order_id = ?,
                    pagbank_charge_id = ?,
                    status_at = ?
                WHERE id = ? AND status != 'pago'
            ", [
                date('Y-m-d H:i:s'),
                $dados['order_id'],
                $dados['charge_id'],
                date('Y-m-d H:i:s'),
                $pagamento['id']
            ]);

            if ($rows === 0) {
                registrar_log('webhook_pagbank', $dados['cadastrado_id'], "Pagamento ja confirmado anteriormente");
                json_response(['status' => 'ok', 'message' => 'Ja processado']);
                return;
            }

            registrar_log('pagamento_confirmado', $dados['cadastrado_id'], "Pagamento {$dados['ano']} confirmado via webhook");

            // Cancela lembretes pendentes
            require_once SRC_DIR . '/Services/LembreteService.php';
            LembreteService::cancelar($pagamento['id']);

            // Processa email e PDF
            self::processarPagamentoConfirmado($dados['cadastrado_id'], $dados['ano']);

            json_response(['status' => 'ok', 'message' => 'Pagamento confirmado']);
            return;

        } elseif (in_array($dados['status'], ['CANCELED', 'DECLINED'])) {
            // Nao sobrescreve filiacoes ja pagas — protege contra webhooks de orders
            // antigas (PIX/boleto nao usados) que expiram apos pagamento via outro metodo.
            $rows = db_execute("
                UPDATE filiacoes SET
                    status = 'cancelado',
                    pagbank_order_id = ?,
                    pagbank_charge_id = ?,
                    status_at = ?
                WHERE id = ? AND status != 'pago'
            ", [$dados['order_id'], $dados['charge_id'], date('Y-m-d H:i:s'), $pagamento['id']]);

            if ($rows === 0) {
                registrar_log('webhook_pagbank', $dados['cadastrado_id'], "Cancelamento ignorado (filiacao ja paga). Order: {$dados['order_id']}");
                json_response(['status' => 'ok', 'message' => 'Filiacao ja paga, cancelamento ignorado']);
                return;
            }

            registrar_log('pagamento_cancelado', $dados['cadastrado_id'], "Pagamento {$dados['ano']} cancelado: {$dados['status']}");
            json_response(['status' => 'ok', 'message' => 'Pagamento cancelado']);
            return;

        } else {
            registrar_log('webhook_pagbank', $dados['cadastrado_id'], "Status nao tratado: {$dados['status']}");
            json_response(['status' => 'ok', 'message' => "Status: {$dados['status']}"]);
        }
    }

    /**
     * Processa pagamento confirmado: gera PDF e envia email
     */
    public static function processarPagamentoConfirmado(int $pessoa_id, int $ano): void {
        require_once SRC_DIR . '/Services/PdfService.php';
        require_once SRC_DIR . '/Services/BrevoService.php';

        try {
            // Busca dados da pessoa com email
            $pessoa = db_fetch_one("
                SELECT p.*, e.email
                FROM pessoas p
                LEFT JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
                WHERE p.id = ?
            ", [$pessoa_id]);

            if (!$pessoa) {
                registrar_log('erro_confirmacao', $pessoa_id, "Pessoa nao encontrada");
                return;
            }

            // Se não tem email principal, pega qualquer um
            if (!$pessoa['email']) {
                $email_row = db_fetch_one("SELECT email FROM emails WHERE pessoa_id = ? ORDER BY principal DESC, id DESC LIMIT 1", [$pessoa_id]);
                $pessoa['email'] = $email_row['email'] ?? '';
            }

            if (!$pessoa['email']) {
                registrar_log('erro_confirmacao', $pessoa_id, "Pessoa sem email cadastrado");
                return;
            }

            // Busca dados da filiacao
            $filiacao = buscar_filiacao($pessoa_id, $ano);

            if (!$filiacao) {
                registrar_log('erro_confirmacao', $pessoa_id, "Filiacao nao encontrada para ano $ano");
                return;
            }

            $valor_centavos = (int)$filiacao['valor'];
            $categoria = $filiacao['categoria'];

            // Gera PDF da declaracao
            $pdf_bytes = PdfService::gerarDeclaracao(
                $pessoa['nome'],
                $pessoa['email'],
                $categoria,
                $ano,
                $valor_centavos
            );

            // Envia email de confirmacao com PDF anexo
            $enviado = BrevoService::enviarConfirmacaoFiliacao(
                $pessoa['email'],
                $pessoa['nome'],
                $categoria,
                $ano,
                $valor_centavos,
                $pdf_bytes
            );

            if ($enviado) {
                registrar_log('email_confirmacao_enviado', $pessoa_id, "Email de confirmacao {$ano} enviado para " . $pessoa['email']);
            } else {
                registrar_log('erro_email_confirmacao', $pessoa_id, "Falha ao enviar email para " . $pessoa['email']);
            }

        } catch (Exception $e) {
            registrar_log('erro_confirmacao', $pessoa_id, "Erro ao processar confirmacao: " . $e->getMessage());
        }
    }

    /**
     * Ramo do webhook para inscricoes em eventos.
     * Espelha a logica da filiacao: UPDATE atomico protegido contra reprocessamento
     * e contra webhooks de orders antigas (PIX/boleto abandonados) que expiram
     * depois de a pessoa ter pago por outro metodo.
     */
    private static function pagbankEvento(array $dados): void {
        $inscricao_id = (int)$dados['inscricao_id'];

        $inscricao = db_fetch_one("SELECT * FROM inscricoes WHERE id = ?", [$inscricao_id]);
        if (!$inscricao) {
            // Mesmo caso do ramo de filiacao: dinheiro entrou, registro sumiu.
            registrar_log('pagamento_orfao', null,
                "PAGAMENTO SEM REGISTRO: inscricao $inscricao_id nao existe (ref {$dados['reference_id']})."
                . " order_id={$dados['order_id']} charge={$dados['charge_id']} status={$dados['status']}."
                . " Conferir no extrato do PagBank.");
            json_response(['status' => 'ok', 'message' => 'Inscricao nao encontrada']);
            return;
        }

        $pessoa_id = (int)$inscricao['pessoa_id'];
        $agora = date('Y-m-d H:i:s');

        // Mesma conferencia do ramo de filiacao. Aqui pesa mais: o valor da
        // inscricao muda de faixa numa data (valor_vigente_categoria), entao
        // cobranca gerada na vespera e paga depois e divergencia esperada — e
        // precisa aparecer nomeada, e nao se perder no meio dos webhooks.
        if (!empty($dados['paid']) && !empty($dados['valor_pago'])) {
            $devido = (int)($inscricao['valor'] ?? 0);
            if ($devido > 0 && (int)$dados['valor_pago'] !== $devido) {
                registrar_log('valor_divergente', $pessoa_id,
                    "Inscricao $inscricao_id: devido " . formatar_valor($devido)
                    . ", pago " . formatar_valor((int)$dados['valor_pago'])
                    . ". order_id={$dados['order_id']}. Conferir antes de emitir comprovante.");
            }
        }

        if ($dados['paid']) {
            $rows = db_execute("
                UPDATE inscricoes SET
                    status = 'pago',
                    data_pagamento = ?,
                    pagbank_order_id = ?,
                    pagbank_charge_id = ?,
                    status_at = ?
                WHERE id = ? AND status NOT IN ('pago', 'gratuita_confirmada')
            ", [$agora, $dados['order_id'], $dados['charge_id'], $agora, $inscricao_id]);

            if ($rows === 0) {
                registrar_log('webhook_pagbank', $pessoa_id, "Inscricao $inscricao_id ja confirmada anteriormente");
                json_response(['status' => 'ok', 'message' => 'Ja processado']);
                return;
            }

            registrar_log('inscricao_paga', $pessoa_id, "Inscricao $inscricao_id confirmada via webhook");

            require_once SRC_DIR . '/Services/LembreteService.php';
            LembreteService::cancelarInscricao($inscricao_id);

            self::processarInscricaoConfirmada($inscricao_id);

            json_response(['status' => 'ok', 'message' => 'Inscricao confirmada']);
            return;
        }

        if (in_array($dados['status'], ['CANCELED', 'DECLINED'])) {
            $rows = db_execute("
                UPDATE inscricoes SET
                    status = 'cancelado',
                    pagbank_order_id = ?,
                    pagbank_charge_id = ?,
                    status_at = ?
                WHERE id = ? AND status NOT IN ('pago', 'gratuita_confirmada')
            ", [$dados['order_id'], $dados['charge_id'], $agora, $inscricao_id]);

            if ($rows === 0) {
                registrar_log('webhook_pagbank', $pessoa_id, "Cancelamento ignorado (inscricao $inscricao_id ja confirmada). Order: {$dados['order_id']}");
                json_response(['status' => 'ok', 'message' => 'Inscricao ja confirmada, cancelamento ignorado']);
                return;
            }

            registrar_log('inscricao_cancelada', $pessoa_id, "Inscricao $inscricao_id cancelada: {$dados['status']}");
            json_response(['status' => 'ok', 'message' => 'Inscricao cancelada']);
            return;
        }

        registrar_log('webhook_pagbank', $pessoa_id, "Status nao tratado na inscricao $inscricao_id: {$dados['status']}");
        json_response(['status' => 'ok', 'message' => "Status: {$dados['status']}"]);
    }

    /**
     * Inscricao confirmada: envia email de confirmacao (sem PDF - decisao 8 do plano)
     */
    public static function processarInscricaoConfirmada(int $inscricao_id): void {
        require_once SRC_DIR . '/Services/BrevoService.php';
        require_once SRC_DIR . '/Services/PdfService.php';

        try {
            $i = db_fetch_one("
                SELECT i.id, i.pessoa_id, i.valor, i.data_pagamento, i.metodo,
                       p.nome, p.cpf,
                       ev.nome AS evento_nome, ev.assinantes, ev.email_contato, ev.imagem_path,
                       ec.nome AS categoria_nome,
                       (SELECT email FROM emails WHERE pessoa_id = p.id ORDER BY principal DESC, id DESC LIMIT 1) AS email
                FROM inscricoes i
                JOIN pessoas p ON p.id = i.pessoa_id
                JOIN eventos ev ON ev.id = i.evento_id
                LEFT JOIN evento_categorias ec ON ec.id = i.categoria_id
                WHERE i.id = ?
            ", [$inscricao_id]);

            if (!$i) {
                registrar_log('erro_confirmacao_inscricao', null, "Inscricao $inscricao_id nao encontrada");
                return;
            }
            if (empty($i['email'])) {
                registrar_log('erro_confirmacao_inscricao', (int)$i['pessoa_id'], "Inscricao $inscricao_id sem email cadastrado");
                return;
            }

            // Comprovante de inscricao em PDF. Se a geracao falhar, o email de
            // confirmacao sai mesmo assim — melhor sem anexo do que sem aviso.
            $pdf_bytes = null;
            try {
                $pdf_bytes = PdfService::gerarComprovanteInscricao([
                    'inscricao_id' => $inscricao_id,
                    'nome' => $i['nome'] ?? '',
                    'email' => $i['email'],
                    'cpf' => $i['cpf'] ?? '',
                    'evento' => $i['evento_nome'],
                    'categoria' => $i['categoria_nome'] ?? '',
                    'valor' => (int)$i['valor'],
                    'data_pagamento' => $i['data_pagamento'] ?? null,
                    'metodo' => $i['metodo'] ?? '',
                    'assinantes' => $i['assinantes'] ?? '',
                    'email_contato' => $i['email_contato'] ?? '',
                    'imagem_path' => $i['imagem_path'] ?? '',
                ]);
            } catch (Exception $e) {
                registrar_log('erro_comprovante_inscricao', (int)$i['pessoa_id'],
                    "Falha ao gerar PDF da inscricao $inscricao_id: " . $e->getMessage());
            }

            $enviado = BrevoService::enviarConfirmacaoInscricao(
                $i['email'],
                $i['nome'] ?? '',
                $i['evento_nome'],
                $i['categoria_nome'] ?? '',
                (int)$i['valor'],
                $pdf_bytes
            );

            if ($enviado) {
                registrar_log('email_confirmacao_inscricao', (int)$i['pessoa_id'],
                    "Confirmacao da inscricao $inscricao_id enviada para " . $i['email']);
            } else {
                registrar_log('erro_confirmacao_inscricao', (int)$i['pessoa_id'],
                    "Falha ao enviar confirmacao da inscricao $inscricao_id para " . $i['email']);
            }
        } catch (Exception $e) {
            registrar_log('erro_confirmacao_inscricao', null,
                "Erro ao confirmar inscricao $inscricao_id: " . $e->getMessage());
        }
    }
}
