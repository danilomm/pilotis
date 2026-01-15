# Pilotis — Contexto para Claude

Sistema de gestão de filiados do Docomomo Brasil.

## Status Atual

**Fases concluídas:** 1, 2, 3, 4, 5 + Painel Admin ✓
**Testado:** PIX, Boleto, Cartão, Emails com PDF
**Deploy:** Arquivos preparados, aguardando configuração do servidor

## Estrutura do Projeto

```
pilotis/
├── pilotis/
│   ├── main.py
│   ├── config.py
│   ├── db.py
│   ├── models.py
│   ├── routers/
│   │   ├── filiacao.py      # Formulário e pagamento
│   │   ├── filiados.py      # Lista pública
│   │   ├── webhook.py       # PagBank callbacks
│   │   └── admin.py         # Painel administrativo
│   ├── services/
│   │   ├── pagbank.py       # API PagBank (PIX)
│   │   ├── email.py         # API Brevo
│   │   └── pdf.py           # Declaração de filiação
│   ├── static/
│   │   ├── logo-docomomo.png
│   │   └── logo-docomomo.jpg
│   └── templates/
│       ├── base.html
│       ├── entrada.html
│       ├── filiacao.html
│       ├── pagamento.html
│       ├── confirmacao.html
│       ├── filiados.html
│       ├── emails/
│       │   ├── confirmacao.html
│       │   ├── lembrete.html
│       │   ├── campanha_renovacao.html
│       │   ├── campanha_convite.html
│       │   └── campanha_seminario.html
│       └── admin/
│           ├── login.html
│           ├── painel.html
│           ├── buscar.html
│           ├── pessoa.html
│           └── novo.html
├── scripts/
│   ├── importar_csv.py
│   ├── gerar_tokens.py
│   ├── backup_db.sh
│   ├── enviar_campanha.py
│   ├── enviar_lembretes.py
│   └── admin.py             # CLI para administração
├── deploy/
│   ├── pilotis.wsgi         # Entry point WSGI
│   ├── .env.producao        # Template producao
│   ├── DEPLOY.md            # Instrucoes
│   ├── preparar_deploy.sh   # Script de preparacao
│   └── servidor.yaml        # Config servidor
└── data/
    └── pilotis.db
```

## Rotas

| Rota | Função |
|------|--------|
| `GET /filiacao/{ano}` | Entrada por email |
| `GET /filiacao/{ano}/{token}` | Formulário pré-preenchido |
| `POST /filiacao/{ano}/{token}` | Salvar e criar pagamento |
| `GET /filiacao/{ano}/{token}/pagamento` | QR Code PIX |
| `GET /filiados/{ano}` | Lista pública de filiados |
| `POST /webhook/pagbank` | Confirmação de pagamento |
| `GET /admin` | Painel administrativo |

## Categorias

| Interno | Display | Valor |
|---------|---------|-------|
| profissional_internacional | Filiado Pleno Internacional+Brasil | R$ 460 |
| profissional_nacional | Filiado Pleno Brasil | R$ 230 |
| estudante | Filiado Estudante Brasil | R$ 115 |

## Scripts

```bash
# Campanha
python scripts/enviar_campanha.py --ano 2026 --dry-run
python scripts/enviar_campanha.py --ano 2026 --tipo seminario

# Lembretes (rodar via cron)
python scripts/enviar_lembretes.py

# Backup
./scripts/backup_db.sh

# Administracao (pagamentos manuais, consultas)
python scripts/admin.py pendentes           # Lista pendentes
python scripts/admin.py buscar "email"      # Busca pessoa
python scripts/admin.py pagar 123           # Marca pagamento como pago
python scripts/admin.py novo                # Cadastra + pagamento manual
python scripts/admin.py exportar 2026       # Exporta filiados CSV
```

## Fluxos

**Filiação:**
1. Pessoa acessa `/filiacao/2026` ou clica link do email
2. Informa email → sistema busca/cria cadastro
3. Preenche formulário → cria pagamento pendente
4. Gera PIX (3 dias validade) → mostra QR Code
5. Pagamento confirmado via webhook → email + PDF

**Campanhas:**
- `renovacao`: filiados do ano anterior
- `seminario`: participantes do 16º Seminário não filiados
- `convite`: outros cadastrados

**Lembretes:**
- No dia do vencimento
- Semanalmente após vencer

## Declaração PDF

Gerada com ReportLab, texto justificado:
- Logo JPG (sem transparência)
- Nome, categoria, valor, ano
- Assinatura: Marta Peixoto, Coordenadora, Gestão 2026-2027

## Comandos

```bash
source venv/bin/activate
uvicorn pilotis.main:app --reload
```

## Credenciais (.env)

```
DATABASE_PATH=/var/lib/pilotis/pilotis.db  # FORA do diretorio web!
PAGBANK_TOKEN=...
PAGBANK_SANDBOX=true
BREVO_API_KEY=...
BASE_URL=https://pilotis.docomomobrasil.com
```

## Painel Admin

Acesso em `/admin` com senha configurada no `.env`.

**Funcoes:**
- Estatisticas (pagos, pendentes, arrecadado)
- Buscar pessoa por nome/email
- Editar todos os dados do cadastro
- Marcar pagamento como pago (manual)
- Cadastrar nova pessoa + pagamento
- Excluir pagamento ou pessoa
- Baixar backup do banco (.db)
- Baixar tabela de filiados (CSV)

## Seguranca

**Banco de dados:** O arquivo `.db` contem dados pessoais (CPF, endereco, etc). Em producao, DEVE ficar fora do diretorio web:
- Linux/VPS: `/var/lib/pilotis/pilotis.db`
- Hospedagem compartilhada: `../dados_privados/pilotis.db` (acima do public_html)

**Backups:** Tambem contem dados sensiveis. O diretorio `backups/` deve ficar protegido ou fora do diretorio web.

**Admin:** A senha do painel deve ser forte. Em producao, use hash SHA256:
```bash
python -c "import hashlib; print('sha256:' + hashlib.sha256(b'sua_senha_forte').hexdigest())"
```

## Deploy

**Servidor:** KingHost (via Labasoft)
**URL:** https://pilotis.docomomobrasil.com
**Tecnologia:** Apache mod_wsgi + Python 3.10+

### Arquivos de deploy

```
deploy/
├── pilotis.wsgi        # Entry point WSGI (adapta FastAPI via a2wsgi)
├── .env.producao       # Template de configuracao
├── DEPLOY.md           # Instrucoes completas
├── preparar_deploy.sh  # Prepara arquivos para upload
└── servidor.yaml       # Config e credenciais do servidor
```

### Fazer deploy

```bash
# 1. Preparar arquivos
./deploy/preparar_deploy.sh

# 2. Upload via FTP (ftp.app.docomomobrasil.com)
#    upload/pilotis/* -> /apps_wsgi/pilotis/
#    upload/dados_privados/* -> /dados_privados/

# 3. Editar .env no servidor com credenciais reais
```

### Estrutura no servidor

```
/home/app/
├── apps_wsgi/pilotis/     # Aplicacao
│   ├── pilotis.wsgi
│   ├── .env
│   └── pilotis/           # Codigo Python
└── dados_privados/
    └── pilotis.db         # Banco (FORA do www)
```

## Backup e Commit

**IMPORTANTE:** Quando o usuário pedir "backup e commit", executar:

```bash
# 1. Gerar dump SQL do banco
sqlite3 data/pilotis.db .dump > data/backup.sql

# 2. Commit de tudo
git add -A
git commit -m "Descrição das mudanças"
```

O arquivo `data/backup.sql` é versionado no git e serve como ponto de restauração.

Para restaurar:
```bash
rm data/pilotis.db
sqlite3 data/pilotis.db < data/backup.sql
```

## WordPress (Site Principal)

API REST para gerenciar paginas de filiados no site docomomobrasil.com.

```
URL base: https://docomomobrasil.com/wp-json/wp/v2/
Tipos: post, page, course (Educaz), dlm_download
Auth: admindocomomo:psXb P4X2 VOOe rQF6 UPcp KZSZ
```

### Exemplo de uso

```bash
# Listar paginas
curl -u "admindocomomo:psXb P4X2 VOOe rQF6 UPcp KZSZ" \
  "https://docomomobrasil.com/wp-json/wp/v2/pages?search=filiados"

# Criar/atualizar pagina
curl -X POST -u "admindocomomo:psXb P4X2 VOOe rQF6 UPcp KZSZ" \
  -H "Content-Type: application/json" \
  -d '{"title":"Filiados 2025","content":"...","status":"publish"}' \
  "https://docomomobrasil.com/wp-json/wp/v2/pages"
```
