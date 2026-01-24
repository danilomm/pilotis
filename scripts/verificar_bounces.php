#!/usr/bin/env php
<?php
/**
 * Verifica emails com bounce na API do Brevo e envia relatório para a tesouraria
 *
 * Uso:
 *   php scripts/verificar_bounces.php
 *   php scripts/verificar_bounces.php --dias 7
 *   php scripts/verificar_bounces.php --dry-run
 *
 * Consulta bounces (hard e soft) dos últimos N dias e envia
 * relatório por email para a tesouraria.
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/Services/BrevoService.php';

$options = getopt('', ['dias:', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "Uso: php scripts/verificar_bounces.php [--dias N] [--dry-run]\n\n";
    echo "Opções:\n";
    echo "  --dias N      Consultar bounces dos últimos N dias (padrão: 7)\n";
    echo "  --dry-run     Mostra relatório sem enviar email\n";
    exit(0);
}

$dias = (int)($options['dias'] ?? 7);
$dry_run = isset($options['dry-run']);

$api_key = getenv('BREVO_API_KEY') ?: ($_ENV['BREVO_API_KEY'] ?? '');
if (!$api_key) {
    echo "ERRO: BREVO_API_KEY não configurada\n";
    exit(1);
}

echo "Verificando bounces dos últimos $dias dias...\n";
echo str_repeat('-', 50) . "\n";

$data_inicio = date('Y-m-d', strtotime("-{$dias} days"));
$data_fim = date('Y-m-d');

// Consulta hard bounces e soft bounces
$bounces = [];

foreach (['hardBounces', 'softBounces'] as $tipo_evento) {
    $offset = 0;
    $limit = 100;

    while (true) {
        $url = "https://api.brevo.com/v3/smtp/statistics/events?" . http_build_query([
            'event' => $tipo_evento,
            'startDate' => $data_inicio,
            'endDate' => $data_fim,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'api-key: ' . $api_key,
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code !== 200) {
            echo "ERRO ao consultar API ($tipo_evento): HTTP $http_code\n";
            echo $response . "\n";
            break;
        }

        $data = json_decode($response, true);
        $events = $data['events'] ?? [];

        if (empty($events)) break;

        foreach ($events as $event) {
            $email = $event['email'] ?? '';
            if (!$email) continue;

            // Evita duplicatas (mesmo email pode ter múltiplos bounces)
            if (!isset($bounces[$email])) {
                $bounces[$email] = [
                    'email' => $email,
                    'tipo' => $tipo_evento === 'hardBounces' ? 'Hard Bounce' : 'Soft Bounce',
                    'motivo' => $event['reason'] ?? '-',
                    'data' => $event['date'] ?? '-',
                ];
            }
        }

        if (count($events) < $limit) break;
        $offset += $limit;
    }
}

echo "Bounces encontrados: " . count($bounces) . "\n\n";

if (empty($bounces)) {
    echo "Nenhum bounce nos últimos $dias dias.\n";
    exit(0);
}

// Cruza com o banco para identificar as pessoas
$relatorio_linhas = [];

foreach ($bounces as $email => $info) {
    // Busca pessoa no banco
    $pessoa = db_fetch_one("
        SELECT p.id, p.nome
        FROM pessoas p
        JOIN emails e ON e.pessoa_id = p.id
        WHERE e.email = ?
    ", [$email]);

    $nome = $pessoa ? $pessoa['nome'] : '(não cadastrado)';
    $id = $pessoa ? $pessoa['id'] : '-';

    $relatorio_linhas[] = [
        'id' => $id,
        'nome' => $nome,
        'email' => $email,
        'tipo' => $info['tipo'],
        'motivo' => $info['motivo'],
        'data' => $info['data'],
    ];

    echo "  [$id] $nome <$email> - {$info['tipo']}\n";
    echo "        Motivo: {$info['motivo']}\n";
}

// Monta relatório HTML
$html = "<h2>Relatório de Bounces - Pilotis</h2>";
$html .= "<p>Período: $data_inicio a $data_fim</p>";
$html .= "<p>Total de bounces: " . count($relatorio_linhas) . "</p>";
$html .= "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; font-size: 14px;'>";
$html .= "<tr style='background: #f0f0f0;'><th>ID</th><th>Nome</th><th>Email</th><th>Tipo</th><th>Motivo</th><th>Data</th></tr>";

foreach ($relatorio_linhas as $linha) {
    $cor = $linha['tipo'] === 'Hard Bounce' ? '#ffe0e0' : '#fff8e0';
    $html .= "<tr style='background: $cor;'>";
    $html .= "<td>{$linha['id']}</td>";
    $html .= "<td>" . htmlspecialchars($linha['nome']) . "</td>";
    $html .= "<td>" . htmlspecialchars($linha['email']) . "</td>";
    $html .= "<td>{$linha['tipo']}</td>";
    $html .= "<td>" . htmlspecialchars($linha['motivo']) . "</td>";
    $html .= "<td>{$linha['data']}</td>";
    $html .= "</tr>";
}

$html .= "</table>";
$html .= "<p style='margin-top: 20px; color: #666;'><small>Hard Bounce = endereço inválido/inexistente. Soft Bounce = caixa cheia/temporário.</small></p>";
$html .= "<p style='color: #666;'><small>Acesse o painel admin para marcar pessoas como inativas se necessário.</small></p>";

if ($dry_run) {
    echo "\n[DRY-RUN] Relatório não enviado.\n";
    exit(0);
}

// Envia relatório para a tesouraria
$email_destino = ORG_EMAIL_CONTATO ?: (getenv('EMAIL_FROM') ?: 'admin@localhost');
$assunto = "Relatório de Bounces - Pilotis ($data_inicio a $data_fim)";

$enviado = BrevoService::enviarEmail($email_destino, $assunto, $html);

if ($enviado) {
    echo "\nRelatório enviado para $email_destino\n";
} else {
    echo "\nERRO ao enviar relatório para $email_destino\n";
    exit(1);
}
