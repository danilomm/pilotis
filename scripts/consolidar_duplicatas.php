<?php
/**
 * Consolida duplicatas por nome exato
 * - Mantém o ID mais antigo (menor)
 * - Move emails para pessoa principal
 * - Move filiações (se não conflitar)
 * - Deleta pessoa duplicada
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';

$dry_run = in_array('--dry-run', $argv);

echo "=== Consolidação de Duplicatas ===\n";
if ($dry_run) {
    echo "MODO: Dry-run (nenhuma alteração será feita)\n";
}
echo "\n";

// Encontra duplicatas por nome exato
$duplicatas = db_fetch_all("
    SELECT p1.id as id1, p1.nome as nome1, p2.id as id2, p2.nome as nome2
    FROM pessoas p1, pessoas p2
    WHERE p1.id < p2.id
    AND LOWER(TRIM(p1.nome)) = LOWER(TRIM(p2.nome))
    ORDER BY p1.nome
");

echo "Duplicatas encontradas: " . count($duplicatas) . "\n\n";

$consolidados = 0;
$erros = 0;

foreach ($duplicatas as $dup) {
    $id_principal = $dup['id1'];
    $id_duplicado = $dup['id2'];
    $nome = $dup['nome1'];

    echo "--- $nome ---\n";
    echo "Principal: ID $id_principal\n";
    echo "Duplicado: ID $id_duplicado\n";

    // Busca emails de ambos
    $emails_principal = db_fetch_all("SELECT email FROM emails WHERE pessoa_id = ?", [$id_principal]);
    $emails_duplicado = db_fetch_all("SELECT email FROM emails WHERE pessoa_id = ?", [$id_duplicado]);

    echo "Emails principal: " . implode(', ', array_column($emails_principal, 'email')) . "\n";
    echo "Emails duplicado: " . implode(', ', array_column($emails_duplicado, 'email')) . "\n";

    // Busca filiações de ambos
    $filiacao_principal = db_fetch_all("SELECT ano FROM filiacoes WHERE pessoa_id = ?", [$id_principal]);
    $filiacao_duplicado = db_fetch_all("SELECT ano FROM filiacoes WHERE pessoa_id = ?", [$id_duplicado]);

    $anos_principal = array_column($filiacao_principal, 'ano');
    $anos_duplicado = array_column($filiacao_duplicado, 'ano');

    echo "Filiações principal: " . implode(', ', $anos_principal) . "\n";
    echo "Filiações duplicado: " . implode(', ', $anos_duplicado) . "\n";

    // Verifica conflito de anos
    $conflito = array_intersect($anos_principal, $anos_duplicado);
    if (!empty($conflito)) {
        echo "⚠️  CONFLITO: Ambos têm filiação em " . implode(', ', $conflito) . "\n";
        echo "   Filiações duplicadas serão removidas do ID $id_duplicado\n";
    }

    if (!$dry_run) {
        // Move emails (ignora se já existe)
        foreach ($emails_duplicado as $e) {
            $existe = db_fetch_one("SELECT 1 FROM emails WHERE pessoa_id = ? AND LOWER(email) = LOWER(?)",
                [$id_principal, $e['email']]);
            if (!$existe) {
                db_execute("UPDATE emails SET pessoa_id = ?, principal = 0 WHERE pessoa_id = ? AND LOWER(email) = LOWER(?)",
                    [$id_principal, $id_duplicado, $e['email']]);
            }
        }

        // Move filiações (remove conflitos)
        foreach ($conflito as $ano) {
            db_execute("DELETE FROM filiacoes WHERE pessoa_id = ? AND ano = ?", [$id_duplicado, $ano]);
        }
        db_execute("UPDATE filiacoes SET pessoa_id = ? WHERE pessoa_id = ?", [$id_principal, $id_duplicado]);

        // Remove emails restantes do duplicado
        db_execute("DELETE FROM emails WHERE pessoa_id = ?", [$id_duplicado]);

        // Remove pessoa duplicada
        db_execute("DELETE FROM pessoas WHERE id = ?", [$id_duplicado]);

        echo "✓ Consolidado\n";
        $consolidados++;
    } else {
        echo "[DRY-RUN] Seria consolidado\n";
        $consolidados++;
    }
    echo "\n";
}

echo "=== Resumo ===\n";
echo "Consolidados: $consolidados\n";
echo "Erros: $erros\n";

// Verifica se ainda há duplicatas
$restantes = db_fetch_one("
    SELECT COUNT(*) as qtd FROM (
        SELECT p1.id
        FROM pessoas p1, pessoas p2
        WHERE p1.id < p2.id
        AND LOWER(TRIM(p1.nome)) = LOWER(TRIM(p2.nome))
    )
");
echo "Duplicatas restantes: " . ($restantes['qtd'] ?? 0) . "\n";
