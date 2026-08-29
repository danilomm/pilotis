<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Buscar Cadastrados</h2>
        <div>
            <a href="/admin" role="button" class="outline">Painel</a>
            <a href="/admin/contatos" role="button" class="outline">Contatos</a>
        </div>
    </div>

    <form method="GET" action="/admin/buscar">
        <div class="grid">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Nome ou email..." autofocus>
            <button type="submit">Buscar</button>
        </div>
    </form>

    <?php if ($q && empty($resultados)): ?>
        <p>Nenhum resultado encontrado para "<?= e($q) ?>".</p>
    <?php elseif (!empty($resultados)): ?>
        <?php if (!empty($truncado)): ?>
            <p><small style="color: #8a6d1f;">
                Mostrando os 50 primeiros em ordem alfabética — há mais.
                Refine o termo para ver o resto.
            </small></p>
        <?php endif; ?>
        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Email</th>
                    <th>Filiações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resultados as $r): ?>
                    <tr>
                        <td>
                            <a href="/admin/pessoa/<?= e($r['id']) ?>">
                                <?= e($r['nome'] ?: '(sem nome)') ?>
                            </a>
                        </td>
                        <td><?= e($r['email']) ?></td>
                        <td><?= e($r['filiacoes'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</article>
