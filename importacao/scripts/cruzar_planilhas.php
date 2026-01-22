<?php
/**
 * Cruza dados das planilhas extraídas com o banco
 * Identifica duplicatas e gera relatório de consolidação
 */

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';

$base_dir = __DIR__ . '/../temp/extraidos';

echo "=== Cruzamento de Planilhas com Banco ===\n\n";

// 1. Carregar todos os emails/nomes do banco
echo "Carregando dados do banco...\n";
$pessoas_db = db_fetch_all("
    SELECT p.id, p.nome, GROUP_CONCAT(e.email, '|') as emails
    FROM pessoas p
    LEFT JOIN emails e ON e.pessoa_id = p.id
    GROUP BY p.id
");

$por_email_db = [];
$por_nome_db = [];
foreach ($pessoas_db as $p) {
    $emails = explode('|', $p['emails'] ?? '');
    foreach ($emails as $email) {
        if ($email) {
            $por_email_db[strtolower(trim($email))] = $p;
        }
    }
    $nome_key = mb_strtolower(trim($p['nome']));
    $por_nome_db[$nome_key][] = $p;
}
echo "Banco: " . count($pessoas_db) . " pessoas, " . count($por_email_db) . " emails\n\n";

// 2. Extrair dados das planilhas
$planilhas = [
    'Ficha de Inscrição Docomomo Brasil (respostas).csv' => ['nome' => 2, 'email' => 3], // 0-indexed after header
    'Docomomo Brasil (fichas de filiação).csv' => ['nome' => 1, 'email' => 3],
    'Ficha Filiação Atual (respostas).csv' => ['nome' => 3, 'email' => 2],
    'Filiação Brasil 2021 (respostas).csv' => ['nome' => 2, 'email' => 1],
    '2021 Filiados (internacional, nacional e estudante).csv' => ['nome' => 2, 'email' => 4],
];

$todos_registros = [];

foreach ($planilhas as $arquivo => $cols) {
    $path = "$base_dir/$arquivo";
    if (!file_exists($path)) {
        echo "Arquivo não encontrado: $arquivo\n";
        continue;
    }

    $handle = fopen($path, 'r');
    $linha = 0;
    while (($row = fgetcsv($handle)) !== false) {
        $linha++;
        if ($linha <= 2) continue; // Pula cabeçalhos

        $nome = trim($row[$cols['nome']] ?? '');
        $email = strtolower(trim($row[$cols['email']] ?? ''));

        // Ignora linhas vazias ou headers
        if (empty($nome) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        if (strpos($nome, 'PLENO') !== false || strpos($nome, 'CATEGORIA') !== false) {
            continue;
        }

        $todos_registros[] = [
            'nome' => $nome,
            'email' => $email,
            'fonte' => $arquivo,
        ];
    }
    fclose($handle);
    echo "Lido: $arquivo\n";
}

echo "\nTotal de registros nas planilhas: " . count($todos_registros) . "\n\n";

// 3. Identificar duplicatas e novos
$novos = [];
$existentes = [];
$duplicatas_potenciais = [];

foreach ($todos_registros as $reg) {
    $email = $reg['email'];
    $nome = $reg['nome'];
    $nome_key = mb_strtolower(trim($nome));

    // Busca por email
    if (isset($por_email_db[$email])) {
        $pessoa_db = $por_email_db[$email];
        $existentes[$email] = [
            'planilha' => $reg,
            'banco' => $pessoa_db,
            'match' => 'email',
        ];
        continue;
    }

    // Busca por nome exato
    if (isset($por_nome_db[$nome_key])) {
        $pessoas_mesmo_nome = $por_nome_db[$nome_key];
        foreach ($pessoas_mesmo_nome as $pessoa_db) {
            $duplicatas_potenciais[] = [
                'planilha' => $reg,
                'banco' => $pessoa_db,
                'match' => 'nome_exato',
            ];
        }
        continue;
    }

    // Busca por primeiro + último nome
    $partes = preg_split('/\s+/', $nome);
    if (count($partes) >= 2) {
        $primeiro = mb_strtolower($partes[0]);
        $ultimo = mb_strtolower($partes[count($partes) - 1]);

        foreach ($por_nome_db as $nome_db_key => $pessoas) {
            $partes_db = preg_split('/\s+/', $nome_db_key);
            if (count($partes_db) >= 2) {
                $primeiro_db = $partes_db[0];
                $ultimo_db = $partes_db[count($partes_db) - 1];

                if ($primeiro === $primeiro_db && $ultimo === $ultimo_db) {
                    foreach ($pessoas as $pessoa_db) {
                        $duplicatas_potenciais[] = [
                            'planilha' => $reg,
                            'banco' => $pessoa_db,
                            'match' => 'primeiro_ultimo_nome',
                        ];
                    }
                }
            }
        }
    }

    // Se não encontrou, é novo
    if (!isset($existentes[$email])) {
        $dominated = false;
        foreach ($duplicatas_potenciais as $dup) {
            if ($dup['planilha']['email'] === $email) {
                $dominated = true;
                break;
            }
        }
        if (!$dominated) {
            $novos[$email] = $reg;
        }
    }
}

// 4. Relatório
echo "=== RESULTADO ===\n\n";
echo "Existentes (email bate): " . count($existentes) . "\n";
echo "Duplicatas potenciais (nome similar, email diferente): " . count($duplicatas_potenciais) . "\n";
echo "Novos (não encontrados): " . count($novos) . "\n";

echo "\n=== DUPLICATAS POTENCIAIS (REVISAR) ===\n\n";
$duplicatas_unicas = [];
foreach ($duplicatas_potenciais as $dup) {
    $key = $dup['planilha']['email'] . '|' . $dup['banco']['id'];
    if (!isset($duplicatas_unicas[$key])) {
        $duplicatas_unicas[$key] = $dup;
    }
}

foreach ($duplicatas_unicas as $dup) {
    echo "PLANILHA: {$dup['planilha']['nome']} <{$dup['planilha']['email']}>\n";
    echo "BANCO ID {$dup['banco']['id']}: {$dup['banco']['nome']} <{$dup['banco']['emails']}>\n";
    echo "Match: {$dup['match']}\n";
    echo "---\n";
}

echo "\n=== NOVOS (não existem no banco) ===\n\n";
$i = 0;
foreach ($novos as $email => $reg) {
    $i++;
    if ($i > 30) {
        echo "... e mais " . (count($novos) - 30) . " novos\n";
        break;
    }
    echo "{$reg['nome']} <{$reg['email']}> (fonte: {$reg['fonte']})\n";
}

echo "\n=== RESUMO ===\n";
echo "Total planilhas: " . count($todos_registros) . "\n";
echo "Já existem (por email): " . count($existentes) . "\n";
echo "Duplicatas para revisar: " . count($duplicatas_unicas) . "\n";
echo "Novos para adicionar: " . count($novos) . "\n";
