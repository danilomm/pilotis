#!/usr/bin/env php
<?php
/**
 * Pilotis - Script de instalacao
 *
 * Cria a estrutura de dados e inicializa o banco SQLite.
 *
 * Uso: php scripts/install.php
 */

echo "=== Pilotis - Instalacao ===\n\n";

$baseDir = dirname(__DIR__);
$dadosDir = $baseDir . '/dados';
$dataDir = $dadosDir . '/data';
$dbPath = $dataDir . '/pilotis.db';
$schemaPath = $baseDir . '/schema.sql';
$envPath = $baseDir . '/.env';
$envExamplePath = $baseDir . '/.env.example';

// 1. Criar estrutura de diretorios
echo "1. Criando estrutura de diretorios...\n";

// Nota: dados/ pode existir como submodule vazio (se clonado sem --recurse-submodules)
// Nesse caso, precisamos garantir que a estrutura interna exista

if (!is_dir($dadosDir)) {
    mkdir($dadosDir, 0755, true);
    echo "   Criado: dados/\n";
} elseif (is_file($dadosDir . '/.git')) {
    // E um submodule - ignorar, usuario deve usar dados do submodule
    echo "   Existe: dados/ (submodule)\n";
    echo "   NOTA: Para usar seus proprios dados, remova o submodule primeiro:\n";
    echo "         git rm dados && rm -rf dados && mkdir dados\n";
} else {
    echo "   Existe: dados/\n";
}

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0755, true);
    echo "   Criado: dados/data/\n";
} else {
    echo "   Existe: dados/data/\n";
}

// 2. Criar .gitignore no dados/
$gitignorePath = $dadosDir . '/.gitignore';
if (!file_exists($gitignorePath)) {
    file_put_contents($gitignorePath, "*.db\n");
    echo "   Criado: dados/.gitignore\n";
}

// 3. Criar banco de dados
echo "\n2. Criando banco de dados...\n";

if (file_exists($dbPath)) {
    echo "   Banco ja existe: $dbPath\n";
    echo "   Para recriar, delete o arquivo e execute novamente.\n";
} else {
    if (!file_exists($schemaPath)) {
        echo "   ERRO: schema.sql nao encontrado!\n";
        exit(1);
    }

    $schema = file_get_contents($schemaPath);

    try {
        $db = new PDO("sqlite:$dbPath");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec($schema);
        echo "   Banco criado: $dbPath\n";
    } catch (PDOException $e) {
        echo "   ERRO ao criar banco: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// 4. Criar .env se nao existir
echo "\n3. Verificando configuracao...\n";

if (file_exists($envPath)) {
    echo "   .env ja existe\n";
} elseif (file_exists($envExamplePath)) {
    copy($envExamplePath, $envPath);
    echo "   Criado .env a partir de .env.example\n";
    echo "   IMPORTANTE: Edite .env com suas credenciais!\n";
} else {
    echo "   AVISO: .env.example nao encontrado\n";
}

// 5. Verificar dependencias
echo "\n4. Verificando dependencias...\n";

$composerLock = $baseDir . '/composer.lock';
$vendorDir = $baseDir . '/vendor';

if (file_exists($composerLock) && !is_dir($vendorDir)) {
    echo "   AVISO: Execute 'composer install' para instalar TCPDF\n";
} elseif (is_dir($vendorDir)) {
    echo "   Dependencias instaladas (vendor/)\n";
} else {
    echo "   Sem dependencias (TCPDF opcional)\n";
}

// Resumo
echo "\n=== Instalacao concluida ===\n\n";
echo "Proximos passos:\n";
echo "1. Edite .env com suas credenciais (PagBank, Brevo, etc)\n";
echo "2. Inicie o servidor: cd public && php -S localhost:8000\n";
echo "3. Acesse: http://localhost:8000/filiacao/" . date('Y') . "\n";
echo "\n";
