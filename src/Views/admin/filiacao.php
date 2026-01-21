<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Filiação <?= e($filiacao['ano']) ?> - <?= e($filiacao['pessoa_nome']) ?></h2>
        <div>
            <a href="/admin/pessoa/<?= e($filiacao['pessoa_id']) ?>" role="button" class="outline">Voltar</a>
        </div>
    </div>

    <?php if ($salvo): ?>
        <div class="alert alert-success">Filiação salva com sucesso!</div>
    <?php endif; ?>

    <form method="POST" action="/admin/filiacao/<?= e($filiacao['id']) ?>">

        <h3>Dados da Filiação</h3>

        <div class="grid">
            <div>
                <label for="categoria">Categoria</label>
                <select id="categoria" name="categoria">
                    <option value="estudante" <?= ($filiacao['categoria'] ?? '') === 'estudante' ? 'selected' : '' ?>>Estudante</option>
                    <option value="profissional_nacional" <?= ($filiacao['categoria'] ?? '') === 'profissional_nacional' ? 'selected' : '' ?>>Profissional Nacional</option>
                    <option value="profissional_internacional" <?= ($filiacao['categoria'] ?? '') === 'profissional_internacional' ? 'selected' : '' ?>>Profissional Internacional</option>
                    <option value="nao_filiado" <?= ($filiacao['categoria'] ?? '') === 'nao_filiado' ? 'selected' : '' ?>>Não Filiado</option>
                </select>
            </div>
            <div>
                <label for="valor">Valor (centavos)</label>
                <input type="number" id="valor" name="valor" value="<?= e($filiacao['valor'] ?? '') ?>">
            </div>
        </div>

        <div class="grid">
            <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="enviado" <?= ($filiacao['status'] ?? '') === 'enviado' ? 'selected' : '' ?>>Enviado</option>
                    <option value="acesso" <?= ($filiacao['status'] ?? '') === 'acesso' ? 'selected' : '' ?>>Acesso</option>
                    <option value="pendente" <?= ($filiacao['status'] ?? '') === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                    <option value="pago" <?= ($filiacao['status'] ?? '') === 'pago' ? 'selected' : '' ?>>Pago</option>
                </select>
            </div>
            <div>
                <label for="metodo">Método de Pagamento</label>
                <input type="text" id="metodo" name="metodo" value="<?= e($filiacao['metodo'] ?? '') ?>" placeholder="pix, cartao, boleto, manual...">
            </div>
        </div>

        <div class="grid">
            <div>
                <label for="data_pagamento">Data Pagamento</label>
                <input type="date" id="data_pagamento" name="data_pagamento" value="<?= e(substr($filiacao['data_pagamento'] ?? '', 0, 10)) ?>">
            </div>
            <div>
                <label for="telefone">Telefone</label>
                <input type="text" id="telefone" name="telefone" value="<?= e($filiacao['telefone'] ?? '') ?>">
            </div>
        </div>

        <hr>
        <h3>Endereço</h3>

        <label for="endereco">Endereço</label>
        <input type="text" id="endereco" name="endereco" value="<?= e($filiacao['endereco'] ?? '') ?>">

        <div class="grid">
            <div>
                <label for="cep">CEP</label>
                <input type="text" id="cep" name="cep" value="<?= e($filiacao['cep'] ?? '') ?>">
            </div>
            <div>
                <label for="cidade">Cidade</label>
                <input type="text" id="cidade" name="cidade" value="<?= e($filiacao['cidade'] ?? '') ?>">
            </div>
        </div>

        <div class="grid">
            <div>
                <label for="estado">Estado</label>
                <input type="text" id="estado" name="estado" value="<?= e($filiacao['estado'] ?? '') ?>">
            </div>
            <div>
                <label for="pais">País</label>
                <input type="text" id="pais" name="pais" value="<?= e($filiacao['pais'] ?? '') ?>">
            </div>
        </div>

        <hr>
        <h3>Dados Profissionais</h3>

        <div class="grid">
            <div>
                <label for="profissao">Profissão</label>
                <input type="text" id="profissao" name="profissao" value="<?= e($filiacao['profissao'] ?? '') ?>">
            </div>
            <div>
                <label for="formacao">Formação</label>
                <select id="formacao" name="formacao">
                    <option value="">Selecione...</option>
                    <?php foreach (FORMACOES as $f): ?>
                        <option value="<?= e($f) ?>" <?= ($filiacao['formacao'] ?? '') === $f ? 'selected' : '' ?>><?= e($f) ?></option>
                    <?php endforeach; ?>
                    <?php if ($filiacao['formacao'] && !in_array($filiacao['formacao'], FORMACOES)): ?>
                        <option value="<?= e($filiacao['formacao']) ?>" selected><?= e($filiacao['formacao']) ?> (legado)</option>
                    <?php endif; ?>
                </select>
            </div>
        </div>

        <label for="instituicao">Instituição</label>
        <input type="text" id="instituicao" name="instituicao" value="<?= e($filiacao['instituicao'] ?? '') ?>">

        <hr>

        <button type="submit">Salvar</button>
    </form>

    <hr>

    <details>
        <summary>Informações Técnicas</summary>
        <p><strong>ID Filiação:</strong> <?= e($filiacao['id']) ?></p>
        <p><strong>ID Pessoa:</strong> <?= e($filiacao['pessoa_id']) ?></p>
        <p><strong>PagBank Order:</strong> <?= e($filiacao['pagbank_order_id'] ?? '-') ?></p>
        <p><strong>PagBank Charge:</strong> <?= e($filiacao['pagbank_charge_id'] ?? '-') ?></p>
        <p><strong>Criado em:</strong> <?= e($filiacao['created_at'] ?? '-') ?></p>
    </details>
</article>
