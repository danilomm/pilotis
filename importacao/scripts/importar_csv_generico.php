<?php
/**
 * Script genérico para importar CSVs limpos no banco
 *
 * Uso:
 *   php importar_csv_generico.php <ano>
 *   php importar_csv_generico.php <ano> --dry-run
 *
 * Exemplos:
 *   php importar_csv_generico.php 2015
 *   php importar_csv_generico.php 2016 --dry-run
 */

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';

// Parse argumentos
$ano = (int)($argv[1] ?? 0);
$dry_run = in_array('--dry-run', $argv);

if ($ano < 2015 || $ano > 2025) {
    die("Uso: php importar_csv_generico.php <ano> [--dry-run]\n");
}

$file_in = __DIR__ . "/../limpos/filiados_{$ano}_limpo.csv";

if (!file_exists($file_in)) {
    die("Arquivo não encontrado: $file_in\n");
}

echo "=== Importando $ano ===\n";
echo "Arquivo: $file_in\n";
if ($dry_run) {
    echo "MODO: Dry-run (nenhuma alteração será feita)\n";
}
echo "\n";

// Verifica se campanha já existe
$campanha = db_fetch_one("SELECT * FROM campanhas WHERE ano = ?", [$ano]);
if (!$campanha) {
    if (!$dry_run) {
        db_execute("INSERT INTO campanhas (ano, status, created_at) VALUES (?, 'fechada', datetime('now'))", [$ano]);
        echo "Campanha $ano criada como fechada.\n";
    } else {
        echo "[DRY-RUN] Campanha $ano seria criada.\n";
    }
}

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

$stats = [
    'lidos' => 0,
    'importados' => 0,
    'pulados_ja_existe' => 0,
    'pessoas_existentes' => 0,
    'pessoas_novas' => 0,
    'nomes_atualizados' => 0,
    'emails_adicionados' => 0,
    'por_categoria' => [],
];

while (($row = fgetcsv($handle, 0, ';')) !== false) {
    if (empty($row[0])) continue;

    $stats['lidos']++;

    $nome = $row[$col['nome']] ?? '';
    $email = strtolower(trim($row[$col['email']] ?? ''));
    $categoria = $row[$col['categoria']] ?? '';
    $valor = (int)($row[$col['valor']] ?? 0);
    $data_pagamento = $row[$col['data_pagamento']] ?? null;
    $metodo = $row[$col['metodo']] ?? 'Desconhecido';
    $cpf = $row[$col['cpf']] ?? '';
    $telefone = $row[$col['telefone']] ?? '';
    $endereco = $row[$col['endereco']] ?? '';
    $cep = $row[$col['cep']] ?? '';
    $cidade = $row[$col['cidade']] ?? '';
    $estado = $row[$col['estado']] ?? '';
    $pais = $row[$col['pais']] ?? 'Brasil';
    $profissao = $row[$col['profissao']] ?? '';
    $formacao = $row[$col['formacao']] ?? '';
    $instituicao = $row[$col['instituicao']] ?? '';
    $seminario = $row[$col['seminario']] ?? '';

    // Colunas de verificação do CSV limpo
    $email_existe = $row[$col['email_existe']] ?? '';
    $pessoa_id_csv = $row[$col['pessoa_id']] ?? '';
    $nome_banco = $row[$col['nome_banco']] ?? '';

    $pessoa_id = null;

    // Determina pessoa
    if ($email_existe === 'SIM' && $pessoa_id_csv) {
        // Pessoa já existe por email
        $pessoa_id = (int)$pessoa_id_csv;
        $stats['pessoas_existentes']++;

        // Atualiza nome se o novo é mais completo
        if (!empty($nome) && strlen($nome) > strlen($nome_banco)) {
            if (!$dry_run) {
                db_execute("UPDATE pessoas SET nome = ?, updated_at = datetime('now') WHERE id = ?", [$nome, $pessoa_id]);
            }
            $stats['nomes_atualizados']++;
        }
    } else {
        // Verifica se email existe no banco (caso CSV esteja desatualizado)
        $pessoa_db = db_fetch_one("
            SELECT p.id, p.nome FROM pessoas p
            JOIN emails e ON e.pessoa_id = p.id
            WHERE LOWER(e.email) = ?
        ", [$email]);

        if ($pessoa_db) {
            $pessoa_id = $pessoa_db['id'];
            $stats['pessoas_existentes']++;

            if (!empty($nome) && strlen($nome) > strlen($pessoa_db['nome'])) {
                if (!$dry_run) {
                    db_execute("UPDATE pessoas SET nome = ?, updated_at = datetime('now') WHERE id = ?", [$nome, $pessoa_id]);
                }
                $stats['nomes_atualizados']++;
            }
        } else {
            // Criar pessoa nova
            if (!$dry_run) {
                $pessoa_id = db_insert("
                    INSERT INTO pessoas (nome, cpf, token, ativo, created_at, updated_at)
                    VALUES (?, ?, ?, 1, datetime('now'), datetime('now'))
                ", [$nome, $cpf, bin2hex(random_bytes(16))]);

                // Criar email
                db_execute("
                    INSERT INTO emails (pessoa_id, email, principal)
                    VALUES (?, ?, 1)
                ", [$pessoa_id, $email]);
            } else {
                $pessoa_id = 0; // Placeholder para dry-run
            }

            $stats['pessoas_novas']++;
        }
    }

    // Verifica se já existe filiação para este ano
    if (!$dry_run) {
        $filiacao_existe = db_fetch_one("
            SELECT id FROM filiacoes WHERE pessoa_id = ? AND ano = ?
        ", [$pessoa_id, $ano]);

        if ($filiacao_existe) {
            $stats['pulados_ja_existe']++;
            continue;
        }
    }

    // Status: se tem data de pagamento, considera pago
    $status = !empty($data_pagamento) ? 'pago' : 'pendente';

    // Criar filiação
    if (!$dry_run) {
        db_execute("
            INSERT INTO filiacoes (
                pessoa_id, ano, categoria, valor, status,
                data_pagamento, metodo, telefone, endereco, cep,
                cidade, estado, pais, profissao, formacao, instituicao, seminario, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ", [
            $pessoa_id, $ano, $categoria, $valor, $status,
            $data_pagamento, $metodo, $telefone, $endereco, $cep,
            $cidade, $estado, $pais, $profissao, $formacao, $instituicao, $seminario
        ]);
    }

    $stats['importados']++;
    $cat_key = $categoria ?: '(vazio)';
    $stats['por_categoria'][$cat_key] = ($stats['por_categoria'][$cat_key] ?? 0) + 1;
}

fclose($handle);

// Exibe resultado
echo "=== Resultado ===\n";
echo "Registros lidos: {$stats['lidos']}\n";
echo "Filiações importadas: {$stats['importados']}\n";
echo "Pulados (já existe filiação): {$stats['pulados_ja_existe']}\n";
echo "Pessoas existentes: {$stats['pessoas_existentes']}\n";
echo "Pessoas novas: {$stats['pessoas_novas']}\n";
echo "Nomes atualizados: {$stats['nomes_atualizados']}\n";

echo "\nPor categoria:\n";
foreach ($stats['por_categoria'] as $cat => $qtd) {
    echo "  $cat: $qtd\n";
}

if (!$dry_run) {
    // Calcula total arrecadado
    $total = db_fetch_one("SELECT SUM(valor) as total FROM filiacoes WHERE ano = ?", [$ano]);
    $total_filiados = db_fetch_one("SELECT COUNT(*) as qtd FROM filiacoes WHERE ano = ?", [$ano]);
    echo "\nTotal no banco para $ano:\n";
    echo "  Filiados: {$total_filiados['qtd']}\n";
    echo "  Arrecadado: R$ " . number_format(($total['total'] ?? 0) / 100, 2, ',', '.') . "\n";
}

echo "\n=== Concluído ===\n";
