#!/usr/bin/env php
<?php
/**
 * Script para envio de campanhas de filiacao
 *
 * Uso:
 *   php scripts/enviar_campanha.php --dry-run
 *   php scripts/enviar_campanha.php
 *   php scripts/enviar_campanha.php --ano 2026 --limite 300
 *
 * Processa grupos em ordem de prioridade:
 *   1. Adimplentes do ano anterior (template: renovacao)
 *   2. Participantes do seminário (template: seminario)
 *   3. Ex-filiados de qualquer ano (template: renovacao)
 *   4. Contatos sem filiação (template: convite)
 *   5. Contatos pendentes - nunca pagaram (template: convite)
 *
 * Pula pessoas que já foram contatadas para o ano (status 'enviado', 'pendente' ou 'pago').
 * Para após atingir o limite diário (padrão: 290).
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/Services/BrevoService.php';

// Processa argumentos
$options = getopt('', ['ano:', 'limite:', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "Uso: php scripts/enviar_campanha.php --ano YYYY [--limite N] [--dry-run]\n\n";
    echo "Opções:\n";
    echo "  --ano YYYY    Ano da campanha (padrão: ano atual)\n";
    echo "  --limite N    Máximo de emails por execução (padrão: 290)\n";
    echo "  --dry-run     Simula sem enviar emails\n";
    exit(0);
}

$limite = (int)($options['limite'] ?? 290);
$dry_run = isset($options['dry-run']);

// Detecta ano: argumento --ano ou campanha em modo 'enviando'
if (isset($options['ano'])) {
    $ano = (int)$options['ano'];
} else {
    $campanha_enviando = db_fetch_one("SELECT ano FROM campanhas WHERE status = 'enviando' ORDER BY ano DESC LIMIT 1");
    if (!$campanha_enviando) {
        echo "Nenhuma campanha em modo 'enviando'. Clique 'Iniciar Envio' no admin.\n";
        exit(0);
    }
    $ano = (int)$campanha_enviando['ano'];
}

// Trava contra execução simultânea
$lock_file = sys_get_temp_dir() . "/pilotis_campanha_{$ano}.lock";
if (file_exists($lock_file)) {
    $lock_time = (int)file_get_contents($lock_file);
    // Lock válido por 30 minutos (proteção contra lock órfão)
    if (time() - $lock_time < 1800) {
        echo "Outra execução em andamento (lock file). Abortando.\n";
        exit(0);
    }
}
file_put_contents($lock_file, time());
// Remove lock ao terminar (normal ou erro)
register_shutdown_function(function() use ($lock_file) {
    @unlink($lock_file);
});

echo "Campanha de filiação $ano (limite: $limite emails)\n";
if ($dry_run) {
    echo "[DRY-RUN] Nenhum email será enviado\n";
}
echo str_repeat('-', 50) . "\n\n";

// Define os 4 grupos em ordem de prioridade
$ano_anterior = $ano - 1;

$grupos = [
    [
        'nome' => 'Adimplentes ' . $ano_anterior,
        'template' => 'renovacao',
        'query' => "
            SELECT DISTINCT p.id, p.nome, p.token,
                   (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
            FROM pessoas p
            JOIN filiacoes f ON f.pessoa_id = p.id
            WHERE p.ativo = 1
            AND f.ano = ? AND f.status = 'pago'
            AND p.id NOT IN (
                SELECT pessoa_id FROM filiacoes WHERE ano = ?
            )
        ",
        'params' => [$ano_anterior, $ano],
    ],
    [
        'nome' => 'Participantes seminário',
        'template' => 'seminario',
        'query' => "
            SELECT DISTINCT p.id, p.nome, p.token,
                   (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
            FROM pessoas p
            JOIN filiacoes f ON f.pessoa_id = p.id
            WHERE p.ativo = 1
            AND f.seminario = 1
            AND p.id NOT IN (
                SELECT pessoa_id FROM filiacoes WHERE status = 'pago'
            )
            AND p.id NOT IN (
                SELECT pessoa_id FROM filiacoes WHERE ano = ? AND status = 'enviado'
            )
        ",
        'params' => [$ano],
    ],
    [
        'nome' => 'Ex-filiados',
        'template' => 'renovacao',
        'query' => "
            SELECT DISTINCT p.id, p.nome, p.token,
                   (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
            FROM pessoas p
            JOIN filiacoes f ON f.pessoa_id = p.id
            WHERE p.ativo = 1
            AND f.status = 'pago'
            AND p.id NOT IN (
                SELECT pessoa_id FROM filiacoes WHERE ano = ? AND status = 'pago'
            )
            AND p.id NOT IN (
                SELECT pessoa_id FROM filiacoes WHERE ano = ? AND status = 'enviado'
            )
            AND p.id NOT IN (
                SELECT pessoa_id FROM filiacoes WHERE seminario = 1
            )
        ",
        'params' => [$ano, $ano],
    ],
    [
        'nome' => 'Contatos sem filiação',
        'template' => 'convite',
        'query' => "
            SELECT p.id, p.nome, p.token,
                   (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
            FROM pessoas p
            WHERE p.ativo = 1
            AND p.id NOT IN (SELECT pessoa_id FROM filiacoes)
        ",
        'params' => [],
    ],
    [
        'nome' => 'Contatos pendentes',
        'template' => 'convite',
        'query' => "
            SELECT DISTINCT p.id, p.nome, p.token,
                   (SELECT email FROM emails WHERE pessoa_id = p.id AND principal = 1 LIMIT 1) as email
            FROM pessoas p
            JOIN filiacoes f ON f.pessoa_id = p.id
            WHERE p.ativo = 1
            AND p.id NOT IN (SELECT pessoa_id FROM filiacoes WHERE status = 'pago')
            AND p.id NOT IN (SELECT pessoa_id FROM filiacoes WHERE seminario = 1)
            AND p.id NOT IN (
                SELECT pessoa_id FROM filiacoes WHERE ano = ? AND status = 'enviado'
            )
        ",
        'params' => [$ano],
    ],
];

$total_enviados = 0;
$total_erros = 0;
$log_destinatarios = [];
$template_usado = null;

foreach ($grupos as $grupo) {
    if ($total_enviados >= $limite) break;

    $destinatarios = db_fetch_all($grupo['query'], $grupo['params']);

    // Filtra sem email
    $destinatarios = array_filter($destinatarios, fn($d) => !empty($d['email']));
    $destinatarios = array_values($destinatarios);

    if (empty($destinatarios)) {
        echo "[{$grupo['nome']}] Nenhum destinatário\n";
        continue;
    }

    $restante = $limite - $total_enviados;
    $enviar_agora = array_slice($destinatarios, 0, $restante);

    echo "[{$grupo['nome']}] " . count($enviar_agora) . " de " . count($destinatarios) . " destinatários\n";

    // Snapshot do template (para log)
    $tpl_snapshot = carregar_template($grupo['template'], [
        'nome' => '(destinatário)',
        'ano' => $ano,
        'link' => BASE_URL . "/filiacao/$ano/TOKEN",
    ]);
    if (!$template_usado) {
        $template_usado = $tpl_snapshot;
    }

    foreach ($enviar_agora as $d) {
        // Gera token se não tiver
        $token = $d['token'];
        if (!$token) {
            $token = gerar_token();
            db_execute("UPDATE pessoas SET token = ? WHERE id = ?", [$token, $d['id']]);
        }

        echo "  {$d['nome']} <{$d['email']}> ... ";

        if ($dry_run) {
            echo "[DRY-RUN]\n";
            $total_enviados++;
            $log_destinatarios[] = ['email' => $d['email'], 'nome' => $d['nome'] ?? '', 'sucesso' => true];
            continue;
        }

        // Marca como 'enviado' ANTES de enviar (evita reenvio se cota estourar)
        $filiacao = db_fetch_one(
            "SELECT id FROM filiacoes WHERE pessoa_id = ? AND ano = ?",
            [$d['id'], $ano]
        );
        if (!$filiacao) {
            db_insert("
                INSERT INTO filiacoes (pessoa_id, ano, status, created_at)
                VALUES (?, ?, 'enviado', CURRENT_TIMESTAMP)
            ", [$d['id'], $ano]);
        }

        try {
            $enviado = false;
            switch ($grupo['template']) {
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

            $log_destinatarios[] = ['email' => $d['email'], 'nome' => $d['nome'] ?? '', 'sucesso' => (bool)$enviado];

            if ($enviado) {
                echo "OK\n";
                $total_enviados++;
            } else {
                echo "ERRO\n";
                $total_erros++;
            }
        } catch (Exception $e) {
            echo "ERRO: " . $e->getMessage() . "\n";
            $total_erros++;
            $log_destinatarios[] = ['email' => $d['email'], 'nome' => $d['nome'] ?? '', 'sucesso' => false];
        }

        usleep(100000); // 100ms entre envios
    }

    echo "\n";
}

// Grava lote de envio (exceto em dry-run)
if (!$dry_run && !empty($log_destinatarios)) {
    registrar_envio_lote(
        'campanha',
        $ano,
        $template_usado['assunto'] ?? "Campanha $ano",
        $template_usado['html'] ?? '',
        $log_destinatarios
    );
}

echo str_repeat('-', 50) . "\n";
echo "Enviados: $total_enviados | Erros: $total_erros\n";

if ($total_enviados >= $limite) {
    echo "\nLimite de $limite atingido. Execute novamente amanhã para continuar.\n";
} elseif ($total_enviados === 0 && !$dry_run) {
    // Campanha encerrada — roda verificação de bounces
    echo "\nCampanha encerrada. Verificando bounces...\n\n";
    passthru('php ' . escapeshellarg(__DIR__ . '/verificar_bounces.php'));
}
