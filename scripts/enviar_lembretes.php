#!/usr/bin/env php
<?php
/**
 * Script para envio de lembretes de pagamento pendente
 *
 * Uso:
 *   php scripts/enviar_lembretes.php
 *   php scripts/enviar_lembretes.php --dry-run
 *
 * Envia lembretes para:
 *   - Pagamentos que vencem hoje
 *   - Pagamentos vencidos ha mais de 7 dias (semanal)
 *
 * Ideal para rodar via cron diariamente.
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/Services/BrevoService.php';

// Processa argumentos
$options = getopt('', ['dry-run', 'help']);

if (isset($options['help'])) {
    echo "Uso: php scripts/enviar_lembretes.php [--dry-run]\n";
    exit(0);
}

$dry_run = isset($options['dry-run']);

echo "Envio de lembretes de pagamento\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n";
if ($dry_run) {
    echo "[DRY-RUN] Nenhum email sera enviado\n";
}
echo str_repeat('-', 50) . "\n";

$hoje = date('Y-m-d');

// Busca pagamentos pendentes
$pendentes = db_fetch_all("
    SELECT p.id, p.cadastrado_id, p.ano, p.valor, p.data_vencimento,
           c.nome, c.email, c.token
    FROM pagamentos p
    JOIN cadastrados c ON c.id = p.cadastrado_id
    WHERE p.status = 'pendente'
    AND p.data_vencimento IS NOT NULL
");

echo "Pagamentos pendentes encontrados: " . count($pendentes) . "\n\n";

$enviados = 0;
$erros = 0;
$pulados = 0;

foreach ($pendentes as $p) {
    $vencimento = $p['data_vencimento'];
    $dias_restantes = (strtotime($vencimento) - strtotime($hoje)) / 86400;

    // Criterios para envio:
    // 1. Vence hoje (dias_restantes = 0)
    // 2. Venceu e e domingo (lembrete semanal)
    $enviar = false;
    $motivo = '';

    if ($dias_restantes <= 0 && $dias_restantes > -1) {
        $enviar = true;
        $motivo = 'vence hoje';
    } elseif ($dias_restantes < 0 && date('N') == 7) {
        // Domingo - lembrete semanal para vencidos
        $enviar = true;
        $motivo = 'vencido (lembrete semanal)';
    }

    if (!$enviar) {
        $pulados++;
        continue;
    }

    // Gera token se nao tiver
    $token = $p['token'];
    if (!$token) {
        $token = gerar_token();
        db_execute("UPDATE cadastrados SET token = ? WHERE id = ?", [$token, $p['cadastrado_id']]);
    }

    echo "{$p['nome']} <{$p['email']}> - {$p['ano']} - $motivo ... ";

    if ($dry_run) {
        echo "[DRY-RUN]\n";
        $enviados++;
        continue;
    }

    try {
        $enviado = BrevoService::enviarLembretePagamento(
            $p['email'],
            $p['nome'],
            $p['ano'],
            $token,
            (int)$dias_restantes,
            (int)$p['valor']
        );

        if ($enviado) {
            echo "OK\n";
            $enviados++;
            registrar_log('lembrete_enviado', $p['cadastrado_id'], "Lembrete {$p['ano']} enviado");
        } else {
            echo "ERRO\n";
            $erros++;
        }
    } catch (Exception $e) {
        echo "ERRO: " . $e->getMessage() . "\n";
        $erros++;
    }

    usleep(100000); // 100ms
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Enviados: $enviados | Erros: $erros | Pulados: $pulados\n";
