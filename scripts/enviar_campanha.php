#!/usr/bin/env php
<?php
/**
 * Script para envio de campanhas de filiacao
 *
 * Uso:
 *   php scripts/enviar_campanha.php --ano 2026 --tipo renovacao --dry-run
 *   php scripts/enviar_campanha.php --ano 2026 --tipo seminario
 *   php scripts/enviar_campanha.php --ano 2026 --tipo convite
 *
 * Tipos:
 *   renovacao - Filiados do ano anterior
 *   seminario - Participantes do seminario nao filiados
 *   convite   - Outros cadastrados
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/Services/BrevoService.php';

// Processa argumentos
$options = getopt('', ['ano:', 'tipo:', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "Uso: php scripts/enviar_campanha.php --ano YYYY --tipo [renovacao|seminario|convite] [--dry-run]\n";
    exit(0);
}

$ano = (int)($options['ano'] ?? date('Y'));
$tipo = $options['tipo'] ?? 'renovacao';
$dry_run = isset($options['dry-run']);

echo "Campanha de filiacao $ano - Tipo: $tipo\n";
if ($dry_run) {
    echo "[DRY-RUN] Nenhum email sera enviado\n";
}
echo str_repeat('-', 50) . "\n";

// Busca destinatarios conforme tipo
$destinatarios = [];

switch ($tipo) {
    case 'renovacao':
        // Filiados do ano anterior
        $ano_anterior = $ano - 1;
        $destinatarios = db_fetch_all("
            SELECT c.id, c.nome, c.email, c.token
            FROM cadastrados c
            JOIN pagamentos p ON p.cadastrado_id = c.id
            WHERE p.ano = ? AND p.status = 'pago'
            AND c.id NOT IN (
                SELECT cadastrado_id FROM pagamentos WHERE ano = ?
            )
        ", [$ano_anterior, $ano]);
        break;

    case 'seminario':
        // Participantes do seminario nao filiados
        $destinatarios = db_fetch_all("
            SELECT c.id, c.nome, c.email, c.token
            FROM cadastrados c
            WHERE c.seminario_2025 = 1
            AND c.id NOT IN (
                SELECT cadastrado_id FROM pagamentos WHERE ano = ? AND status = 'pago'
            )
        ", [$ano]);
        break;

    case 'convite':
        // Outros cadastrados nao filiados
        $destinatarios = db_fetch_all("
            SELECT c.id, c.nome, c.email, c.token
            FROM cadastrados c
            WHERE c.id NOT IN (
                SELECT cadastrado_id FROM pagamentos WHERE ano = ?
            )
            AND c.seminario_2025 = 0
            AND c.id NOT IN (
                SELECT cadastrado_id FROM pagamentos WHERE status = 'pago'
            )
        ", [$ano]);
        break;

    default:
        echo "Tipo invalido: $tipo\n";
        exit(1);
}

echo "Total de destinatarios: " . count($destinatarios) . "\n\n";

if (empty($destinatarios)) {
    echo "Nenhum destinatario encontrado.\n";
    exit(0);
}

$enviados = 0;
$erros = 0;

foreach ($destinatarios as $d) {
    // Gera token se nao tiver
    $token = $d['token'];
    if (!$token) {
        $token = gerar_token();
        db_execute("UPDATE cadastrados SET token = ? WHERE id = ?", [$token, $d['id']]);
    }

    echo "{$d['nome']} <{$d['email']}> ... ";

    if ($dry_run) {
        echo "[DRY-RUN]\n";
        $enviados++;
        continue;
    }

    try {
        $enviado = false;
        switch ($tipo) {
            case 'renovacao':
                $enviado = BrevoService::enviarCampanhaRenovacao($d['email'], $d['nome'], $ano, $token);
                break;
            case 'seminario':
                $enviado = BrevoService::enviarCampanhaSeminario($d['email'], $d['nome'], $ano, $token);
                break;
            case 'convite':
                $enviado = BrevoService::enviarCampanhaConvite($d['email'], $d['nome'], $ano, $token);
                break;
        }

        if ($enviado) {
            echo "OK\n";
            $enviados++;
            registrar_log('campanha_enviada', $d['id'], "Campanha $tipo $ano enviada");
        } else {
            echo "ERRO\n";
            $erros++;
        }
    } catch (Exception $e) {
        echo "ERRO: " . $e->getMessage() . "\n";
        $erros++;
    }

    // Pausa para evitar rate limit (300/dia no plano gratuito)
    usleep(100000); // 100ms
}

echo "\n" . str_repeat('-', 50) . "\n";
echo "Enviados: $enviados | Erros: $erros\n";
