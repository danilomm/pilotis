<?php
/**
 * Guarda a tabela de rotas: todo handler citado tem de existir.
 *
 * roda: php tests/rotas.php
 *
 * POR QUE: as rotas apontam para handlers por STRING ('AdminController::painel').
 * Uma string nao e verificada por nada — nem pelo `php -l`, nem pelo editor. Um
 * metodo renomeado, movido de classe ou com erro de digitacao so aparece quando
 * alguem abre aquela URL, e o sintoma e 500, nao "rota quebrada".
 *
 * Isso deixou de ser hipotetico quando o AdminController, com 3.058 linhas, foi
 * dividido por assunto: cinquenta rotas mudaram de classe de uma vez. O risco
 * nao e errar todas — e errar uma, numa tela pouco usada, e descobrir em
 * producao, sem SSH.
 *
 * Teste de CODIGO, nao de comportamento: le index.php e confere as strings.
 * Nao levanta servidor nem toca no banco.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}

$raiz = dirname(__DIR__);

// Carrega as classes sem executar o dispatcher.
require_once $raiz . '/src/config.php';
require_once $raiz . '/src/db.php';
foreach (glob($raiz . '/src/Controllers/*.php') as $c) {
    require_once $c;
}

$fonte = file_get_contents($raiz . '/public/index.php');
$falhas = 0;

// get('/rota', 'Classe::metodo')  —  fecho anonimo nao entra aqui, e proposital:
// ele e verificado pelo proprio php -l.
preg_match_all(
    "/^\s*(get|post)\(\s*'([^']+)'\s*,\s*'([A-Za-z_][A-Za-z0-9_]*)::([A-Za-z_][A-Za-z0-9_]*)'/m",
    $fonte, $m, PREG_SET_ORDER
);

echo "== handlers de rota ==\n";
$vistos = [];
foreach ($m as $r) {
    [, $metodo_http, $rota, $classe, $metodo] = $r;
    $vistos[] = "$metodo_http $rota";

    if (!class_exists($classe)) {
        echo "  FALHA  $metodo_http $rota -> classe inexistente: $classe\n";
        $falhas++;
        continue;
    }
    if (!method_exists($classe, $metodo)) {
        echo "  FALHA  $metodo_http $rota -> $classe nao tem o metodo $metodo()\n";
        $falhas++;
        continue;
    }
    $ref = new ReflectionMethod($classe, $metodo);
    if (!$ref->isPublic()) {
        echo "  FALHA  $metodo_http $rota -> $classe::$metodo() nao e publico\n";
        $falhas++;
        continue;
    }
    if (!$ref->isStatic()) {
        echo "  FALHA  $metodo_http $rota -> $classe::$metodo() nao e estatico\n";
        $falhas++;
        continue;
    }

    // A rota passa um argumento por {parametro}; o metodo tem de aceita-los.
    preg_match_all('/\{(\w+)\}/', $rota, $params);
    $n_rota = count($params[1]);
    $n_min  = $ref->getNumberOfRequiredParameters();
    $n_max  = $ref->getNumberOfParameters();
    if ($n_rota < $n_min || $n_rota > $n_max) {
        echo "  FALHA  $metodo_http $rota tem $n_rota parametro(s), mas "
           . "$classe::$metodo() aceita de $n_min a $n_max\n";
        $falhas++;
    }
}
echo "  " . count($m) . " rotas com handler nomeado conferidas\n";

echo "\n== rotas duplicadas ==\n";
$dup = array_filter(array_count_values($vistos), fn($n) => $n > 1);
if ($dup) {
    foreach ($dup as $rota => $n) {
        echo "  FALHA  $rota declarada $n vezes — a primeira vence, a outra e codigo morto\n";
        $falhas++;
    }
} else {
    echo "  ok     nenhuma\n";
}

echo "\n== ordem: rota literal antes de rota com curinga no mesmo nivel ==\n";
// O dispatcher casa na ORDEM. '/eventos/{slug}/{token}' engoliria
// '/eventos/{slug}/inscricao' se viesse antes — foi o que quase aconteceu em
// 29/08/2026 quando a entrada por CPF virou pagina propria.
$ordem = [];
foreach ($m as $r) { $ordem[] = [$r[1], $r[2]]; }
foreach ($ordem as $i => [$mh, $rota]) {
    $partes = explode('/', trim($rota, '/'));
    foreach (array_slice($ordem, 0, $i) as [$mh2, $anterior]) {
        if ($mh2 !== $mh) continue;
        $p2 = explode('/', trim($anterior, '/'));
        if (count($p2) !== count($partes)) continue;
        $engole = true;
        $tem_curinga_onde_ha_literal = false;
        foreach ($partes as $k => $seg) {
            $seg2 = $p2[$k];
            $cur2 = str_starts_with($seg2, '{');
            $cur1 = str_starts_with($seg, '{');
            if (!$cur2 && $seg2 !== $seg) { $engole = false; break; }
            if ($cur2 && !$cur1) $tem_curinga_onde_ha_literal = true;
        }
        if ($engole && $tem_curinga_onde_ha_literal) {
            echo "  FALHA  '$anterior' vem antes e engole '$rota'\n";
            $falhas++;
        }
    }
}
if (!$falhas) echo "  ok     nenhuma rota inalcancavel\n";

echo "\n";
if ($falhas > 0) {
    echo "FALHOU: $falhas problema(s).\n";
    exit(1);
}
echo "Tudo certo.\n";
exit(0);
