# Pilotis — Contexto para Claude

Sistema de gestao de filiados do Docomomo Brasil.

## Status Atual

**Versao:** 1.0.0 (PHP)
**Tecnologia:** PHP 8.1+ / SQLite / Pico CSS
**Testado:** PIX, Boleto, Cartao, Emails com PDF
**Deploy:** Pronto para upload via FTP

## Estrutura do Projeto

```
pilotis/
├── public/                 # Document root (Apache)
│   ├── index.php          # Front controller
│   ├── .htaccess          # URL rewriting
│   └── assets/
│       └── img/
│           ├── logo-docomomo.png
│           └── logo-docomomo.jpg
├── src/
│   ├── config.php         # Configuracoes e helpers
│   ├── db.php             # Conexao SQLite + funcoes
│   ├── routes.php         # Sistema de rotas
│   ├── Controllers/
│   │   ├── FiliacaoController.php
│   │   ├── FiliadosController.php
│   │   ├── WebhookController.php
│   │   └── AdminController.php
│   ├── Services/
│   │   ├── PagBankService.php
│   │   ├── BrevoService.php
│   │   └── PdfService.php
│   └── Views/
│       ├── layout.php
│       ├── filiacao/
│       │   ├── entrada.php
│       │   ├── formulario.php
│       │   ├── pagamento.php
│       │   └── confirmacao.php
│       ├── filiados/
│       │   └── listar.php
│       ├── admin/
│       │   ├── login.php
│       │   ├── painel.php
│       │   ├── buscar.php
│       │   ├── pessoa.php
│       │   └── novo.php
│       └── errors/
│           ├── 404.php
│           └── 500.php
├── scripts/
│   ├── enviar_campanha.php
│   ├── enviar_lembretes.php
│   └── admin.php          # CLI para administracao
├── data/
│   ├── pilotis.db         # Banco SQLite
│   └── backup.sql         # Versionado no git
├── backup-python/         # Codigo Python anterior (referencia)
├── .env                   # Credenciais (nao versionado)
├── .env.example           # Template de credenciais
└── composer.json          # Dependencias (TCPDF)
```

## Rotas

| Rota | Funcao |
|------|--------|
| `GET /filiacao/{ano}` | Entrada por email |
| `POST /filiacao/{ano}` | Processa email |
| `GET /filiacao/{ano}/{token}` | Formulario pre-preenchido |
| `POST /filiacao/{ano}/{token}` | Salvar e criar pagamento |
| `GET /filiacao/{ano}/{token}/pagamento` | Tela de pagamento |
| `POST /filiacao/{ano}/{token}/gerar-pix` | Gera PIX |
| `POST /filiacao/{ano}/{token}/gerar-boleto` | Gera Boleto |
| `POST /filiacao/{ano}/{token}/pagar-cartao` | Paga com Cartao |
| `GET /filiados/{ano}` | Lista publica de filiados |
| `POST /webhook/pagbank` | Confirmacao de pagamento |
| `GET /admin` | Painel administrativo |
| `GET /admin/login` | Tela de login |
| `GET /admin/buscar` | Busca cadastrados |
| `GET /admin/pessoa/{id}` | Detalhes de pessoa |
| `GET /admin/novo` | Novo cadastro |
| `GET /admin/download/csv` | Exportar filiados |
| `GET /admin/download/banco` | Backup do banco |

## Categorias

| Interno | Display | Valor |
|---------|---------|-------|
| profissional_internacional | Filiado Pleno Internacional+Brasil | R$ 460 |
| profissional_nacional | Filiado Pleno Brasil | R$ 230 |
| estudante | Filiado Estudante Brasil | R$ 115 |

**Categoria default:** `profissional_internacional` (Internacional) - a mais cara. Esta ordem é intencional.

## Heranca de Dados Cadastrais

Ao abrir o formulario de filiacao, os dados cadastrais sao buscados da **ultima filiacao que tenha dados preenchidos**, nao necessariamente a mais recente. Isso evita herdar de registros vazios criados pelo envio de campanha.

Registros de filiacao criados pelo envio de email so contem: `pessoa_id`, `ano`, `status='enviado'`. Os dados cadastrais sao preenchidos quando a pessoa acessa e preenche o formulario.

## Scripts CLI

```bash
# Campanha
php scripts/enviar_campanha.php --ano 2026 --dry-run
php scripts/enviar_campanha.php --ano 2026 --tipo seminario
php scripts/enviar_campanha.php --ano 2026 --tipo convite

# Lembretes (rodar via cron)
php scripts/enviar_lembretes.php
php scripts/enviar_lembretes.php --dry-run

# Administracao
php scripts/admin.php pendentes           # Lista pendentes
php scripts/admin.php buscar "email"      # Busca pessoa
php scripts/admin.php pagar 123           # Marca pagamento como pago
php scripts/admin.php novo                # Cadastra + pagamento manual
php scripts/admin.php exportar 2026       # Exporta filiados CSV
php scripts/admin.php stats 2026          # Estatisticas do ano
```

## Fluxos

**Filiacao:**
1. Pessoa acessa `/filiacao/2026` ou clica link do email
2. Informa email → sistema busca/cria cadastro
3. Preenche formulario → cria pagamento pendente
4. Gera PIX (3 dias validade) → mostra QR Code
5. Pagamento confirmado via webhook → email + PDF

**Campanhas:**
- `renovacao`: filiados do ano anterior
- `seminario`: participantes do 16o Seminario nao filiados
- `convite`: outros cadastrados

**Lembretes:**
- No dia do vencimento
- Semanalmente apos vencer (domingos)

## Declaracao PDF

Gerada com TCPDF (ou fallback simples):
- Logo JPG (sem transparencia)
- Nome, categoria, valor, ano
- Assinatura: Marta Peixoto, Coordenadora, Gestao 2026-2027

## Comandos de Desenvolvimento

```bash
# Servidor local (requer PHP 8.1+)
cd public && php -S localhost:8000

# Instalar TCPDF (opcional, para PDF melhor)
composer install
```

## Credenciais (.env)

```
# Banco de dados
DATABASE_PATH=data/pilotis.db

# PagBank
PAGBANK_TOKEN=seu_token_aqui
PAGBANK_SANDBOX=true

# Email (Brevo - ex-Sendinblue)
BREVO_API_KEY=sua_chave_aqui
EMAIL_FROM=tesouraria@docomomobrasil.com

# App
BASE_URL=http://localhost:8000
SECRET_KEY=chave_secreta_para_tokens

# Admin
ADMIN_PASSWORD=sua_senha_aqui

# Valores de filiacao (centavos)
VALOR_ESTUDANTE=11500
VALOR_PROFISSIONAL=23000
VALOR_INTERNACIONAL=46000
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
- `../dados_privados/pilotis.db` (acima do public_html)

**Admin:** A senha do painel deve ser forte. Em producao, use hash SHA256:
```bash
php -r "echo 'sha256:' . hash('sha256', 'sua_senha_forte') . PHP_EOL;"
```

## Deploy

**Servidor:** KingHost (via Labasoft)
**URL:** https://pilotis.docomomobrasil.com
**Tecnologia:** Apache + PHP 8.1+

### Estrutura no servidor

```
/home/app/
├── public_html/pilotis/   # Document root
│   ├── index.php
│   ├── .htaccess
│   ├── .env
│   └── assets/
├── pilotis-src/           # Codigo PHP (fora do www)
│   ├── src/
│   └── scripts/
└── dados_privados/
    └── pilotis.db         # Banco (FORA do www)
```

### Fazer deploy

```bash
# 1. Upload via FTP (ftp.app.docomomobrasil.com)
#    - public/* -> /public_html/pilotis/
#    - src/* -> /pilotis-src/src/
#    - scripts/* -> /pilotis-src/scripts/

# 2. Editar index.php para apontar caminhos corretos

# 3. Criar .env no servidor com credenciais reais

# 4. Ajustar DATABASE_PATH no .env
```

## Backup e Commit

**IMPORTANTE:** Quando o usuario pedir "backup e commit", executar:

```bash
# 1. Gerar dump SQL do banco
sqlite3 data/pilotis.db .dump > data/backup.sql

# 2. Commit de tudo
git add -A
git commit -m "Descricao das mudancas"
```

O arquivo `data/backup.sql` e versionado no git e serve como ponto de restauracao.

Para restaurar:
```bash
rm data/pilotis.db
sqlite3 data/pilotis.db < data/backup.sql
```

## Regras de Consolidacao de Dados

- **Nomes duplicados:** Sempre usar o nome mais completo ao consolidar registros
- **Emails:** Manter todos os emails da pessoa (principal + secundarios)
- **Filiacoes:** Preservar historico de todos os anos

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

## Migracao Python -> PHP

O codigo Python original esta preservado em `backup-python/` para referencia.
O banco de dados SQLite e o mesmo (compativel).
