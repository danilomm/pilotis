<?php
/**
 * Pilotis — criação de inscrição em evento.
 *
 * POR QUE ESTE ARQUIVO EXISTE (restrição 1 do ROADMAP, etapa 2):
 *
 * Hoje toda inscrição nasce de alguém clicando um link — o convite apenas cria
 * o registro em `enviado` e espera. A etapa 2 precisa que o admin e a
 * organização inscrevam DIRETO: é o que sustenta controle de presença e
 * certificado retroativo para quem apareceu sem se inscrever.
 *
 * Enquanto a criação vivia solta em quatro pontos (três no EventosController,
 * um no AdminController), a etapa 2 teria de duplicá-la ou refatorar sob
 * pressão, a duas semanas do evento. Aqui ela é uma função só, e a interface de
 * admin, quando vier, chama a mesma.
 *
 * O que este arquivo NÃO faz, de propósito: cobrança, email, comprovante. Criar
 * a inscrição e cobrar por ela são decisões separadas — a inscrição retroativa
 * da etapa 2 cria sem cobrar.
 */

/**
 * Garante que exista inscrição desta pessoa neste evento, e devolve-a.
 *
 * Idempotente: se já existir, devolve a que existe sem tocar no status — quem
 * já pagou não volta para `enviado` porque alguém reenviou um convite.
 *
 * $origem entra no log e é o que vai distinguir, na etapa 2, a inscrição feita
 * pela pessoa da feita pela organização na mesa de credenciamento.
 */
function criar_inscricao(
    int $pessoa_id,
    int $evento_id,
    string $status = 'enviado',
    string $origem = 'publico',
    ?int $categoria_id = null,
    ?int $valor = null,
    bool $atualizar_existente = false
): ?array {
    if ($pessoa_id <= 0 || $evento_id <= 0) {
        return null;
    }

    $existente = buscar_inscricao($pessoa_id, $evento_id);
    if ($existente) {
        // Por padrao devolve o que existe sem tocar em nada: e o que o fluxo
        // publico quer, e e o que impede um convite reenviado de rebaixar quem
        // ja pagou.
        //
        // Mas a mesa de credenciamento da etapa 2 precisa do oposto: a maioria
        // de quem chega JA TEM registro, criado pelo convite em 'enviado', e
        // confirmar essa pessoa e justamente atualizar a linha. Por isso o
        // $atualizar_existente — explicito, nunca por padrao, e **nunca** mexe
        // em quem ja esta pago ou confirmado.
        if (!$atualizar_existente) {
            return $existente;
        }

        if (in_array($existente['status'], ['pago', 'gratuita_confirmada'], true)) {
            return $existente;
        }

        db_execute("
            UPDATE inscricoes
            SET status = ?, status_at = datetime('now','localtime'),
                categoria_id = COALESCE(?, categoria_id),
                valor = COALESCE(?, valor)
            WHERE id = ?
        ", [$status, $categoria_id, $valor, (int)$existente['id']]);

        registrar_log('inscricao_atualizada', $pessoa_id,
            "Inscricao {$existente['id']} atualizada para status=$status (origem=$origem)");

        return buscar_inscricao($pessoa_id, $evento_id);
    }

    // UNIQUE(pessoa_id, evento_id) e INSERT OR IGNORE: duas requisições
    // simultâneas — a pessoa clicando duas vezes — não criam duas linhas.
    // Grava tambem o VALOR. Sem ele, a inscricao feita na mesa de credenciamento
    // sai com valor NULL, e a lista de inscritos e o comprovante saem sem valor.
    db_insert("
        INSERT OR IGNORE INTO inscricoes (pessoa_id, evento_id, categoria_id, valor, status, status_at)
        VALUES (?, ?, ?, ?, ?, datetime('now','localtime'))
    ", [$pessoa_id, $evento_id, $categoria_id, $valor, $status]);

    $inscricao = buscar_inscricao($pessoa_id, $evento_id);

    if ($inscricao) {
        registrar_log('inscricao_criada', $pessoa_id,
            "Inscricao {$inscricao['id']} criada no evento $evento_id"
            . " (status=$status, origem=$origem)");
    }

    return $inscricao;
}

/**
 * Marca presença. Coluna própria, NÃO status (restrição 2 do ROADMAP).
 *
 * Presença é ortogonal ao pagamento: dá para ter pago e não ter ido, e para ter
 * ido sem ter pago (inscrição tardia). Forçá-la no mesmo eixo dos status
 * quebraria as contagens do painel — as mesmas que foram corrigidas em
 * 29/08/2026, quando "Não pagos" somava três dos seis status e deixava 1308
 * filiações fora da conta.
 *
 * Fica pronta agora porque a coluna precisa existir antes de o evento começar;
 * a tela que a usa é da etapa 2.
 */
function marcar_presenca(int $inscricao_id, ?string $por = null): bool {
    $linhas = db_execute("
        UPDATE inscricoes
        SET presenca_em = datetime('now','localtime'), presenca_por = ?
        WHERE id = ? AND presenca_em IS NULL
    ", [$por, $inscricao_id]);

    if ($linhas > 0) {
        registrar_log('presenca_marcada', null,
            "Presenca na inscricao $inscricao_id" . ($por ? " por $por" : ''));
        return true;
    }
    return false;
}

/**
 * Desfaz a marcacao de presenca.
 *
 * POR QUE EXISTE: numa mesa de credenciamento com trezentas pessoas, alguem vai
 * marcar a linha errada. Sem esta funcao o desfazer seria SQL por FTP, num
 * sabado, com fila esperando. `marcar_presenca()` e irreversivel por desenho
 * (so age quando `presenca_em IS NULL`), e essa e a metade que faltava.
 *
 * Registra quem desfez, pelo mesmo motivo que registra quem marcou.
 */
function desmarcar_presenca(int $inscricao_id, ?string $por = null): bool {
    $linhas = db_execute("
        UPDATE inscricoes SET presenca_em = NULL, presenca_por = NULL
        WHERE id = ? AND presenca_em IS NOT NULL
    ", [$inscricao_id]);

    if ($linhas > 0) {
        registrar_log('presenca_desmarcada', null,
            "Presenca DESFEITA na inscricao $inscricao_id" . ($por ? " por $por" : ''));
        return true;
    }
    return false;
}

function tem_presenca(array $inscricao): bool {
    return !empty($inscricao['presenca_em']);
}
