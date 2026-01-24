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
 *   - Pagamentos que vencem amanhã
 *   - Pagamentos vencidos (quinzenal, domingos, máx 3)
 *   - Formulários incompletos (quinzenal, domingos, máx 3)
 *   - "Última chance" (3 dias antes do fim da campanha)
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
$domingo_quinzenal = (date('N') == 7 && date('W') % 2 == 0);

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
    // 1. Vence amanhã (dias_restantes = 1) - lembrete preventivo
    // 2. Venceu e é domingo de semana par - aviso de expiração (quinzenal, máx 3)
    $enviar = false;
    $motivo = '';
    $tipo_lembrete = 'lembrete'; // template padrão

    if ($dias_restantes >= 1 && $dias_restantes < 2) {
        $enviar = true;
        $motivo = 'vence amanhã';
    } elseif ($dias_restantes < 0 && $domingo_quinzenal) {
        // Verificar limite de 3 lembretes
        $total_lembretes = db_fetch_one("
            SELECT COUNT(*) as total FROM log
            WHERE tipo = 'lembrete_enviado'
            AND cadastrado_id = ?
            AND mensagem LIKE ?
        ", [$p['pessoa_id'], "%{$p['ano']}%"]);

        if (($total_lembretes['total'] ?? 0) < 3) {
            $enviar = true;
            $motivo = 'vencido (gerar novo)';
            $tipo_lembrete = 'lembrete_vencido';
        }
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
        $link = BASE_URL . "/filiacao/{$p['ano']}/$token/pagamento";

        $template = carregar_template($tipo_lembrete, [
            'nome' => $p['nome'],
            'ano' => $p['ano'],
            'valor' => formatar_valor((int)$p['valor']),
            'link' => $link,
            'urgencia' => '',
            'dias_info' => 'Seu pagamento vence amanhã.',
        ]);

        $enviado = BrevoService::enviarEmail(
            $email,
            $template['assunto'],
            $template['html']
        );

        if ($enviado) {
            echo "OK\n";
            $enviados++;
            registrar_log('lembrete_enviado', $p['pessoa_id'], "Lembrete {$p['ano']} ($tipo_lembrete)");
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

// --- Lembrete para quem acessou mas não concluiu (quinzenal, máx 3) ---
if ($domingo_quinzenal) {
    $campanha_para_acesso = db_fetch_one("
        SELECT ano FROM campanhas WHERE status = 'aberta' ORDER BY ano DESC LIMIT 1
    ");

    if ($campanha_para_acesso) {
        $ano_acesso = (int)$campanha_para_acesso['ano'];

        echo "\n=== Lembretes de formulário incompleto (campanha $ano_acesso) ===\n";

        $com_acesso = db_fetch_all("
            SELECT f.pessoa_id, p.nome, p.token,
                   (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
            FROM filiacoes f
            JOIN pessoas p ON p.id = f.pessoa_id
            WHERE f.ano = ?
            AND f.status = 'acesso'
            AND p.ativo = 1
        ", [$ano_acesso]);

        echo "Formulários incompletos: " . count($com_acesso) . "\n\n";

        $ac_enviados = 0;
        $ac_erros = 0;

        foreach ($com_acesso as $pessoa) {
            if (!$pessoa['email']) continue;

            // Verificar limite de 3 lembretes
            $total_lembretes = db_fetch_one("
                SELECT COUNT(*) as total FROM log
                WHERE tipo = 'lembrete_acesso_enviado'
                AND cadastrado_id = ?
                AND mensagem LIKE ?
            ", [$pessoa['pessoa_id'], "%$ano_acesso%"]);

            if (($total_lembretes['total'] ?? 0) >= 3) {
                continue;
            }

            $token = $pessoa['token'];
            if (!$token) {
                $token = gerar_token();
                db_execute("UPDATE pessoas SET token = ? WHERE id = ?", [$token, $pessoa['pessoa_id']]);
            }

            $link = BASE_URL . "/filiacao/$ano_acesso/$token";

            echo "{$pessoa['nome']} <{$pessoa['email']}> ... ";

            if ($dry_run) {
                echo "[DRY-RUN]\n";
                $ac_enviados++;
                continue;
            }

            try {
                $template = carregar_template('lembrete_acesso', [
                    'nome' => $pessoa['nome'],
                    'ano' => $ano_acesso,
                    'link' => $link,
                ]);

                $enviado = BrevoService::enviarEmail(
                    $pessoa['email'],
                    $template['assunto'],
                    $template['html']
                );

                if ($enviado) {
                    echo "OK\n";
                    $ac_enviados++;
                    registrar_log('lembrete_acesso_enviado', $pessoa['pessoa_id'], "Lembrete acesso $ano_acesso");
                } else {
                    echo "ERRO\n";
                    $ac_erros++;
                }
            } catch (Exception $e) {
                echo "ERRO: " . $e->getMessage() . "\n";
                $ac_erros++;
            }

            usleep(100000); // 100ms
        }

        echo "\nFormulários incompletos - Enviados: $ac_enviados | Erros: $ac_erros\n";
    }
}

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
