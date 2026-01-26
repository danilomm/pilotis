<article>
    <h2>Filiação <?= e($ano) ?></h2>

    <p><strong>Prezado(a) associado(a),</strong></p>

    <p>Pedimos desculpas pelo inconveniente.</p>

    <p>O email de campanha de filiação <?= e($ano) ?> foi enviado acidentalmente enquanto ainda estávamos configurando o novo sistema de gestão de filiados.</p>

    <p>Neste momento, aguardamos a homologação do sistema de pagamentos pelo PagSeguro, o que deve ser concluído no <strong>início de fevereiro</strong>.</p>

    <p>Assim que tudo estiver funcionando, você receberá um novo email com o link correto para realizar sua filiação.</p>

    <p>Agradecemos a compreensão!</p>

    <p>
        <strong>Tesouraria <?= e(ORG_NOME) ?></strong><br>
        <a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a>
    </p>
</article>
