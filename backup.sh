#!/bin/bash
# Script para backup e commit dos dois repos (publico e privado)
# Uso: ./backup.sh "Descricao das mudancas"

set -e

MSG="${1:-Backup $(date +%Y-%m-%d)}"
DIR="$(cd "$(dirname "$0")" && pwd)"

echo "=== Backup Pilotis ==="
echo "Mensagem: $MSG"
echo ""

# 1. Gera dump SQL do banco
echo ">> Gerando dump SQL..."
sqlite3 "$DIR/dados/data/pilotis.db" .dump > "$DIR/dados/data/backup.sql"

# 2. Commit no submodule (dados privados)
echo ""
echo ">> Commit no repo privado (dados/)..."
cd "$DIR/dados"
git add -A
if git diff --cached --quiet; then
    echo "   Nenhuma mudanca nos dados"
else
    git commit -m "$MSG

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
    git push
    echo "   Push realizado"
fi

# 3. Commit no repo principal (codigo publico)
echo ""
echo ">> Commit no repo publico (pilotis/)..."
cd "$DIR"
git add -A
if git diff --cached --quiet; then
    echo "   Nenhuma mudanca no codigo"
else
    git commit -m "$MSG

Co-Authored-By: Claude Opus 4.5 <noreply@anthropic.com>"
    # Usa token do gh para evitar problema de credenciais
    TOKEN=$(gh auth token 2>/dev/null)
    if [ -n "$TOKEN" ]; then
        git push "https://${TOKEN}@github.com/danilomm/pilotis.git" master
    else
        git push
    fi
    echo "   Push realizado"
fi

echo ""
echo "=== Backup concluido ==="
