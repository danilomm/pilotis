<article style="max-width: 400px; margin: 0 auto;">
    <h2>Admin - Login</h2>

    <?php if ($erro): ?>
        <div class="alert alert-error"><?= e($erro) ?></div>
    <?php endif; ?>

    <form method="POST" action="/admin/login">
        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" required autofocus>

        <button type="submit">Entrar</button>
    </form>
</article>
