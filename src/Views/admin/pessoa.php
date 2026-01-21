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
        <?php foreach ($filiacoes as $f): ?>
            <div style="border: 1px solid #ddd; border-radius: 8px; padding: 15px; margin-bottom: 15px; background: #fafafa;">
                <!-- Cabeçalho do card -->
                <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
                    <div>
                        <strong style="font-size: 1.2em;"><?= e($f['ano']) ?></strong>
                        <?php if ($f['status'] === 'pago'): ?>
                            <mark style="background: #28a745; margin-left: 10px;">Pago</mark>
                        <?php elseif ($f['status'] === 'pendente'): ?>
                            <mark style="background: #ffc107; color: #000; margin-left: 10px;">Pendente</mark>
                        <?php elseif ($f['status'] === 'enviado'): ?>
                            <mark style="background: #17a2b8; margin-left: 10px;">Enviado</mark>
                        <?php elseif ($f['status'] === 'acesso'): ?>
                            <mark style="background: #6c757d; margin-left: 10px;">Acesso</mark>
                        <?php else: ?>
                            <mark style="background: #dc3545; margin-left: 10px;"><?= e($f['status'] ?? '-') ?></mark>
                        <?php endif; ?>
                    </div>
                    <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                        <a href="/admin/filiacao/<?= e($f['id']) ?>" style="display: inline-block; padding: 4px 8px; font-size: 11px; background: #6c757d; color: white !important; text-decoration: none; border-radius: 4px; line-height: 1.4;">Editar</a>
                        <?php if ($f['status'] !== 'pago'): ?>
                            <form method="POST" action="/admin/pagar/<?= e($f['id']) ?>" style="margin: 0;">
                                <button type="submit" style="padding: 4px 8px; font-size: 11px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer;"
                                        onclick="return confirm('Marcar como pago?')">Pagar</button>
                            </form>
                            <form method="POST" action="/admin/enviar-email/<?= e($f['id']) ?>" style="margin: 0;">
                                <button type="submit" style="padding: 4px 8px; font-size: 11px; background: #17a2b8; color: white; border: none; border-radius: 4px; cursor: pointer;"
                                        onclick="return confirm('Enviar email de filiação?')">Email</button>
                            </form>
                        <?php endif; ?>
                        <form method="POST" action="/admin/excluir/pagamento/<?= e($f['id']) ?>" style="margin: 0;">
                            <button type="submit" style="padding: 4px 8px; font-size: 11px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;"
                                    onclick="return confirm('Excluir filiação?')">Excluir</button>
                        </form>
                    </div>
                </div>

                <!-- Dados da filiação em fonte pequena -->
                <div style="font-size: 0.85em; color: #555;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                        <div>
                            <strong>Categoria:</strong> <?= e(CATEGORIAS_DISPLAY[$f['categoria'] ?? ''] ?? $f['categoria'] ?? '-') ?><br>
                            <strong>Valor:</strong> <?= formatar_valor((int)$f['valor']) ?><br>
                            <strong>Método:</strong> <?= e($f['metodo'] ?? '-') ?><br>
                            <strong>Data Pgto:</strong> <?= e($f['data_pagamento'] ?? '-') ?>
                        </div>
                        <div>
                            <strong>Telefone:</strong> <?= e($f['telefone'] ?? '-') ?><br>
                            <strong>Profissão:</strong> <?= e($f['profissao'] ?? '-') ?><br>
                            <strong>Formação:</strong> <?= e($f['formacao'] ?? '-') ?><br>
                            <strong>Instituição:</strong> <?= e($f['instituicao'] ?? '-') ?>
                        </div>
                        <div>
                            <strong>Endereço:</strong> <?= e($f['endereco'] ?? '-') ?><br>
                            <strong>CEP:</strong> <?= e($f['cep'] ?? '-') ?><br>
                            <strong>Cidade:</strong> <?= e($f['cidade'] ?? '-') ?> / <?= e($f['estado'] ?? '-') ?><br>
                            <strong>País:</strong> <?= e($f['pais'] ?? '-') ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
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
