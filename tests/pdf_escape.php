<?php
/**
 * Guarda o escape do que entra no HTML lido pelo TCPDF.
 *
 * roda: php tests/pdf_escape.php
 *
 * POR QUE: o TCPDF **interpreta** o HTML que recebe — inclusive `<img>`, que ele
 * carrega do disco por caminho absoluto. Como `pessoas.nome` vem de `$_POST` com
 * `trim()` e mais nada, um nome contendo uma tag de imagem fazia o comprovante
 * de inscricao sair com o **comprovante de matricula de outra pessoa** embutido
 * — e o sistema envia esse PDF por email a quem se inscreveu. Sem credencial
 * nenhuma: bastava se inscrever.
 *
 * A revisao de 29/08/2026 conferiu 693 saidas de VIEW com `e()` e nao viu isto:
 * o TCPDF e um SEGUNDO renderizador de HTML, e ninguem o contou. Este teste e o
 * equivalente do `texto_formatado.php` para o outro renderizador.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}

$raiz = dirname(__DIR__);
require_once $raiz . '/src/config.php';
require_once $raiz . '/src/Services/PdfService.php';

$falhas = 0;

// Cargas que o TCPDF interpretaria. Dados ficticios, como manda a regra do
// projeto — nenhum nome, CPF ou caminho de pessoa real.
$ataques = [
    'img absoluta'   => 'Fulana <img src="/home/pilotis/dados_privados/comprovantes/evt1_1.jpg">',
    'img relativa'   => 'Fulana <img src="../../dados_privados/comprovantes/x.png">',
    'img http'       => 'Fulana <img src="http://exemplo.invalido/rastreio.png">',
    'tag fechando'   => 'Fulana</strong><img src="/etc/x.jpg"><strong>',
    'aspas simples'  => "Fulana <img src='/tmp/x.jpg'>",
    'maiuscula'      => 'Fulana <IMG SRC="/tmp/x.jpg">',
    'link'           => 'Fulana <a href="http://exemplo.invalido">clique</a>',
    'estilo'         => 'Fulana <div style="background:url(/tmp/x.jpg)">x</div>',
];

/** Chama metodo privado — sao internos por desenho, e e a propriedade deles que importa. */
function chamar(string $metodo, array $args) {
    $r = new ReflectionMethod('PdfService', $metodo);
    $r->setAccessible(true);
    return $r->invokeArgs(null, $args);
}

echo "== comprovante de inscricao ==\n";
foreach ($ataques as $nome_caso => $carga) {
    $html = chamar('textoComprovantePadrao',
        [$carga, '000.000.000-00', 'Evento Ficticio', 'Participante', 'R$ 50,00', '01/01/2026', 'PIX']);
    $ok = stripos($html, '<img') === false
       && stripos($html, '<a ') === false
       && stripos($html, '<div') === false
       && strpos($html, '&lt;') !== false;
    printf("  %-16s %s\n", $nome_caso, $ok ? 'neutralizado' : 'FALHOU');
    if (!$ok) { $falhas++; echo "    saiu: " . substr($html, 0, 160) . "\n"; }
}

echo "\n== o mesmo pelos outros campos, nao so o nome ==\n";
foreach (['evento' => 2, 'categoria' => 3] as $campo => $pos) {
    $args = ['Fulana Ficticia', '000.000.000-00', 'Evento Ficticio', 'Participante', 'R$ 50,00', '01/01/2026', 'PIX'];
    $args[$pos] = '<img src="/tmp/x.jpg">';
    $html = chamar('textoComprovantePadrao', $args);
    $ok = stripos($html, '<img') === false;
    printf("  %-16s %s\n", $campo, $ok ? 'neutralizado' : 'FALHOU');
    if (!$ok) $falhas++;
}

echo "\n== declaracao de filiacao ==\n";
foreach (['img absoluta', 'tag fechando'] as $caso) {
    $html = chamar('textoDeclaracaoPadrao', [$ataques[$caso], 'Filiado Pleno Brasil', 'R$ 240,00', 2026]);
    $ok = stripos($html, '<img') === false && strpos($html, '&lt;') !== false;
    printf("  %-16s %s\n", $caso, $ok ? 'neutralizado' : 'FALHOU');
    if (!$ok) $falhas++;
}

echo "\n== o texto legitimo continua legivel ==\n";
$html = chamar('textoComprovantePadrao',
    ['Fulana de Tal & Filha', '000.000.000-00', 'Seminário "Modernidade"', 'Participante', 'R$ 50,00', '01/01/2026', 'PIX']);
$casos_ok = [
    'nome aparece'        => strpos($html, 'Fulana de Tal') !== false,
    'e comercial escapa'  => strpos($html, '&amp;') !== false,
    'aspas escapam'       => strpos($html, '&quot;') !== false,
    'marcacao do sistema' => strpos($html, '<strong>') !== false && strpos($html, '<p>') !== false,
];
foreach ($casos_ok as $nome_caso => $ok) {
    printf("  %-22s %s\n", $nome_caso, $ok ? 'ok' : 'FALHOU');
    if (!$ok) $falhas++;
}

echo "\n== carregar_template escapa no HTML e nao no assunto ==\n";
$fonte = file_get_contents($raiz . '/src/Dados/Templates.php');
$checks = [
    'escapa o valor que vai ao HTML' => strpos($fonte, 'htmlspecialchars($cru') !== false,
    'assunto recebe o valor cru'     => preg_match('/\$assunto = str_replace\([^)]*\$cru/', $fonte) === 1,
    'html recebe o valor escapado'   => preg_match('/\$html\s*= str_replace\([^)]*\$seguro/', $fonte) === 1,
    'ha canal separado para HTML'    => strpos($fonte, '$vars_html') !== false,
];
foreach ($checks as $nome_caso => $ok) {
    printf("  %-34s %s\n", $nome_caso, $ok ? 'ok' : 'FALHOU');
    if (!$ok) $falhas++;
}

echo "\n";
if ($falhas > 0) { echo "FALHOU: $falhas problema(s).\n"; exit(1); }
echo "Tudo certo.\n";
exit(0);
