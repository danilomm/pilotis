<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Buscar Cadastrados</h2>
        <a href="/admin" role="button" class="outline">Voltar</a>
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
        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Email</th>
                    <th>Categoria</th>
                    <th>Pagamentos</th>
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
                        <td><?= e(CATEGORIAS_DISPLAY[$r['categoria'] ?? ''] ?? $r['categoria'] ?? '-') ?></td>
                        <td><?= e($r['pagamentos'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</article>
