<?php
/**
 * Analisa campos originais das planilhas 2024 e 2025
 */

// 2024
$file_2024 = __DIR__ . '/../backup-python/desenvolvimento/Docomomo Brasil Filiação 2024 (respostas) - Respostas ao formulário 1.csv';

if (file_exists($file_2024)) {
    $h = fopen($file_2024, 'r');
    $header = fgetcsv($h);

    // Encontra índices das colunas
    $col_formacao = null;
    $col_inst = null;
    foreach ($header as $i => $col) {
        if (stripos($col, 'Formação acadêmica') !== false) $col_formacao = $i;
        if (stripos($col, 'professor/a ou estudante') !== false) $col_inst = $i;
    }

    $formacoes = [];
    $instituicoes = [];
    while (($row = fgetcsv($h)) !== false) {
        $f = trim($row[$col_formacao] ?? '');
        if ($f) $formacoes[$f] = ($formacoes[$f] ?? 0) + 1;

        $inst = trim($row[$col_inst] ?? '');
        if ($inst) $instituicoes[$inst] = ($instituicoes[$inst] ?? 0) + 1;
    }
    fclose($h);

    arsort($formacoes);
    echo "=== FORMAÇÕES ORIGINAIS 2024 ===\n";
    foreach ($formacoes as $f => $c) {
        echo sprintf("%3d | %s\n", $c, $f);
    }

    echo "\n=== INSTITUIÇÕES ORIGINAIS 2024 (top 30) ===\n";
    arsort($instituicoes);
    $i = 0;
    foreach ($instituicoes as $inst => $c) {
        if ($i++ >= 30) break;
        echo sprintf("%3d | %s\n", $c, mb_substr($inst, 0, 70));
    }
}

echo "\n\n";

// 2025 - consolidado
$file_2025 = __DIR__ . '/../backup-python/desenvolvimento/cadastrados_docomomo_2025_consolidado.csv';

if (file_exists($file_2025)) {
    $h = fopen($file_2025, 'r');
    $header = fgetcsv($h);

    $col_form = array_search('formacao', $header);
    $col_inst = array_search('instituicao', $header);

    $formacoes = [];
    $instituicoes = [];

    while (($row = fgetcsv($h)) !== false) {
        $f = trim($row[$col_form] ?? '');
        if ($f) $formacoes[$f] = ($formacoes[$f] ?? 0) + 1;

        $i = trim($row[$col_inst] ?? '');
        if ($i) $instituicoes[$i] = ($instituicoes[$i] ?? 0) + 1;
    }
    fclose($h);

    arsort($formacoes);
    echo "=== FORMAÇÕES NO CONSOLIDADO 2025 ===\n";
    foreach ($formacoes as $f => $c) {
        echo sprintf("%3d | %s\n", $c, $f);
    }

    echo "\n=== INSTITUIÇÕES NO CONSOLIDADO 2025 (top 30) ===\n";
    arsort($instituicoes);
    $n = 0;
    foreach ($instituicoes as $i => $c) {
        if ($n++ >= 30) break;
        echo sprintf("%3d | %s\n", $c, mb_substr($i, 0, 70));
    }
}
