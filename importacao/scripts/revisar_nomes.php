<?php
/**
 * Lista nomes para revisão manual em CSV limpo
 *
 * Uso:
 *   php revisar_nomes.php <arquivo_csv>
 *
 * Mostra:
 * - Linhas com acao_sugerida = VERIFICAR_MANUAL (mesmo nome, email diferente)
 * - Linhas com acao_sugerida = ATUALIZAR_NOME (planilha tem nome mais completo)
 * - Nomes que parecem incompletos (menos de 2 palavras)
 * - Nomes com caracteres estranhos
 *
 * Exemplos:
 *   php scripts/revisar_nomes.php importacao/limpos/filiados_2021_limpo.csv
 */

if (!isset($argv[1])) {
    die("Uso: php revisar_nomes.php <arquivo_csv>\n");
}

$file_in = $argv[1];

if (!file_exists($file_in)) {
    die("Arquivo não encontrado: $file_in\n");
}

echo "=== Revisão de Nomes ===\n";
echo "Arquivo: $file_in\n\n";

// Lê CSV
$handle = fopen($file_in, 'r');

// Pula BOM se existir
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($handle);
}

// Lê cabeçalho
$header = fgetcsv($handle, 0, ';');
$col = array_flip($header);

if (!isset($col['nome'])) {
    die("Erro: CSV não tem coluna 'nome'\n");
}

$revisoes = [
    'verificar_manual' => [],
    'atualizar_nome' => [],
    'nomes_curtos' => [],
    'caracteres_estranhos' => [],
];

$linha = 1;
while (($row = fgetcsv($handle, 0, ';')) !== false) {
    $linha++;
    if (empty($row[0])) continue;

    $nome = $row[$col['nome']] ?? '';
    $email = $row[$col['email']] ?? '';
    $acao = isset($col['acao_sugerida']) ? ($row[$col['acao_sugerida']] ?? '') : '';
    $nome_banco_email = isset($col['nome_banco_email']) ? ($row[$col['nome_banco_email']] ?? '') : '';
    $nome_banco_similar = isset($col['nome_banco_similar']) ? ($row[$col['nome_banco_similar']] ?? '') : '';
    $nome_banco = $nome_banco_email ?: $nome_banco_similar;
    $pessoa_id_email = isset($col['pessoa_id_email']) ? ($row[$col['pessoa_id_email']] ?? '') : '';
    $pessoa_id_nome = isset($col['pessoa_id_nome']) ? ($row[$col['pessoa_id_nome']] ?? '') : '';
    $pessoa_id = $pessoa_id_email ?: $pessoa_id_nome;

    // Ação sugerida VERIFICAR_MANUAL
    if ($acao === 'VERIFICAR_MANUAL') {
        $revisoes['verificar_manual'][] = [
            'linha' => $linha,
            'nome' => $nome,
            'email' => $email,
            'nome_banco' => $nome_banco,
            'pessoa_id' => $pessoa_id,
        ];
    }

    // Ação sugerida ATUALIZAR_NOME
    if ($acao === 'ATUALIZAR_NOME') {
        $revisoes['atualizar_nome'][] = [
            'linha' => $linha,
            'nome' => $nome,
            'email' => $email,
            'nome_banco' => $nome_banco,
            'pessoa_id' => $pessoa_id,
        ];
    }

    // Nomes curtos (menos de 2 palavras)
    $palavras = preg_split('/\s+/', trim($nome));
    if (count($palavras) < 2) {
        $revisoes['nomes_curtos'][] = [
            'linha' => $linha,
            'nome' => $nome,
            'email' => $email,
        ];
    }

    // Caracteres estranhos (números, símbolos)
    if (preg_match('/[0-9@#$%^&*()=+\[\]{}|\\\\<>]/', $nome)) {
        $revisoes['caracteres_estranhos'][] = [
            'linha' => $linha,
            'nome' => $nome,
            'email' => $email,
        ];
    }
}

fclose($handle);

// Exibe resultados
echo "=== VERIFICAR_MANUAL (mesmo nome, email diferente) ===\n";
if (empty($revisoes['verificar_manual'])) {
    echo "Nenhum registro precisa de verificação manual.\n";
} else {
    echo "Encontrados: " . count($revisoes['verificar_manual']) . "\n\n";
    foreach ($revisoes['verificar_manual'] as $r) {
        echo "Linha {$r['linha']}: {$r['nome']}\n";
        echo "  Email CSV: {$r['email']}\n";
        echo "  No banco (ID {$r['pessoa_id']}): {$r['nome_banco']}\n";
        echo "  → Verificar se é a mesma pessoa\n\n";
    }
}

echo "\n=== ATUALIZAR_NOME (planilha tem nome mais completo) ===\n";
if (empty($revisoes['atualizar_nome'])) {
    echo "Nenhum nome precisa ser atualizado.\n";
} else {
    echo "Encontrados: " . count($revisoes['atualizar_nome']) . "\n\n";
    foreach ($revisoes['atualizar_nome'] as $r) {
        echo "Linha {$r['linha']}:\n";
        echo "  No banco (ID {$r['pessoa_id']}): {$r['nome_banco']}\n";
        echo "  No CSV (mais completo): {$r['nome']}\n";
        echo "  → Confirmar atualização do nome\n\n";
    }
}

echo "\n=== Nomes Curtos (menos de 2 palavras) ===\n";
if (empty($revisoes['nomes_curtos'])) {
    echo "Nenhum nome curto encontrado.\n";
} else {
    echo "Encontrados: " . count($revisoes['nomes_curtos']) . "\n\n";
    foreach ($revisoes['nomes_curtos'] as $r) {
        echo "Linha {$r['linha']}: \"{$r['nome']}\" ({$r['email']})\n";
    }
}

echo "\n=== Caracteres Estranhos no Nome ===\n";
if (empty($revisoes['caracteres_estranhos'])) {
    echo "Nenhum nome com caracteres estranhos.\n";
} else {
    echo "Encontrados: " . count($revisoes['caracteres_estranhos']) . "\n\n";
    foreach ($revisoes['caracteres_estranhos'] as $r) {
        echo "Linha {$r['linha']}: \"{$r['nome']}\" ({$r['email']})\n";
    }
}

// Resumo
$total = count($revisoes['verificar_manual']) + count($revisoes['atualizar_nome']) +
         count($revisoes['nomes_curtos']) + count($revisoes['caracteres_estranhos']);
echo "\n=== Resumo ===\n";
echo "Verificar manual: " . count($revisoes['verificar_manual']) . "\n";
echo "Atualizar nome: " . count($revisoes['atualizar_nome']) . "\n";
echo "Nomes curtos: " . count($revisoes['nomes_curtos']) . "\n";
echo "Caracteres estranhos: " . count($revisoes['caracteres_estranhos']) . "\n";
echo "Total para revisão: $total\n";

if ($total > 0) {
    echo "\n⚠️  Revise e corrija os itens acima no CSV antes de importar!\n";
}

echo "\n=== Concluído ===\n";
