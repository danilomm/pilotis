<article>
    <p style="margin-bottom: .4rem;">
        <a href="/eventos/<?= e($evento['slug']) ?>">&larr; <?= e($evento['nome']) ?></a>
    </p>

    <h2>Inscrição</h2>

    <?php if ($evento['data_inicio']): ?>
        <p><small><?= data_por_extenso($evento['data_inicio'], $evento['data_fim']) ?><?php
            if (!empty($evento['local'])) {
                // So a primeira linha do endereco: aqui ela e referencia, nao
                // instrucao de como chegar — o endereco inteiro esta na pagina.
                $primeira = trim(strtok((string)$evento['local'], "\n"));
                echo ' · ' . e($primeira);
            }
        ?></small></p>
    <?php endif; ?>

    <p>Informe seu CPF. Se você já tem cadastro no Docomomo Brasil,
    enviaremos o link para o email do seu cadastro — não precisa lembrar
    qual você usou.</p>

    <form method="POST" action="/eventos/<?= e($evento['slug']) ?>/inscrever"><?= campo_csrf() ?>
        <label for="cpf">CPF</label>
        <input type="text" id="cpf" name="cpf" placeholder="000.000.000-00"
               inputmode="numeric" autocomplete="off" required>
        <small>Digite apenas os números.</small>
        <?php
        // O aviso fica AQUI, colado no campo, e nao num rodape.
        //
        // Esta e a primeira tela e ja pede CPF — dado que identifica a pessoa
        // na Receita. Avisar depois, no formulario, seria avisar tarde: o
        // numero ja teria sido digitado. Duas linhas, com o essencial dito no
        // proprio texto (para que serve, e que nao e publicado) e o resto a um
        // clique.
        ?>
        <small style="display: block; margin: -.2rem 0 .8rem; color: var(--pico-muted-color);">
            O CPF é usado para encontrar seu cadastro e é exigido pelo meio de
            pagamento. Não é publicado.
            <a href="/privacidade" target="_blank" rel="noopener">Como seus dados são tratados</a>.
        </small>
        <button type="submit">Continuar</button>
    </form>

    <?php
    // "Nao tenho CPF brasileiro" — e um caminho de verdade desde 30/08/2026.
    //
    // Ate entao era caminho fechado: a pessoa recebia o link, preenchia nome,
    // endereco, telefone e pais, escolhia a categoria e levava "CPF é
    // obrigatório para inscrição paga". Agora o formulario aceita passaporte
    // (ou RNM, DNI...) no lugar do CPF, a inscricao fica registrada como
    // pendente, aparece na lista da organizacao, e a tesouraria combina o
    // pagamento por fora — porque o PagBank exige CPF ou CNPJ, e essa
    // exigencia e dele, nao do Docomomo.
    ?>
    <details style="margin-top: 12px;">
        <summary style="cursor: pointer;"><small>Não tenho CPF brasileiro</small></summary>
        <p style="margin-top: 10px;"><small>Informe seu email para continuar. No
        formulário você poderá usar passaporte ou outro documento no lugar do CPF.
        Se a inscrição for paga, a tesouraria entra em contato para combinar o
        pagamento — o meio de pagamento online só aceita CPF.</small></p>
        <form method="POST" action="/eventos/<?= e($evento['slug']) ?>/inscrever"><?= campo_csrf() ?>
            <label for="email">Email</label>
            <input type="email" id="email" name="email" placeholder="seu@email.com" required>
            <button type="submit" class="secondary">Continuar por email</button>
        </form>
    </details>

    <?php if ($evento['prazo_inscricao']): ?>
        <p style="margin-top: 1.2rem;"><small>Inscrições até
        <?= date('d/m/Y', strtotime($evento['prazo_inscricao'])) ?>.</small></p>
    <?php endif; ?>

    <p><small>O desconto de filiado depende de anuidade em dia.
    <a href="/eventos/<?= e($evento['slug']) ?>">Ver categorias e valores</a>.</small></p>

    <script>
    // Formatacao do CPF conforme digita
    document.getElementById('cpf').addEventListener('input', function (e) {
        let v = e.target.value.replace(/\D/g, '').slice(0, 11);
        if (v.length > 9)      v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, '$1.$2.$3-$4');
        else if (v.length > 6) v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, '$1.$2.$3');
        else if (v.length > 3) v = v.replace(/(\d{3})(\d{1,3})/, '$1.$2');
        e.target.value = v;
    });
    </script>
</article>
