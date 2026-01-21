<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Campanha <?= e($ano_campanha) ?></h2>
        <div>
            <a href="/admin" role="button" class="outline">Painel</a>
            <a href="/admin/contatos" role="button" class="outline">Contatos</a>
            <a href="/admin/logout" role="button" class="secondary outline">Sair</a>
        </div>
    </div>

    <?php if ($msg = get_flash('success')): ?>
        <div style="background: #d4edda; padding: 15px; border-radius: 8px; margin-bottom: 20px; color: #155724;">
            <?= e($msg) ?>
        </div>
    <?php endif; ?>

    <?php if ($msg = get_flash('error')): ?>
        <div style="background: #f8d7da; padding: 15px; border-radius: 8px; margin-bottom: 20px; color: #721c24;">
            <?= e($msg) ?>
        </div>
    <?php endif; ?>

    <!-- Resumo do ano anterior -->
    <h3>Ano anterior (<?= e($ano_anterior) ?>)</h3>
    <div class="grid">
        <div style="background: #e9ecef; padding: 15px; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0; color: #495057;"><?= (int)($stats_anterior['total'] ?? 0) ?></h3>
            <small>Total no funil</small>
        </div>
        <div style="background: #d4edda; padding: 15px; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0; color: #155724;"><?= (int)($stats_anterior['pagos'] ?? 0) ?></h3>
            <small>Pagos</small>
        </div>
    </div>

    <hr>

    <!-- Campanha atual -->
    <h3>Campanha <?= e($ano_campanha) ?></h3>

    <!-- Funil -->
    <div class="grid">
        <div style="background: #e9ecef; padding: 15px; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0; color: #495057;"><?= (int)($stats_atual['total'] ?? 0) ?></h3>
            <small>Total no funil</small>
        </div>
        <div style="background: #17a2b8; padding: 15px; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0; color: white;"><?= (int)($stats_atual['enviados'] ?? 0) ?></h3>
            <small style="color: white;">Enviados</small>
        </div>
        <div style="background: #6c757d; padding: 15px; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0; color: white;"><?= (int)($stats_atual['acessos'] ?? 0) ?></h3>
            <small style="color: white;">Acessaram</small>
        </div>
        <div style="background: #ffc107; padding: 15px; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0; color: #000;"><?= (int)($stats_atual['pendentes'] ?? 0) ?></h3>
            <small>Pendentes</small>
        </div>
        <div style="background: #28a745; padding: 15px; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0; color: white;"><?= (int)($stats_atual['pagos'] ?? 0) ?></h3>
            <small style="color: white;">Pagos</small>
        </div>
        <?php if (($stats_atual['nao_pagos'] ?? 0) > 0): ?>
        <div style="background: #dc3545; padding: 15px; border-radius: 8px; text-align: center;">
            <h3 style="margin: 0; color: white;"><?= (int)($stats_atual['nao_pagos'] ?? 0) ?></h3>
            <small style="color: white;">Não Pagos</small>
        </div>
        <?php endif; ?>
    </div>

    <hr>

    <!-- Valores -->
    <h3>Valores da Filiação</h3>
    <table>
        <thead>
            <tr>
                <th>Categoria</th>
                <th>Valor</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Estudante</td>
                <td><?= formatar_valor($valores['estudante']) ?></td>
            </tr>
            <tr>
                <td>Profissional Nacional</td>
                <td><?= formatar_valor($valores['profissional_nacional']) ?></td>
            </tr>
            <tr>
                <td>Profissional Internacional</td>
                <td><?= formatar_valor($valores['profissional_internacional']) ?></td>
            </tr>
        </tbody>
    </table>
    <p><small>Para alterar valores, edite o arquivo <code>.env</code></small></p>

    <hr>

    <!-- Ações -->
    <h3>Ações</h3>

    <div style="display: flex; gap: 15px; flex-wrap: wrap; margin-bottom: 20px;">
        <!-- Preparar campanha -->
        <form method="POST" action="/admin/campanha/preparar"
              onsubmit="return confirm('Isso criará registros de filiação para <?= e($ano_campanha) ?> para todos os pagos de <?= e($ano_anterior) ?>. Continuar?')">
            <button type="submit" class="outline">
                Preparar <?= e($ano_campanha) ?> (pagos de <?= e($ano_anterior) ?>)
            </button>
        </form>

        <!-- Fechar campanha -->
        <form method="POST" action="/admin/campanha/fechar"
              onsubmit="return confirm('ATENÇÃO: Isso marcará todos os não pagos como \"não filiado\" e copiará os dados de <?= e($ano_anterior) ?>. Esta ação não pode ser desfeita facilmente. Continuar?') && confirm('Tem certeza? A campanha será encerrada.')">
            <button type="submit" style="background: #dc3545;">
                Fechar Campanha <?= e($ano_campanha) ?>
            </button>
        </form>
    </div>

    <hr>

    <h3>Enviar Emails</h3>
    <p>Total de contatos: <strong><?= e($total_contatos) ?></strong></p>

    <form method="POST" action="/admin/campanha/enviar"
          onsubmit="return confirm('ATENÇÃO: Isso enviará emails para os destinatários selecionados. Esta ação não pode ser desfeita. Continuar?') && confirm('Tem certeza? Verifique se os valores e templates estão corretos.')">

        <label for="tipo">Enviar para:</label>
        <select name="tipo" id="tipo" style="width: auto; display: inline-block;">
            <option value="todos">Todos os contatos (<?= e($total_contatos) ?>)</option>
            <option value="enviado">Apenas status "enviado" (<?= (int)($stats_atual['enviados'] ?? 0) ?>)</option>
            <option value="pendente">Apenas status "pendente" (<?= (int)($stats_atual['pendentes'] ?? 0) ?>)</option>
        </select>

        <br><br>

        <button type="submit" style="background: #dc3545;">
            Enviar Emails de Campanha
        </button>
    </form>

    <hr>

    <details>
        <summary>Pendências (versão futura)</summary>
        <ul>
            <li>Tabela de campanhas com status aberta/fechada</li>
            <li>Fechar campanha (pendentes -> não pago)</li>
            <li>Edição de templates de email</li>
            <li>Edição de valores na interface</li>
            <li>Envio em lote com progresso</li>
            <li>Trava por data real</li>
        </ul>
    </details>
</article>
