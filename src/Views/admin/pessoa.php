<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2><?= e($pessoa['nome'] ?: 'Cadastro #' . $pessoa['id']) ?></h2>
        <div>
            <a href="/admin" role="button" class="outline">Painel</a>
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
                <label for="telefone">Telefone</label>
                <input type="text" id="telefone" name="telefone" value="<?= e($pessoa['telefone'] ?? '') ?>">
            </div>
        </div>

        <label for="categoria">Categoria</label>
        <select id="categoria" name="categoria">
            <option value="">-- Selecione --</option>
            <?php foreach (CATEGORIAS_DISPLAY as $val => $nome): ?>
                <option value="<?= e($val) ?>" <?= ($pessoa['categoria'] ?? '') === $val ? 'selected' : '' ?>>
                    <?= e($nome) ?>
                </option>
            <?php endforeach; ?>
            <option value="participante_seminario" <?= ($pessoa['categoria'] ?? '') === 'participante_seminario' ? 'selected' : '' ?>>
                Participante Seminario
            </option>
            <option value="cadastrado" <?= ($pessoa['categoria'] ?? '') === 'cadastrado' ? 'selected' : '' ?>>
                Cadastrado
            </option>
        </select>

        <label for="endereco">Endereco</label>
        <input type="text" id="endereco" name="endereco" value="<?= e($pessoa['endereco'] ?? '') ?>">

        <div class="grid">
            <div>
                <label for="cep">CEP</label>
                <input type="text" id="cep" name="cep" value="<?= e($pessoa['cep'] ?? '') ?>">
            </div>
            <div>
                <label for="cidade">Cidade</label>
                <input type="text" id="cidade" name="cidade" value="<?= e($pessoa['cidade'] ?? '') ?>">
            </div>
        </div>

        <div class="grid">
            <div>
                <label for="estado">Estado (UF)</label>
                <input type="text" id="estado" name="estado" value="<?= e($pessoa['estado'] ?? '') ?>" maxlength="2">
            </div>
            <div>
                <label for="pais">Pais</label>
                <input type="text" id="pais" name="pais" value="<?= e($pessoa['pais'] ?? 'Brasil') ?>">
            </div>
        </div>

        <div class="grid">
            <div>
                <label for="profissao">Profissao</label>
                <input type="text" id="profissao" name="profissao" value="<?= e($pessoa['profissao'] ?? '') ?>">
            </div>
            <div>
                <label for="formacao">Formacao</label>
                <input type="text" id="formacao" name="formacao" value="<?= e($pessoa['formacao'] ?? '') ?>">
            </div>
        </div>

        <label for="instituicao">Instituicao</label>
        <input type="text" id="instituicao" name="instituicao" value="<?= e($pessoa['instituicao'] ?? '') ?>">

        <label for="observacoes">Observacoes (admin)</label>
        <textarea id="observacoes" name="observacoes" rows="2"><?= e($pessoa['observacoes'] ?? '') ?></textarea>

        <label for="observacoes_filiado">Observacoes do filiado</label>
        <textarea id="observacoes_filiado" name="observacoes_filiado" rows="2"><?= e($pessoa['observacoes_filiado'] ?? '') ?></textarea>

        <button type="submit">Salvar</button>
    </form>

    <hr>

    <!-- Pagamentos -->
    <h3>Pagamentos</h3>

    <?php if (empty($pagamentos)): ?>
        <p>Nenhum pagamento registrado.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Ano</th>
                    <th>Valor</th>
                    <th>Status</th>
                    <th>Metodo</th>
                    <th>Data Pagamento</th>
                    <th>Acoes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pagamentos as $p): ?>
                    <tr>
                        <td><?= e($p['ano']) ?></td>
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
                        <td><?= e($p['data_pagamento'] ?? '-') ?></td>
                        <td>
                            <?php if ($p['status'] === 'pendente'): ?>
                                <form method="POST" action="/admin/pagar/<?= e($p['id']) ?>" style="display: inline;">
                                    <button type="submit" class="outline" style="padding: 5px 10px; font-size: 12px;">
                                        Marcar Pago
                                    </button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" action="/admin/excluir/pagamento/<?= e($p['id']) ?>" style="display: inline;">
                                <button type="submit" class="secondary outline" style="padding: 5px 10px; font-size: 12px;"
                                        onclick="return confirm('Excluir pagamento?')">
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
        <summary>Informacoes do Cadastro</summary>
        <p><strong>ID:</strong> <?= e($pessoa['id']) ?></p>
        <p><strong>Token:</strong> <?= e($pessoa['token'] ?? '-') ?></p>
        <p><strong>Data Cadastro:</strong> <?= e($pessoa['data_cadastro'] ?? '-') ?></p>
        <p><strong>Ultima Atualizacao:</strong> <?= e($pessoa['data_atualizacao'] ?? '-') ?></p>
        <p><strong>Seminario 2025:</strong> <?= ($pessoa['seminario_2025'] ?? 0) ? 'Sim' : 'Nao' ?></p>
    </details>

    <hr>

    <!-- Excluir pessoa -->
    <form method="POST" action="/admin/excluir/pessoa/<?= e($pessoa['id']) ?>"
          onsubmit="return confirm('ATENCAO: Esta acao excluira a pessoa e todos os seus pagamentos. Continuar?')">
        <button type="submit" class="secondary" style="background-color: #dc3545;">
            Excluir Pessoa
        </button>
    </form>
</article>
