<?php
/**
 * Teste do formato de conteudo da pagina do evento.
 *
 * Roda sem dependencia: php tests/texto_formatado.php
 *
 * O que ele guarda nao e a aparencia — e a propriedade de seguranca. O texto e
 * escrito pela organizacao do evento no painel e vai direto para uma pagina
 * publica. texto_formatado() escapa ANTES de converter; o teste confere que
 * nenhuma entrada produz tag ou atributo fora da lista, inclusive as que
 * tentam de proposito.
 */

// Nao e endpoint: sem isto, um .php em pasta servida vira URL executavel.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}

require_once __DIR__ . '/../src/config.php';

$casos = [
    'titulo'         => ["## Apresentação\n\nO seminário reúne pesquisadores.", '<h3>Apresentação</h3>'],
    'subtitulo'      => ["### Eixo 1", '<h4>Eixo 1</h4>'],
    'lista'          => ["- Documentação\n- Preservação", '<li>Documentação</li>'],
    'lista numerada' => ["1. Enviar resumo\n2. Aguardar parecer", '<ol>'],
    'linhas se juntam' => ["Uma frase que veio\nquebrada em duas linhas.", '<p>Uma frase que veio quebrada em duas linhas.</p>'],
    'parágrafos'     => ["Um.\n\nDois.", "<p>Um.</p>\n<p>Dois.</p>"],
    'negrito'        => ["até **30 de setembro**", '<strong>30 de setembro</strong>'],
    'italico'        => ["o termo *moderno* aqui", '<em>moderno</em>'],
    'link'           => ["[edital](https://docomomobrasil.com/e)", 'href="https://docomomobrasil.com/e"'],
    'mailto'         => ["[contato](mailto:v@exemplo.org)", 'href="mailto:v@exemplo.org"'],
    'url com &'      => ["[busca](https://x.org/?a=1&b=2)", 'href="https://x.org/?a=1&amp;b=2"'],
    'divisoria'      => ["Antes\n\n---\n\nDepois", '<hr>'],
    'asterisco solto'=> ["Nota* sobre 3 * 4", 'Nota* sobre 3 * 4'],
    'vazio'          => ["", ''],
    'so espaco'      => ["   \n\n  ", ''],
];

$ataques = [
    'tag script'     => '<script>alert(1)</script>',
    'img onerror'    => '<img src=x onerror="alert(1)">',
    'link javascript'=> '[clique](javascript:alert(1))',
    'link data'      => '[clique](data:text/html,<script>alert(1)</script>)',
    'aspas no href'  => '[x](https://a.com" onmouseover="alert(1))',
    'aspas no titulo'=> '## Título" onload="alert(1)',
    'iframe'         => '<iframe src="https://mal.example"></iframe>',
    'svg onload'     => '<svg onload=alert(1)>',
    'entidade'       => '&lt;script&gt;alert(1)&lt;/script&gt;',
];

/** Devolve a descricao do problema, ou '' se a saida so tem a marcacao permitida. */
function marcacao_indevida(string $html): string {
    if (preg_match_all('/<a href="([^"]*)"( rel="noopener")?>/', $html, $ms)) {
        foreach ($ms[1] as $href) {
            if (!preg_match('#^(https?://|mailto:)#i', $href)) {
                return "href com esquema nao permitido: $href";
            }
        }
    }
    $resto = preg_replace([
        '/<a href="[^"]*"( rel="noopener")?>/',
        '#</a>#',
        '#</?(p|hr|h[1-5]|ul|ol|li|strong|em)>#',
    ], '', $html);
    if (strpos($resto, '<') !== false || strpos($resto, '>') !== false) {
        return 'marcação crua na saída: ' . substr(trim($resto), 0, 70);
    }
    return '';
}

$falhas = 0;

foreach ($casos as $nome => [$entrada, $esperado]) {
    $saida = texto_formatado($entrada);
    $problema = marcacao_indevida($saida);
    $ok = $problema === '' && ($esperado === '' ? $saida === '' : strpos($saida, $esperado) !== false);
    if (!$ok) {
        $falhas++;
        echo "FALHA  $nome\n";
        echo "  esperava conter: " . var_export($esperado, true) . "\n";
        echo "  obteve:          " . var_export($saida, true) . "\n";
        if ($problema !== '') echo "  $problema\n";
    } else {
        echo "ok     $nome\n";
    }
}

foreach ($ataques as $nome => $entrada) {
    $saida = texto_formatado($entrada);
    $problema = marcacao_indevida($saida);
    if ($problema !== '') {
        $falhas++;
        echo "FALHA  ataque: $nome\n  $problema\n  saída: " . var_export($saida, true) . "\n";
    } else {
        echo "ok     ataque neutralizado: $nome\n";
    }
}

echo "\n" . ($falhas === 0
    ? count($casos) . ' casos e ' . count($ataques) . " ataques: tudo passou.\n"
    : "$falhas falha(s).\n");

exit($falhas === 0 ? 0 : 1);
