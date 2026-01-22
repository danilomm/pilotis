<?php
/**
 * Verifica emails em CSV limpo
 *
 * Uso:
 *   php verificar_emails.php <arquivo_csv>
 *
 * Verifica:
 * - Typos conhecidos em domínios (gmal.com, hotmal.com, etc)
 * - Emails duplicados no CSV
 * - Formato inválido de email
 *
 * Exemplos:
 *   php scripts/verificar_emails.php importacao/limpos/filiados_2021_limpo.csv
 */

$typos_map = require __DIR__ . '/emails_typos.php';

if (!isset($argv[1])) {
    die("Uso: php verificar_emails.php <arquivo_csv>\n");
}

$file_in = $argv[1];

if (!file_exists($file_in)) {
    die("Arquivo não encontrado: $file_in\n");
}

echo "=== Verificando Emails ===\n";
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

if (!isset($col['email'])) {
    die("Erro: CSV não tem coluna 'email'\n");
}

$emails_vistos = [];
$problemas = [
    'typos' => [],
    'duplicados' => [],
    'invalidos' => [],
];

$linha = 1;
while (($row = fgetcsv($handle, 0, ';')) !== false) {
    $linha++;
    if (empty($row[0])) continue;

    $nome = $row[$col['nome']] ?? '';
    $email = strtolower(trim($row[$col['email']] ?? ''));

    if (empty($email)) {
        continue;
    }

    // Verifica formato básico
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $problemas['invalidos'][] = [
            'linha' => $linha,
            'nome' => $nome,
            'email' => $email,
            'motivo' => 'Formato inválido',
        ];
        continue;
    }

    // Extrai domínio
    $partes = explode('@', $email);
    $dominio = $partes[1] ?? '';

    // Verifica typos de domínio
    if (isset($typos_map['dominios'][$dominio])) {
        $corrigido = $partes[0] . '@' . $typos_map['dominios'][$dominio];
        $problemas['typos'][] = [
            'linha' => $linha,
            'nome' => $nome,
            'email' => $email,
            'corrigido' => $corrigido,
        ];
    }

    // Verifica typos de email específico
    if (isset($typos_map['emails'][$email])) {
        $problemas['typos'][] = [
            'linha' => $linha,
            'nome' => $nome,
            'email' => $email,
            'corrigido' => $typos_map['emails'][$email],
        ];
    }

    // Verifica duplicados
    if (isset($emails_vistos[$email])) {
        $problemas['duplicados'][] = [
            'linha' => $linha,
            'nome' => $nome,
            'email' => $email,
            'primeira_linha' => $emails_vistos[$email]['linha'],
            'primeiro_nome' => $emails_vistos[$email]['nome'],
        ];
    } else {
        $emails_vistos[$email] = ['linha' => $linha, 'nome' => $nome];
    }
}

fclose($handle);

// Exibe resultados
echo "=== Typos de Domínio ===\n";
if (empty($problemas['typos'])) {
    echo "Nenhum typo encontrado.\n";
} else {
    echo "Encontrados: " . count($problemas['typos']) . "\n\n";
    foreach ($problemas['typos'] as $p) {
        echo "Linha {$p['linha']}: {$p['nome']}\n";
        echo "  Atual:     {$p['email']}\n";
        echo "  Corrigido: {$p['corrigido']}\n\n";
    }
}

echo "\n=== Emails Duplicados ===\n";
if (empty($problemas['duplicados'])) {
    echo "Nenhum duplicado encontrado.\n";
} else {
    echo "Encontrados: " . count($problemas['duplicados']) . "\n\n";
    foreach ($problemas['duplicados'] as $p) {
        echo "Email: {$p['email']}\n";
        echo "  Linha {$p['primeira_linha']}: {$p['primeiro_nome']}\n";
        echo "  Linha {$p['linha']}: {$p['nome']} (DUPLICADO)\n\n";
    }
}

echo "\n=== Emails Inválidos ===\n";
if (empty($problemas['invalidos'])) {
    echo "Nenhum email inválido encontrado.\n";
} else {
    echo "Encontrados: " . count($problemas['invalidos']) . "\n\n";
    foreach ($problemas['invalidos'] as $p) {
        echo "Linha {$p['linha']}: {$p['nome']}\n";
        echo "  Email: {$p['email']}\n";
        echo "  Motivo: {$p['motivo']}\n\n";
    }
}

// Resumo
$total_problemas = count($problemas['typos']) + count($problemas['duplicados']) + count($problemas['invalidos']);
echo "\n=== Resumo ===\n";
echo "Typos: " . count($problemas['typos']) . "\n";
echo "Duplicados: " . count($problemas['duplicados']) . "\n";
echo "Inválidos: " . count($problemas['invalidos']) . "\n";
echo "Total de problemas: $total_problemas\n";

if ($total_problemas > 0) {
    echo "\n⚠️  Corrija os problemas no CSV antes de importar!\n";
}

echo "\n=== Concluído ===\n";
