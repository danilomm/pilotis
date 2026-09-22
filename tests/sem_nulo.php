<?php
/**
 * Guarda contra caractere nulo em string do codigo.
 *
 * roda: php tests/sem_nulo.php
 *
 * O SQLite corta o parametro no caractere nulo. A busca do /admin passava
 * "\x00nunca" como padrao de CPF que "nunca casa", e o que chegava ao banco era
 * um padrao VAZIO — que casa com todo CPF vazio, 1.379 dos 1.647 cadastros. De
 * 28/08 a 22/09/2026 toda busca por nome devolveu essa massa, e o corte em 50
 * escondia quem se procurava.
 *
 * O teste le os TOKENS do PHP, e nao o texto: comentario que explica o caso nao
 * conta, so string que vai para o codigo que roda.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}

$raiz = dirname(__DIR__);
$falhas = 0;

foreach (['src', 'public', 'scripts'] as $pasta) {
    $arquivos = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($raiz . '/' . $pasta, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($arquivos as $arq) {
        if ($arq->getExtension() !== 'php') {
            continue;
        }
        foreach (token_get_all(file_get_contents($arq->getPathname())) as $tok) {
            if (!is_array($tok)) {
                continue;
            }
            [$tipo, $texto, $linha] = $tok;
            if ($tipo !== T_CONSTANT_ENCAPSED_STRING && $tipo !== T_ENCAPSED_AND_WHITESPACE) {
                continue;
            }
            // Aspas simples nao interpretam escape; so a dupla vira nulo de fato.
            $dupla = $tipo === T_ENCAPSED_AND_WHITESPACE || $texto[0] === '"';
            if ($dupla && preg_match('/\\\\(x0{1,2}|0)(?![0-7x])/', $texto)
                || str_contains($texto, "\0")) {
                $rel = substr($arq->getPathname(), strlen($raiz) + 1);
                echo "FALHA  $rel:$linha  caractere nulo em string: $texto\n";
                $falhas++;
            }
        }
    }
}

echo $falhas ? "\n$falhas string(s) com caractere nulo.\n" : "Tudo certo.\n";
exit($falhas ? 1 : 0);
