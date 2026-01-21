<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Todos os Contatos</h2>
        <div>
            <a href="/admin" role="button" class="outline">Painel</a>
            <a href="/admin/buscar" role="button" class="outline">Buscar</a>
            <a href="/admin/novo" role="button" class="outline">+ Novo</a>
            <a href="/admin/logout" role="button" class="secondary outline">Sair</a>
        </div>
    </div>

    <p>Total: <strong><?= count($contatos) ?></strong> contatos</p>

    <?php if (empty($contatos)): ?>
        <p>Nenhum contato cadastrado.</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th><a href="?ordem=nome" style="text-decoration: none;">Nome <?= ($ordem ?? 'nome') === 'nome' ? '▼' : '' ?></a></th>
                        <th>Email</th>
                        <th style="white-space: nowrap;"><a href="?ordem=ultima" style="text-decoration: none;">Última Filiação <?= ($ordem ?? '') === 'ultima' ? '▼' : '' ?></a></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contatos as $c): ?>
                        <tr>
                            <td>
                                <a href="/admin/pessoa/<?= e($c['id']) ?>">
                                    <?= e($c['nome'] ?: '(sem nome)') ?>
                                </a>
                            </td>
                            <td><?= e($c['email'] ?? '-') ?></td>
                            <td style="white-space: nowrap;">
                                <?php if ($c['ultima_filiacao']): ?>
                                    <?= e($c['ultima_filiacao']) ?>
                                <?php else: ?>
                                    <span style="color: #999;">nunca</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</article>
