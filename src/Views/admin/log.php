<article>
    <h2>Log do sistema</h2>
    <p><small>Últimos <?= e((string)$dias) ?> dias. Mostrando no máximo 300 linhas.</small></p>

    <?php if ($pendencias): ?>
        <div style="border-left: 4px solid #b00; background: #fff3f3; padding: 12px 16px; border-radius: 6px; margin-bottom: 1.5rem;">
            <strong>Pedem atenção</strong>
            <table style="margin: 8px 0 0;">
                <tbody>
                <?php foreach ($pendencias as $p): ?>
                    <tr>
                        <td style="width: 4rem;"><strong><?= e((string)$p['n']) ?></strong></td>
                        <td>
                            <a href="/admin/log?tipo=<?= e($p['tipo']) ?>&dias=<?= e((string)$dias) ?>"><?= e($p['tipo']) ?></a><br>
                            <small><?= e($criticos[$p['tipo']] ?? '') ?></small>
                        </td>
                        <td><small>último: <?= e($p['ultimo']) ?></small></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <p><small>Nada na lista de atenção nesta janela.</small></p>
    <?php endif; ?>

    <form method="GET" action="/admin/log" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end; margin-bottom: 1rem;">
        <label style="flex: 1 1 12rem; margin: 0;">Tipo
            <select name="tipo">
                <option value="">todos</option>
                <?php foreach ($tipos as $t): ?>
                    <option value="<?= e($t['tipo']) ?>" <?= $t['tipo'] === $tipo ? 'selected' : '' ?>>
                        <?= e($t['tipo']) ?> (<?= e((string)$t['n']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label style="flex: 1 1 12rem; margin: 0;">Contém
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="texto da mensagem">
        </label>
        <label style="flex: 0 0 7rem; margin: 0;">Dias
            <input type="number" name="dias" value="<?= e((string)$dias) ?>" min="1" max="365">
        </label>
        <button type="submit" style="flex: 0 0 auto;">Filtrar</button>
    </form>

    <?php if (!$registros): ?>
        <p>Nenhum registro com esses critérios.</p>
    <?php else: ?>
        <div style="overflow-x: auto;">
        <table>
            <thead><tr><th>Quando</th><th>Tipo</th><th>Pessoa</th><th>Mensagem</th></tr></thead>
            <tbody>
            <?php foreach ($registros as $r): ?>
                <tr<?= isset($criticos[$r['tipo']]) ? ' style="background: #fff6f6;"' : '' ?>>
                    <td style="white-space: nowrap;"><small><?= e($r['timestamp']) ?></small></td>
                    <td><small><a href="/admin/log?tipo=<?= e($r['tipo']) ?>&dias=<?= e((string)$dias) ?>"><?= e($r['tipo']) ?></a></small></td>
                    <td>
                        <?php if ($r['pessoa_id']): ?>
                            <small><a href="/admin/pessoa/<?= e((string)$r['pessoa_id']) ?>">#<?= e((string)$r['pessoa_id']) ?></a></small>
                        <?php endif; ?>
                    </td>
                    <td><small><?= e((string)$r['mensagem']) ?></small></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <p style="margin-top: 1.5rem;"><a href="/admin" role="button" class="secondary">Voltar ao painel</a></p>
</article>
