<article>
    <h2><?= e($evento['nome']) ?></h2>

    <div class="alert alert-warning" style="padding: 12px; background: #fff3cd; color: #856404; border-radius: 6px;">
        As inscrições para este evento estão encerradas.
    </div>

    <?php if ($evento['prazo_inscricao']): ?>
        <p>O prazo de inscrição terminou em <strong><?= date('d/m/Y', strtotime($evento['prazo_inscricao'])) ?></strong>.</p>
    <?php endif; ?>

    <p>Dúvidas? Escreva para <a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a>.</p>

    <p><a href="/eventos" role="button" class="outline">Ver outros eventos</a></p>
</article>
