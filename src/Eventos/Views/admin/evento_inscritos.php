<?php
/**
 * Lista de inscritos de um evento.
 *
 * É por aqui que a tesouraria opera o evento: confere quem pagou, quem ficou
 * no meio do caminho, quem deve comprovante de matrícula, e exporta a lista
 * para a organização.
 *
 * @var array $evento
 * @var array $inscritos
 * @var array $totais
 * @var string $filtro
 * @var string $busca
 */
$situacoes = [
    'pago'                => ['Pago',                  '#2E7D32'],
    'gratuita_confirmada' => ['Isento confirmado',     '#2E7D32'],
    'pendente'            => ['Aguardando pagamento',  '#8a6d1f'],
    'acesso'              => ['Abriu o formulário',    '#8a6d1f'],
    'enviado'             => ['Link enviado',          '#777777'],
];
$metodos = ['pix' => 'PIX', 'boleto' => 'Boleto', 'cartao' => 'Cartão'];

$filtros = [
    ''             => 'Todos',
    'confirmadas'  => 'Confirmadas',
    'pendentes'    => 'Aguardando pagamento',
    'sem_resposta' => 'Sem resposta',
    'comprovante'  => 'Exigem comprovante',
];

$query = function (array $extra) use ($filtro, $busca): string {
    $p = array_filter(array_merge(['status' => $filtro, 'q' => $busca], $extra), fn($v) => $v !== '' && $v !== null);
    return $p ? '?' . http_build_query($p) : '';
};
?>

<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Inscritos — <?= e($evento['nome']) ?></h2>
        <div>
            <a href="/admin/eventos/<?= (int)$evento['id'] ?>/inscritos.xlsx<?= $query([]) ?>" role="button">Baixar planilha</a>
            <a href="/admin/eventos/<?= (int)$evento['id'] ?>/inscritos.csv<?= $query([]) ?>" role="button" class="outline">CSV</a>
            <a href="/admin/eventos/<?= (int)$evento['id'] ?>" role="button" class="outline">Voltar ao evento</a>
        </div>
    </div>

    <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; margin: 1rem 0; font-size: .95rem;">
        <div><strong><?= (int)$totais['total'] ?></strong> inscrições</div>
        <div style="color: #2E7D32;"><strong><?= (int)$totais['confirmadas'] ?></strong> confirmadas
            <small>(<?= (int)$totais['pagas'] ?> pagas, <?= (int)$totais['isentas'] ?> isentas)</small></div>
        <div style="color: #8a6d1f;"><strong><?= (int)$totais['pendentes'] ?></strong> aguardando pagamento</div>
        <div style="color: #777;"><strong><?= (int)$totais['sem_resposta'] ?></strong> sem resposta</div>
        <div><strong><?= formatar_valor((int)$totais['arrecadado']) ?></strong> arrecadado</div>
    </div>

    <form method="GET" style="display: flex; gap: .6rem; align-items: end; flex-wrap: wrap; margin-bottom: 1rem;">
        <div style="flex: 1; min-width: 200px;">
            <label for="q" style="font-size: .85em;">Buscar por nome, email ou CPF</label>
            <input type="search" id="q" name="q" value="<?= e($busca) ?>" style="margin-bottom: 0;">
        </div>
        <div style="min-width: 180px;">
            <label for="status" style="font-size: .85em;">Situação</label>
            <select id="status" name="status" style="margin-bottom: 0;" onchange="this.form.submit()">
                <?php foreach ($filtros as $valor => $rotulo): ?>
                    <option value="<?= e($valor) ?>" <?= $filtro === $valor ? 'selected' : '' ?>><?= e($rotulo) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" style="margin-bottom: 0;">Filtrar</button>
        <?php if ($filtro !== '' || $busca !== ''): ?>
            <a href="/admin/eventos/<?= (int)$evento['id'] ?>/inscritos" role="button" class="outline"
               style="margin-bottom: 0;">Limpar</a>
        <?php endif; ?>
    </form>

    <?php if (empty($inscritos)): ?>
        <p><?= ($filtro !== '' || $busca !== '')
            ? 'Nenhuma inscrição encontrada com esse filtro.'
            : 'Ninguém se inscreveu neste evento ainda.' ?></p>
    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Categoria</th>
                    <th>Valor</th>
                    <th>Situação</th>
                    <th>Matrícula</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inscritos as $i): ?>
                    <?php [$rotulo, $cor] = $situacoes[$i['status']] ?? [$i['status'], '#555']; ?>
                    <tr>
                        <td>
                            <a href="/admin/pessoa/<?= (int)$i['pessoa_id'] ?>">
                                <?= e(trim($i['nome'] ?? '') ?: '(sem nome)') ?>
                            </a>
                            <br><small style="color: var(--muted-color);">
                                <?= e($i['email'] ?? 'sem email') ?>
                                <?php if (!empty($i['cidade'])): ?>
                                    · <?= e($i['cidade']) ?><?= $i['estado'] ? '/' . e($i['estado']) : '' ?>
                                <?php endif; ?>
                                <?php if (!empty($i['instituicao'])): ?>
                                    · <?= e($i['instituicao']) ?>
                                <?php endif; ?>
                            </small>
                        </td>
                        <td><?= e($i['categoria_nome'] ?? '—') ?></td>
                        <td><?= $i['valor'] !== null ? formatar_valor((int)$i['valor']) : '—' ?></td>
                        <td style="color: <?= $cor ?>;">
                            <?= e($rotulo) ?>
                            <?php if ($i['data_pagamento']): ?>
                                <br><small><?= date('d/m/Y', strtotime($i['data_pagamento'])) ?>
                                    <?= isset($metodos[$i['metodo']]) ? '· ' . $metodos[$i['metodo']] : '' ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (empty($i['requer_comprovante'])): ?>
                                <small style="color: var(--muted-color);">não exigido</small>
                            <?php elseif (tem_comprovante_evento((int)$evento['id'], (int)$i['pessoa_id'])): ?>
                                <a href="/admin/eventos/<?= (int)$evento['id'] ?>/comprovante/<?= (int)$i['pessoa_id'] ?>"
                                   target="_blank">ver</a>
                            <?php else: ?>
                                <small style="color: #b71c1c;">falta</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php // Reenvio do comprovante: o email pode ter falhado no instante
                                  // do pagamento, e a reentrega do webhook nao remanda. ?>
                            <?php if (in_array($i['status'], ['pago', 'gratuita_confirmada'], true)): ?>
                                <form method="POST" action="/admin/eventos/inscricao/<?= (int)$i['id'] ?>/enviar-confirmacao" style="margin:0;"><?= campo_csrf() ?>
                                    <button type="submit" class="secondary outline" style="padding: .2rem .5rem; font-size: .75rem;"
                                            onclick="return confirm('Reenviar o comprovante por email?')">reenviar</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <small style="display: block; margin-top: 1rem; color: var(--muted-color);">
            O CSV exporta exatamente o que está filtrado aqui, com os campos que a organização costuma
            pedir: nome, email, CPF, telefone, categoria, valor, situação, forma e data do pagamento,
            instituição e cidade, mais o estado do comprovante de matrícula.
            <br>
            "Sem resposta" é quem recebeu o link e não chegou a escolher categoria — inclui os convidados
            que ainda não confirmaram.
        </small>

    <?php endif; ?>
</article>
