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
│   │   ├── PdfService.php
│   │   └── LembreteService.php
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
│   ├── enviar_campanha.php   # CLI de campanha (backup, substituido por UI admin)
│   ├── enviar_lembretes.php  # CLI de lembretes antigo (substituido por processar_lembretes.php)
│   ├── processar_lembretes.php # CLI idempotente para lembretes agendados
│   ├── admin.php          # CLI para administracao
│   ├── verificar_emails.php   # Verifica typos e duplicados
│   ├── revisar_nomes.php      # Lista nomes para revisao manual
│   ├── emails_typos.php       # Mapa de typos de email
│   ├── instituicoes_normalizadas.php  # Mapa de normalizacao
│   ├── cidades_normalizadas.php       # Mapa de normalizacao
│   ├── limpar_csv_*.php   # Scripts de limpeza por ano
│   ├── importar_csv_*.php # Scripts de importacao por ano
│   └── normalizar_*.php   # Scripts de normalizacao
├── dados/                 # SUBMODULE (repo privado: pilotis-dados)
│   ├── data/
│   │   ├── pilotis.db     # Banco SQLite
│   │   └── backup.sql     # Versionado no repo privado
│   ├── importacao/        # CSVs de importação
│   └── DEVLOG.md          # Log com dados sensíveis
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
| `POST /admin/campanha/enviar-lote` | Envia lote de emails (AJAX JSON) |
| `POST /admin/campanha/preview-lote` | Conta destinatarios por grupo (AJAX JSON) |
| `POST /admin/lembretes/processar` | Processa lembretes pendentes (AJAX JSON) |

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
# Campanha — ENVIO MANUAL via admin (/admin/campanha)
# O envio agora e feito por lotes interativos no painel admin.
# O script CLI abaixo e mantido como backup:
php scripts/enviar_campanha.php --dry-run
php scripts/enviar_campanha.php

# Lembretes agendados (idempotente — seguro para rodar N vezes)
# Lembretes sao agendados automaticamente pelo sistema:
# - PIX/Boleto gerado -> lembrete de vencimento (D-1)
# - Formulario acessado -> 3 lembretes quinzenais
# - Data fim definida -> ultima chance (D-3)
# - Pagamento confirmado -> cancela todos
# Processamento tambem disponivel via botao no admin (/admin/campanha)
php scripts/processar_lembretes.php --dry-run
php scripts/processar_lembretes.php
php scripts/processar_lembretes.php --limite 20

# Script antigo de lembretes (substituido por processar_lembretes.php)
php scripts/enviar_lembretes.php
php scripts/enviar_lembretes.php --dry-run

# Verificacao de bounces (roda automaticamente ao fim da campanha)
php scripts/verificar_bounces.php --dry-run
php scripts/verificar_bounces.php --dias 30

# Backup do banco (rodar via cron diariamente)
php scripts/backup_servidor.php
php scripts/backup_servidor.php --dry-run

# Administracao
php scripts/admin.php pendentes           # Lista pendentes
php scripts/admin.php buscar "email"      # Busca pessoa
php scripts/admin.php pagar 123           # Marca pagamento como pago
php scripts/admin.php novo                # Cadastra + pagamento manual
php scripts/admin.php exportar 2026       # Exporta filiados CSV
php scripts/admin.php stats 2026          # Estatisticas do ano
```

## Cron (via GET externo)

O servidor nao permite shell_exec, entao os crons sao disparados via GET por maquina externa do provedor (Labasoft).

**Endpoints protegidos por token:**

| Horario (BRT) | URL |
|---------------|-----|
| 9h (campanha) | `https://pilotis.docomomobrasil.com/cron-campanha.php?token=cron-secreto-pilotis-2026` |
| 8h (lembretes) | `https://pilotis.docomomobrasil.com/cron-lembretes.php?token=cron-secreto-pilotis-2026` |

Os scripts so executam se houver campanha aberta ou pagamentos pendentes.

## Backup Automatico (GitHub Actions)

O backup do banco e feito via GitHub Actions (nao no servidor).

- **Frequencia:** Diario as 3h (Brasilia)
- **Workflow:** `dados/.github/workflows/backup.yml`
- **Segredo:** `BACKUP_TOKEN` no repo `docomomobr/pilotis-dados`

O Action baixa o banco via `backup-download.php?token=...` e commita o `backup.sql` no repo privado.

**Endpoint de backup:**
```
https://pilotis.docomomobrasil.com/backup-download.php?token=backup-secreto-pilotis-2026
```

## Fluxos

**Filiacao:**
1. Pessoa acessa `/filiacao/2026` ou clica link do email
2. Informa email → sistema busca/cria cadastro → status `enviado`
3. Acessa formulario → status `acesso`
4. Preenche formulario → cria pagamento pendente → status `pendente`
5. Gera PIX (3 dias validade) ou Boleto (7 dias) → mostra QR Code ou boleto
6. Pagamento confirmado via webhook → email + PDF → status `pago`

**Campanhas:**
- `renovacao`: filiados do ano anterior
- `seminario`: participantes do 16o Seminario nao filiados
- `convite`: outros cadastrados

**Lembretes (tabela `lembretes_agendados`, processados por `LembreteService`):**

Lembretes sao agendados individualmente no banco e processados sob demanda (idempotente).

| Evento | Lembrete agendado |
|--------|-------------------|
| PIX/Boleto gerado | `vencimento_amanha` para D-1 |
| Formulario acessado (status `acesso`) | `formulario_incompleto` para D+14, D+28, D+42 |
| Data fim da campanha definida | `ultima_chance` para data_fim - 3 dias |
| Pagamento confirmado | Cancela TODOS os lembretes da filiacao |
| Campanha fechada | Cancela todos os lembretes do ano |

Processamento: via botao no admin (`/admin/campanha`) ou CLI (`scripts/processar_lembretes.php`)

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
DATABASE_PATH=dados/data/pilotis.db

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
**Tecnologia:** Apache + PHP 8.3 (FPM)
**Status:** Instalado e funcionando

## Para Entrar no Ar (apos homologacao PagBank)

**Status atual:** Aguardando homologacao do PagBank (solicitacao enviada 2026-01-25).

A campanha esta pausada e o site mostra pagina de manutencao. Quando o PagBank aprovar:

### 1. Reabrir a campanha

```bash
sshpass -p 'King@123' ssh pilotis@ftp.pilotis.docomomobrasil.com \
  "/usr/bin/php81 -r \"require 'www/src/config.php'; require 'www/src/db.php'; db_execute(\\\"UPDATE campanhas SET status = 'aberta' WHERE ano = 2026\\\"); echo 'Campanha reaberta';\""
```

Ou via admin: https://pilotis.docomomobrasil.com/admin → Campanhas → Reabrir

### 2. Testar pagamento

1. Acessar https://pilotis.docomomobrasil.com/filiacao/2026
2. Informar email de teste
3. Preencher formulario
4. Gerar PIX e verificar se aparece QR Code

### 3. Enviar emails restantes

O cron vai continuar enviando automaticamente (290/dia).
Para forcar envio manual:

```bash
sshpass -p 'King@123' ssh pilotis@ftp.pilotis.docomomobrasil.com \
  "/usr/bin/php81 www/scripts/enviar_campanha.php"
```

### Credenciais do servidor

```
Host: ftp.pilotis.docomomobrasil.com
Usuario: pilotis
Senha: King@123
SSH: mesmas credenciais
```

### Estrutura no servidor

```
/home/pilotis/
├── www/                   # Document root
│   ├── index.php
│   ├── .htaccess
│   ├── .env
│   ├── assets/
│   ├── src/               # Codigo PHP
│   ├── scripts/           # Scripts CLI
│   └── vendor/            # TCPDF (composer)
└── dados_privados/
    └── pilotis.db         # Banco (FORA do www)
```

PHP 8.1 disponivel em `/usr/bin/php81`. Extensoes necessarias ja instaladas (pdo_sqlite, curl, mbstring).

### Fazer deploy

```bash
# 1. Upload via FTP (ftp.pilotis.docomomobrasil.com)
#    - public/* -> /www/
#    - src/* -> /www/src/
#    - scripts/* -> /www/scripts/
#    - vendor/* -> /www/vendor/

# 2. Editar index.php para apontar caminhos corretos

# 3. Criar .env no servidor com credenciais reais

# 4. Ajustar DATABASE_PATH no .env
```

## Repositorios GitHub

O projeto usa dois repositorios separados:

| Repo | Visibilidade | Conteudo |
|------|--------------|----------|
| `danilomm/pilotis` | Publico | Codigo PHP (software generico) |
| `docomomobr/pilotis-dados` | Privado | Dados sensiveis (banco, CSVs, DEVLOG) |

A pasta `dados/` e um submodule que aponta para o repo privado.

**Configuracao necessaria:** `danilomm` deve ser colaborador do repo `docomomobr/pilotis-dados` com permissao de Write.

## Backup e Commit

**IMPORTANTE:** Quando o usuario pedir "backup e commit", executar o script:

```bash
./backup.sh "Descricao das mudancas"
```

O script:
1. Gera dump SQL do banco (`dados/data/backup.sql`)
2. Faz commit e push no repo privado (`docomomobr/pilotis-dados`)
3. Faz commit e push no repo publico (`danilomm/pilotis`)

Para restaurar o banco:
```bash
rm dados/data/pilotis.db
sqlite3 dados/data/pilotis.db < dados/data/backup.sql
```

## Atualizar Copia Local (dados do servidor)

O GitHub Actions faz backup automatico diario as 3h (Brasilia),
baixando o banco do servidor e commitando `backup.sql` no repo privado.

Para trabalhar localmente com os dados mais recentes do servidor:

```bash
# 1. Puxa o backup mais recente
cd dados
git pull

# 2. Restaura o banco local
rm data/pilotis.db
sqlite3 data/pilotis.db < data/backup.sql
cd ..
```

Para forcar backup manual: va em GitHub Actions do repo `docomomobr/pilotis-dados` e clique "Run workflow".

## Regras de Consolidacao de Dados

- **Nomes duplicados:** Sempre usar o nome mais completo ao consolidar registros
- **Emails:** Manter todos os emails da pessoa (principal + secundarios)
- **Filiacoes:** Preservar historico de todos os anos
- **Cidades estrangeiras:** Usar grafia do pais de origem (New York, Milano), nao traduzir
- **Telefones internacionais:** Manter formato internacional (+1, +351, etc). Verificar endereco quando numero parecer estranho
- **Prioridade para correcao de dados:** Ao encontrar dados inconsistentes ou faltantes:
  1. Primeiro: buscar em outros anos da mesma pessoa
  2. Segundo: verificar no CSV original (dados/importacao/originais/)
  3. Ultimo: usar metodos dedutivos (inferir do CEP, endereco, etc)

## WordPress (Site Principal)

API REST para gerenciar paginas de filiados no site docomomobrasil.com.

```
URL base: https://docomomobrasil.com/wp-json/wp/v2/
Tipos: post, page, course (Educaz), dlm_download
Auth: Credenciais em .env (WP_USER e WP_APP_PASSWORD)
```

### Exemplo de uso

```bash
# Listar paginas
curl -u "$WP_USER:$WP_APP_PASSWORD" \
  "https://docomomobrasil.com/wp-json/wp/v2/pages?search=filiados"

# Criar/atualizar pagina
curl -X POST -u "$WP_USER:$WP_APP_PASSWORD" \
  -H "Content-Type: application/json" \
  -d '{"title":"Filiados 2025","content":"...","status":"publish"}' \
  "https://docomomobrasil.com/wp-json/wp/v2/pages"
```

## Importacao de Dados de Anos Anteriores

Processo para importar filiados de planilhas de anos anteriores (Google Forms exportados).

**Documentacao completa:** `dados/importacao/README.md`

**Arquivos preservados:**
- `dados/importacao/originais/` - CSVs originais do Google Forms
- `dados/importacao/limpos/` - CSVs limpos e normalizados
- `importacao/scripts/` - Scripts usados na importacao

### Visao Geral

1. **Receber planilha** (CSV do Google Forms)
2. **Criar script de limpeza** (`scripts/limpar_csv_YYYY.php`)
3. **Verificar emails** - typos, duplicados, invalidos
4. **Revisar nomes** - VERIFICAR_MANUAL, ATUALIZAR_NOME, curtos
5. **Importar dados** (`scripts/importar_csv_YYYY.php`)
6. **Verificar duplicatas** no banco (OBRIGATORIO)
7. **Consolidar duplicatas** se houver

### Etapa 1: Script de Limpeza

Cada ano tem estrutura de colunas diferente. Criar script baseado em `limpar_csv_2022.php`:

**Normalizacoes obrigatorias:**
- **Nomes:** Capitalizar corretamente (preposicoes minusculas: de, da, do, das, dos, e)
- **Emails:** Lowercase, trim
- **Telefones:** Formato `(XX) XXXXX-XXXX` ou `(XX) XXXX-XXXX`. Se houver multiplos numeros, usar apenas o primeiro.
- **CEP:** Formato `XXXXX-XXX` (extrair manualmente se endereco em campo unico)
- **Cidade/Estado:** Extrair manualmente se endereco em campo unico
- **Cidades estrangeiras:** Usar grafia do pais de origem (New York, Milano), nao traduzir
- **Categorias:** Mapear para `profissional_internacional`, `profissional_nacional`, `estudante`
- **Valores:** Em centavos (29000, 14500, 5000 para 2022-2023)
- **Instituicoes:** Normalizar preservando unidades (ex: FAU-USP, IAU-USP, PROPAR-UFRGS) - ver `instituicoes_normalizadas.php`
- **Formacao:** Mapear para valores do sistema:
  - Ensino Medio
  - Graduacao em andamento
  - Graduacao
  - Especializacao / MBA em andamento
  - Especializacao / MBA
  - Mestrado em andamento
  - Mestrado
  - Doutorado em andamento
  - Doutorado
  - Pos-Doutorado
- **Metodo pagamento:** PIX, Deposito, Boleto, Cartao

**Arquivos de mapeamento:**
- `scripts/enderecos_YYYY_manual.php` - CEP/cidade/estado extraidos manualmente
- `scripts/instituicoes_normalizadas.php` - Mapeamento de instituicoes (reutilizavel, compartilhado entre anos)

**IMPORTANTE:** Preservar os arquivos na pasta `importacao/`:
- `dados/importacao/originais/` - CSVs originais do Google Forms
- `dados/importacao/limpos/` - CSVs limpos e normalizados
- `importacao/scripts/` - Copia dos scripts usados

Esses arquivos sao necessarios para correcoes futuras e servem de template para novos anos.

**Colunas de verificacao no CSV limpo:**
- `email_existe`: SIM se email ja existe no banco
- `pessoa_id_email`: ID da pessoa se email existe
- `nome_banco_email`: Nome no banco (para comparar)
- `nome_similar`: EXATO ou PARCIAL se nome similar existe
- `pessoa_id_nome`: ID da pessoa se nome similar
- `nome_banco_similar`: Nome no banco
- `acao_sugerida`: USAR_EXISTENTE, ATUALIZAR_NOME, VERIFICAR_MANUAL, CRIAR_NOVO

### Normalizacao de Instituicoes

Usar formato `UNIDADE-UNIVERSIDADE` para preservar informacao de localizacao:

| Original | Normalizado | Local |
|----------|-------------|-------|
| Faculdade de Arquitetura e Urbanismo da USP | FAU-USP | Sao Paulo |
| Instituto de Arquitetura e Urbanismo da USP | IAU-USP | Sao Carlos |
| PROPAR UFRGS | PROPAR-UFRGS | Porto Alegre |
| Faculdade de Arquitetura da UFBA | FAUFBA | Salvador |
| PROARQ UFRJ | PROARQ-UFRJ | Rio de Janeiro |

Se nao houver unidade especifica, usar apenas a sigla: USP, UFRJ, UFBA, etc.

### Etapa 2: Verificar Emails

Executar script de verificacao de emails:

```bash
php scripts/verificar_emails.php dados/importacao/limpos/filiados_YYYY_limpo.csv
```

O script verifica:
- **Typos de dominio:** gmal.com → gmail.com, hotmal.com → hotmail.com, etc.
- **Emails duplicados:** mesmo email aparece mais de uma vez no CSV
- **Formato invalido:** emails mal formatados

Typos conhecidos estao em `scripts/emails_typos.php`. Adicionar novos conforme descobertos.

Se encontrar problemas, **corrigir no CSV antes de continuar**.

### Etapa 3: Revisar Nomes

Executar script de revisao de nomes:

```bash
php scripts/revisar_nomes.php dados/importacao/limpos/filiados_YYYY_limpo.csv
```

O script lista:
- **VERIFICAR_MANUAL:** mesmo nome existe no banco com email diferente (pode ser outra pessoa ou email secundario)
- **ATUALIZAR_NOME:** planilha tem nome mais completo que o banco
- **Nomes curtos:** menos de 2 palavras (pode ser incompleto)
- **Caracteres estranhos:** numeros ou simbolos no nome

Para cada item, **verificar e corrigir no CSV manualmente**.

### Etapa 4: Importacao

Usar script generico ou criar especifico:

```bash
# Script generico (para CSVs no formato padrao)
php importacao/scripts/importar_csv_generico.php YYYY
php importacao/scripts/importar_csv_generico.php YYYY --dry-run  # Testar antes

# Ou script especifico do ano
php scripts/importar_csv_YYYY.php
```

O script:
1. Cria campanha do ano como 'fechada'
2. Para cada linha:
   - Se email existe: usa pessoa existente
   - Se nome similar: usa pessoa existente (apos verificacao manual)
   - Senao: cria pessoa nova
3. Cria filiacao com status 'pago'

### Corrigir Normalizacao Apos Importacao

Se precisar atualizar a normalizacao de dados ja importados:
1. Regenerar CSV limpo: `php scripts/limpar_csv_YYYY.php`
2. Atualizar banco: `php scripts/atualizar_normalizacao.php`

O script `atualizar_normalizacao.php` le os CSVs limpos e atualiza instituicao, formacao e metodo no banco.

### Etapa 5: Verificacao de Duplicatas (OBRIGATORIO)

**IMPORTANTE:** Apos TODA importacao, verificar duplicatas por nome similar:

```sql
SELECT p1.id, p1.nome, p2.id, p2.nome
FROM pessoas p1, pessoas p2
WHERE p1.id < p2.id
AND (
  LOWER(SUBSTR(p1.nome, 1, INSTR(p1.nome || ' ', ' '))) =
  LOWER(SUBSTR(p2.nome, 1, INSTR(p2.nome || ' ', ' ')))
)
ORDER BY p1.nome;
```

Essa query encontra pessoas com mesmo primeiro nome. Revisar manualmente cada par.

### Etapa 6: Consolidacao de Duplicatas

Se encontrar duplicatas:
1. Decidir qual nome manter (sempre o **mais completo**)
2. Mover emails para pessoa principal
3. Mover filiacoes para pessoa principal (cuidado com UNIQUE constraint)
4. Deletar pessoa duplicada

```sql
-- Exemplo de consolidacao
UPDATE emails SET pessoa_id = ID_PRINCIPAL WHERE pessoa_id = ID_DUPLICADO;
DELETE FROM filiacoes WHERE pessoa_id = ID_DUPLICADO AND ano = ANO; -- se duplicado
UPDATE filiacoes SET pessoa_id = ID_PRINCIPAL WHERE pessoa_id = ID_DUPLICADO;
DELETE FROM pessoas WHERE id = ID_DUPLICADO;
```

### Historico de Importacoes

| Ano | Registros | Novos | Duplicatas | Data |
|-----|-----------|-------|------------|------|
| 2024 | 178 | 61 | 0 | 2026-01-16 |
| 2023 | 123 | 25 | 3 | 2026-01-22 |
| 2022 | 154 | 57 | 1 (excluída) | 2026-01-22 |
| 2021 | 131 | 94 | 42 (pendentes) | 2026-01-22 |
| 2020 | 127 | 83 | - | 2026-01-22 |
| 2019 | 158 | 120 | - | 2026-01-22 |
| 2018 | 22 | 11 | - | 2026-01-22 |
| 2016 | 99 | 63 | - | 2026-01-22 |
| 2015 | 22 | 21 | - | 2026-01-22 |

## Migracao Python -> PHP

O codigo Python original esta preservado em `backup-python/` para referencia.
O banco de dados SQLite e o mesmo (compativel).

## Open Source

O sistema e distribuido sob licenca **GPL-3.0**.

Arquivos para quem fizer fork:
- `README.md` — Documentacao completa
- `LICENSE` — GPL-3.0
- `schema.sql` — Estrutura do banco
- `scripts/install.php` — Instalacao automatica
