<?php
/**
 * Rodape de apoio e patrocinio. Parcial, porque aparece na pagina do evento e
 * na de inscricao — e uma so definicao evita que as duas divirjam.
 *
 * Texto e faixa nao repetem um ao outro. A faixa mostra os logotipos; o texto
 * existe para dizer o que a imagem nao diz — QUAL o papel de cada um. Por isso
 * o bloco escrito so aparece quando ha ao menos uma linha de qualificacao
 * (terminada em dois-pontos: "Realizacao:", "Apoio:"). Lista de nomes sem papel
 * nenhum seria a legenda da imagem, e vai para o alt dela, onde serve a quem
 * nao ve a figura.
 */
if (empty($evento['apoiadores']) && empty($evento['imagem_apoiadores'])) {
    return;
}

$linhas_apoio = array_values(array_filter(array_map('trim',
    preg_split('/\R/', (string)($evento['apoiadores'] ?? '')) ?: []
), fn($l) => $l !== ''));

$tem_qualificacao = false;
foreach ($linhas_apoio as $l) {
    if (substr($l, -1) === ':') { $tem_qualificacao = true; break; }
}
?>
<footer style="margin-top: 2.5rem; padding-top: 1.2rem; border-top: 1px solid var(--pico-muted-border-color);">
    <?php if ($tem_qualificacao): ?>
        <div style="font-size: .9rem; line-height: 1.6;">
        <?php foreach ($linhas_apoio as $linha): ?>
            <?php if (substr($linha, -1) === ':'): // linha terminada em dois-pontos e titulo de bloco ?>
                <p style="margin: .9rem 0 .2rem; font-weight: 600;"><?= e(rtrim($linha, ':')) ?></p>
            <?php else: ?>
                <p style="margin: 0; color: var(--pico-muted-color);"><?= e($linha) ?></p>
            <?php endif; ?>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($evento['imagem_apoiadores'])): ?>
        <?php
        // A faixa e imagem: nao vai para busca nem para leitor de tela. Quando
        // ha lista de nomes, ela vira o texto alternativo — assim a informacao
        // existe sem precisar ser repetida na pagina. Com o bloco escrito na
        // tela, repetir tudo no alt seria ouvir duas vezes; sem ele, o alt e o
        // unico lugar onde os nomes existem.
        $alt = $tem_qualificacao ? '' : implode(' · ', $linhas_apoio);
        if ($alt === '' && !$tem_qualificacao) $alt = 'Instituições envolvidas no evento';
        ?>
        <img src="<?= e(EVENTOS_IMG_URL . '/' . $evento['imagem_apoiadores']) ?>"
             alt="<?= e($alt) ?>"
             style="max-width: 100%; height: auto; margin-top: 1.2rem; display: block;">
    <?php endif; ?>
</footer>
