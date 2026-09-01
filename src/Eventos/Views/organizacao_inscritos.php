<?php
/**
 * Painel de acompanhamento da organização. Somente leitura.
 *
 * Mostra o cadastro dos inscritos (menos CPF) porque a organização precisa
 * falar com as pessoas e mandar correspondência. Por isso o aviso de que são
 * dados pessoais fica visível, e não escondido num rodapé.
 *
 * @var array  $evento
 * @var array  $inscritos
 * @var array  $totais
 * @var string $filtro
 * @var string $busca
 * @var string $email_sessao
 */
$situacoes = [
    'pago'                => ['Pago',                 '#2E7D32'],
    'gratuita_confirmada' => ['Isento confirmado',    '#2E7D32'],
    'pendente'            => ['Aguardando pagamento', '#8a6d1f'],
    'acesso'              => ['Abriu o formulário',   '#8a6d1f'],
    'enviado'             => ['Link enviado',         '#777777'],
];
$metodos = ['pix' => 'PIX', 'boleto' => 'Boleto', 'cartao' => 'Cartão'];
$filtros = [
    ''             => 'Todos',
    'confirmadas'  => 'Confirmadas',
    'pendentes'    => 'Aguardando pagamento',
    'sem_resposta' => 'Sem resposta',
    'comprovante'  => 'Exigem comprovante',
];
$base = '/eventos/' . rawurlencode($evento['slug']) . '/organizacao';
$parametros = array_filter(['status' => $filtro, 'q' => $busca], fn($v) => $v !== '');
$sufixo = $parametros ? '?' . http_build_query($parametros) : '';
?>

<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Inscritos — <?= e($evento['nome']) ?></h2>
        <div>
            <a href="<?= e($base) ?>/planilha<?= e($sufixo) ?>" role="button" class="primary">Baixar planilha</a>
            <a href="<?= e($base) ?>/csv<?= e($sufixo) ?>" role="button" class="outline">CSV</a>
        </div>
    </div>

    <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; margin: 1rem 0; font-size: .95rem;">
        <div><strong><?= (int)$totais['total'] ?></strong> inscrições</div>
        <div style="color: #2E7D32;"><strong><?= (int)$totais['confirmadas'] ?></strong> confirmadas
            <small>(<?= (int)$totais['pagas'] ?> pagas, <?= (int)$totais['isentas'] ?> isentas)</small></div>
        <div style="color: #8a6d1f;"><strong><?= (int)$totais['pendentes'] ?></strong> aguardando pagamento</div>
        <div style="color: #777;"><strong><?= (int)$totais['sem_resposta'] ?></strong> sem resposta</div>
    </div>

    <?php
    // Quanto o evento arrecadou. Ate 30/08/2026 o painel escondia valores, por
    // minimizacao de dados — decisao minha, e errada: a coordenacao precisa
    // saber quanto o proprio seminario rendeu, e e a tesouraria quem repassa.
    // Esconder so obrigava a pedir o numero a cada vez.
    //
    // O CPF continua fora: aquele nao serve a trabalho nenhum da organizacao.
    ?>
    <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; margin: 0 0 1rem; font-size: .95rem;
                padding: .7rem .9rem; border: 1px solid var(--pico-muted-border-color); border-radius: 6px;">
        <div style="color: #2E7D32;"><strong><?= formatar_valor((int)($totais['arrecadado'] ?? 0)) ?></strong> arrecadado</div>
        <?php if ((int)($totais['a_receber'] ?? 0) > 0): ?>
            <div style="color: #8a6d1f;"><strong><?= formatar_valor((int)$totais['a_receber']) ?></strong> a receber
                <small>(inscrições aguardando pagamento)</small></div>
        <?php endif; ?>
        <div style="color: var(--pico-muted-color);"><small>O repasse é feito pela tesouraria.</small></div>
    </div>

    <form method="GET" action="<?= e($base) ?>" style="display: flex; gap: .6rem; align-items: end; flex-wrap: wrap; margin-bottom: 1rem;">
        <div style="flex: 1; min-width: 200px;">
            <label for="q" style="font-size: .85em;">Buscar por nome ou email</label>
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
        <?php // width:auto: sem isso o Pico estica o botao para 100% quando a
              // linha do filtro quebra. Mesmo ajuste da tela do admin. ?>
        <button type="submit" style="margin-bottom: 0; width: auto;">Filtrar</button>
        <?php if ($filtro !== '' || $busca !== ''): ?>
            <a href="<?= e($base) ?>" role="button" class="outline" style="margin-bottom: 0;">Limpar</a>
        <?php endif; ?>
    </form>

    <?php if (empty($inscritos)): ?>
        <p><?= ($filtro !== '' || $busca !== '')
            ? 'Nenhuma inscrição encontrada com esse filtro.'
            : 'Ninguém se inscreveu ainda.' ?></p>
    <?php else: ?>

        <div style="overflow-x: auto;">
        <table>
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Contato</th>
                    <th>Categoria</th>
                    <th>Valor</th>
                    <th>Situação</th>
                    <th>Matrícula</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($inscritos as $i): ?>
                    <?php [$rotulo, $cor] = $situacoes[$i['status']] ?? [$i['status'], '#555']; ?>
                    <tr>
                        <td>
                            <?= e(trim($i['nome'] ?? '') ?: '(sem nome)') ?>
                            <?php if (!empty($i['instituicao']) || !empty($i['cidade'])): ?>
                                <br><small style="color: var(--muted-color);">
                                    <?= e($i['instituicao'] ?? '') ?><?php if (!empty($i['instituicao']) && !empty($i['cidade'])): ?> · <?php endif; ?>
                                    <?= e($i['cidade'] ?? '') ?><?= !empty($i['estado']) ? '/' . e($i['estado']) : '' ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td style="font-size: .9em;">
                            <?= e($i['email'] ?? '—') ?>
                            <?php if (!empty($i['telefone'])): ?>
                                <br><span style="color: var(--muted-color);"><?= e($i['telefone']) ?></span>
                            <?php endif; ?>
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
                                <small style="color: var(--muted-color);">—</small>
                            <?php elseif (tem_comprovante_evento((int)$evento['id'], (int)$i['pessoa_id'])): ?>
                                <small style="color: #2E7D32;">enviado</small>
                            <?php else: ?>
                                <small style="color: #b71c1c;">falta</small>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

    <?php endif; ?>

    <footer style="margin-top: 2rem; padding-top: 1rem; border-top: 1px solid var(--pico-muted-border-color);">
        <small>
            <strong>Esta lista tem dados pessoais de terceiros</strong>, confiados à organização do evento
            para o trabalho do evento. Use para contato e correspondência do seminário, e não repasse a
            lista nem este acesso — cada pessoa da organização pede o seu próprio.
            <br>
            <?php
            // A frase dizia "o valor pago e o CPF ficam com a tesouraria" — e
            // ficou falsa em 30/08/2026, quando o valor passou a aparecer aqui
            // a pedido do tesoureiro. O CPF continua fora, e e o que importa
            // dizer: e o dado que nao serve a trabalho nenhum da organizacao.
            //
            // Aviso que descreve errado o que a tela mostra e pior do que aviso
            // nenhum: ensina a nao ler os outros.
            ?>
            O <strong>CPF não aparece aqui</strong> e fica com a tesouraria do <?= e(ORG_NOME) ?>.
            Os valores são os desta lista; o repasse e qualquer dúvida de pagamento são com
            <a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a>.
            <br><br>
            Acesso aberto por <strong><?= e($email_sessao) ?></strong> ·
            <a href="<?= e($base) ?>/sair">sair</a>
        </small>
    </footer>
</article>
