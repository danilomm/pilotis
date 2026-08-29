<article>
    <h2>Inscrição confirmada ✓</h2>

    <div class="alert alert-success" style="padding: 16px; background: #d4edda; color: #155724; border-radius: 8px;">
        <p style="margin: 0 0 8px 0;"><strong><?= e($cadastrado['nome'] ?: 'Participante') ?></strong>, sua inscrição está confirmada!</p>
        <p style="margin: 0 0 4px 0;"><strong>Evento:</strong> <?= e($evento['nome']) ?></p>
        <?php if (!empty($categoria['nome'])): ?>
            <p style="margin: 0 0 4px 0;"><strong>Categoria:</strong> <?= e($categoria['nome']) ?></p>
        <?php endif; ?>
        <p style="margin: 0;"><strong>Valor:</strong> <?= (int)($inscricao['valor'] ?? 0) > 0 ? formatar_valor((int)$inscricao['valor']) : 'Gratuita' ?></p>
    </div>

    <?php if ($evento['data_inicio']): ?>
        <?php $varios_dias = $evento['data_fim'] && $evento['data_fim'] !== $evento['data_inicio']; ?>
        <p>
            <strong><?= $varios_dias ? 'Datas do evento:' : 'Data do evento:' ?></strong>
            <?= date('d/m/Y', strtotime($evento['data_inicio'])) ?><?php
                if ($varios_dias) echo ' a ' . date('d/m/Y', strtotime($evento['data_fim']));
            ?>
        </p>
    <?php endif; ?>

    <p>Você também recebeu esta confirmação por email. Informações operacionais do evento (links, instruções, material) serão enviadas pela organização.</p>

    <p><small>Dúvidas: <a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a></small></p>
</article>
