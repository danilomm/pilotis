#!/usr/bin/env php
<?php
/**
 * Backup automático do banco de dados para GitHub
 *
 * Gera dump SQL via PDO e faz git push para o repo privado.
 * Projetado para rodar via cron no servidor de produção.
 *
 * Uso:
 *   php scripts/backup_servidor.php
 *   php scripts/backup_servidor.php --dry-run
 *
 * Pré-requisitos no servidor:
 *   - git instalado e configurado com acesso ao repo
 *   - Diretório de dados inicializado como repo git
 *
 * Cron sugerido (diário às 3h):
 *   0 3 * * * php /caminho/para/scripts/backup_servidor.php >> /tmp/backup_pilotis.log 2>&1
 */

require_once __DIR__ . '/../src/config.php';

$dry_run = in_array('--dry-run', $argv);
$data = date('Y-m-d H:i:s');

echo "=== Backup Pilotis - $data ===\n";

// Verifica se o banco existe
if (!file_exists(DATABASE_PATH)) {
    echo "ERRO: Banco não encontrado em " . DATABASE_PATH . "\n";
    exit(1);
}

// Diretório onde está o banco (deve ser um repo git)
$dados_dir = dirname(DATABASE_PATH);
$backup_file = $dados_dir . '/backup.sql';

echo "Banco: " . DATABASE_PATH . "\n";
echo "Dump: $backup_file\n";

// --- Gera dump SQL via PDO ---
echo "Gerando dump SQL...\n";

try {
    $pdo = new PDO('sqlite:' . DATABASE_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "ERRO: Não foi possível abrir o banco: " . $e->getMessage() . "\n";
    exit(1);
}

$sql = "";
$sql .= "-- Pilotis - Backup automático\n";
$sql .= "-- Gerado em: $data\n";
$sql .= "-- Banco: " . DATABASE_PATH . "\n\n";
$sql .= "PRAGMA foreign_keys=OFF;\n";
$sql .= "BEGIN TRANSACTION;\n\n";

// Obtém todas as tabelas
$tables = $pdo->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

foreach ($tables as $table) {
    $name = $table['name'];
    $create_sql = $table['sql'];

    $sql .= "-- Tabela: $name\n";
    $sql .= "DROP TABLE IF EXISTS $name;\n";
    $sql .= "$create_sql;\n";

    // Exporta dados
    $rows = $pdo->query("SELECT * FROM \"$name\"")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $values = array_map(function($v) {
            if ($v === null) return 'NULL';
            return "'" . str_replace("'", "''", $v) . "'";
        }, array_values($row));
        $sql .= "INSERT INTO $name VALUES(" . implode(',', $values) . ");\n";
    }
    $sql .= "\n";
}

$sql .= "COMMIT;\n";

// Salva o dump
$bytes = file_put_contents($backup_file, $sql);
echo "Dump gerado: " . number_format($bytes / 1024, 1) . " KB\n";

if ($dry_run) {
    echo "[DRY-RUN] Dump gerado mas não commitado.\n";
    exit(0);
}

// --- Git commit e push ---
echo "Verificando git...\n";

// Verifica se é um repo git
$is_git = shell_exec("cd " . escapeshellarg($dados_dir) . " && git rev-parse --is-inside-work-tree 2>&1");
if (trim($is_git) !== 'true') {
    echo "ERRO: $dados_dir não é um repositório git.\n";
    echo "Inicialize com: cd $dados_dir && git init && git remote add origin <url>\n";
    exit(1);
}

// Verifica se há mudanças
$status = shell_exec("cd " . escapeshellarg($dados_dir) . " && git status --porcelain 2>&1");
if (empty(trim($status))) {
    echo "Nenhuma alteração no banco. Nada a fazer.\n";
    exit(0);
}

// Commit e push
$msg = "Backup automático $data";
$cmds = [
    "git add -A",
    "git commit -m " . escapeshellarg($msg),
    "git push",
];

foreach ($cmds as $cmd) {
    $full_cmd = "cd " . escapeshellarg($dados_dir) . " && $cmd 2>&1";
    echo "$ $cmd\n";
    $output = shell_exec($full_cmd);
    echo $output;
}

echo "\nBackup concluído com sucesso.\n";
