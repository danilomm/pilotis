<?php
/**
 * Rodape institucional do evento: quem organiza e quem apoia.
 *
 * Arquivo a parte, e nao um trecho do `pagina.php`, porque a decisao de o que
 * mostrar e o que mandar para o `alt` tem regra propria — a de baixo — e ela
 * cabe inteira aqui. Hoje so o `pagina.php` o inclui; a tela de inscricao e
 * deliberadamente curta (o campo de CPF sem rolar a pagina) e nao o carrega.
 *
 * REGRA (30/08/2026): a faixa MOSTRA, o texto EXPLICA, e nenhum dos dois
 * repete o outro.
 *
 *   com faixa  — os nomes ja estao la, em logotipo. O texto escrito vira o
 *                `alt` da imagem: a informacao continua existindo para quem
 *                nao ve a figura e para a busca, sem imprimir duas vezes a
 *                mesma lista de instituicoes.
 *   sem faixa  — o texto e o unico lugar onde os nomes existem, e aparece.
 *
 * A regra anterior era outra — o texto aparecia quando "qualificava", isto e,
 * quando havia linha terminada em dois-pontos ("Apoio institucional:"). Na
 * pratica isso nao distinguia nada: o sdrj05 tem a linha de qualificacao E a
 * faixa com os mesmos cinco logotipos, e a pagina saiu com a lista duas vezes.
 * A regra de agora e previsivel e quem edita a controla: para ter os creditos
 * escritos na tela, nao se sobe faixa.
 *
 * O que se perde, dito com todas as letras: havendo faixa, o PAPEL de cada
 * instituicao (realizacao x apoio) fica so no alt. Se um dia isso importar
 * mais do que a repeticao incomoda, o lugar de mudar e aqui.
 *
 * O ORGANIZADOR desceu para ca em 30/08/2026. Ficava como linha solta abaixo
 * do titulo, onde apenas repetia o nome do evento ("V Seminario Docomomo Rio
 * de Janeiro" / "Organizacao: Docomomo Rio de Janeiro"). Credito institucional
 * e assunto de rodape, junto com o apoio.
 */
$linhas_apoio = array_values(array_filter(array_map('trim',
    preg_split('/\R/', (string)($evento['apoiadores'] ?? '')) ?: []
), fn($l) => $l !== ''));

$tem_faixa = !empty($evento['imagem_apoiadores']);
$organizador = trim((string)($evento['organizador'] ?? ''));

if (!$linhas_apoio && !$tem_faixa && $organizador === '') {
    return;
}
?>
<footer style="margin-top: 2.5rem; padding-top: 1.2rem; border-top: 1px solid var(--pico-muted-border-color);">
    <?php if ($organizador !== ''): ?>
        <p style="margin: 0; font-size: .9rem;">
            <strong>Organização:</strong> <?= e($organizador) ?>
        </p>
    <?php endif; ?>

    <?php if ($linhas_apoio && !$tem_faixa): ?>
        <div style="font-size: .9rem; line-height: 1.6; margin-top: .9rem;">
        <?php foreach ($linhas_apoio as $linha): ?>
            <?php if (substr($linha, -1) === ':'): // linha terminada em dois-pontos e titulo de bloco ?>
                <p style="margin: .9rem 0 .2rem; font-weight: 600;"><?= e(rtrim($linha, ':')) ?></p>
            <?php else: ?>
                <p style="margin: 0; color: var(--pico-muted-color);"><?= e($linha) ?></p>
            <?php endif; ?>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($tem_faixa): ?>
        <?php
        // O alt carrega o texto escrito. A linha de qualificacao termina em
        // dois-pontos e por isso ja emenda na lista sem precisar de pontuacao
        // nossa; as demais se separam por ponto medio.
        $alt = '';
        foreach ($linhas_apoio as $i => $linha) {
            if ($i === 0)                            $alt  = $linha;
            elseif (substr($alt, -1) === ':')        $alt .= ' ' . $linha;
            else                                     $alt .= ' · ' . $linha;
        }
        if ($alt === '') $alt = 'Instituições envolvidas no evento';
        ?>
        <img src="<?= e(EVENTOS_IMG_URL . '/' . $evento['imagem_apoiadores']) ?>"
             alt="<?= e($alt) ?>"
             style="max-width: 100%; height: auto; margin-top: 1.2rem; display: block;">
    <?php endif; ?>
</footer>
