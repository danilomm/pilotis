<article>
    <?php if (!empty($evento['imagem_path'])):
        // A imagem pode ser uma faixa horizontal (cabecalho) ou um cartaz
        // vertical. Faixa ocupa a largura toda; cartaz fica centralizado.
        $img_arquivo = EVENTOS_IMG_DIR . '/' . $evento['imagem_path'];
        $img_info = @getimagesize($img_arquivo);
        $eh_faixa = $img_info && $img_info[1] > 0 && ($img_info[0] / $img_info[1]) >= 2;
        $img_estilo = $eh_faixa
            ? 'width: 100%; height: auto; display: block; margin: 0 0 20px; border-radius: 6px;'
            : 'width: 100%; max-width: 460px; height: auto; display: block; margin: 0 auto 24px; border-radius: 8px;';
    ?>
        <img src="<?= e(EVENTOS_IMG_URL . '/' . $evento['imagem_path']) ?>"
             alt="<?= $eh_faixa ? '' : 'Cartaz — ' . e($evento['nome']) ?>"
             style="<?= $img_estilo ?>">
    <?php endif; ?>

    <h2><?= e($evento['nome']) ?></h2>

    <?php if ($evento['organizador']): ?>
        <p><small>Organização: <?= e($evento['organizador']) ?></small></p>
    <?php endif; ?>

    <?php
    // Quando e onde ficam juntos e no alto: e o que a pessoa procura primeiro,
    // e o que vai para o cartaz impresso.
    $tem_quando = !empty($evento['data_inicio']);
    $tem_onde = !empty($evento['local']);
    ?>
    <?php if ($tem_quando || $tem_onde): ?>
        <div style="display: flex; flex-wrap: wrap; gap: 1.5rem 3rem; margin: 1.2rem 0 1.6rem;
                    padding: 1rem 1.2rem; background: var(--pico-card-sectioning-background-color);
                    border-radius: 8px;">
            <?php if ($tem_quando): ?>
                <div>
                    <strong style="display: block; font-size: .8rem; text-transform: uppercase;
                                   letter-spacing: .04em; color: var(--pico-muted-color);">Quando</strong>
                    <?= data_por_extenso($evento['data_inicio'], $evento['data_fim']) ?>
                </div>
            <?php endif; ?>
            <?php if ($tem_onde): ?>
                <div>
                    <strong style="display: block; font-size: .8rem; text-transform: uppercase;
                                   letter-spacing: .04em; color: var(--pico-muted-color);">Onde</strong>
                    <?= nl2br(e($evento['local'])) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($evento['descricao']): ?>
        <p style="font-size: 1.08rem; line-height: 1.6;"><?= nl2br(e($evento['descricao'])) ?></p>
    <?php endif; ?>

    <?php if (!empty($evento['conteudo'])): ?>
        <div class="conteudo-evento">
            <?= texto_formatado($evento['conteudo']) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($evento['programa_path'])): ?>
        <?php // A programacao e um PDF que a organizacao publica quando existir —
              // semanas antes do evento, nao na abertura das inscricoes. Ate la o
              // bloco simplesmente nao aparece. ?>
        <p style="margin: 1.5rem 0;">
            <a href="<?= e(EVENTOS_DOC_URL . '/' . $evento['programa_path']) ?>"
               role="button" class="secondary" target="_blank" rel="noopener">
                Programação (PDF)
            </a>
        </p>
    <?php endif; ?>

    <h3>Inscrição</h3>

    <table>
        <thead><tr><th>Categoria</th><th>Valor</th></tr></thead>
        <tbody>
            <?php foreach ($categorias as $cat): ?>
                <tr>
                    <td>
                        <?= e($cat['nome']) ?>
                        <?php if ($cat['requer_comprovante']): ?>
                            <br><small>(exige comprovante de matrícula)</small>
                        <?php endif; ?>
                        <?php if ($cat['verifica_adimplencia']): ?>
                            <br><small>(para filiados com anuidade em dia)</small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $v = valor_vigente_categoria($cat, $evento); ?>
                        <?= $v > 0 ? formatar_valor($v) : 'Gratuita' ?>
                        <?php if (categoria_tem_duas_faixas($cat, $evento)): ?>
                            <?php if (date('Y-m-d') < $evento['data_valor_cheio']): ?>
                                <br><small>até <?= date('d/m', strtotime($evento['data_valor_cheio'] . ' -1 day')) ?>;
                                depois <?= formatar_valor((int)$cat['valor_cheio']) ?></small>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($abertas): ?>
        <?php if ($evento['prazo_inscricao']): ?>
            <p><strong>Inscrições até <?= date('d/m/Y', strtotime($evento['prazo_inscricao'])) ?>.</strong></p>
        <?php endif; ?>

        <p style="margin: 1.5rem 0;">
            <a href="/eventos/<?= e($evento['slug']) ?>/inscricao" role="button"
               style="display: inline-block; padding: .9rem 2rem; font-size: 1.05rem;">
                Inscrever-se
            </a>
        </p>

        <p><small>O desconto de filiado depende de anuidade em dia.</small></p>
    <?php else: ?>
        <div style="padding: 12px; background: #fff3cd; color: #856404; border-radius: 6px;">
            As inscrições para este evento estão encerradas.
        </div>
    <?php endif; ?>

    <?php require SRC_DIR . '/Views/eventos/_apoiadores.php'; ?>
</article>
