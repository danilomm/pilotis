<?php
/**
 * Guarda a protecao contra CSRF.
 *
 * roda: php tests/csrf.php
 *
 * Ate 29/08/2026 nenhuma das 44 rotas POST conferia origem: com o tesoureiro
 * logado no /admin, uma pagina aberta noutra aba disparava "marcar como pago",
 * "excluir pessoa" ou o envio de um lote de campanha — o navegador manda o
 * cookie sozinho.
 *
 * Como a insercao do campo em 46 formularios foi feita de uma vez, o risco
 * agora e o inverso: alguem escrever um <form method="POST"> novo e esquecer o
 * campo. Formulario sem campo nao da erro visivel na hora de escrever — da
 * erro para a PESSOA que clica, depois, em producao. Por isso o teste e de
 * codigo: varre as views e cobra o campo em todo formulario POST.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}

$raiz = dirname(__DIR__);
$falhas = 0;
$conferidos = 0;

/** Fim da tag iniciada em $i, pulando <?php ?> e aspas. */
function fim_da_tag(string $s, int $i): int {
    $j = $i; $aspas = null;
    while ($j < strlen($s)) {
        if (substr($s, $j, 2) === '<?') {
            $k = strpos($s, '?>', $j);
            if ($k === false) return -1;
            $j = $k + 2;
            continue;
        }
        $c = $s[$j];
        if ($aspas !== null) {
            if ($c === $aspas) $aspas = null;
        } elseif ($c === '"' || $c === "'") {
            $aspas = $c;
        } elseif ($c === '>') {
            return $j;
        }
        $j++;
    }
    return -1;
}

echo "== formularios POST com campo_csrf() ==\n";

$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($raiz . '/src/Views', FilesystemIterator::SKIP_DOTS)
);
foreach ($it as $arquivo) {
    if ($arquivo->getExtension() !== 'php') continue;
    $s = file_get_contents($arquivo->getPathname());
    $rel = str_replace($raiz . '/', '', $arquivo->getPathname());

    if (!preg_match_all('/<form\b/i', $s, $m, PREG_OFFSET_CAPTURE)) continue;
    foreach ($m[0] as $achado) {
        $ini = $achado[1];
        $fim = fim_da_tag($s, $ini);
        if ($fim === -1) continue;
        $tag = substr($s, $ini, $fim - $ini + 1);
        if (!preg_match('/method\s*=\s*["\']?post/i', $tag)) continue;

        $conferidos++;
        $linha = substr_count(substr($s, 0, $ini), "\n") + 1;
        if (strpos(substr($s, $fim, 120), 'campo_csrf') === false) {
            echo "  FALHA  $rel:$linha  <form method=\"POST\"> sem campo_csrf()\n";
            $falhas++;
        }
    }
}
echo "  $conferidos formularios POST conferidos\n\n";

echo "== o dispatcher exige o token em POST ==\n";
$routes = file_get_contents($raiz . '/src/routes.php');
foreach ([
    'csrf_valido'  => 'a funcao de conferencia existe',
    'hash_equals'  => 'a comparacao e por hash_equals, nao ===',
    'random_bytes' => 'o token vem de random_bytes',
] as $agulha => $porque) {
    if (strpos($routes, $agulha) === false) {
        echo "  FALHA  routes.php: $porque\n";
        $falhas++;
    } else {
        echo "  ok     $porque\n";
    }
}
if (!preg_match('/\$method\s*===\s*.POST.\s*&&.*csrf_valido\(\)/s', $routes)) {
    echo "  FALHA  routes.php: o dispatcher nao barra POST sem token\n";
    $falhas++;
} else {
    echo "  ok     o dispatcher barra POST sem token\n";
}

echo "\n== chamadas fetch() POST mandam o cabecalho ==\n";
$it2 = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($raiz . '/src/Views', FilesystemIterator::SKIP_DOTS)
);
foreach ($it2 as $arquivo) {
    if ($arquivo->getExtension() !== 'php') continue;
    $s = file_get_contents($arquivo->getPathname());
    $rel = str_replace($raiz . '/', '', $arquivo->getPathname());
    if (!preg_match_all('/fetch\(([^;]*?)\}\)/s', $s, $m2)) continue;
    foreach ($m2[0] as $chamada) {
        if (stripos($chamada, "method: 'POST'") === false
            && stripos($chamada, 'method: "POST"') === false) continue;
        if (stripos($chamada, 'X-CSRF-Token') === false) {
            echo "  FALHA  $rel: fetch POST sem X-CSRF-Token\n";
            $falhas++;
        } else {
            echo "  ok     $rel: fetch POST com X-CSRF-Token\n";
        }
    }
}

echo "\n";
if ($falhas > 0) {
    echo "FALHOU: $falhas problema(s).\n";
    exit(1);
}
echo "Tudo certo.\n";
exit(0);
