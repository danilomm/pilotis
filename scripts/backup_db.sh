#!/bin/bash
# Gera dump SQL do banco para versionamento
# Uso: ./scripts/backup_db.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
DB_PATH="$PROJECT_DIR/data/pilotis.db"
BACKUP_PATH="$PROJECT_DIR/data/backup.sql"

if [ ! -f "$DB_PATH" ]; then
    echo "Erro: banco não encontrado em $DB_PATH"
    exit 1
fi

sqlite3 "$DB_PATH" .dump > "$BACKUP_PATH"

echo "Backup criado: $BACKUP_PATH"
echo "Tamanho: $(du -h "$BACKUP_PATH" | cut -f1)"
echo "Registros: $(sqlite3 "$DB_PATH" 'SELECT COUNT(*) FROM cadastrados') cadastrados"
