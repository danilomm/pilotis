<?php
/**
 * Extrai todos os dados das planilhas e salva em arquivo consolidado
 */

$base_dir = __DIR__ . '/../temp/extraidos';
$output_file = __DIR__ . '/../consolidado_planilhas.csv';

echo "=== Extraindo dados das planilhas ===\n\n";

$planilhas = [
    'Ficha de Inscrição Docomomo Brasil (respostas).csv' => [
        'nome' => 2, 'email' => 3, 'fonte' => '2023 (formulário principal)'
    ],
    'Docomomo Brasil (fichas de filiação).csv' => [
        'nome' => 1, 'email' => 3, 'fonte' => '2015-2017 (fichas antigas)'
    ],
    'Ficha Filiação Atual (respostas).csv' => [
        'nome' => 3, 'email' => 2, 'fonte' => '2019-2020'
    ],
    'Filiação Brasil 2021 (respostas).csv' => [
        'nome' => 2, 'email' => 1, 'fonte' => '2021 (formulário)'
    ],
    '2021 Filiados (internacional, nacional e estudante).csv' => [
        'nome' => 2, 'email' => 4, 'fonte' => '2021 (consolidado confirmados)'
    ],
];

$todos = [];

foreach ($planilhas as $arquivo => $config) {
    $path = "$base_dir/$arquivo";
    if (!file_exists($path)) {
        echo "Não encontrado: $arquivo\n";
        continue;
    }

    $handle = fopen($path, 'r');
    $linha = 0;
    $count = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $linha++;
        if ($linha <= 2) continue;

        $nome = trim($row[$config['nome']] ?? '');
        $email = strtolower(trim($row[$config['email']] ?? ''));

        if (empty($nome) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        if (strpos($nome, 'PLENO') !== false || strpos($nome, 'CATEGORIA') !== false) {
            continue;
        }

        $todos[] = [
            'nome' => $nome,
            'email' => $email,
            'fonte' => $config['fonte'],
            'arquivo' => $arquivo,
        ];
        $count++;
    }
    fclose($handle);
    echo "$arquivo: $count registros\n";
}

// Remove duplicatas por email (mantém primeiro)
$unicos = [];
foreach ($todos as $reg) {
    if (!isset($unicos[$reg['email']])) {
        $unicos[$reg['email']] = $reg;
    }
}

echo "\nTotal bruto: " . count($todos) . "\n";
echo "Total únicos (por email): " . count($unicos) . "\n";

// Salva CSV
$out = fopen($output_file, 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8
fputcsv($out, ['nome', 'email', 'fonte', 'arquivo'], ';');
foreach ($unicos as $reg) {
    fputcsv($out, $reg, ';');
}
fclose($out);

echo "\nSalvo em: $output_file\n";
