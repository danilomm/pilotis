# Pilotis

Sistema de gestao de filiados para associacoes e organizacoes sem fins lucrativos.

## Funcionalidades

- **Formulario de filiacao** com pre-preenchimento de dados
- **Pagamento online** via PagBank (PIX, Boleto, Cartao)
- **Confirmacao automatica** via webhook
- **Email de confirmacao** com PDF de declaracao
- **Lista publica** de filiados por ano
- **Painel administrativo** com busca, edicao, relatorios
- **Campanhas de email** (renovacao, convites)
- **Lembretes automaticos** de pagamento pendente

## Requisitos

- PHP 8.1+
- SQLite 3
- Composer (opcional, para TCPDF)
- Conta PagBank (para pagamentos)
- Conta Brevo (para emails)

## Instalacao

### 1. Clone o repositorio

```bash
git clone https://github.com/danilomm/pilotis.git
cd pilotis
```

> **Nota**: O repositorio contem um submodule `dados/` que aponta para um repo privado.
> Se voce esta fazendo fork para uso proprio, ignore o submodule e crie a estrutura manualmente.

### 2. Crie a estrutura de dados

```bash
# Se dados/ existir como submodule vazio, remova primeiro:
rm -rf dados

# Crie a estrutura e inicialize o banco:
mkdir -p dados/data
php scripts/install.php
```

Isso cria o banco SQLite vazio em `dados/data/pilotis.db`.

### 3. Configure as credenciais

```bash
cp .env.example .env
```

Edite `.env` com os dados da sua organizacao:

```env
# Organizacao
ORG_NOME=Minha Associacao
ORG_SIGLA=MA
ORG_LOGO=logo.png
ORG_COR_PRIMARIA=#4a8c4a
ORG_COR_SECUNDARIA=#7ab648
ORG_EMAIL_CONTATO=contato@minhaassociacao.org
ORG_SITE_URL=https://www.minhaassociacao.org
ORG_INSTAGRAM=minhaassociacao

# Categorias de filiacao
# Formato: chave:label:valor_centavos (separados por virgula)
CATEGORIAS=pleno:Pleno:30000,estudante:Estudante:15000

# Banco de dados
DATABASE_PATH=dados/data/pilotis.db

# PagBank (obter em https://pagseguro.uol.com.br)
PAGBANK_TOKEN=seu_token_aqui
PAGBANK_SANDBOX=true

# Brevo - ex-Sendinblue (obter em https://www.brevo.com)
BREVO_API_KEY=sua_chave_aqui
EMAIL_FROM=contato@minhaassociacao.org

# App
BASE_URL=http://localhost:8000
SECRET_KEY=chave_secreta_aleatoria
ADMIN_PASSWORD=sua_senha_admin
```

### 4. Instale dependencias (opcional)

```bash
composer install
```

Isso instala TCPDF para geracao de PDFs mais bonitos. Sem ele, o sistema usa um fallback simples.

### 5. Inicie o servidor

```bash
cd public
php -S localhost:8000
```

Acesse http://localhost:8000/filiacao/2026

## Estrutura

```
pilotis/
├── public/                 # Document root
│   ├── index.php          # Front controller
│   ├── .htaccess          # URL rewriting (Apache)
│   └── assets/            # CSS, imagens
├── src/
│   ├── config.php         # Configuracoes
│   ├── db.php             # Conexao SQLite
│   ├── routes.php         # Rotas
│   ├── Controllers/       # Logica de negocio
│   ├── Services/          # PagBank, Brevo, PDF
│   └── Views/             # Templates HTML
├── scripts/               # CLI e utilitarios
├── dados/                 # Dados (criar manualmente)
│   └── data/
│       └── pilotis.db     # Banco SQLite
├── .env                   # Credenciais (nao versionar!)
└── .env.example           # Template de credenciais
```

## Rotas

| Rota | Descricao |
|------|-----------|
| `GET /filiacao/{ano}` | Formulario de entrada (email) |
| `GET /filiacao/{ano}/{token}` | Formulario de filiacao |
| `GET /filiacao/{ano}/{token}/pagamento` | Tela de pagamento |
| `POST /webhook/pagbank` | Webhook de confirmacao |
| `GET /filiados/{ano}` | Lista publica de filiados |
| `GET /admin` | Painel administrativo |

## Personalizacao

Toda a personalizacao e feita via `.env`, sem editar codigo.

### Identidade visual

```env
ORG_NOME=Minha Associacao
ORG_SIGLA=MA
ORG_LOGO=logo.png            # arquivo em public/assets/img/
ORG_COR_PRIMARIA=#4a8c4a     # cor principal (header, botoes)
ORG_COR_SECUNDARIA=#7ab648   # cor de destaque
ORG_SITE_URL=https://www.minhaassociacao.org
ORG_INSTAGRAM=minhaassociacao
```

Coloque sua logo em `public/assets/img/` (PNG para web, JPG para PDF sem transparencia).

### Categorias de filiacao

Defina as categorias no `.env` no formato `chave:label:valor_centavos`:

```env
# Exemplo com 3 categorias:
CATEGORIAS=pleno:Pleno:30000,estudante:Estudante:15000,aposentado:Aposentado:7500

# Exemplo com 1 categoria:
CATEGORIAS=associado:Associado:12000
```

### Templates de email

Os templates de email sao editaveis pelo painel admin em `/admin/campanha`.
Na primeira execucao, o sistema cria templates padrao que podem ser personalizados.

## Scripts CLI

```bash
# Enviar campanha de emails
php scripts/enviar_campanha.php --ano 2026 --dry-run
php scripts/enviar_campanha.php --ano 2026 --tipo renovacao

# Enviar lembretes (rodar via cron)
php scripts/enviar_lembretes.php

# Administracao
php scripts/admin.php pendentes
php scripts/admin.php buscar "email@exemplo.com"
php scripts/admin.php pagar 123
php scripts/admin.php exportar 2026
```

## Deploy em Producao

### Seguranca

1. **Banco fora do document root**: Coloque `pilotis.db` em diretorio nao acessivel pela web
2. **Senha admin forte**: Use hash SHA256 no `.env`:
   ```bash
   php -r "echo 'sha256:' . hash('sha256', 'sua_senha_forte') . PHP_EOL;"
   ```
3. **HTTPS obrigatorio**: Configure SSL no servidor

### Apache

```apache
<VirtualHost *:443>
    ServerName filiacao.suaorganizacao.com
    DocumentRoot /var/www/pilotis/public

    <Directory /var/www/pilotis/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Webhook PagBank

Configure a URL de webhook no painel PagBank:
```
https://filiacao.suaorganizacao.com/webhook/pagbank
```

## Licenca

GPL-3.0 - veja [LICENSE](LICENSE) para detalhes.

## Creditos

Desenvolvido para o [Docomomo Brasil](https://docomomobrasil.com) por Danilo Matoso Macedo.

---

*Pilotis: porque toda boa arquitetura precisa de uma base solida.*
