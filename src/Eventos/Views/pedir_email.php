<article>
    <h2><?= e($evento['nome']) ?></h2>

    <div class="alert alert-warning" style="padding: 12px; background: #fff3cd; color: #856404; border-radius: 6px;">
        Não encontramos esse CPF no cadastro do Docomomo Brasil.
    </div>

    <p>Sem problema — você pode se inscrever mesmo assim. Informe seu email e
    enviaremos um link para completar a inscrição.</p>

    <p>Se você já foi filiado(a) em anos anteriores, dê preferência ao email do
    seu último cadastro. Assim reaproveitamos seus dados e evitamos criar um
    cadastro duplicado.</p>

    <form method="POST" action="/eventos/<?= e($evento['slug']) ?>/inscrever"><?= campo_csrf() ?>
        <input type="hidden" name="cpf_pendente" value="<?= e($cpf_pendente ?? '') ?>">

        <label for="email">Email</label>
        <input type="email" id="email" name="email" placeholder="seu@email.com" required autofocus>

        <button type="submit">Continuar</button>
    </form>

    <?php
    // "Volte" e LINK, e aponta para a tela do CPF — nao para a pagina do evento.
    // Quem chega aqui provavelmente errou um digito, e o caminho de volta tem
    // de ser o campo onde ele digitou, num clique. Ate 31/08/2026 o texto dizia
    // "confira o CPF digitado" sem dizer como, e o unico "Voltar" da tela ficava
    // no rodape e levava a pagina do evento: a pessoa tinha de achar o botao de
    // inscricao de novo.
    ?>
    <p><small>
        Se você é filiado(a) adimplente neste ano e acha que houve engano,
        <a href="/eventos/<?= e($evento['slug']) ?>/inscricao">volte e confira o CPF digitado</a>,
        ou escreva para <a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a>.
    </small></p>

    <p><small><a href="/eventos/<?= e($evento['slug']) ?>">&larr; Página do evento</a></small></p>
</article>
