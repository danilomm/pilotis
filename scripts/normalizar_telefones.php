<?php
/**
 * Normaliza telefones em todas as filiações
 * Formato alvo: (XX) XXXXX-XXXX ou (XX) XXXX-XXXX
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';

$dry_run = in_array('--dry-run', $argv);

echo "=== Normalizando Telefones ===\n";
if ($dry_run) echo "MODO: Dry-run\n";
echo "\n";

function normalizar_telefone($tel) {
    if (empty($tel)) return '';

    $original = $tel;

    // Remove +55 do início
    $tel = preg_replace('/^\+55\s*/', '', $tel);

    // Remove caracteres não numéricos
    $numeros = preg_replace('/\D/', '', $tel);

    // Se tem mais de 11 dígitos, pode ser múltiplos números - pega só o primeiro
    if (strlen($numeros) > 11) {
        // Tenta encontrar padrão de DDD + número
        if (preg_match('/^0?(\d{2})(\d{8,9})/', $numeros, $m)) {
            $numeros = $m[1] . $m[2];
        } else {
            $numeros = substr($numeros, 0, 11);
        }
    }

    // Remove zero inicial do DDD
    if (strlen($numeros) == 12 && $numeros[0] == '0') {
        $numeros = substr($numeros, 1);
    }
    if (strlen($numeros) == 11 && $numeros[0] == '0') {
        $numeros = substr($numeros, 1);
    }

    // Se tem 10 ou 11 dígitos, formata
    if (strlen($numeros) == 11) {
        // Celular: (XX) XXXXX-XXXX
        return sprintf('(%s) %s-%s',
            substr($numeros, 0, 2),
            substr($numeros, 2, 5),
            substr($numeros, 7, 4)
        );
    } elseif (strlen($numeros) == 10) {
        // Fixo: (XX) XXXX-XXXX
        return sprintf('(%s) %s-%s',
            substr($numeros, 0, 2),
            substr($numeros, 2, 4),
            substr($numeros, 6, 4)
        );
    } elseif (strlen($numeros) == 8 || strlen($numeros) == 9) {
        // Sem DDD - retorna só os números formatados
        if (strlen($numeros) == 9) {
            return sprintf('%s-%s', substr($numeros, 0, 5), substr($numeros, 5, 4));
        } else {
            return sprintf('%s-%s', substr($numeros, 0, 4), substr($numeros, 4, 4));
        }
    }

    // Número internacional ou formato desconhecido - mantém original
    if (strpos($original, '+') !== false && strpos($original, '+55') === false) {
        return $original; // Mantém internacional
    }

    return $original;
}

// Busca todos os telefones únicos
$telefones = db_fetch_all("
    SELECT DISTINCT telefone
    FROM filiacoes
    WHERE telefone IS NOT NULL AND telefone != ''
");

$alterados = 0;
$mantidos = 0;
$problemas = [];

foreach ($telefones as $t) {
    $original = $t['telefone'];
    $normalizado = normalizar_telefone($original);

    if ($normalizado !== $original) {
        echo "ANTES:  $original\n";
        echo "DEPOIS: $normalizado\n\n";

        if (!$dry_run) {
            db_execute("UPDATE filiacoes SET telefone = ? WHERE telefone = ?", [$normalizado, $original]);
        }
        $alterados++;
    } else {
        // Verifica se está no formato correto
        if (!preg_match('/^\(\d{2}\) \d{4,5}-\d{4}$/', $normalizado) &&
            strpos($normalizado, '+') === false) {
            $problemas[] = $original;
        }
        $mantidos++;
    }
}

echo "=== Resumo ===\n";
echo "Alterados: $alterados\n";
echo "Mantidos: $mantidos\n";

if (!empty($problemas)) {
    echo "\n=== Telefones com problemas (não normalizados) ===\n";
    foreach (array_slice($problemas, 0, 20) as $p) {
        echo "  $p\n";
    }
    if (count($problemas) > 20) {
        echo "  ... e mais " . (count($problemas) - 20) . "\n";
    }
}
