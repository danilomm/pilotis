<?php
/**
 * Analisa categorias em todos os arquivos de importação
 */

$arquivos = [
    '2015-2016' => [
        'arquivo' => __DIR__ . '/../importacao/originais/filiacao-2015-2016.csv',
        'col_categoria' => 6,
        'skip_header' => true,
    ],
    '2018' => [
        'arquivo' => __DIR__ . '/../importacao/originais/2018 Filiação Nacional.xls - Folha1.csv',
        'col_categoria' => 1, // PN, E, etc.
        'skip_lines' => 5,
    ],
    '2019' => [
        'arquivo' => __DIR__ . '/../importacao/originais/2019 campanha filiação.xls - Folha1.csv',
        'col_categoria' => null, // Analisar estrutura
        'skip_lines' => 5,
    ],
    '2020' => [
        'arquivo' => __DIR__ . '/../importacao/originais/Ficha Filiação 2020.csv',
        'col_categoria' => 9,
        'skip_header' => true,
    ],
    '2021' => [
        'arquivo' => __DIR__ . '/../importacao/originais/Filiação Brasil 2021 (respostas) - Respostas ao formulário 1.csv',
        'col_categoria' => 8,
        'skip_header' => true,
    ],
];

foreach ($arquivos as $ano => $config) {
    echo "=== $ano ===\n";

    if (!file_exists($config['arquivo'])) {
        echo "  Arquivo não encontrado\n\n";
        continue;
    }

    $f = fopen($config['arquivo'], 'r');

    // Pula linhas de metadata se necessário
    if (isset($config['skip_lines'])) {
        for ($i = 0; $i < $config['skip_lines']; $i++) {
            fgetcsv($f);
        }
    }

    // Pula header se necessário
    if ($config['skip_header'] ?? false) {
        $header = fgetcsv($f);
        echo "Colunas: " . implode(" | ", array_slice($header, 0, 12)) . "\n";
    }

    $categorias = [];
    $total = 0;
    while (($row = fgetcsv($f)) !== false) {
        if (empty($row[0]) || $row[0] === 'No.') continue;

        $total++;

        if ($config['col_categoria'] !== null) {
            $cat = trim($row[$config['col_categoria']] ?? '');
            if (!empty($cat)) {
                $categorias[$cat] = ($categorias[$cat] ?? 0) + 1;
            }
        }
    }
    fclose($f);

    echo "Total registros: $total\n";

    if (!empty($categorias)) {
        echo "Categorias:\n";
        arsort($categorias);
        foreach ($categorias as $cat => $qtd) {
            echo "  $qtd x $cat\n";
        }
    }
    echo "\n";
}
