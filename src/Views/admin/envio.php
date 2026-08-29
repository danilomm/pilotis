<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Envio #<?= $lote['id'] ?> — <?= e(ucfirst($lote['tipo'])) ?> <?= $lote['ano'] ?></h2>
        <a href="/admin/campanha" role="button" class="outline">Voltar</a>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin-bottom: 20px;">
        <div style="background: #f8f9fa; padding: 12px; border-radius: 8px; text-align: center;">
            <div style="font-size: 1.5em; font-weight: bold;"><?= $lote['total_enviados'] ?></div>
            <small>Total</small>
        </div>
        <div style="background: #d4edda; padding: 12px; border-radius: 8px; text-align: center;">
            <div style="font-size: 1.5em; font-weight: bold; color: #155724;"><?= $lote['total_sucesso'] ?></div>
            <small>Sucesso</small>
        </div>
        <?php if ($lote['total_falha'] > 0): ?>
        <div style="background: #f8d7da; padding: 12px; border-radius: 8px; text-align: center;">
            <div style="font-size: 1.5em; font-weight: bold; color: #721c24;"><?= $lote['total_falha'] ?></div>
            <small>Falha</small>
        </div>
        <?php endif; ?>
        <div style="background: #f8f9fa; padding: 12px; border-radius: 8px; text-align: center;">
            <div style="font-size: 0.9em;"><?= date('d/m/Y H:i', strtotime($lote['created_at'])) ?></div>
            <small>Data</small>
        </div>
    </div>

    <!-- Email enviado -->
    <details open style="margin-bottom: 20px; background: #f8f9fa; padding: 12px 15px; border-radius: 8px; border: 1px solid #dee2e6;">
        <summary style="cursor: pointer; font-weight: bold;">Email enviado</summary>
        <div style="margin-top: 10px;">
            <p><strong>Assunto:</strong> <?= e($lote['assunto_snapshot']) ?></p>
            <div style="border: 1px solid #ddd; padding: 15px; background: white; border-radius: 5px; margin-top: 10px;">
                <?= $lote['html_snapshot'] ?>
            </div>
        </div>
    </details>

    <!-- Destinatários -->
    <details style="background: #f8f9fa; padding: 12px 15px; border-radius: 8px; border: 1px solid #dee2e6;">
        <summary style="cursor: pointer; font-weight: bold;">Destinatarios (<?= count($destinatarios) ?>)</summary>
        <div style="margin-top: 10px; max-height: 400px; overflow-y: auto;">
            <table style="width: 100%; font-size: 0.85em;">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Email</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($destinatarios as $d): ?>
                    <tr>
                        <td><?= e($d['nome'] ?: '—') ?></td>
                        <td><?= e($d['email']) ?></td>
                        <td>
                            <?php if ($d['sucesso']): ?>
                                <span style="color: #155724;">OK</span>
                            <?php else: ?>
                                <span style="color: #721c24;">Falha</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </details>
</article>
