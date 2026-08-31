<?php
/**
 * Inscricao registrada, sem pagamento online.
 *
 * A tela existe para DIZER O MOTIVO. Quem chega aqui preencheu o formulario
 * inteiro e nao viu a tela de pagamento: sem explicacao, ela conclui que o
 * sistema quebrou e tenta de novo. Por isso o motivo vem antes de qualquer
 * outra coisa, e nomeia o responsavel — o meio de pagamento, nao o Docomomo.
 *
 * Nao pede acao nenhuma alem de esperar, e diz por qual email a resposta vem.
 */
?>
<article>
    <h2>Inscrição registrada</h2>

    <p>Sua inscrição no <?= e($evento['nome']) ?> está registrada
    na categoria <?= e($categoria['nome'] ?? '') ?><?php
        if ((int)($inscricao['valor'] ?? 0) > 0) {
            echo ', no valor de ' . formatar_valor((int)$inscricao['valor']);
        }
    ?>.</p>

    <div class="alert alert-warning">
        <p style="margin: 0 0 .6rem;">Não há pagamento online nesta inscrição, e não é falha do sistema.</p>
        <p style="margin: 0;">Você se identificou com
        <?= $identificacao !== '' ? e($identificacao) : 'documento estrangeiro' ?>,
        e o meio de pagamento que usamos aceita apenas CPF ou CNPJ brasileiro —
        é exigência dele, não do Docomomo. Por isso não aparecem aqui as opções de
        PIX, boleto e cartão: elas seriam recusadas.</p>
    </div>

    <h3>O que acontece agora</h3>
    <p>A tesouraria entra em contato
    <?php if (!empty($cadastrado['email'])): ?>
        pelo email <?= e($cadastrado['email']) ?>
    <?php endif; ?>
    para combinar como você paga. Quando o pagamento for lançado, você recebe a
    confirmação e o comprovante em PDF, por email.</p>

    <p>Não precisa fazer mais nada. Se preferir adiantar, ou se tiver dúvida,
    escreva para <a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a>.</p>

    <?php
    // Quem TEM CPF e caiu aqui por engano — digitou no lugar errado, ou o
    // cadastro estava vazio — precisa de um caminho de volta que nao dependa
    // de descobrir sozinho. O formulario aceita o CPF e o pagamento online
    // volta a existir.
    ?>
    <p style="margin-top: 1.6rem;"><small>Tem CPF brasileiro e chegou aqui por engano?
    <a href="/eventos/<?= e($evento['slug']) ?>/<?= e($cadastrado['token']) ?>">Volte ao formulário</a>
    e informe o CPF — aí o pagamento online fica disponível.</small></p>

    <p><small><a href="/eventos/<?= e($evento['slug']) ?>">&larr; Página do evento</a></small></p>
</article>
