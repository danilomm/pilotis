<article>
    <h2>Filiação <?= e($ano) ?></h2>

    <p>O <strong><?= e(ORG_NOME) ?></strong> é uma organização dedicada à documentação e conservação do patrimônio moderno — arquitetura, urbanismo e paisagismo.</p>

    <p><strong>Junte-se a nós!</strong> A filiação é anual e garante:</p>
    <ul>
        <li>Descontos em eventos do <?= e(ORG_NOME) ?> e núcleos regionais</li>
        <li>Acesso à rede de profissionais e pesquisadores</li>
        <li>Participação nas atividades, publicações e assembleias</li>
        <li>Para a categoria internacional: Docomomo Journal, Member Card e descontos em museus</li>
    </ul>

    <hr>

    <h3>Iniciar filiação</h3>
    <p>Para iniciar ou renovar sua filiação, informe seu email abaixo.</p>

    <form method="POST" action="/filiacao/<?= e($ano) ?>"><?= campo_csrf() ?>
        <label for="email">Email</label>
        <input type="email" id="email" name="email" placeholder="seu@email.com" required autofocus>

        <button type="submit">Continuar</button>
    </form>

    <p><small>Enviaremos um link de acesso para seu email. Se você já possui cadastro, seus dados serão pré-preenchidos.</small></p>

    <hr>

    <h3>Categorias e valores</h3>
    <table>
        <thead>
            <tr><th>Categoria</th><th>Valor</th></tr>
        </thead>
        <tbody>
            <?php foreach (CATEGORIAS_FILIACAO as $cat_key => $cat_info):
                if (isset($campanha) && categoria_expirada($campanha, $cat_key)) continue;
            ?>
            <tr>
                <td><?= e($cat_info['nome']) ?></td>
                <td><?= formatar_valor($cat_info['valor']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if (!empty($campanha['data_fim_internacional']) || !empty($campanha['data_fim'])): ?>
    <p><strong>Prazos:</strong>
        <?php
        $prazos = [];
        if (!empty($campanha['data_fim_internacional'])) {
            $prazos[] = 'Internacional até ' . date('d/m/Y', strtotime($campanha['data_fim_internacional']));
        }
        if (!empty($campanha['data_fim'])) {
            $prazos[] = 'Nacional e Estudante até ' . date('d/m/Y', strtotime($campanha['data_fim']));
        }
        echo implode(' | ', $prazos);
        ?>
    </p>
    <?php endif; ?>

    <p>O pagamento pode ser feito por <strong>PIX</strong>, <strong>boleto bancário</strong> ou <strong>cartão de crédito</strong>.</p>
</article>
