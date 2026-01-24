<article>
    <h2>Filiação <?= e($ano) ?></h2>
    <p>Para iniciar ou renovar sua filiação ao <strong><?= e(ORG_NOME) ?></strong>, informe seu email.</p>

    <form method="POST" action="/filiacao/<?= e($ano) ?>">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" placeholder="seu@email.com" required autofocus>

        <button type="submit">Continuar</button>
    </form>

    <p><small>Enviaremos um link de acesso para seu email. Se você já possui cadastro, seus dados serão pré-preenchidos.</small></p>
</article>
