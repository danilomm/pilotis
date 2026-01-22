<?php
/**
 * Script para importar dados limpos de 2022 para o banco
 *
 * Pré-requisito: Executar limpar_csv_2022.php primeiro
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';

$file_in = __DIR__ . '/../public/data/filiados_2022_limpo.csv';
$ano = 2022;

if (!file_exists($file_in)) {
    die("Erro: Execute primeiro limpar_csv_2022.php para gerar o CSV limpo.\n");
}

// Verifica se campanha já existe
$campanha = db_fetch_one("SELECT * FROM campanhas WHERE ano = ?", [$ano]);
if ($campanha) {
    die("Erro: Campanha $ano já existe. Delete-a primeiro se quiser reimportar.\n");
}

// Cria campanha como fechada
db_execute("INSERT INTO campanhas (ano, status, created_at) VALUES (?, 'fechada', datetime('now'))", [$ano]);
echo "Campanha $ano criada como fechada.\n";

// Lê CSV limpo
$handle = fopen($file_in, 'r');

// Pula BOM se existir
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($handle);
}

// Lê cabeçalho
$header = fgetcsv($handle, 0, ';');

// Índices das colunas
$col = array_flip($header);

$stats = [
    'importados' => 0,
    'existentes' => 0,
    'novos' => 0,
    'emails_adicionados' => 0,
    'nomes_atualizados' => 0,
    'por_categoria' => [],
];

while (($row = fgetcsv($handle, 0, ';')) !== false) {
    if (empty($row[0])) continue;

    $nome = $row[$col['nome']];
    $email = strtolower(trim($row[$col['email']]));
    $categoria = $row[$col['categoria']];
    $valor = (int)$row[$col['valor']];
    $telefone = $row[$col['telefone']] ?? '';
    $cep = $row[$col['cep']] ?? '';
    $cidade = $row[$col['cidade']] ?? '';
    $estado = $row[$col['estado']] ?? '';
    $endereco = $row[$col['endereco']] ?? '';
    $profissao = $row[$col['profissao']] ?? '';
    $formacao = $row[$col['formacao']] ?? '';
    $instituicao = $row[$col['instituicao']] ?? '';
    $metodo = $row[$col['metodo']] ?? '';
    $data = $row[$col['data']] ?? '';

    // Colunas de verificação
    $email_existe = $row[$col['email_existe']] ?? '';
    $pessoa_id_email = $row[$col['pessoa_id_email']] ?? '';
    $nome_banco_email = $row[$col['nome_banco_email']] ?? '';
    $nome_similar = $row[$col['nome_similar']] ?? '';
    $pessoa_id_nome = $row[$col['pessoa_id_nome']] ?? '';
    $nome_banco_similar = $row[$col['nome_banco_similar']] ?? '';
    $acao = $row[$col['acao_sugerida']] ?? '';

    $pessoa_id = null;

    // Determina pessoa
    if ($email_existe === 'SIM' && $pessoa_id_email) {
        // Pessoa existe por email
        $pessoa_id = (int)$pessoa_id_email;
        $stats['existentes']++;

        // Atualiza nome se planilha tem nome mais completo
        if ($acao === 'ATUALIZAR_NOME' && strlen($nome) > strlen($nome_banco_email)) {
            db_execute("UPDATE pessoas SET nome = ?, updated_at = datetime('now') WHERE id = ?", [$nome, $pessoa_id]);
            $stats['nomes_atualizados']++;
            echo "Nome atualizado: '$nome_banco_email' -> '$nome'\n";
        }
    } elseif ($nome_similar && $pessoa_id_nome) {
        // Pessoa existe por nome similar (verificado manualmente)
        $pessoa_id = (int)$pessoa_id_nome;
        $stats['existentes']++;

        // Adiciona email secundário se não existe
        $email_existe_db = db_fetch_one("SELECT id FROM emails WHERE email = ?", [$email]);
        if (!$email_existe_db) {
            db_execute("INSERT INTO emails (pessoa_id, email, principal) VALUES (?, ?, 0)", [$pessoa_id, $email]);
            $stats['emails_adicionados']++;
            echo "Email adicionado para {$nome_banco_similar}: $email\n";
        }
    } else {
        // Criar pessoa nova
        $pessoa_id = db_insert("
            INSERT INTO pessoas (nome, token, ativo, created_at, updated_at)
            VALUES (?, ?, 1, datetime('now'), datetime('now'))
        ", [$nome, bin2hex(random_bytes(16))]);

        // Criar email
        db_execute("
            INSERT INTO emails (pessoa_id, email, principal)
            VALUES (?, ?, 1)
        ", [$pessoa_id, $email]);

        $stats['novos']++;
    }

    // Verifica se já existe filiação para este ano
    $filiacao_existe = db_fetch_one("
        SELECT id FROM filiacoes WHERE pessoa_id = ? AND ano = ?
    ", [$pessoa_id, $ano]);

    if ($filiacao_existe) {
        echo "AVISO: Filiação já existe para pessoa $pessoa_id ($nome) em $ano. Pulando.\n";
        continue;
    }

    // Converte data para formato ISO
    $data_pagamento = null;
    if ($data) {
        // Formato esperado: "dd/mm/yyyy hh:mm:ss" ou "dd/mm/yyyy"
        if (preg_match('#(\d{2})/(\d{2})/(\d{4})#', $data, $m)) {
            $data_pagamento = "{$m[3]}-{$m[2]}-{$m[1]}";
        }
    }

    // Criar filiação
    db_execute("
        INSERT INTO filiacoes (
            pessoa_id, ano, categoria, valor, status,
            data_pagamento, metodo, telefone, endereco, cep,
            cidade, estado, profissao, formacao, instituicao, created_at
        ) VALUES (?, ?, ?, ?, 'pago', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
    ", [
        $pessoa_id, $ano, $categoria, $valor,
        $data_pagamento, $metodo, $telefone, $endereco, $cep,
        $cidade, $estado, $profissao, $formacao, $instituicao
    ]);

    $stats['importados']++;
    $stats['por_categoria'][$categoria] = ($stats['por_categoria'][$categoria] ?? 0) + 1;
}

fclose($handle);

// Exibe resultado
echo "\n=== Importação Concluída ===\n";
echo "Filiações importadas: {$stats['importados']}\n";
echo "Pessoas existentes: {$stats['existentes']}\n";
echo "Pessoas novas: {$stats['novos']}\n";
echo "Emails adicionados: {$stats['emails_adicionados']}\n";
echo "Nomes atualizados: {$stats['nomes_atualizados']}\n";
echo "\nPor categoria:\n";
foreach ($stats['por_categoria'] as $cat => $qtd) {
    echo "  $cat: $qtd\n";
}

// Calcula total arrecadado
$total = db_fetch_one("SELECT SUM(valor) as total FROM filiacoes WHERE ano = ?", [$ano]);
echo "\nTotal arrecadado: R$ " . number_format(($total['total'] ?? 0) / 100, 2, ',', '.') . "\n";

echo "\n=== PRÓXIMO PASSO OBRIGATÓRIO ===\n";
echo "Verificar duplicatas por nome similar:\n\n";
echo "sqlite3 data/pilotis.db \"SELECT p1.id, p1.nome, p2.id, p2.nome FROM pessoas p1, pessoas p2 WHERE p1.id < p2.id AND LOWER(SUBSTR(p1.nome, 1, INSTR(p1.nome || ' ', ' '))) = LOWER(SUBSTR(p2.nome, 1, INSTR(p2.nome || ' ', ' '))) ORDER BY p1.nome;\"\n";
