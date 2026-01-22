<?php
/**
 * Verifica quais pessoas do semi-organizado já existem no banco por email
 */

require_once __DIR__ . '/../src/db.php';

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

// Ler arquivo limpo para saber quais nomes excluir
$limpo = fopen(__DIR__ . "/../importacao/limpos/filiados_2023_limpo.csv", "r");
fread($limpo, 3); // BOM
$header = fgetcsv($limpo, 0, ";");
$col_nome = array_search("nome", $header);

$nomes_limpo = [];
while (($row = fgetcsv($limpo, 0, ";")) !== false) {
    if (!empty($row[$col_nome])) {
        $nomes_limpo[normalizar_nome($row[$col_nome])] = true;
    }
}
fclose($limpo);

// Ler arquivo semi-organizado
$alt = fopen(__DIR__ . "/../importacao/originais/filiacao-2023-semi-organizada.csv", "r");
$header = fgetcsv($alt);
$col_nome = array_search("Nome completo", $header);
$col_email = array_search("E-mail", $header);

$so_semi = [];
while (($row = fgetcsv($alt)) !== false) {
    if (!empty($row[$col_nome])) {
        $nome_norm = normalizar_nome($row[$col_nome]);
        // Só pegar quem NÃO está no limpo
        if (!isset($nomes_limpo[$nome_norm])) {
            $so_semi[] = [
                "nome" => $row[$col_nome],
                "email" => strtolower(trim($row[$col_email]))
            ];
        }
    }
}
fclose($alt);

echo "=== Verificando " . count($so_semi) . " pessoas do semi-organizado ===\n\n";

// Verificar cada email no banco
$existe_no_banco = [];
$nao_existe = [];

foreach ($so_semi as $pessoa) {
    $email = $pessoa['email'];

    // Buscar na tabela emails
    $result = db_fetch_one(
        "SELECT e.email, p.nome, p.id as pessoa_id
         FROM emails e
         JOIN pessoas p ON e.pessoa_id = p.id
         WHERE LOWER(e.email) = ?",
        [$email]
    );

    if ($result) {
        $existe_no_banco[] = [
            "nome_semi" => $pessoa['nome'],
            "email" => $email,
            "nome_banco" => $result['nome'],
            "pessoa_id" => $result['pessoa_id']
        ];
    } else {
        $nao_existe[] = $pessoa;
    }
}

echo "=== JÁ EXISTEM NO BANCO (" . count($existe_no_banco) . ") ===\n\n";
foreach ($existe_no_banco as $i => $p) {
    $num = $i + 1;
    echo "$num. {$p['nome_semi']}\n";
    echo "   Email: {$p['email']}\n";
    echo "   No banco como: {$p['nome_banco']} (ID: {$p['pessoa_id']})\n\n";
}

echo "=== NÃO EXISTEM NO BANCO (" . count($nao_existe) . ") ===\n\n";
foreach ($nao_existe as $i => $p) {
    $num = $i + 1;
    echo "$num. {$p['nome']} <{$p['email']}>\n";
}

echo "\n=== RESUMO ===\n";
echo "Total só no semi-organizado: " . count($so_semi) . "\n";
echo "Já existem no banco: " . count($existe_no_banco) . "\n";
echo "Não existem no banco: " . count($nao_existe) . "\n";
