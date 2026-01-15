# Deploy do Pilotis

Instrucoes para deploy no servidor KingHost via FTP.

## Pre-requisitos (servidor)

O provedor precisa configurar:

1. **Subdominio:** `pilotis.docomomobrasil.com`
2. **VHost Apache** apontando para `/home/app/apps_wsgi/pilotis/pilotis.wsgi`
3. **Python 3.10+** disponivel para mod_wsgi
4. **Diretorio de dados:** `/home/app/dados_privados/` (fora do www)

## Estrutura no servidor

```
/home/app/
├── apps_wsgi/
│   └── pilotis/
│       ├── pilotis.wsgi      # Entry point WSGI
│       ├── .env              # Configuracoes (do .env.producao)
│       ├── pilotis/          # Pacote Python
│       │   ├── __init__.py
│       │   ├── main.py
│       │   ├── config.py
│       │   ├── db.py
│       │   ├── models.py
│       │   ├── routers/
│       │   ├── services/
│       │   ├── static/
│       │   └── templates/
│       └── scripts/          # Scripts administrativos
└── dados_privados/
    └── pilotis.db            # Banco de dados (FORA do www!)
```

## Passo a passo

### 1. Instalar dependencias (se tiver SSH)

```bash
cd /home/app/apps_wsgi/pilotis
pip install --target=/home/app/apps_wsgi/.site-packages -r requirements.txt
```

Se NAO tiver SSH, peca ao provedor para instalar os pacotes do requirements.txt.

### 2. Upload via FTP

Conecte no FTP:
- Host: `ftp.app.docomomobrasil.com`
- User: `app`
- Pass: (senha fornecida)

Faca upload dos arquivos para `/apps_wsgi/pilotis/`:

```
LOCAL                           -> SERVIDOR
deploy/pilotis.wsgi            -> /apps_wsgi/pilotis/pilotis.wsgi
deploy/.env.producao           -> /apps_wsgi/pilotis/.env (renomear!)
pilotis/                       -> /apps_wsgi/pilotis/pilotis/
scripts/                       -> /apps_wsgi/pilotis/scripts/
data/pilotis.db                -> /dados_privados/pilotis.db
```

### 3. Configurar .env

Edite o arquivo `.env` no servidor com as credenciais reais:

- `PAGBANK_TOKEN` - Token de producao do PagBank
- `BREVO_API_KEY` - Chave da API Brevo
- `SECRET_KEY` - Gere uma nova chave secreta
- `ADMIN_PASSWORD` - Hash SHA256 da senha admin

### 4. Criar diretorio de dados

Via FTP, crie o diretorio `/home/app/dados_privados/` e faca upload do banco de dados.

### 5. Testar

Acesse `https://pilotis.docomomobrasil.com/` e verifique se a aplicacao carrega.

### 6. Configurar webhook PagBank

No painel do PagBank, configure o webhook para:
```
https://pilotis.docomomobrasil.com/webhook/pagbank
```

## Atualizacoes futuras

Para atualizar a aplicacao:

1. Faca upload dos arquivos modificados via FTP
2. Se necessario, reinicie o Apache (peca ao provedor)

O banco de dados NAO precisa ser reenviado (a menos que queira restaurar backup).

## Troubleshooting

### Erro 500 Internal Server Error

1. Verifique o log de erros: `/home/app/error.log`
2. Confirme que o `.env` esta configurado corretamente
3. Confirme que as dependencias estao instaladas

### Pagina em branco

1. Verifique se o VHost esta configurado
2. Confirme o caminho do arquivo WSGI

### Erro de banco de dados

1. Verifique se o arquivo existe em `/home/app/dados_privados/pilotis.db`
2. Verifique permissoes de leitura/escrita
