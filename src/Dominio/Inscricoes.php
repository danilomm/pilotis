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
    ?int $categoria_id = null
): ?array {
    if ($pessoa_id <= 0 || $evento_id <= 0) {
        return null;
    }

    $existente = buscar_inscricao($pessoa_id, $evento_id);
    if ($existente) {
        return $existente;
    }

    // UNIQUE(pessoa_id, evento_id) e INSERT OR IGNORE: duas requisições
    // simultâneas — a pessoa clicando duas vezes — não criam duas linhas.
    db_insert("
        INSERT OR IGNORE INTO inscricoes (pessoa_id, evento_id, categoria_id, status, status_at)
        VALUES (?, ?, ?, ?, datetime('now','localtime'))
    ", [$pessoa_id, $evento_id, $categoria_id, $status]);

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
        UPDATE inscricoes SET presenca_em = datetime('now','localtime')
        WHERE id = ? AND presenca_em IS NULL
    ", [$inscricao_id]);

    if ($linhas > 0) {
        registrar_log('presenca_marcada', null,
            "Presenca na inscricao $inscricao_id" . ($por ? " por $por" : ''));
        return true;
    }
    return false;
}

function tem_presenca(array $inscricao): bool {
    return !empty($inscricao['presenca_em']);
}
