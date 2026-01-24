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
 *   - Pagamentos vencidos há mais de 7 dias (semanal)
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
    echo "[DRY-RUN] Nenhum email será enviado\n";
}
echo str_repeat('-', 50) . "\n";

$hoje = date('Y-m-d');

// Busca filiações pendentes
$pendentes = db_fetch_all("
    SELECT f.id, f.pessoa_id, f.ano, f.valor, f.data_vencimento,
           p.nome, e.email, p.token
    FROM filiacoes f
    JOIN pessoas p ON p.id = f.pessoa_id
    LEFT JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
    WHERE f.status = 'pendente'
    AND f.data_vencimento IS NOT NULL
    AND p.ativo = 1
");

echo "Pagamentos pendentes encontrados: " . count($pendentes) . "\n\n";

$enviados = 0;
$erros = 0;
$pulados = 0;

foreach ($pendentes as $p) {
    $vencimento = $p['data_vencimento'];
    $dias_restantes = (strtotime($vencimento) - strtotime($hoje)) / 86400;

    // Critérios para envio:
    // 1. Vence hoje (dias_restantes = 0)
    // 2. Venceu e é domingo (lembrete semanal)
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

    // Gera token se não tiver
    $token = $p['token'];
    if (!$token) {
        $token = gerar_token();
        db_execute("UPDATE pessoas SET token = ? WHERE id = ?", [$token, $p['pessoa_id']]);
    }

    $email = $p['email'];
    if (!$email) {
        // Busca qualquer email
        $email_row = db_fetch_one("SELECT email FROM emails WHERE pessoa_id = ? LIMIT 1", [$p['pessoa_id']]);
        $email = $email_row['email'] ?? null;
    }

    if (!$email) {
        echo "{$p['nome']} - SEM EMAIL\n";
        $pulados++;
        continue;
    }

    echo "{$p['nome']} <{$email}> - {$p['ano']} - $motivo ... ";

    if ($dry_run) {
        echo "[DRY-RUN]\n";
        $enviados++;
        continue;
    }

    try {
        $enviado = BrevoService::enviarLembretePagamento(
            $email,
            $p['nome'],
            $p['ano'],
            $token,
            (int)$dias_restantes,
            (int)$p['valor']
        );

        if ($enviado) {
            echo "OK\n";
            $enviados++;
            registrar_log('lembrete_enviado', $p['pessoa_id'], "Lembrete {$p['ano']} enviado");
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

// --- Lembrete "última chance" (3 dias antes do fim da campanha) ---
$campanha_aberta = db_fetch_one("
    SELECT ano, data_fim FROM campanhas
    WHERE status = 'aberta' AND data_fim IS NOT NULL
    ORDER BY ano DESC LIMIT 1
");

if ($campanha_aberta) {
    $ano = (int)$campanha_aberta['ano'];
    $data_fim = $campanha_aberta['data_fim'];
    $dias_para_fim = (int)((strtotime($data_fim) - strtotime($hoje)) / 86400);

    if ($dias_para_fim === 3) {
        echo "\n=== Última Chance (campanha $ano encerra em 3 dias) ===\n";

        // Pessoas que receberam a campanha mas não pagaram
        $nao_pagaram = db_fetch_all("
            SELECT f.pessoa_id, p.nome, p.token,
                   (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
            FROM filiacoes f
            JOIN pessoas p ON p.id = f.pessoa_id
            WHERE f.ano = ?
            AND f.status IN ('enviado', 'acesso', 'pendente')
            AND p.ativo = 1
        ", [$ano]);

        echo "Destinatários: " . count($nao_pagaram) . "\n\n";

        $uc_enviados = 0;
        $uc_erros = 0;
        $data_fim_formatada = date('d/m/Y', strtotime($data_fim));

        foreach ($nao_pagaram as $pessoa) {
            if (!$pessoa['email']) continue;

            $token = $pessoa['token'];
            if (!$token) {
                $token = gerar_token();
                db_execute("UPDATE pessoas SET token = ? WHERE id = ?", [$token, $pessoa['pessoa_id']]);
            }

            $link = BASE_URL . "/filiacao/$ano/$token";

            echo "{$pessoa['nome']} <{$pessoa['email']}> ... ";

            if ($dry_run) {
                echo "[DRY-RUN]\n";
                $uc_enviados++;
                continue;
            }

            try {
                $template = carregar_template('ultima_chance', [
                    'nome' => $pessoa['nome'],
                    'ano' => $ano,
                    'dias' => '3',
                    'data_fim' => $data_fim_formatada,
                    'link' => $link,
                ]);

                $enviado = BrevoService::enviarEmail(
                    $pessoa['email'],
                    $template['assunto'],
                    $template['html']
                );

                if ($enviado) {
                    echo "OK\n";
                    $uc_enviados++;
                } else {
                    echo "ERRO\n";
                    $uc_erros++;
                }
            } catch (Exception $e) {
                echo "ERRO: " . $e->getMessage() . "\n";
                $uc_erros++;
            }

            usleep(100000); // 100ms
        }

        echo "\nÚltima chance - Enviados: $uc_enviados | Erros: $uc_erros\n";
    } elseif ($dias_para_fim >= 0 && $dias_para_fim < 3) {
        echo "\nCampanha $ano encerra em $dias_para_fim dia(s). Lembrete 'última chance' já foi enviado.\n";
    }
}
