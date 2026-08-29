<article>
    <h2>Filiação <?= e(ORG_NOME) ?></h2>

    <p>O <strong><?= e(ORG_NOME) ?></strong> é a associação dedicada à documentação e conservação do patrimônio moderno — arquitetura, urbanismo, design e paisagismo.</p>

    <p>Confira a lista dos filiados 2026 em <a href="https://docomomobrasil.com/filiados-2026/">docomomobrasil.com/filiados-2026</a>.</p>

    <h3>Quando a filiação está aberta</h3>

    <p>As filiações são realizadas em <strong>um período determinado no primeiro semestre de cada ano</strong>. Quando o período estiver aberto, você poderá renovar sua filiação nesta página informando seu email.</p>

    <p>A próxima campanha de filiação será no primeiro semestre de 2027.</p>

    <h3>Categorias de filiação</h3>

    <ul>
        <li><strong>Filiação Plena <?= e(ORG_SIGLA) ?> Internacional+Brasil</strong> — inclui Docomomo Journal, Member Card e descontos em museus parceiros.</li>
        <li><strong>Filiação Plena <?= e(ORG_SIGLA) ?> Brasil</strong>.</li>
        <li><strong>Filiação Estudante <?= e(ORG_SIGLA) ?> Brasil</strong> — para estudantes de graduação e pós-graduação (com comprovante de matrícula).</li>
    </ul>

    <p><em>Valores de 2026: R$ 480 (Internacional+Brasil), R$ 240 (Brasil) e R$ 120 (Estudante). Sujeitos a reajuste para 2027.</em></p>

    <h3>Benefícios da filiação</h3>

    <ul>
        <li>Descontos em eventos do <?= e(ORG_NOME) ?> e núcleos regionais</li>
        <li>Acesso à rede de profissionais e pesquisadores</li>
        <li>Participação nas atividades, publicações e assembleias</li>
    </ul>

    <hr>

    <p>
        <strong>Tesouraria <?= e(ORG_NOME) ?></strong><br>
        <?php
        // Ofusca o email contra coletores simples: caracteres viram entities numericas (&#NN;).
        // Bots que so fazem regex no HTML cru nao reconhecem o @; humanos veem e clicam normal.
        $contato = ORG_EMAIL_CONTATO;
        $href = 'mailto:' . $contato;
        $ofuscar = function (string $s): string {
            $out = '';
            for ($i = 0; $i < strlen($s); $i++) $out .= '&#' . ord($s[$i]) . ';';
            return $out;
        };
        ?>
        <a href="<?= $ofuscar($href) ?>"><?= $ofuscar($contato) ?></a>
    </p>
</article>
