#!/bin/bash
# Prepara arquivos para deploy via FTP
# Cria um diretorio com a estrutura pronta para upload

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"
DEPLOY_DIR="$PROJECT_DIR/deploy/upload"

echo "=== Preparando deploy do Pilotis ==="
echo "Diretorio do projeto: $PROJECT_DIR"
echo "Diretorio de saida: $DEPLOY_DIR"
echo ""

# Limpa e cria diretorio de saida
rm -rf "$DEPLOY_DIR"
mkdir -p "$DEPLOY_DIR/pilotis"
mkdir -p "$DEPLOY_DIR/dados_privados"

# Copia arquivo WSGI
cp "$SCRIPT_DIR/pilotis.wsgi" "$DEPLOY_DIR/pilotis/"

# Copia .env de producao como template
cp "$SCRIPT_DIR/.env.producao" "$DEPLOY_DIR/pilotis/.env"

# Copia pacote Python (excluindo __pycache__)
rsync -a --exclude='__pycache__' --exclude='*.pyc' "$PROJECT_DIR/pilotis" "$DEPLOY_DIR/pilotis/"

# Copia scripts (excluindo __pycache__)
rsync -a --exclude='__pycache__' --exclude='*.pyc' "$PROJECT_DIR/scripts" "$DEPLOY_DIR/pilotis/"

# Copia requirements
cp "$PROJECT_DIR/requirements.txt" "$DEPLOY_DIR/pilotis/"

# Copia banco de dados
if [ -f "$PROJECT_DIR/data/pilotis.db" ]; then
    cp "$PROJECT_DIR/data/pilotis.db" "$DEPLOY_DIR/dados_privados/"
    echo "Banco de dados copiado."
else
    echo "AVISO: Banco de dados nao encontrado em data/pilotis.db"
fi

echo ""
echo "=== Arquivos preparados! ==="
echo ""
echo "Estrutura criada em: $DEPLOY_DIR"
echo ""
echo "Para fazer upload via FTP:"
echo "  1. Conecte em ftp.app.docomomobrasil.com"
echo "  2. Upload: $DEPLOY_DIR/pilotis/* -> /apps_wsgi/pilotis/"
echo "  3. Upload: $DEPLOY_DIR/dados_privados/* -> /dados_privados/"
echo "  4. Edite /apps_wsgi/pilotis/.env com as credenciais reais"
echo ""
