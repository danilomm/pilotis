<article>
    <h2>Filiação <?= e($ano) ?></h2>

    <p><strong>Prezado(a) associado(a),</strong></p>

    <p>Pedimos desculpas pelo inconveniente.</p>

    <p>O email de campanha de filiação <?= e($ano) ?> foi enviado acidentalmente enquanto ainda estávamos configurando o novo sistema de gestão de filiados. Alguns associados receberam o email mais de uma vez, nos dias 25 e 26 de janeiro. Pedimos desculpas pela repetição — o problema já foi corrigido e não vai acontecer novamente.</p>

    <p>Neste momento, aguardamos a homologação do sistema de pagamentos pelo PagSeguro, o que deve ser concluído no <strong>início de fevereiro</strong>.</p>

    <p>Assim que tudo estiver funcionando, você receberá um novo email com o link correto para realizar sua filiação.</p>

    <p>Agradecemos a compreensão!</p>

    <p>
        <strong>Tesouraria <?= e(ORG_NOME) ?></strong><br>
        <a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a>
    </p>
</article>
