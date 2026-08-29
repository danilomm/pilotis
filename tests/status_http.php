<?php
/**
 * Guarda contra o 200 silencioso em pagina de erro.
 *
 * roda: php tests/status_http.php
 *
 * header('HTTP/1.1 200 OK') grava uma linha de status CRUA, e um
 * http_response_code(404) posterior NAO a substitui. Enquanto o dispatcher
 * usava essa forma antes de chamar o handler, todo error_404() e todo
 * error_500() levantado de dentro de uma rota que casou respondia 200: o 404 de
 * slug errado era indexavel pelo Google, e a excecao no pagamento aparecia como
 * pagina sa para qualquer monitor de disponibilidade.
 *
 * O comentario no layout.php descrevia esse conserto desde 28/08/2026 — e ele
 * estava feito ali, mas nao no dispatcher, que roda antes. Por isso o teste e
 * de codigo, e nao de comportamento: garante que a forma crua nao volte a
 * nenhum arquivo, em vez de conferir uma rota so.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}

$raiz = dirname(__DIR__);
$falhas = 0;

$arquivos = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($raiz . '/src', FilesystemIterator::SKIP_DOTS)
);
$lista = [];
foreach ($arquivos as $arquivo) {
    if ($arquivo->getExtension() === 'php') {
        $lista[] = $arquivo->getPathname();
    }
}
foreach (glob($raiz . '/public/*.php') as $arquivo) {
    $lista[] = $arquivo;
}

foreach ($lista as $caminho) {
    foreach (file($caminho) as $n => $linha) {
        // Comentario que MENCIONA a forma errada nao e a forma errada — esta
        // pagina de teste existe justamente para ser explicada em comentario.
        $sem_espaco = ltrim($linha);
        if ($sem_espaco === '' || $sem_espaco[0] === '*'
            || str_starts_with($sem_espaco, '//') || str_starts_with($sem_espaco, '/*')) {
            continue;
        }
        // Linha de status crua em qualquer forma: header('HTTP/...').
        if (preg_match('/header\s*\(\s*[\'"]HTTP\//i', $linha)) {
            $falhas++;
            $relativo = substr($caminho, strlen($raiz) + 1);
            echo "FALHA  $relativo:" . ($n + 1) . "\n";
            echo "  " . trim($linha) . "\n";
            echo "  Use http_response_code(): a linha crua nao e substituida depois.\n";
        }
    }
}

echo $falhas === 0
    ? count($lista) . " arquivos: nenhuma linha de status crua.\n"
    : "$falhas ocorrência(s).\n";

exit($falhas === 0 ? 0 : 1);
