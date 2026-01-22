<?php
/**
 * Normaliza cidades em todas as filiações
 * Usa mapa de normalização em cidades_normalizadas.php
 */

require_once __DIR__ . '/../src/db.php';

$cidades_map = require __DIR__ . '/cidades_normalizadas.php';

echo "=== Normalizando Cidades ===\n\n";

// Busca todas as cidades distintas
$cidades = db_fetch_all("SELECT DISTINCT cidade FROM filiacoes WHERE cidade IS NOT NULL AND cidade <> ''");

$total = 0;
$atualizadas = 0;
$nao_mapeadas = [];

foreach ($cidades as $c) {
    $original = $c['cidade'];
    $key = mb_strtolower(trim($original));

    if (isset($cidades_map[$key])) {
        $novo = $cidades_map[$key];

        if ($novo !== $original) {
            $result = db_execute(
                "UPDATE filiacoes SET cidade = ? WHERE cidade = ?",
                [$novo, $original]
            );
            if ($result > 0) {
                $atualizadas++;
                $total += $result;
                $display_novo = $novo === '' ? '(vazio)' : "'$novo'";
                echo "  '$original' → $display_novo: $result registros\n";
            }
        }
    } else {
        $nao_mapeadas[] = $original;
    }
}

echo "\n=== Resumo ===\n";
echo "Cidades atualizadas: $atualizadas\n";
echo "Registros atualizados: $total\n";

if (!empty($nao_mapeadas)) {
    echo "\nCidades NÃO mapeadas (" . count($nao_mapeadas) . "):\n";
    foreach ($nao_mapeadas as $c) {
        echo "  - $c\n";
    }
}

// Verificação final
echo "\n=== Verificação ===\n";
$cidades_final = db_fetch_all("
    SELECT cidade, COUNT(*) as qtd
    FROM filiacoes
    WHERE cidade IS NOT NULL AND cidade <> ''
    GROUP BY cidade
    ORDER BY qtd DESC
    LIMIT 20
");

echo "Top 20 cidades:\n";
foreach ($cidades_final as $c) {
    echo sprintf("  %3d | %s\n", $c['qtd'], $c['cidade']);
}

echo "\n=== Concluído ===\n";
