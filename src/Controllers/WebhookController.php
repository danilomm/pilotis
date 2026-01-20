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

        // Processa payload
        $dados = PagBankService::parseWebhookPayload($payload);

        if (!$dados['cadastrado_id'] || !$dados['ano']) {
            registrar_log('webhook_pagbank', null, "Reference ID invalido: " . $dados['reference_id']);
            json_response(['status' => 'ok', 'message' => 'Reference ID invalido']);
            return;
        }

        // Busca pagamento
        $pagamento = buscar_pagamento($dados['cadastrado_id'], $dados['ano']);

        if (!$pagamento) {
            registrar_log('webhook_pagbank', $dados['cadastrado_id'], "Pagamento nao encontrado para ano " . $dados['ano']);
            json_response(['status' => 'ok', 'message' => 'Pagamento nao encontrado']);
            return;
        }

        // Se ja esta pago, ignora (idempotente)
        if ($pagamento['status'] === 'pago') {
            registrar_log('webhook_pagbank', $dados['cadastrado_id'], "Pagamento ja confirmado anteriormente");
            json_response(['status' => 'ok', 'message' => 'Ja processado']);
            return;
        }

        // Atualiza status conforme retorno
        if ($dados['paid']) {
            db_execute("
                UPDATE pagamentos SET
                    status = 'pago',
                    data_pagamento = ?,
                    pagbank_order_id = ?,
                    pagbank_charge_id = ?
                WHERE id = ?
            ", [
                date('Y-m-d H:i:s'),
                $dados['order_id'],
                $dados['charge_id'],
                $pagamento['id']
            ]);

            registrar_log('pagamento_confirmado', $dados['cadastrado_id'], "Pagamento {$dados['ano']} confirmado via webhook");

            // Processa email e PDF
            self::processarPagamentoConfirmado($dados['cadastrado_id'], $dados['ano']);

            json_response(['status' => 'ok', 'message' => 'Pagamento confirmado']);
            return;

        } elseif (in_array($dados['status'], ['CANCELED', 'DECLINED'])) {
            db_execute("
                UPDATE pagamentos SET
                    status = 'cancelado',
                    pagbank_order_id = ?,
                    pagbank_charge_id = ?
                WHERE id = ?
            ", [$dados['order_id'], $dados['charge_id'], $pagamento['id']]);

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
    public static function processarPagamentoConfirmado(int $cadastrado_id, int $ano): void {
        require_once SRC_DIR . '/Services/PdfService.php';
        require_once SRC_DIR . '/Services/BrevoService.php';

        try {
            // Busca dados do cadastrado
            $cadastrado = db_fetch_one(
                "SELECT * FROM cadastrados WHERE id = ?",
                [$cadastrado_id]
            );

            if (!$cadastrado) {
                registrar_log('erro_confirmacao', $cadastrado_id, "Cadastrado nao encontrado");
                return;
            }

            // Busca dados do pagamento
            $pagamento = buscar_pagamento($cadastrado_id, $ano);

            if (!$pagamento) {
                registrar_log('erro_confirmacao', $cadastrado_id, "Pagamento nao encontrado");
                return;
            }

            $valor_centavos = (int)$pagamento['valor'];

            // Gera PDF da declaracao
            $pdf_bytes = PdfService::gerarDeclaracao(
                $cadastrado['nome'],
                $cadastrado['email'],
                $cadastrado['categoria'],
                $ano,
                $valor_centavos
            );

            // Envia email de confirmacao com PDF anexo
            $enviado = BrevoService::enviarConfirmacaoFiliacao(
                $cadastrado['email'],
                $cadastrado['nome'],
                $cadastrado['categoria'],
                $ano,
                $valor_centavos,
                $pdf_bytes
            );

            if ($enviado) {
                registrar_log('email_confirmacao_enviado', $cadastrado_id, "Email de confirmacao enviado para " . $cadastrado['email']);
            } else {
                registrar_log('erro_email_confirmacao', $cadastrado_id, "Falha ao enviar email para " . $cadastrado['email']);
            }

        } catch (Exception $e) {
            registrar_log('erro_confirmacao', $cadastrado_id, "Erro ao processar confirmacao: " . $e->getMessage());
        }
    }
}
