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
// Controllers ficam em dois lugares: os transversais em src/Controllers/, e os
// de cada modulo dentro da pasta do proprio modulo (src/Eventos/, etc.) — o
// sistema e organizado por MODULO, nao por camada.
foreach (array_merge(
    glob($raiz . '/src/Controllers/*.php'),
    glob($raiz . '/src/*/*Controller.php')
) as $c) {
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

echo "\n== referencias Classe::metodo fora do index.php ==\n";
// O index.php nao e o unico lugar que aponta para metodo por STRING. Os
// endpoints de cron em public/ chamam por Reflection, e foi exatamente ali que
// a divisao do AdminController quebrou sem ninguem ver: ReflectionMethod nao
// sobe para a classe filha, e a chamada ficava DEPOIS das travas — o erro so
// apareceria no dia do primeiro envio automatico.
$outros = array_diff(glob($raiz . '/public/*.php'), [$raiz . '/public/index.php']);
$conferidas = 0;
foreach ($outros as $arquivo) {
    $txt = file_get_contents($arquivo);
    $rel = str_replace($raiz . '/', '', $arquivo);

    // new ReflectionMethod('Classe', 'metodo')
    if (preg_match_all("/new\s+ReflectionMethod\(\s*'(\w+)'\s*,\s*'(\w+)'/", $txt, $m, PREG_SET_ORDER)) {
        foreach ($m as $r) {
            [, $classe, $metodo] = $r;
            $conferidas++;
            if (!class_exists($classe)) {
                echo "  FALHA  $rel -> classe inexistente: $classe\n"; $falhas++; continue;
            }
            // Reflection NAO herda: o metodo tem de estar na propria classe.
            $ref = new ReflectionClass($classe);
            $tem = false;
            foreach ($ref->getMethods() as $mm) {
                if ($mm->getName() === $metodo && $mm->getDeclaringClass()->getName() === $classe) { $tem = true; break; }
            }
            if (!$tem) {
                echo "  FALHA  $rel -> $classe nao DECLARA $metodo() (Reflection nao sobe para a filha)\n";
                $falhas++;
            }
        }
    }

    // Classe::metodo( em chamada estatica direta
    if (preg_match_all('/\b(Admin\w*Controller|\w+Controller|\w+Service)::(\w+)\s*\(/', $txt, $m2, PREG_SET_ORDER)) {
        foreach ($m2 as $r) {
            [, $classe, $metodo] = $r;
            if (!class_exists($classe)) continue;   // pode nao estar carregada aqui
            $conferidas++;
            if (!method_exists($classe, $metodo)) {
                echo "  FALHA  $rel -> $classe::$metodo() nao existe\n";
                $falhas++;
            }
        }
    }
}
echo "  $conferidas referencia(s) conferida(s) em " . count($outros) . " arquivo(s) de public/\n";

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

// ---------------------------------------------------------------------------
// Todo require aponta para arquivo que existe.
//
// Existe porque a reorganizacao em modulos de 30/08 quebrou um require que
// nenhum outro teste via: o PdfService incluia ValidacaoService por
// __DIR__, dentro de um ramo que so roda ao gerar PDF com QR. O php -l nao
// ve, o boot nao ve, e o erro so apareceria na hora de emitir o comprovante
// de quem acabou de pagar.
// ---------------------------------------------------------------------------
echo "\n== requires apontam para arquivo existente ==\n";

$arquivos_php = [];
foreach (['src', 'public', 'scripts', 'tests'] as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/' . $dir));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') $arquivos_php[] = $f->getPathname();
    }
}

$conferidos = 0;
foreach ($arquivos_php as $arq) {
    $src = file_get_contents($arq);
    $rel_arq = str_replace($raiz . '/', '', $arq);

    // SRC_DIR . '/...'  — resolve contra src/
    if (preg_match_all("/SRC_DIR\s*\.\s*'([^']+\.php)'/", $src, $mm)) {
        foreach ($mm[1] as $ref) {
            $conferidos++;
            if (!is_file($raiz . '/src' . $ref)) {
                echo "  FALHA  $rel_arq -> SRC_DIR . '$ref' nao existe\n"; $falhas++;
            }
        }
    }
    // __DIR__ . '/...'  — resolve contra a pasta do proprio arquivo.
    //
    // Um mesmo arquivo pode escrever DUAS formas do mesmo destino de
    // proposito: backup-download.php e os cron-*.php resolvem o layout local
    // (public/ irmao de src/) e o do servidor (src/ dentro de www/) com um
    // is_file. Por isso a falha e por BASENAME: so acusa quando nenhuma das
    // formas escritas naquele arquivo chega a um arquivo existente.
    if (preg_match_all("/__DIR__\\s*\\.\\s*'([^']+\\.php)'/", $src, $mm)) {
        $por_nome = [];
        foreach ($mm[1] as $ref) $por_nome[basename($ref)][] = $ref;
        foreach ($por_nome as $nome => $refs) {
            $conferidos++;
            $achou = false;
            foreach ($refs as $ref) {
                if (is_file(dirname($arq) . $ref)) { $achou = true; break; }
            }
            if (!$achou) {
                echo "  FALHA  $rel_arq -> __DIR__ . '" . $refs[0] . "' nao existe\n";
                $falhas++;
            }
        }
    }
}
echo "  $conferidos require(s) conferido(s) em " . count($arquivos_php) . " arquivo(s)\n";

echo "\n";
if ($falhas > 0) {
    echo "FALHOU: $falhas problema(s).\n";
    exit(1);
}
echo "Tudo certo.\n";
exit(0);
