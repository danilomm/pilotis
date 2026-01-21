<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2><?= e($pessoa['nome'] ?: 'Cadastro #' . $pessoa['id']) ?></h2>
        <div>
            <a href="/admin" role="button" class="outline">Painel</a>
            <a href="/admin/contatos" role="button" class="outline">Contatos</a>
            <a href="/admin/buscar" role="button" class="outline">Buscar</a>
        </div>
    </div>

    <?php if ($salvo): ?>
        <div class="alert alert-success">Dados salvos com sucesso!</div>
    <?php endif; ?>

    <form method="POST" action="/admin/pessoa/<?= e($pessoa['id']) ?>">

        <div class="grid">
            <div>
                <label for="nome">Nome</label>
                <input type="text" id="nome" name="nome" value="<?= e($pessoa['nome'] ?? '') ?>">
            </div>
            <div>
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?= e($pessoa['email'] ?? '') ?>">
            </div>
        </div>

        <div class="grid">
            <div>
                <label for="cpf">CPF</label>
                <input type="text" id="cpf" name="cpf" value="<?= e($pessoa['cpf'] ?? '') ?>">
            </div>
            <div>
                <label for="token">Token</label>
                <input type="text" id="token" value="<?= e($pessoa['token'] ?? '') ?>" readonly disabled>
            </div>
        </div>

        <label for="notas">Notas (admin)</label>
        <textarea id="notas" name="notas" rows="2"><?= e($pessoa['notas'] ?? '') ?></textarea>

        <button type="submit">Salvar</button>
    </form>

    <hr>

    <!-- Filiações -->
    <h3>Filiações</h3>

    <?php if (empty($filiacoes)): ?>
        <p>Nenhuma filiação registrada.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Ano</th>
                    <th>Categoria</th>
                    <th>Valor</th>
                    <th>Status</th>
                    <th>Método</th>
                    <th>Data Pagamento</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filiacoes as $f): ?>
                    <tr>
                        <td><?= e($f['ano']) ?></td>
                        <td><?= e(CATEGORIAS_DISPLAY[$f['categoria'] ?? ''] ?? $f['categoria'] ?? '-') ?></td>
                        <td><?= formatar_valor((int)$f['valor']) ?></td>
                        <td>
                            <?php if ($f['status'] === 'pago'): ?>
                                <mark style="background: #28a745;">Pago</mark>
                            <?php elseif ($f['status'] === 'pendente'): ?>
                                <mark style="background: #ffc107; color: #000;">Pendente</mark>
                            <?php elseif ($f['status'] === 'enviado'): ?>
                                <mark style="background: #17a2b8;">Enviado</mark>
                            <?php elseif ($f['status'] === 'acesso'): ?>
                                <mark style="background: #6c757d;">Acesso</mark>
                            <?php else: ?>
                                <mark style="background: #dc3545;"><?= e($f['status'] ?? '-') ?></mark>
                            <?php endif; ?>
                        </td>
                        <td><?= e($f['metodo'] ?? '-') ?></td>
                        <td><?= e($f['data_pagamento'] ?? '-') ?></td>
                        <td style="white-space: nowrap;">
                            <?php if ($f['status'] !== 'pago'): ?>
                                <form method="POST" action="/admin/pagar/<?= e($f['id']) ?>" style="display: inline;">
                                    <button type="submit" class="outline" style="padding: 5px 10px; font-size: 12px;"
                                            onclick="return confirm('Marcar como pago?')">
                                        Pagar
                                    </button>
                                </form>
                                <form method="POST" action="/admin/enviar-email/<?= e($f['id']) ?>" style="display: inline;">
                                    <button type="submit" class="outline" style="padding: 5px 10px; font-size: 12px;"
                                            onclick="return confirm('Enviar email de filiação?')">
                                        Email
                                    </button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" action="/admin/excluir/pagamento/<?= e($f['id']) ?>" style="display: inline;">
                                <button type="submit" class="secondary outline" style="padding: 5px 10px; font-size: 12px;"
                                        onclick="return confirm('Excluir filiação?')">
                                    Excluir
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <hr>

    <!-- Info adicional -->
    <details>
        <summary>Informações do Cadastro</summary>
        <p><strong>ID:</strong> <?= e($pessoa['id']) ?></p>
        <p><strong>Token:</strong> <?= e($pessoa['token'] ?? '-') ?></p>
        <p><strong>Data Cadastro:</strong> <?= e($pessoa['created_at'] ?? '-') ?></p>
        <p><strong>Última Atualização:</strong> <?= e($pessoa['updated_at'] ?? '-') ?></p>
    </details>

    <hr>

    <!-- Excluir pessoa -->
    <form method="POST" action="/admin/excluir/pessoa/<?= e($pessoa['id']) ?>"
          onsubmit="return confirm('ATENÇÃO: Esta ação excluirá a pessoa e todas as suas filiações. Continuar?')">
        <button type="submit" class="secondary" style="background-color: #dc3545;">
            Excluir Pessoa
        </button>
    </form>
</article>
