<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Eventos</h2>
        <div>
            <a href="/admin/eventos/novo" role="button">+ Novo Evento</a>
            <a href="/admin" role="button" class="outline">Voltar</a>
        </div>
    </div>

    <?php if (empty($eventos)): ?>
        <p>Nenhum evento cadastrado ainda.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Evento</th>
                    <th>Status</th>
                    <th>Prazo inscrição</th>
                    <th>Inscrições</th>
                    <th>Confirmadas</th>
                    <th>Arrecadado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eventos as $ev): ?>
                    <?php
                        $cores = ['rascunho' => '#856404', 'publicado' => '#155724', 'encerrado' => '#6c757d'];
                        $fundos = ['rascunho' => '#fff3cd', 'publicado' => '#d4edda', 'encerrado' => '#e9ecef'];
                        $cor = $cores[$ev['status']] ?? '#333';
                        $fundo = $fundos[$ev['status']] ?? '#eee';
                    ?>
                    <tr>
                        <td>
                            <a href="/admin/eventos/<?= (int)$ev['id'] ?>"><strong><?= e($ev['nome']) ?></strong></a><br>
                            <small><?= e($ev['slug']) ?><?= $ev['organizador'] ? ' — ' . e($ev['organizador']) : '' ?></small>
                        </td>
                        <td><span style="background: <?= $fundo ?>; color: <?= $cor ?>; padding: 2px 8px; border-radius: 4px; font-size: 0.85em;"><?= e($ev['status']) ?></span></td>
                        <td><?= $ev['prazo_inscricao'] ? date('d/m/Y', strtotime($ev['prazo_inscricao'])) : '—' ?></td>
                        <td>
                            <?php if ((int)$ev['total_inscricoes'] > 0): ?>
                                <a href="/admin/eventos/<?= (int)$ev['id'] ?>/inscritos"><?= (int)$ev['total_inscricoes'] ?></a>
                            <?php else: ?>
                                0
                            <?php endif; ?>
                        </td>
                        <td><?= (int)$ev['confirmadas'] ?></td>
                        <td><?= formatar_valor((int)$ev['arrecadado']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</article>
