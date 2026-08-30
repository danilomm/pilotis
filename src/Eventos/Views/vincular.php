<article>
    <h2>Vincular cadastro</h2>

    <p>Encontramos um cadastro anterior no nosso sistema que pode ser seu.</p>

    <div style="background: #fff8e1; padding: 16px; border-radius: 8px; margin: 16px 0; border-left: 4px solid #f9a825;">
        <p style="margin: 0 0 8px 0;">
            <strong>Nome:</strong> <?= e($antigo['nome']) ?>
        </p>
        <?php if ($email_mascarado): ?>
        <p style="margin: 0 0 8px 0;">
            <strong>Email cadastrado:</strong> <?= e($email_mascarado) ?>
        </p>
        <?php endif; ?>
        <?php if (!empty($ultima_paga['ano'])): ?>
        <p style="margin: 0;">
            <strong>Última filiação paga:</strong> <?= e($ultima_paga['ano']) ?>
        </p>
        <?php endif; ?>
    </div>

    <p>O que você prefere fazer?</p>

    <form method="POST" action="/eventos/<?= e($evento['slug']) ?>/<?= e($token) ?>/vincular" style="display: flex; flex-direction: column; gap: 12px;"><?= campo_csrf() ?>
        <input type="hidden" name="match" value="<?= e($antigo['id']) ?>">
        <input type="hidden" name="sig" value="<?= e($sig) ?>">

        <button type="submit" name="decisao" value="sim" style="background: #28a745; color: white; padding: 12px;">
            <strong>Sim, é meu cadastro</strong><br>
            <small>Enviaremos um link de confirmação ao email desse cadastro</small>
        </button>

        <button type="submit" name="decisao" value="nao" style="background: #6c757d; color: white; padding: 12px;">
            <strong>Não, é outra pessoa</strong><br>
            <small>Seguir com cadastro separado</small>
        </button>
    </form>

    <p style="margin-top: 1rem;"><small>
        A unificação só acontece depois que alguém abrir o link enviado ao email
        do cadastro antigo. É como confirmamos que o cadastro é mesmo seu —
        nome e email aparecem em páginas públicas, e sozinhos não provam nada.
    </small></p>

    <p><small>Em caso de dúvida, entre em contato com a tesouraria: <a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a></small></p>
</article>
