<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Painel Admin - <?= e($ano) ?></h2>
        <div>
            <a href="/admin/buscar" role="button" class="outline">Buscar</a>
            <a href="/admin/novo" role="button" class="outline">+ Novo</a>
            <a href="/admin/logout" role="button" class="secondary outline">Sair</a>
        </div>
    </div>

    <!-- Seletor de ano -->
    <form method="GET" style="margin-bottom: 20px;">
        <label for="ano">Ano:</label>
        <select name="ano" id="ano" onchange="this.form.submit()" style="width: auto; display: inline-block;">
            <?php for ($a = date('Y') + 1; $a >= 2020; $a--): ?>
                <option value="<?= $a ?>" <?= $a == $ano ? 'selected' : '' ?>><?= $a ?></option>
            <?php endfor; ?>
        </select>
    </form>

    <!-- Estatisticas -->
    <div class="grid">
        <div style="background: #f5f5f5; padding: 15px; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0; color: #4a8c4a;"><?= (int)($stats['pagos'] ?? 0) ?></h3>
            <small>Pagos</small>
        </div>
        <div style="background: #fff3cd; padding: 15px; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0; color: #856404;"><?= (int)($stats['pendentes'] ?? 0) ?></h3>
            <small>Pendentes</small>
        </div>
        <div style="background: #d4edda; padding: 15px; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0; color: #155724;"><?= formatar_valor((int)($stats['arrecadado'] ?? 0)) ?></h3>
            <small>Arrecadado</small>
        </div>
    </div>

    <!-- Downloads -->
    <div style="margin: 20px 0;">
        <a href="/admin/download/csv?ano=<?= e($ano) ?>">Exportar CSV</a> |
        <a href="/admin/download/banco">Baixar Banco (.db)</a>
    </div>

    <!-- Lista de pagamentos -->
    <h3>Pagamentos <?= e($ano) ?></h3>

    <?php if (empty($pagamentos)): ?>
        <p>Nenhum pagamento encontrado para <?= e($ano) ?>.</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Email</th>
                        <th>Valor</th>
                        <th>Status</th>
                        <th>Metodo</th>
                        <th>Data</th>
                        <th>Acoes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pagamentos as $p): ?>
                        <tr>
                            <td>
                                <a href="/admin/pessoa/<?= e($p['cadastrado_id']) ?>">
                                    <?= e($p['nome'] ?: '(sem nome)') ?>
                                </a>
                            </td>
                            <td><?= e($p['email']) ?></td>
                            <td><?= formatar_valor((int)$p['valor']) ?></td>
                            <td>
                                <?php if ($p['status'] === 'pago'): ?>
                                    <mark style="background: #28a745;">Pago</mark>
                                <?php elseif ($p['status'] === 'pendente'): ?>
                                    <mark style="background: #ffc107; color: #000;">Pendente</mark>
                                <?php else: ?>
                                    <mark style="background: #dc3545;"><?= e($p['status']) ?></mark>
                                <?php endif; ?>
                            </td>
                            <td><?= e($p['metodo'] ?? '-') ?></td>
                            <td><?= e($p['data_pagamento'] ?? $p['data_criacao'] ?? '-') ?></td>
                            <td>
                                <?php if ($p['status'] === 'pendente'): ?>
                                    <form method="POST" action="/admin/pagar/<?= e($p['id']) ?>" style="display: inline;">
                                        <button type="submit" class="outline" style="padding: 5px 10px; font-size: 12px;"
                                                onclick="return confirm('Marcar como pago?')">
                                            Pagar
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</article>
