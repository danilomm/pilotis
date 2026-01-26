#!/usr/bin/env php
<?php
/**
 * Script para processar lembretes agendados
 *
 * Uso:
 *   php scripts/processar_lembretes.php
 *   php scripts/processar_lembretes.php --dry-run
 *   php scripts/processar_lembretes.php --limite 20
 *
 * Diferente do antigo enviar_lembretes.php, este script e IDEMPOTENTE:
 * - Le registros pre-agendados na tabela lembretes_agendados
 * - Marca enviado ANTES de enviar (flag individual)
 * - Rodar N vezes produz o mesmo resultado
 *
 * Os lembretes sao agendados automaticamente pelo sistema nos momentos certos:
 * - PIX/Boleto gerado -> lembrete de vencimento
 * - Formulario acessado -> lembretes de formulario incompleto
 * - Data fim da campanha definida -> lembretes de ultima chance
 * - Pagamento confirmado -> cancela todos os lembretes
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/Services/LembreteService.php';

// Processa argumentos
$options = getopt('', ['limite:', 'dry-run', 'help']);

if (isset($options['help'])) {
    echo "Uso: php scripts/processar_lembretes.php [--limite N] [--dry-run]\n\n";
    echo "Opcoes:\n";
    echo "  --limite N    Maximo de lembretes por execucao (padrao: 50)\n";
    echo "  --dry-run     Simula sem enviar emails\n";
    exit(0);
}

$limite = (int)($options['limite'] ?? 50);
$dry_run = isset($options['dry-run']);

echo "Processamento de lembretes agendados\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n";
if ($dry_run) {
    echo "[DRY-RUN] Nenhum email sera enviado\n";
}
echo str_repeat('-', 50) . "\n\n";

// Conta pendentes
$contagem = LembreteService::contarPendentes();
echo "Lembretes pendentes para hoje:\n";
echo "  Vencimento amanha:      {$contagem['vencimento_amanha']}\n";
echo "  Pagamento vencido:      {$contagem['pagamento_vencido']}\n";
echo "  Formulario incompleto:  {$contagem['formulario_incompleto']}\n";
echo "  Ultima chance:          {$contagem['ultima_chance']}\n";
echo "  Total:                  {$contagem['total']}\n";
echo "  Agendados para futuro:  {$contagem['agendados_futuro']}\n\n";

if ($contagem['total'] === 0) {
    echo "Nenhum lembrete pendente para hoje.\n";
    exit(0);
}

if ($dry_run) {
    // Em dry-run, lista os pendentes sem enviar
    $pendentes = db_fetch_all("
        SELECT la.id, la.tipo, la.data_agendada,
               p.nome, e.email, f.ano
        FROM lembretes_agendados la
        JOIN filiacoes f ON f.id = la.filiacao_id
        JOIN pessoas p ON p.id = f.pessoa_id
        LEFT JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
        WHERE la.enviado = 0 AND la.data_agendada <= DATE('now')
        ORDER BY la.data_agendada ASC
        LIMIT ?
    ", [$limite]);

    echo "Lembretes que seriam processados:\n\n";
    foreach ($pendentes as $l) {
        echo "  [{$l['tipo']}] {$l['nome']} <{$l['email']}> - {$l['ano']} (agendado: {$l['data_agendada']})\n";
    }
    echo "\n[DRY-RUN] Nenhum email enviado.\n";
    exit(0);
}

// Processa
$resultado = LembreteService::processar($limite);

echo str_repeat('-', 50) . "\n";
echo "Processados: {$resultado['processados']}\n";
echo "Enviados:    {$resultado['enviados']}\n";
echo "Erros:       {$resultado['erros']}\n";
echo "Pulados:     {$resultado['pulados']}\n";

if (!empty($resultado['detalhes'])) {
    echo "\nDetalhes:\n";
    foreach ($resultado['detalhes'] as $d) {
        $info = $d['email'] ?? '';
        echo "  #{$d['id']} [{$d['tipo']}] {$d['status']}" . ($info ? " - $info" : '') . (isset($d['motivo']) ? " ({$d['motivo']})" : '') . "\n";
    }
}
