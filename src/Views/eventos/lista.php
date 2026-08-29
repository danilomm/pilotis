<article>
    <h2>Eventos com inscrições abertas</h2>

    <?php if (empty($eventos)): ?>
        <p>Nenhum evento com inscrições abertas no momento.</p>
        <p>Acompanhe a programação em <a href="<?= e(ORG_SITE_URL) ?>"><?= e(preg_replace('#^https?://(www\.)?#', '', ORG_SITE_URL)) ?></a>.</p>
    <?php else: ?>
        <?php foreach ($eventos as $ev): ?>
            <div style="border: 1px solid #ddd; border-left: 4px solid var(--org-cor-secundaria); border-radius: 8px; padding: 16px; margin-bottom: 16px;">
                <?php if (!empty($ev['imagem_path'])): ?>
                    <a href="/eventos/<?= e($ev['slug']) ?>">
                        <img src="<?= e(EVENTOS_IMG_URL . '/' . $ev['imagem_path']) ?>"
                             alt="Cartaz — <?= e($ev['nome']) ?>"
                             style="float: right; width: 110px; height: auto; margin: 0 0 12px 16px; border-radius: 6px;">
                    </a>
                <?php endif; ?>
                <h3 style="margin-top: 0;"><a href="/eventos/<?= e($ev['slug']) ?>"><?= e($ev['nome']) ?></a></h3>
                <?php if ($ev['organizador']): ?>
                    <p style="margin: 4px 0;"><small><?= e($ev['organizador']) ?></small></p>
                <?php endif; ?>
                <p style="margin: 4px 0;">
                    <?php if ($ev['data_inicio']): ?>
                        <strong><?= data_por_extenso($ev['data_inicio'], $ev['data_fim']) ?></strong>
                    <?php endif; ?>
                    <?php if (!empty($ev['local'])): ?>
                        <?php // So a primeira linha: aqui e referencia, nao endereco de chegada. ?>
                        — <?= e(trim(strtok((string)$ev['local'], "\n"))) ?>
                    <?php endif; ?>
                    <?php if ($ev['prazo_inscricao']): ?>
                        <br><small>inscrições até <?= date('d/m/Y', strtotime($ev['prazo_inscricao'])) ?></small>
                    <?php endif; ?>
                </p>

                <?php if (!empty($ev['descricao'])): ?>
                    <p style="margin: 8px 0 12px;"><?= nl2br(e($ev['descricao'])) ?></p>
                <?php endif; ?>

                <a href="/eventos/<?= e($ev['slug']) ?>" role="button">Ver evento e inscrever-se</a>
                <div style="clear: both;"></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</article>
