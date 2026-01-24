<article>
    <h2>Filiados <?= e(ORG_NOME) ?> - <?= e($ano) ?></h2>

    <p>Total: <strong><?= e($total) ?></strong> filiado(s) adimplente(s)</p>

    <?php if ($total === 0): ?>
        <p>Nenhum filiado adimplente para <?= e($ano) ?> ainda.</p>
    <?php else: ?>

        <?php if (!empty($por_categoria['profissional_internacional'])): ?>
            <h3>Filiacao Plena Internacional + Brasil</h3>
            <ul>
                <?php foreach ($por_categoria['profissional_internacional'] as $f): ?>
                    <li>
                        <?= e($f['nome']) ?>
                        <?php if (!empty($f['instituicao'])): ?>
                            <small>(<?= e($f['instituicao']) ?>)</small>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (!empty($por_categoria['profissional_nacional'])): ?>
            <h3>Filiacao Plena Brasil</h3>
            <ul>
                <?php foreach ($por_categoria['profissional_nacional'] as $f): ?>
                    <li>
                        <?= e($f['nome']) ?>
                        <?php if (!empty($f['instituicao'])): ?>
                            <small>(<?= e($f['instituicao']) ?>)</small>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if (!empty($por_categoria['estudante'])): ?>
            <h3>Filiacao Estudante</h3>
            <ul>
                <?php foreach ($por_categoria['estudante'] as $f): ?>
                    <li>
                        <?= e($f['nome']) ?>
                        <?php if (!empty($f['instituicao'])): ?>
                            <small>(<?= e($f['instituicao']) ?>)</small>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

    <?php endif; ?>

    <hr>
    <p class="text-center">
        <a href="/filiacao/<?= e($ano) ?>">Filiar-se</a>
    </p>
    <?php if ($ultima_atualizacao): ?>
        <p class="text-center" style="color: #999; font-size: 0.8rem;">
            Atualizado em <?= (new DateTime($ultima_atualizacao, new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y \à\s H:i') ?>
        </p>
    <?php endif; ?>
</article>
