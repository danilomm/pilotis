<article>
    <h2>Filiacao <?= e($ano) ?></h2>

    <?php if ($pagamento_existente && $pagamento_existente['status'] === 'pago'): ?>
        <div class="alert alert-success">
            Sua filiacao para <?= e($ano) ?> ja esta confirmada!
        </div>
    <?php elseif ($pagamento_existente && $pagamento_existente['status'] === 'pendente'): ?>
        <div class="alert alert-warning">
            Voce tem um pagamento pendente. <a href="/filiacao/<?= e($ano) ?>/<?= e($token) ?>/pagamento">Clique aqui para pagar</a>.
        </div>
    <?php endif; ?>

    <form method="POST" action="/filiacao/<?= e($ano) ?>/<?= e($token) ?>">

        <fieldset>
            <legend>Dados Pessoais</legend>

            <label for="nome">Nome Completo *</label>
            <input type="text" id="nome" name="nome" value="<?= e($cadastrado['nome'] ?? '') ?>" required>

            <label for="email">Email *</label>
            <input type="email" id="email" name="email" value="<?= e($cadastrado['email'] ?? '') ?>" required>

            <label for="cpf">CPF</label>
            <input type="text" id="cpf" name="cpf" value="<?= e($cadastrado['cpf'] ?? '') ?>" placeholder="000.000.000-00">

            <label for="telefone">Telefone</label>
            <input type="tel" id="telefone" name="telefone" value="<?= e($cadastrado['telefone'] ?? '') ?>" placeholder="(00) 00000-0000">
        </fieldset>

        <fieldset>
            <legend>Endereco</legend>

            <label for="endereco">Endereco</label>
            <input type="text" id="endereco" name="endereco" value="<?= e($cadastrado['endereco'] ?? '') ?>">

            <div class="grid">
                <div>
                    <label for="cep">CEP</label>
                    <input type="text" id="cep" name="cep" value="<?= e($cadastrado['cep'] ?? '') ?>" placeholder="00000-000">
                </div>
                <div>
                    <label for="cidade">Cidade</label>
                    <input type="text" id="cidade" name="cidade" value="<?= e($cadastrado['cidade'] ?? '') ?>">
                </div>
            </div>

            <div class="grid">
                <div>
                    <label for="estado">Estado (UF)</label>
                    <input type="text" id="estado" name="estado" value="<?= e($cadastrado['estado'] ?? '') ?>" maxlength="2" placeholder="XX">
                </div>
                <div>
                    <label for="pais">Pais</label>
                    <input type="text" id="pais" name="pais" value="<?= e($cadastrado['pais'] ?? 'Brasil') ?>">
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Dados Profissionais</legend>

            <label for="profissao">Profissao</label>
            <input type="text" id="profissao" name="profissao" value="<?= e($cadastrado['profissao'] ?? '') ?>">

            <label for="formacao">Formacao</label>
            <input type="text" id="formacao" name="formacao" value="<?= e($cadastrado['formacao'] ?? '') ?>">

            <label for="instituicao">Instituicao</label>
            <input type="text" id="instituicao" name="instituicao" value="<?= e($cadastrado['instituicao'] ?? '') ?>">
        </fieldset>

        <fieldset>
            <legend>Categoria de Filiacao *</legend>

            <?php foreach ($categorias as $cat): ?>
                <label>
                    <input type="radio" name="categoria" value="<?= e($cat['valor']) ?>"
                           <?= $cat['selecionada'] ? 'checked' : '' ?> required>
                    <?= e($cat['label']) ?>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <fieldset>
            <legend>Observacoes</legend>
            <label for="observacoes_filiado">Algo que queira nos informar?</label>
            <textarea id="observacoes_filiado" name="observacoes_filiado" rows="3"><?= e($cadastrado['observacoes_filiado'] ?? '') ?></textarea>
        </fieldset>

        <button type="submit">Continuar para Pagamento</button>

    </form>
</article>
