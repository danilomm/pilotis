<?php
/**
 * Compara os dois arquivos de 2023 por nome
 */

// Função para normalizar nome para comparação
function normalizar_nome($nome) {
    $nome = mb_strtolower(trim($nome));
    $nome = preg_replace("/[áàâã]/u", "a", $nome);
    $nome = preg_replace("/[éèê]/u", "e", $nome);
    $nome = preg_replace("/[íìî]/u", "i", $nome);
    $nome = preg_replace("/[óòôõ]/u", "o", $nome);
    $nome = preg_replace("/[úùû]/u", "u", $nome);
    $nome = preg_replace("/ç/u", "c", $nome);
    $nome = preg_replace("/\s+/", " ", $nome);
    return $nome;
}

// Ler arquivo limpo
$limpo = fopen(__DIR__ . "/../importacao/limpos/filiados_2023_limpo.csv", "r");
fread($limpo, 3); // BOM
$header = fgetcsv($limpo, 0, ";");
$col_nome = array_search("nome", $header);
$col_email = array_search("email", $header);

$pessoas_limpo = [];
while (($row = fgetcsv($limpo, 0, ";")) !== false) {
    if (!empty($row[$col_nome])) {
        $nome_norm = normalizar_nome($row[$col_nome]);
        $pessoas_limpo[$nome_norm] = [
            "nome" => $row[$col_nome],
            "email" => $row[$col_email]
        ];
    }
}
fclose($limpo);

// Ler arquivo alternativo
$alt = fopen(__DIR__ . "/../importacao/originais/filiacao-2023-semi-organizada.csv", "r");
$header = fgetcsv($alt);
$col_nome = array_search("Nome completo", $header);
$col_email = array_search("E-mail", $header);

$pessoas_alt = [];
while (($row = fgetcsv($alt)) !== false) {
    if (!empty($row[$col_nome])) {
        $nome_norm = normalizar_nome($row[$col_nome]);
        $pessoas_alt[$nome_norm] = [
            "nome" => $row[$col_nome],
            "email" => $row[$col_email]
        ];
    }
}
fclose($alt);

echo "=== MESMA PESSOA, EMAIL DIFERENTE ===\n\n";
$duplicatas = 0;
foreach ($pessoas_limpo as $nome_norm => $dados_limpo) {
    if (isset($pessoas_alt[$nome_norm])) {
        $dados_alt = $pessoas_alt[$nome_norm];
        if (strtolower($dados_limpo["email"]) !== strtolower($dados_alt["email"])) {
            $duplicatas++;
            echo "$duplicatas. {$dados_limpo['nome']}\n";
            echo "   Limpo: {$dados_limpo['email']}\n";
            echo "   Semi:  {$dados_alt['email']}\n\n";
        }
    }
}

echo "=== RESUMO ===\n";
echo "Pessoas no limpo: " . count($pessoas_limpo) . "\n";
echo "Pessoas no semi-organizado: " . count($pessoas_alt) . "\n";

$em_comum = array_intersect_key($pessoas_limpo, $pessoas_alt);
echo "Em comum (mesmo nome): " . count($em_comum) . "\n";
echo "Com email diferente: $duplicatas\n";

$so_limpo = array_diff_key($pessoas_limpo, $pessoas_alt);
$so_alt = array_diff_key($pessoas_alt, $pessoas_limpo);
echo "Só no limpo: " . count($so_limpo) . "\n";
echo "Só no semi-organizado: " . count($so_alt) . "\n";

echo "\n=== SÓ NO SEMI-ORGANIZADO (" . count($so_alt) . ") ===\n";
foreach ($so_alt as $nome_norm => $dados) {
    echo "  {$dados['nome']} <{$dados['email']}>\n";
}
