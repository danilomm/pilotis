<?php
/**
 * Extrai nomes dos certificados (PDFs) dos ZIPs extraídos
 */

$base_dir = __DIR__ . '/../temp/extraidos';
$output_file = __DIR__ . '/../certificados_emitidos.csv';

echo "=== Extraindo nomes dos certificados ===\n\n";

$certificados = [];

// 2018
$dir_2018 = "$base_dir/2018/filiação 2018/certificados 2018";
if (is_dir($dir_2018)) {
    foreach (glob("$dir_2018/*.pdf") as $file) {
        $nome_arquivo = basename($file, '.pdf');
        if (strpos($nome_arquivo, 'modelo') !== false) continue;
        
        // Formato: 01_Leonardo OBA.pdf ou Certificado2018_01.pdf
        if (preg_match('/^\d+_(.+)$/', $nome_arquivo, $m)) {
            $nome = trim($m[1]);
            $certificados[] = ['ano' => 2018, 'categoria' => 'nacional', 'nome' => $nome, 'arquivo' => basename($file)];
        }
    }
    echo "2018: " . count(array_filter($certificados, fn($c) => $c['ano'] == 2018)) . " certificados\n";
}

// 2019
$dir_2019 = "$base_dir/2019/filiação 2019/certificados 2019";
if (is_dir($dir_2019)) {
    foreach (glob("$dir_2019/*.pdf") as $file) {
        $nome_arquivo = basename($file, '.pdf');
        
        // Formatos: D2019_01_NOME.pdf, PN2019_01_NOME.pdf, E2019_01_NOME.pdf
        // Ou: 48_Recibo D2019_NOME.pdf
        $categoria = 'desconhecida';
        $nome = '';
        
        if (preg_match('/^D2019_\d+_(.+)$/', $nome_arquivo, $m)) {
            $categoria = 'dupla_nacional';
            $nome = trim($m[1]);
        } elseif (preg_match('/^PN2019_\d+_(.+)$/', $nome_arquivo, $m)) {
            $categoria = 'pleno_nacional';
            $nome = trim($m[1]);
        } elseif (preg_match('/^E2019_\d+_(.+)$/', $nome_arquivo, $m)) {
            $categoria = 'estudante';
            $nome = trim($m[1]);
        } elseif (preg_match('/Recibo\s+(dupla|nacional|estudante)[_\s]*(.+)$/i', $nome_arquivo, $m)) {
            $cat_map = ['dupla' => 'dupla_nacional', 'nacional' => 'pleno_nacional', 'estudante' => 'estudante'];
            $categoria = $cat_map[strtolower($m[1])] ?? 'desconhecida';
            $nome = trim($m[2]);
        } elseif (preg_match('/^\d+_Recibo D2019_(.+)$/', $nome_arquivo, $m)) {
            $categoria = 'dupla_nacional';
            $nome = trim($m[1]);
        }
        
        if ($nome) {
            $certificados[] = ['ano' => 2019, 'categoria' => $categoria, 'nome' => $nome, 'arquivo' => basename($file)];
        }
    }
    $c2019 = count(array_filter($certificados, fn($c) => $c['ano'] == 2019));
    echo "2019: $c2019 certificados\n";
}

// 2021
$dirs_2021 = [
    "$base_dir/geral/FILIAÇÃO/Filiação 2021/Certificados Pleno Internacional" => 'profissional_internacional',
    "$base_dir/geral/FILIAÇÃO/Filiação 2021/Pleno Nacional" => 'profissional_nacional',
    "$base_dir/geral/FILIAÇÃO/Filiação 2021/estudante" => 'estudante',
];

foreach ($dirs_2021 as $dir => $categoria) {
    if (!is_dir($dir)) continue;
    foreach (glob("$dir/*.pdf") as $file) {
        $nome = basename($file, '.pdf');
        $certificados[] = ['ano' => 2021, 'categoria' => $categoria, 'nome' => $nome, 'arquivo' => basename($file)];
    }
}
$c2021 = count(array_filter($certificados, fn($c) => $c['ano'] == 2021));
echo "2021: $c2021 certificados\n";

// Salva CSV
$out = fopen($output_file, 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM UTF-8
fputcsv($out, ['ano', 'categoria', 'nome', 'arquivo'], ';');
foreach ($certificados as $c) {
    fputcsv($out, $c, ';');
}
fclose($out);

echo "\nTotal: " . count($certificados) . " certificados\n";
echo "Salvo em: $output_file\n";

// Resumo por categoria
echo "\n=== Por categoria ===\n";
$por_cat = [];
foreach ($certificados as $c) {
    $key = $c['ano'] . '|' . $c['categoria'];
    $por_cat[$key] = ($por_cat[$key] ?? 0) + 1;
}
ksort($por_cat);
foreach ($por_cat as $key => $qtd) {
    echo "$key: $qtd\n";
}
