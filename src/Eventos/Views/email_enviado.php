<article>
    <h2>Verifique seu email</h2>

    <?php if (!empty($erro_envio)): ?>
        <div class="alert alert-error" style="padding: 12px; background: #f8d7da; color: #721c24; border-radius: 6px;">
            <?= e($erro_envio) ?>
        </div>
        <p><a href="/eventos/<?= e($evento['slug']) ?>" role="button" class="outline">Voltar e tentar novamente</a></p>
    <?php else: ?>
        <div class="alert alert-success" style="padding: 12px; background: #d4edda; color: #155724; border-radius: 6px;">
            Enviamos um link de inscrição para <strong><?= e($email_exibicao ?? $email) ?></strong>.
        </div>
        <?php
        // O rotulo aqui tem de ser IGUAL ao botao do template `evento_acesso`.
        // Divergindo, a pessoa abre o email procurando um botao que nao existe.
        ?>
        <p>Abra o email e clique em “Preencher minha inscrição” para continuar.</p>
        <p><small>Não recebeu? Confira a pasta de spam. O email chega em poucos minutos.</small></p>
    <?php endif; ?>
</article>
