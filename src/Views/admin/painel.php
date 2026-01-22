<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Painel Admin - <?= e($ano) ?></h2>
        <div>
            <a href="/admin/campanha" role="button" class="outline">Campanha</a>
            <a href="/admin/contatos" role="button" class="outline">Contatos</a>
            <a href="/admin/buscar" role="button" class="outline">Buscar</a>
            <a href="/admin/novo" role="button" class="outline">+ Novo</a>
            <a href="/admin/logout" role="button" class="secondary outline">Sair</a>
        </div>
    </div>

    <!-- Filtros -->
    <form method="GET" style="margin-bottom: 20px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
        <div>
            <label for="ano">Ano:</label>
            <select name="ano" id="ano" onchange="this.form.submit()" style="width: auto; display: inline-block;">
                <?php foreach ($anos_disponiveis as $a): ?>
                    <option value="<?= $a ?>" <?= $a == $ano ? 'selected' : '' ?>><?= $a ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="status">Status:</label>
            <select name="status" id="status" onchange="this.form.submit()" style="width: auto; display: inline-block;">
                <option value="" <?= empty($status) ? 'selected' : '' ?>>Todos</option>
                <option value="pago" <?= ($status ?? '') === 'pago' ? 'selected' : '' ?>>Pago</option>
                <option value="pendente" <?= ($status ?? '') === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                <option value="enviado" <?= ($status ?? '') === 'enviado' ? 'selected' : '' ?>>Enviado</option>
                <option value="acesso" <?= ($status ?? '') === 'acesso' ? 'selected' : '' ?>>Acesso</option>
            </select>
        </div>
        <input type="hidden" name="ordem" value="<?= e($ordem ?? 'data') ?>">
    </form>

    <!-- Estatisticas -->
    <div class="grid">
        <div style="background: #e9ecef; padding: 15px; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0; color: #495057;"><?= (int)($stats['total'] ?? 0) ?></h3>
            <small>Total</small>
        </div>
        <div style="background: #d4edda; padding: 15px; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0; color: #155724;"><?= (int)($stats['pagos'] ?? 0) ?></h3>
            <small>Pagos</small>
        </div>
        <div style="background: #fff3cd; padding: 15px; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0; color: #856404;"><?= (int)($stats['pendentes'] ?? 0) ?></h3>
            <small>Não pagos</small>
        </div>
        <div style="background: #d4edda; padding: 15px; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0; color: #155724;"><?= formatar_valor((int)($stats['arrecadado'] ?? 0)) ?></h3>
            <small>Arrecadado Bruto</small>
        </div>
    </div>

    <!-- Downloads -->
    <div style="margin: 20px 0;">
        <a href="/admin/download/csv?ano=<?= e($ano) ?>">Exportar CSV</a> |
        <a href="/admin/download/banco">Baixar Banco (.db)</a>
    </div>

    <!-- Lista de filiações -->
    <h3>Filiações <?= e($ano) ?></h3>

    <?php if (empty($pagamentos)): ?>
        <p>Nenhuma filiação encontrada para <?= e($ano) ?>.</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th><a href="?ano=<?= e($ano) ?>&status=<?= e($status ?? '') ?>&ordem=nome" style="text-decoration: none;">Nome <?= ($ordem ?? '') === 'nome' ? '▼' : '' ?></a></th>
                        <th>Email</th>
                        <th><a href="?ano=<?= e($ano) ?>&status=<?= e($status ?? '') ?>&ordem=categoria" style="text-decoration: none;">Categoria <?= ($ordem ?? '') === 'categoria' ? '▼' : '' ?></a></th>
                        <th style="white-space: nowrap;">Valor</th>
                        <th style="white-space: nowrap;"><a href="?ano=<?= e($ano) ?>&status=<?= e($status ?? '') ?>&ordem=status" style="text-decoration: none;">Status <?= ($ordem ?? '') === 'status' ? '▼' : '' ?></a></th>
                        <th style="white-space: nowrap;">Método</th>
                        <th style="white-space: nowrap;"><a href="?ano=<?= e($ano) ?>&status=<?= e($status ?? '') ?>&ordem=data" style="text-decoration: none;">Data <?= ($ordem ?? 'data') === 'data' ? '▼' : '' ?></a></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagamentos as $p): ?>
                        <tr>
                            <td>
                                <a href="/admin/pessoa/<?= e($p['pessoa_id']) ?>">
                                    <?= e($p['nome'] ?: '(sem nome)') ?>
                                </a>
                            </td>
                            <td><?= e($p['email']) ?></td>
                            <td><?= e(CATEGORIAS_DISPLAY[$p['categoria'] ?? ''] ?? $p['categoria'] ?? '-') ?></td>
                            <td style="white-space: nowrap;"><?= formatar_valor((int)$p['valor']) ?></td>
                            <td style="white-space: nowrap;">
                                <?php if ($p['status'] === 'pago'): ?>
                                    <mark style="background: #28a745;">Pago</mark>
                                <?php elseif ($p['status'] === 'pendente'): ?>
                                    <mark style="background: #ffc107; color: #000;">Pendente</mark>
                                <?php elseif ($p['status'] === 'enviado'): ?>
                                    <mark style="background: #17a2b8;">Enviado</mark>
                                <?php elseif ($p['status'] === 'acesso'): ?>
                                    <mark style="background: #6c757d;">Acesso</mark>
                                <?php else: ?>
                                    <mark style="background: #dc3545;"><?= e($p['status'] ?? '-') ?></mark>
                                <?php endif; ?>
                            </td>
                            <td style="white-space: nowrap;"><?= e($p['metodo'] ?? '-') ?></td>
                            <td style="white-space: nowrap;"><?= e($p['data_pagamento'] ?? $p['created_at'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</article>
