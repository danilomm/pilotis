<?php
/**
 * Normaliza dados de 2024 e 2025
 * - Instituições (usando mapa existente)
 * - Métodos de pagamento
 */

require_once __DIR__ . '/../src/db.php';

$instituicoes_map = require __DIR__ . '/instituicoes_normalizadas.php';

echo "=== Normalizando 2024 e 2025 ===\n\n";

// 1. Métodos de pagamento
echo "--- Métodos de Pagamento ---\n";

$metodos_map = [
    'Pix' => 'PIX',
    'pix' => 'PIX',
    'PIX' => 'PIX',
    'cartao' => 'Cartão',
    'Cartão de Crédito' => 'Cartão',
    'boleto' => 'Boleto',
    'Boleto' => 'Boleto',
    'desconhecido' => 'Desconhecido',
    'manual' => 'Manual',
    '' => '',
];

foreach ([2024, 2025] as $ano) {
    echo "\n$ano:\n";
    foreach ($metodos_map as $de => $para) {
        if ($de === $para) continue;
        $result = db_execute(
            "UPDATE filiacoes SET metodo = ? WHERE ano = ? AND metodo = ?",
            [$para, $ano, $de]
        );
        if ($result > 0) {
            $de_display = $de === '' ? '(vazio)' : "'$de'";
            echo "  $de_display → '$para': $result\n";
        }
    }
}

// 2. Instituições
echo "\n--- Instituições ---\n";

$total_inst = 0;
foreach ([2024, 2025] as $ano) {
    $filiacoes = db_fetch_all("SELECT id, instituicao FROM filiacoes WHERE ano = ? AND instituicao <> ''", [$ano]);
    $count = 0;
    foreach ($filiacoes as $f) {
        $key = mb_strtolower(trim($f['instituicao']));
        if (isset($instituicoes_map[$key]) && $instituicoes_map[$key] !== $f['instituicao']) {
            $novo = $instituicoes_map[$key];
            db_execute("UPDATE filiacoes SET instituicao = ? WHERE id = ?", [$novo, $f['id']]);
            $count++;
        }
    }
    echo "$ano: $count instituições atualizadas\n";
    $total_inst += $count;
}

// 3. Verificação
echo "\n=== Verificação ===\n";

foreach ([2024, 2025] as $ano) {
    echo "\nMétodos $ano:\n";
    $mets = db_fetch_all("SELECT metodo, COUNT(*) as qtd FROM filiacoes WHERE ano = ? GROUP BY metodo ORDER BY qtd DESC", [$ano]);
    foreach ($mets as $m) {
        $met = $m['metodo'] ?: '(vazio)';
        echo sprintf("  %3d | %s\n", $m['qtd'], $met);
    }
}

foreach ([2024, 2025] as $ano) {
    echo "\nInstituições $ano (top 20):\n";
    $insts = db_fetch_all("SELECT instituicao, COUNT(*) as qtd FROM filiacoes WHERE ano = ? AND instituicao <> '' GROUP BY instituicao ORDER BY qtd DESC LIMIT 20", [$ano]);
    foreach ($insts as $i) {
        echo sprintf("  %3d | %s\n", $i['qtd'], $i['instituicao']);
    }
}

// 4. Instituições não mapeadas
echo "\n=== Instituições NÃO mapeadas ===\n";
foreach ([2024, 2025] as $ano) {
    $insts = db_fetch_all("SELECT DISTINCT instituicao FROM filiacoes WHERE ano = ? AND instituicao <> ''", [$ano]);
    $nao_mapeadas = [];
    foreach ($insts as $i) {
        $key = mb_strtolower(trim($i['instituicao']));
        if (!isset($instituicoes_map[$key])) {
            $nao_mapeadas[] = $i['instituicao'];
        }
    }
    echo "\n$ano (" . count($nao_mapeadas) . " não mapeadas):\n";
    foreach (array_slice($nao_mapeadas, 0, 15) as $inst) {
        echo "  - " . mb_substr($inst, 0, 70) . "\n";
    }
    if (count($nao_mapeadas) > 15) {
        echo "  ... e mais " . (count($nao_mapeadas) - 15) . "\n";
    }
}

echo "\n=== Concluído ===\n";
