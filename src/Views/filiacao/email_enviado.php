<article>
    <div class="text-center">
        <h2>Verifique seu Email</h2>

        <?php if (!empty($erro_envio)): ?>
            <div style="background: #f8d7da; padding: 15px; border-radius: 8px; margin: 20px 0; color: #721c24;">
                <?= e($erro_envio) ?>
            </div>
        <?php endif; ?>

        <div style="background: #d4edda; padding: 30px; border-radius: 8px; margin: 20px 0;">
            <p style="font-size: 1.2em; margin-bottom: 0;">
                Enviamos um link de acesso para:
            </p>
            <p style="font-size: 1.3em; font-weight: bold; color: #4a8c4a;">
                <?= e($email) ?>
            </p>
        </div>

        <p>Por segurança, o formulário de filiação só pode ser acessado através do link enviado por email.</p>

        <p style="margin-top: 20px;">
            <strong>Não recebeu o email?</strong>
        </p>
        <ul style="list-style: none; padding: 0;">
            <li>Verifique sua caixa de spam</li>
            <li>Aguarde alguns minutos</li>
            <li>Confira se digitou o email corretamente</li>
        </ul>

        <p style="margin-top: 30px;">
            <a href="/filiacao/<?= e($ano) ?>" role="button" class="secondary">Tentar outro email</a>
        </p>

        <hr style="margin: 2rem 0;">

        <p style="color: #666;">
            <small>
                Se ainda assim não receber, entre em contato:<br>
                <strong>tesouraria.docomomobr@gmail.com</strong>
            </small>
        </p>

        <?php if (PAGBANK_SANDBOX): ?>
            <div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin-top: 20px; color: #856404;">
                <strong>Modo de teste:</strong><br>
                <a href="/filiacao/<?= e($ano) ?>/<?= e($token) ?>">Acessar formulário diretamente</a>
            </div>
        <?php endif; ?>
    </div>
</article>
