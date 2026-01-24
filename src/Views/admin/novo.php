<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Novo Cadastro + Pagamento</h2>
        <a href="/admin" role="button" class="outline">Voltar</a>
    </div>

    <p>Este formulario cria um novo cadastro e marca o pagamento como <strong>pago (manual)</strong>.</p>

    <form method="POST" action="/admin/novo">

        <label for="nome">Nome *</label>
        <input type="text" id="nome" name="nome" required>

        <label for="email">Email *</label>
        <input type="email" id="email" name="email" required>

        <label for="categoria">Categoria *</label>
        <?php
            $map_val = [
                'profissional_internacional' => $valores_ano['valor_internacional'],
                'profissional_nacional' => $valores_ano['valor_profissional'],
                'estudante' => $valores_ano['valor_estudante'],
            ];
        ?>
        <select id="categoria" name="categoria" required>
            <option value="">-- Selecione --</option>
            <?php foreach (CATEGORIAS_FILIACAO as $val => $info): ?>
                <option value="<?= e($val) ?>"><?= e($info['nome']) ?> - <?= formatar_valor($map_val[$val] ?? $info['valor']) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="ano">Ano *</label>
        <select id="ano" name="ano" required>
            <?php for ($a = date('Y') + 1; $a >= 2020; $a--): ?>
                <option value="<?= $a ?>" <?= $a == $ano ? 'selected' : '' ?>><?= $a ?></option>
            <?php endfor; ?>
        </select>

        <label for="cpf">CPF</label>
        <input type="text" id="cpf" name="cpf">

        <label for="telefone">Telefone</label>
        <input type="tel" id="telefone" name="telefone">

        <button type="submit">Criar e Marcar como Pago</button>
    </form>
</article>
