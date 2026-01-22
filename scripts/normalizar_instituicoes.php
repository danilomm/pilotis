<?php
/**
 * Normaliza instituições em todas as filiações
 * Usa mapa de normalização em instituicoes_normalizadas.php
 */

require_once __DIR__ . '/../src/db.php';

$instituicoes_map = require __DIR__ . '/instituicoes_normalizadas.php';

echo "=== Normalizando Instituições (todos os anos) ===\n\n";

// Busca todos os anos com filiações
$anos = db_fetch_all("SELECT DISTINCT ano FROM filiacoes ORDER BY ano");

$total_geral = 0;

foreach ($anos as $a) {
    $ano = $a['ano'];
    $filiacoes = db_fetch_all(
        "SELECT id, instituicao FROM filiacoes WHERE ano = ? AND instituicao IS NOT NULL AND instituicao <> ''",
        [$ano]
    );

    $count = 0;
    foreach ($filiacoes as $f) {
        $key = mb_strtolower(trim($f['instituicao']));
        if (isset($instituicoes_map[$key]) && $instituicoes_map[$key] !== $f['instituicao']) {
            $novo = $instituicoes_map[$key];
            db_execute("UPDATE filiacoes SET instituicao = ? WHERE id = ?", [$novo, $f['id']]);
            $count++;
        }
    }

    if ($count > 0) {
        echo "$ano: $count instituições atualizadas\n";
        $total_geral += $count;
    }
}

echo "\n=== Resumo ===\n";
echo "Total de instituições normalizadas: $total_geral\n";

// Verificação - top 20 instituições geral
echo "\n=== Top 20 Instituições (geral) ===\n";
$insts = db_fetch_all("
    SELECT instituicao, COUNT(*) as qtd
    FROM filiacoes
    WHERE instituicao IS NOT NULL AND instituicao <> ''
    GROUP BY instituicao
    ORDER BY qtd DESC
    LIMIT 20
");
foreach ($insts as $i) {
    echo sprintf("  %3d | %s\n", $i['qtd'], $i['instituicao']);
}

// Instituições não mapeadas
echo "\n=== Instituições NÃO mapeadas (amostra) ===\n";
$insts = db_fetch_all("
    SELECT DISTINCT instituicao
    FROM filiacoes
    WHERE instituicao IS NOT NULL AND instituicao <> ''
");

$nao_mapeadas = [];
foreach ($insts as $i) {
    $key = mb_strtolower(trim($i['instituicao']));
    if (!isset($instituicoes_map[$key])) {
        $nao_mapeadas[] = $i['instituicao'];
    }
}

echo "Total não mapeadas: " . count($nao_mapeadas) . "\n";
if (!empty($nao_mapeadas)) {
    echo "\nPrimeiras 20:\n";
    foreach (array_slice($nao_mapeadas, 0, 20) as $inst) {
        echo "  - " . mb_substr($inst, 0, 70) . "\n";
    }
    if (count($nao_mapeadas) > 20) {
        echo "  ... e mais " . (count($nao_mapeadas) - 20) . "\n";
    }
}

echo "\n=== Concluído ===\n";
