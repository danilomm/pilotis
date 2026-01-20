#!/bin/bash
# Gera dump SQL do banco para versionamento
# Uso: ./scripts/backup_db.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
BACKUP_DIR="$PROJECT_DIR/backups"

# Le DATABASE_PATH do .env (ou usa padrao)
if [ -f "$PROJECT_DIR/.env" ]; then
    DB_PATH=$(grep -E "^DATABASE_PATH=" "$PROJECT_DIR/.env" | cut -d'=' -f2)
fi
DB_PATH="${DB_PATH:-data/pilotis.db}"

# Se caminho relativo, junta com PROJECT_DIR
if [[ "$DB_PATH" != /* ]]; then
    DB_PATH="$PROJECT_DIR/$DB_PATH"
fi

if [ ! -f "$DB_PATH" ]; then
    echo "Erro: banco nao encontrado em $DB_PATH"
    exit 1
fi

# Cria diretorio de backup se nao existir
mkdir -p "$BACKUP_DIR"

# Nome do backup com data
BACKUP_FILE="$BACKUP_DIR/pilotis_$(date +%Y%m%d_%H%M%S).sql"

sqlite3 "$DB_PATH" .dump > "$BACKUP_FILE"

echo "Backup criado: $BACKUP_FILE"
echo "Tamanho: $(du -h "$BACKUP_FILE" | cut -f1)"
echo "Registros: $(sqlite3 "$DB_PATH" 'SELECT COUNT(*) FROM cadastrados') cadastrados"

# Mantem apenas os ultimos 10 backups
cd "$BACKUP_DIR"
ls -t pilotis_*.sql 2>/dev/null | tail -n +11 | xargs -r rm -f
echo "Backups mantidos: $(ls pilotis_*.sql 2>/dev/null | wc -l)"
