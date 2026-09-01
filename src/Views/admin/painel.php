<?php if (AVISOS_AMBIENTE): ?>
    <?php // O .env deste servidor descreve outro ambiente. Sao falhas que NAO
          // dao erro: o sistema responde normalmente e nao faz o que deveria. ?>
    <div style="border: 3px solid #b00; background: #fff3f3; padding: 16px; border-radius: 8px; margin-bottom: 1.5rem;">
        <strong style="color: #b00;">⚠ A configuração deste servidor está inconsistente</strong>
        <ul style="margin: 8px 0 0;">
            <?php foreach (AVISOS_AMBIENTE as $aviso): ?>
                <li><?= e($aviso) ?></li>
            <?php endforeach; ?>
        </ul>
        <small>Nenhuma dessas falhas aparece como erro — o sistema responde
        normalmente e não faz o que deveria. Corrigir o <code>.env</code> do
        servidor.</small>
    </div>
<?php endif; ?>

<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Painel Admin - <?= e($ano) ?></h2>
        <div>
            <?php
            // Contagem ao lado do link: sem ela o log continua sendo uma pagina
            // que ninguem tem motivo para abrir. A lista de tipos mora em
            // tipos_log_criticos(), no db.php, para nao divergir da tela de log.
            $n_log = contar_log_criticos(30);
            ?>
            <a href="/admin/buscar" role="button" class="outline">Buscar</a>
            <a href="/admin/templates" role="button" class="outline">Templates</a>
            <a href="/admin/log" role="button" class="outline"<?= $n_log ? ' style="border-color:#b00;color:#b00;"' : '' ?>>
                Log<?= $n_log ? ' (' . e((string)$n_log) . ')' : '' ?>
            </a>
            <a href="/admin/logout" role="button" class="secondary outline">Sair</a>
        </div>
    </div>

    <?php
    // EVENTOS primeiro. A pergunta do dia, em novembro, e quantas inscricoes
    // chegaram e quanto entrou — e ela estava a dois cliques, atras de um botao
    // no meio dos da campanha. A filiacao continua logo abaixo, inteira.
    ?>
    <section style="margin-bottom: 2.2rem;">
        <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 10px; flex-wrap: wrap;">
            <h3 style="margin: 0;">Eventos</h3>
            <a href="/admin/eventos" role="button" class="outline" style="padding: .3rem .8rem; font-size: .85rem;">
                Todos os eventos
            </a>
        </div>

        <?php if (empty($eventos_painel)): ?>
            <p><small>Nenhum evento publicado.
            <a href="/admin/eventos/novo">Criar um evento</a>.</small></p>
        <?php else: ?>
            <div style="overflow-x: auto;">
            <table>
                <thead>
                    <tr>
                        <th>Evento</th>
                        <th>Quando</th>
                        <th style="text-align: right;">Confirmadas</th>
                        <th style="text-align: right;">Pendentes</th>
                        <th style="text-align: right;">Arrecadado</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($eventos_painel as $ev): ?>
                    <tr>
                        <?php
                        // O NOME leva a pagina do evento, que e onde se edita
                        // tudo; os NUMEROS levam a lista de inscritos, que e o
                        // que esta atras deles. Ate 31/08/2026 o nome levava a
                        // lista, e quem queria mexer no evento caia no lugar
                        // errado.
                        ?>
                        <td>
                            <a href="/admin/eventos/<?= (int)$ev['id'] ?>"><?= e($ev['nome']) ?></a>
                            <br><small style="color: var(--muted-color);"><?= e($ev['slug']) ?></small>
                            <?php if (($ev['status'] ?? '') === 'pausado'): ?>
                                <br><small style="color: #8a4b00;">inscrições pausadas</small>
                            <?php endif; ?>
                        </td>
                        <td><small><?= $ev['data_inicio']
                            ? e(data_por_extenso($ev['data_inicio'], $ev['data_fim']))
                            : '—' ?>
                            <?php if ($ev['prazo_inscricao']): ?>
                                <br>inscrições até <?= date('d/m/Y', strtotime($ev['prazo_inscricao'])) ?>
                            <?php endif; ?>
                        </small></td>
                        <td style="text-align: right;">
                            <a href="/admin/eventos/<?= (int)$ev['id'] ?>/inscritos"
                               style="color: #2E7D32;"><strong><?= (int)$ev['confirmadas'] ?></strong></a>
                        </td>
                        <td style="text-align: right;">
                            <?php if ((int)$ev['pendentes']): ?>
                                <a href="/admin/eventos/<?= (int)$ev['id'] ?>/inscritos?status=pendente"
                                   style="color: #8a6d1f;"><?= (int)$ev['pendentes'] ?></a>
                            <?php else: ?>
                                <span style="color: var(--muted-color);">0</span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align: right;"><strong><?= formatar_valor((int)$ev['arrecadado']) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </section>

    <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 10px; flex-wrap: wrap;">
        <h3 style="margin: 0 0 .4rem;">Filiação</h3>
        <div>
            <a href="/admin/campanha" role="button" class="outline" style="padding: .3rem .8rem; font-size: .85rem;">Campanhas</a>
            <a href="/admin/contatos" role="button" class="outline" style="padding: .3rem .8rem; font-size: .85rem;">Contatos</a>
            <a href="/admin/novo" role="button" class="outline" style="padding: .3rem .8rem; font-size: .85rem;">+ Novo</a>
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
            <h3 style="margin: 0; color: #856404;"><?= (int)($stats['nao_pagos'] ?? 0) ?></h3>
            <small>Não pagos</small>
            <?php if ((int)($stats['pendentes'] ?? 0) > 0): ?>
                <br><small style="color: var(--muted-color);">
                    <?= (int)$stats['pendentes'] ?> ainda no funil
                </small>
            <?php endif; ?>
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
    <?php // O ano ja esta no filtro logo acima; repetir aqui era ruido. ?>

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
                            <td style="white-space: nowrap;"><?= e($p['status_at'] ?? $p['created_at'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</article>
