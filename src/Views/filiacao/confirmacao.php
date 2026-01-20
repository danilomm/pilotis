<article>
    <div class="text-center">
        <h2 style="color: #4a8c4a;">Filiacao Confirmada!</h2>

        <p style="font-size: 1.2em;"><?= e($mensagem ?? 'Sua filiacao esta confirmada.') ?></p>

        <div style="background: #d4edda; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <p><strong>Nome:</strong> <?= e($cadastrado['nome'] ?? '') ?></p>
            <p><strong>Ano:</strong> <?= e($ano) ?></p>
        </div>

        <p>Voce recebera um email de confirmacao com sua declaracao de filiacao em anexo.</p>

        <p style="margin-top: 30px;">
            <a href="/filiados/<?= e($ano) ?>" role="button">Ver Lista de Filiados</a>
        </p>
    </div>
</article>
