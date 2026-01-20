<article>
    <h2>Filiacao <?= e($ano) ?></h2>
    <p>Para iniciar ou renovar sua filiacao ao <strong>Docomomo Brasil</strong>, informe seu email.</p>

    <form method="POST" action="/filiacao/<?= e($ano) ?>">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" placeholder="seu@email.com" required autofocus>

        <button type="submit">Continuar</button>
    </form>

    <p><small>Se voce ja possui cadastro, seus dados serao pre-preenchidos.</small></p>
</article>
