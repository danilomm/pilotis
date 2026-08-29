<?php
/**
 * Prévia dos convites de uma categoria restrita.
 *
 * Convidar cria cadastro para quem ainda não existe, e cadastro criado às
 * cegas vira duplicata. Esta tela mostra a lista já resolvida — quem é quem,
 * quem é novo, quem já respondeu — antes de qualquer email sair.
 *
 * @var array $evento
 * @var array $cat
 * @var array $destinos
 */
$rotulos = [
    'nova'          => ['Cadastro novo',      '#0b6bcb'],
    'cadastrada'    => ['Já no cadastro',     '#2E7D32'],
    'ja_convidada'  => ['Já convidada',       '#8a6d1f'],
    'ja_confirmada' => ['Inscrição confirmada', '#555555'],
    'sem_email'     => ['Sem email — inscreve-se pelo CPF', '#b71c1c'],
    'divergente'    => ['CPF e email são de pessoas diferentes', '#b71c1c'],
];
$enviaveis = array_filter($destinos, fn($d) => $d['enviavel']);
?>

<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Convites — <?= e($cat['nome']) ?></h2>
        <a href="/admin/eventos/<?= (int)$evento['id'] ?>" role="button" class="outline">Voltar ao evento</a>
    </div>

    <p><?= e($evento['nome']) ?> — categoria restrita, valor
        <strong><?= (int)$cat['valor'] > 0 ? formatar_valor((int)$cat['valor']) : 'gratuita' ?></strong>.</p>

    <?php if (empty($destinos)): ?>
        <p>A lista de liberados desta categoria está vazia. Volte ao evento e preencha CPFs ou emails.</p>
    <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>Da lista</th>
                    <th>Pessoa</th>
                    <th>Email do convite</th>
                    <th>Situação</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($destinos as $d): ?>
                    <?php [$rotulo, $cor] = $rotulos[$d['situacao']] ?? ['—', '#555']; ?>
                    <tr>
                        <td style="font-family: monospace; font-size: .9em;"><?= e($d['entrada']) ?></td>
                        <td>
                            <?php if ($d['pessoa']): ?>
                                <a href="/admin/pessoa/<?= (int)$d['pessoa']['id'] ?>">
                                    <?= e(trim($d['pessoa']['nome'] ?? '') ?: '(sem nome)') ?>
                                </a>
                                <?php if (!empty($d['homonimos'])): ?>
                                    <br><small style="color: #b71c1c;">
                                        Mesmo nome em outro cadastro:
                                        <?php foreach ($d['homonimos'] as $h): ?>
                                            <a href="/admin/pessoa/<?= (int)$h['id'] ?>">#<?= (int)$h['id'] ?></a>
                                        <?php endforeach; ?>
                                        — conferir antes de enviar.
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                <em>será criada ao enviar</em>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e($d['email'] ?: '—') ?>
                            <?php if (!empty($d['acrescenta_email'])): ?>
                                <br><small style="color: #0b6bcb;">será acrescentado ao cadastro</small>
                            <?php endif; ?>
                            <?php if (!empty($d['acrescenta_cpf'])): ?>
                                <br><small style="color: #0b6bcb;">CPF será gravado no cadastro</small>
                            <?php endif; ?>
                            <?php if (!empty($d['divergencia'])): ?>
                                <br><small style="color: #b71c1c;">
                                    Este email já é de
                                    <a href="/admin/pessoa/<?= (int)$d['divergencia']['id'] ?>"><?= e($d['divergencia']['nome']) ?></a>,
                                    e o CPF da lista aponta para outra pessoa. Quase sempre é um dígito
                                    trocado no CPF — não enviamos até isso ser resolvido.
                                </small>
                            <?php endif; ?>
                        </td>
                        <td style="color: <?= $cor ?>;"><?= e($rotulo) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top: 1rem;">
            <strong><?= count($enviaveis) ?></strong> de <?= count($destinos) ?> receberão convite.
            Quem já confirmou a inscrição não recebe, e quem já foi convidado também não —
            para mandar de novo, marque a caixa abaixo. Quem veio só com CPF e ainda não está na base não tem
            para onde receber — mas se inscreve sozinho pela página do evento, entrando com o próprio CPF:
            a lista o reconhece do mesmo jeito.
        </p>

        <?php if (!empty($enviaveis)): ?>
            <?php $novos = array_filter($enviaveis, fn($e) => ($e['situacao'] ?? '') !== 'ja_convidada'); ?>
            <form method="POST" action="/admin/eventos/<?= (int)$evento['id'] ?>/categoria/<?= (int)$cat['id'] ?>/convidar"><?= campo_csrf() ?>
                <label style="font-weight: normal;">
                    <input type="checkbox" name="reenviar" value="1">
                    Reenviar também para quem já foi convidado
                    <?php if (count($enviaveis) > count($novos)): ?>
                        (<?= count($enviaveis) - count($novos) ?>)
                    <?php endif; ?>
                </label>
                <button type="submit"
                        onclick="return confirm('Enviar convite(s) por email agora?');">
                    Enviar <?= count($novos) ?> convite(s)
                </button>
            </form>
        <?php endif; ?>

        <small style="display: block; margin-top: 1rem; color: var(--muted-color);">
            O convite leva o link pronto do formulário, com a categoria já reservada — quem recebe não
            passa pela entrada por CPF nem digita email, então não há como divergir do endereço da lista.
            Reenviar não duplica pessoa nem inscrição.
            <br>
            Uma linha por convidado, com CPF e email quando houver os dois: aí um cadastro achado pelo CPF
            recebe o email que faltava, em vez de nascer um cadastro paralelo.
            <br>
            Cadastro criado agora que na verdade já existe sob outro email é resolvido depois, pela
            vinculação: ao preencher nome e CPF, a pessoa recebe a oferta de juntar ao cadastro antigo.
        </small>

    <?php endif; ?>
</article>
