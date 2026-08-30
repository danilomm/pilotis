<?php
/**
 * Entrada do painel da organização: pede o email autorizado.
 *
 * A resposta é a mesma para email autorizado e não autorizado — quem tenta
 * não descobre quem faz parte da organização do evento.
 *
 * @var array $evento
 * @var bool  $enviado
 */
?>

<article>
    <h2>Acompanhamento das inscrições</h2>
    <p><?= e($evento['nome']) ?></p>

    <?php if (!empty($enviado)): ?>

        <div class="alert alert-success" style="padding: 12px; background: #d4edda; color: #155724; border-radius: 6px;">
            Se este email estiver na lista da organização do evento, o link de acesso acaba de ser enviado
            para ele. O link vale por 30 minutos.
        </div>

        <p><small>Não chegou? Confira a caixa de spam, ou
        <a href="/eventos/<?= e($evento['slug']) ?>/organizacao">tente outro endereço</a>.
        Se você faz parte da organização e nenhum dos seus emails funciona, escreva para
        <a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a>.</small></p>

    <?php else: ?>

        <p>Esta área é da organização do evento. Informe seu email para receber o link de acesso.</p>

        <form method="POST" action="/eventos/<?= e($evento['slug']) ?>/organizacao"><?= campo_csrf() ?>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" placeholder="seu@email.com" required autofocus>
            <button type="submit">Receber link de acesso</button>
        </form>

        <p><small>O acesso é por email cadastrado, e não por senha: assim ninguém precisa guardar
        segredo nenhum, e o acesso de cada pessoa pode ser retirado sem afetar os demais.</small></p>

    <?php endif; ?>
</article>
