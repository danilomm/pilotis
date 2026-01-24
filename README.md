# Pilotis

Sistema de gestão de filiados para associações e organizações sem fins lucrativos.

## Funcionalidades

- **Formulário de filiação** com pré-preenchimento de dados
- **Pagamento online** via PagBank (PIX, Boleto, Cartão)
- **Confirmação automática** via webhook
- **Email de confirmação** com PDF de declaração
- **Lista pública** de filiados por ano
- **Painel administrativo** com busca, edição, relatórios
- **Campanhas de email** (renovação, convites)
- **Lembretes automáticos** de pagamento pendente

## Requisitos

- PHP 8.1+
- SQLite 3
- Composer (opcional, para TCPDF)
- Conta PagBank (para pagamentos)
- Conta Brevo (para emails)

## Instalação

### 1. Clone o repositório

```bash
git clone https://github.com/danilomm/pilotis.git
cd pilotis
```

> **Nota**: O repositório contém um submodule `dados/` que aponta para um repo privado.
> Se você está fazendo fork para uso próprio, ignore o submodule e crie a estrutura manualmente.

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

Edite `.env` com os dados da sua organização:

```env
# Organização
ORG_NOME=Minha Associação
ORG_SIGLA=MA
ORG_LOGO=logo.png
ORG_COR_PRIMARIA=#4a8c4a
ORG_COR_SECUNDARIA=#7ab648
ORG_EMAIL_CONTATO=contato@minhaassociacao.org
ORG_SITE_URL=https://www.minhaassociacao.org
ORG_INSTAGRAM=minhaassociacao

# Categorias de filiação
# Formato: chave:label:valor_centavos (separados por vírgula)
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

### 4. Instale dependências (opcional)

```bash
composer install
```

Isso instala TCPDF para geração de PDFs mais elaborados. Sem ele, o sistema usa um fallback simples.

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
│   ├── config.php         # Configurações
│   ├── db.php             # Conexão SQLite
│   ├── routes.php         # Rotas
│   ├── Controllers/       # Lógica de negócio
│   ├── Services/          # PagBank, Brevo, PDF
│   └── Views/             # Templates HTML
├── scripts/               # CLI e utilitários
├── dados/                 # Dados (criar manualmente)
│   └── data/
│       └── pilotis.db     # Banco SQLite
├── .env                   # Credenciais (não versionar!)
└── .env.example           # Template de credenciais
```

## Rotas

| Rota | Descrição |
|------|-----------|
| `GET /filiacao/{ano}` | Formulário de entrada (email) |
| `GET /filiacao/{ano}/{token}` | Formulário de filiação |
| `GET /filiacao/{ano}/{token}/pagamento` | Tela de pagamento |
| `POST /webhook/pagbank` | Webhook de confirmação |
| `GET /filiados/{ano}` | Lista pública de filiados |
| `GET /admin` | Painel administrativo |

## Personalização

Toda a personalização é feita via `.env`, sem editar código.

### Identidade visual

```env
ORG_NOME=Minha Associação
ORG_SIGLA=MA
ORG_LOGO=logo.png            # arquivo em public/assets/img/
ORG_COR_PRIMARIA=#4a8c4a     # cor principal (header, botões)
ORG_COR_SECUNDARIA=#7ab648   # cor de destaque
ORG_SITE_URL=https://www.minhaassociacao.org
ORG_INSTAGRAM=minhaassociacao
```

Coloque sua logo em `public/assets/img/` (recomendado: 300-600px de largura).
Na web, a logo é exibida com no máximo 300px de largura. No PDF, com 80mm.
Use JPG para compatibilidade com o PDF (PNG com transparência pode gerar fundo branco).

### Categorias de filiação

Defina as categorias no `.env` no formato `chave:label:valor_centavos`:

```env
# Exemplo com 3 categorias:
CATEGORIAS=pleno:Pleno:30000,estudante:Estudante:15000,aposentado:Aposentado:7500

# Exemplo com 1 categoria:
CATEGORIAS=associado:Associado:12000
```

### Templates de email

Os templates de email são editáveis pelo painel admin em `/admin/campanha`.
Na primeira execução, o sistema cria templates padrão que podem ser personalizados.

## Scripts CLI

```bash
# Campanha (detecta campanha aberta, envia por grupos, limite 290/dia)
php scripts/enviar_campanha.php --dry-run
php scripts/enviar_campanha.php

# Lembretes (rodar via cron diariamente)
# - 1 dia antes do vencimento (PIX/Boleto)
# - Quinzenalmente: vencidos + formulários incompletos (máx 3)
# - "Última chance" 3 dias antes do fim da campanha
php scripts/enviar_lembretes.php
php scripts/enviar_lembretes.php --dry-run

# Backup do banco para GitHub
php scripts/backup_servidor.php

# Administração
php scripts/admin.php pendentes
php scripts/admin.php buscar "email@exemplo.com"
php scripts/admin.php pagar 123
php scripts/admin.php exportar 2026
```

## Deploy em Produção

### Segurança

1. **Banco fora do document root**: Coloque `pilotis.db` em diretório não acessível pela web
2. **Senha admin forte**: Use hash SHA256 no `.env`:
   ```bash
   php -r "echo 'sha256:' . hash('sha256', 'sua_senha_forte') . PHP_EOL;"
   ```
3. **HTTPS obrigatório**: Configure SSL no servidor

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

### Cron

```bash
# Campanha diária às 9h (detecta campanha aberta, envia até 290/dia)
0 12 * * * php /caminho/para/scripts/enviar_campanha.php >> /tmp/campanha.log 2>&1

# Lembretes diários às 8h (vencimento, quinzenais, última chance)
0 11 * * * php /caminho/para/scripts/enviar_lembretes.php >> /tmp/lembretes.log 2>&1

# Backup diário às 3h
0 6 * * * php /caminho/para/scripts/backup_servidor.php >> /tmp/backup.log 2>&1
```

Nota: horários em UTC. Para Brasília (UTC-3), 12h UTC = 9h BRT.

## Licença

GPL-3.0 - veja [LICENSE](LICENSE) para detalhes.

## Créditos

Desenvolvido para o [Docomomo Brasil](https://docomomobrasil.com) por Danilo Matoso Macedo.

---

*Pilotis: porque toda boa arquitetura precisa de uma base sólida.*
