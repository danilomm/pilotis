<?php
/**
 * Atualiza dados de filiação com normalização corrigida
 * Lê os CSVs limpos e atualiza os campos no banco
 */

require_once __DIR__ . '/../src/db.php';

function atualizar_ano($ano) {
    $file = __DIR__ . "/../public/data/filiados_{$ano}_limpo.csv";

    if (!file_exists($file)) {
        echo "Arquivo não encontrado: $file\n";
        return;
    }

    echo "=== Atualizando $ano ===\n";

    $handle = fopen($file, 'r');

    // Pula BOM
    $bom = fread($handle, 3);
    if ($bom !== "\xEF\xBB\xBF") {
        rewind($handle);
    }

    // Lê cabeçalho
    $header = fgetcsv($handle, 0, ';');
    $col = array_flip($header);

    $atualizados = 0;
    $nao_encontrados = 0;

    while (($row = fgetcsv($handle, 0, ';')) !== false) {
        if (empty($row[0])) continue;

        $email = strtolower(trim($row[$col['email']]));
        $instituicao = $row[$col['instituicao']];
        $formacao = $row[$col['formacao']];
        $metodo = $row[$col['metodo']];

        // Busca pessoa por email
        $pessoa = db_fetch_one("
            SELECT p.id
            FROM pessoas p
            JOIN emails e ON e.pessoa_id = p.id
            WHERE LOWER(e.email) = ?
        ", [$email]);

        if (!$pessoa) {
            $nao_encontrados++;
            continue;
        }

        // Atualiza filiação do ano
        $result = db_execute("
            UPDATE filiacoes
            SET instituicao = ?, formacao = ?, metodo = ?
            WHERE pessoa_id = ? AND ano = ?
        ", [$instituicao, $formacao, $metodo, $pessoa['id'], $ano]);

        if ($result > 0) {
            $atualizados++;
        }
    }

    fclose($handle);

    echo "Atualizados: $atualizados\n";
    echo "Não encontrados: $nao_encontrados\n\n";
}

// Atualiza 2022 e 2023
atualizar_ano(2022);
atualizar_ano(2023);

// Verificação
echo "=== Verificação ===\n\n";

foreach ([2022, 2023] as $ano) {
    echo "Instituições $ano (top 15):\n";
    $insts = db_fetch_all("
        SELECT instituicao, COUNT(*) as qtd
        FROM filiacoes
        WHERE ano = ? AND instituicao <> ''
        GROUP BY instituicao
        ORDER BY qtd DESC
        LIMIT 15
    ", [$ano]);
    foreach ($insts as $i) {
        echo sprintf("  %3d | %s\n", $i['qtd'], $i['instituicao']);
    }
    echo "\n";
}

echo "Formações 2022:\n";
$forms = db_fetch_all("SELECT DISTINCT formacao, COUNT(*) as qtd FROM filiacoes WHERE ano = 2022 AND formacao <> '' GROUP BY formacao ORDER BY qtd DESC");
foreach ($forms as $f) {
    echo sprintf("  %3d | %s\n", $f['qtd'], $f['formacao']);
}

echo "\nFormações 2023:\n";
$forms = db_fetch_all("SELECT DISTINCT formacao, COUNT(*) as qtd FROM filiacoes WHERE ano = 2023 AND formacao <> '' GROUP BY formacao ORDER BY qtd DESC");
foreach ($forms as $f) {
    echo sprintf("  %3d | %s\n", $f['qtd'], $f['formacao']);
}

echo "\nMétodos 2022:\n";
$mets = db_fetch_all("SELECT DISTINCT metodo, COUNT(*) as qtd FROM filiacoes WHERE ano = 2022 GROUP BY metodo ORDER BY qtd DESC");
foreach ($mets as $m) {
    echo sprintf("  %3d | %s\n", $m['qtd'], $m['metodo']);
}

echo "\nMétodos 2023:\n";
$mets = db_fetch_all("SELECT DISTINCT metodo, COUNT(*) as qtd FROM filiacoes WHERE ano = 2023 GROUP BY metodo ORDER BY qtd DESC");
foreach ($mets as $m) {
    echo sprintf("  %3d | %s\n", $m['qtd'], $m['metodo']);
}

echo "\n=== Concluído ===\n";
