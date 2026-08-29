<?php
/**
 * Verifica pagamentos pendentes consultando a API do PagBank.
 *
 * Rede de segurança: se o webhook falhar, este cron descobre
 * pagamentos PIX/Boleto que já foram pagos mas não notificados.
 *
 * Seguro para rodar N vezes: usa o mesmo UPDATE idempotente do webhook
 * (WHERE status != 'pago'), e processarPagamentoConfirmado() só envia
 * email se o UPDATE afetou linhas.
 *
 * Uso: GET /cron-verificar-pagamentos.php?token=CRON_TOKEN
 */

chdir(__DIR__);
require_once __DIR__ . "/src/config.php";

// Verifica token
$token_esperado = env("CRON_TOKEN", "");
if (empty($token_esperado)) {
    http_response_code(500);
    header("Content-Type: application/json");
    die(json_encode(["erro" => "CRON_TOKEN nao configurado no .env"]));
}

$token = $_GET["token"] ?? "";
if (!hash_equals($token_esperado, $token)) {
    http_response_code(403);
    header("Content-Type: application/json");
    die(json_encode(["erro" => "Acesso negado"]));
}

require_once __DIR__ . "/src/db.php";
require_once SRC_DIR . "/Services/PagBankService.php";
require_once SRC_DIR . "/Controllers/WebhookController.php";
require_once SRC_DIR . "/Services/LembreteService.php";

header("Content-Type: application/json; charset=utf-8");

try {
    // Busca todos os pedidos PagBank de filiações não pagas
    $pendentes = db_fetch_all("
        SELECT pp.id as pedido_id, pp.order_id, pp.metodo,
               f.id as filiacao_id, f.pessoa_id, f.ano, f.valor
        FROM pagbank_pedidos pp
        JOIN filiacoes f ON f.id = pp.filiacao_id
        -- 'cancelado' entra aqui de proposito: o webhook grava esse status em
        -- CANCELED e DECLINED, entao cartao recusado deixa a filiacao cancelada.
        -- Se a pessoa pagar por PIX depois e ESSE webhook se perder — que e a
        -- unica razao deste cron existir — a filiacao ficava fora da varredura
        -- para sempre: dinheiro na conta do Docomomo, e a pessoa sem declaracao,
        -- fora da lista publica e fora do arrecadado. O UPDATE abaixo ja
        -- descarta quem esta pago, entao incluir nao produz efeito indevido.
        -- 'nao_pago' entra pelo mesmo motivo que 'cancelado': e um status
        -- final que NAO impede o pagamento de entrar depois. Quem gerou PIX,
        -- deixou vencer e pagou o QR antigo fica com pedido no PagBank e
        -- filiacao marcada como nao paga. Sao 13 filiacoes nessa situacao na
        -- base de 28/08 — o caso Maisa, reproduzido pelo proprio sistema.
        WHERE f.status IN ('pendente', 'acesso', 'enviado', 'cancelado', 'nao_pago')
    ");

    $verificados = 0;
    $confirmados = 0;
    $erros = 0;

    foreach ($pendentes as $p) {
        $verificados++;

        try {
            $pedido = PagBankService::consultarPedido($p['order_id']);
            $charges = $pedido['charges'] ?? [];
            $status = !empty($charges) ? ($charges[0]['status'] ?? '') : '';

            if ($status === 'PAID') {
                $charge_id = $charges[0]['id'] ?? '';

                // Mesma conferencia do webhook: este cron confirma pagamento
                // pelo mesmo caminho, e sem ela a divergencia passaria pela via
                // de recuperacao, que e a menos observada das duas.
                $pago = (int)($charges[0]['amount']['value'] ?? 0);
                $devido = (int)($p['valor'] ?? 0);
                if ($pago > 0 && $devido > 0 && $pago !== $devido) {
                    registrar_log('valor_divergente', $p['pessoa_id'], "Filiacao {$p['filiacao_id']} ({$p['ano']}): devido "
                        . formatar_valor($devido) . ", pago " . formatar_valor($pago)
                        . ". order_id={$p['order_id']} (confirmado pelo cron).");
                }

                $rows = db_execute("
                    UPDATE filiacoes SET
                        status = 'pago',
                        data_pagamento = ?,
                        pagbank_order_id = ?,
                        pagbank_charge_id = ?,
                        status_at = ?
                    WHERE id = ? AND status != 'pago'
                ", [date('Y-m-d H:i:s'), $p['order_id'], $charge_id, date('Y-m-d H:i:s'), $p['filiacao_id']]);

                if ($rows > 0) {
                    $confirmados++;

                    // Cancela lembretes
                    LembreteService::cancelar($p['filiacao_id']);

                    // Envia email de confirmação com PDF
                    WebhookController::processarPagamentoConfirmado($p['pessoa_id'], $p['ano']);

                    registrar_log('pagamento_confirmado_cron', $p['pessoa_id'],
                        "Pagamento {$p['ano']} confirmado via cron (webhook falhou). Order: {$p['order_id']}"
                    );
                }
            }
        } catch (Exception $e) {
            $erros++;
            registrar_log('erro_verificacao_pagamento', $p['pessoa_id'],
                "Erro ao verificar pedido {$p['order_id']}: " . $e->getMessage()
            );
        }
    }

    // Rede de seguranca tambem para inscricoes em eventos.
    // (a consulta acima usa JOIN filiacoes, que ja exclui pedidos de inscricao,
    //  cujo filiacao_id e NULL)
    $pendentes_evt = db_fetch_all("
        SELECT pp.order_id, i.id as inscricao_id, i.pessoa_id, i.valor, ev.nome as evento_nome
        FROM pagbank_pedidos pp
        JOIN inscricoes i ON i.id = pp.inscricao_id
        JOIN eventos ev ON ev.id = i.evento_id
        -- Mesmo motivo do bloco de filiacoes acima.
        WHERE i.status IN ('pendente', 'acesso', 'enviado', 'cancelado', 'nao_pago')
    ");

    foreach ($pendentes_evt as $p) {
        $verificados++;

        try {
            $pedido = PagBankService::consultarPedido($p['order_id']);
            $charges = $pedido['charges'] ?? [];
            $status = !empty($charges) ? ($charges[0]['status'] ?? '') : '';

            if ($status === 'PAID') {
                $charge_id = $charges[0]['id'] ?? '';

                // Mesma conferencia do webhook: este cron confirma pagamento
                // pelo mesmo caminho, e sem ela a divergencia passaria pela via
                // de recuperacao, que e a menos observada das duas.
                $pago = (int)($charges[0]['amount']['value'] ?? 0);
                $devido = (int)($p['valor'] ?? 0);
                if ($pago > 0 && $devido > 0 && $pago !== $devido) {
                    registrar_log('valor_divergente', $p['pessoa_id'], "Inscricao {$p['inscricao_id']}: devido "
                        . formatar_valor($devido) . ", pago " . formatar_valor($pago)
                        . ". order_id={$p['order_id']} (confirmado pelo cron).");
                }

                $rows = db_execute("
                    UPDATE inscricoes SET
                        status = 'pago',
                        data_pagamento = ?,
                        pagbank_order_id = ?,
                        pagbank_charge_id = ?,
                        status_at = ?
                    WHERE id = ? AND status NOT IN ('pago', 'gratuita_confirmada')
                ", [date('Y-m-d H:i:s'), $p['order_id'], $charge_id, date('Y-m-d H:i:s'), $p['inscricao_id']]);

                if ($rows > 0) {
                    $confirmados++;

                    LembreteService::cancelarInscricao((int)$p['inscricao_id']);
                    WebhookController::processarInscricaoConfirmada((int)$p['inscricao_id']);

                    registrar_log('inscricao_paga_cron', $p['pessoa_id'],
                        "Inscricao {$p['inscricao_id']} ({$p['evento_nome']}) confirmada via cron. Order: {$p['order_id']}"
                    );
                }
            }
        } catch (Exception $e) {
            $erros++;
            registrar_log('erro_verificacao_pagamento', $p['pessoa_id'],
                "Erro ao verificar pedido de inscricao {$p['order_id']}: " . $e->getMessage()
            );
        }
    }

    $resposta = [
        "ok" => true,
        "verificados" => $verificados,
        "confirmados" => $confirmados,
        "erros" => $erros,
        "timestamp" => date("c"),
    ];

    if ($verificados > 0) {
        registrar_log("cron_verificar_pagamentos", null,
            "Verificacao: $verificados consultados, $confirmados confirmados, $erros erros"
        );
    }

    echo json_encode($resposta, JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "erro" => $e->getMessage(),
        "timestamp" => date("c"),
    ]);
}
