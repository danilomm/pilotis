# Pilotis — Contexto para Claude

Sistema de gestão de filiados do Docomomo Brasil.

## Status Atual

**Fases concluídas:** 1 e 2 de 5
**Próxima fase:** 3 (Formulário de Filiação)

## Estrutura do Projeto

```
pilotis/
├── pilotis/              # Módulo Python principal
│   ├── main.py           # FastAPI app
│   ├── config.py         # Settings do .env
│   ├── db.py             # SQLite + schema
│   ├── models.py         # Dataclasses
│   ├── routers/          # Rotas (a implementar)
│   ├── services/         # PagBank, Email (a implementar)
│   └── templates/        # Jinja2
├── scripts/              # CLI scripts
│   ├── importar_csv.py   # Importa cadastrados (com detecção de duplicatas)
│   ├── gerar_tokens.py   # Gera tokens únicos
│   └── backup_db.sh      # Dump SQL para versionamento
├── data/
│   ├── pilotis.db              # Banco SQLite (724 cadastrados) — NÃO versionado
│   ├── backup.sql              # Dump SQL — versionado
│   └── cadastrados_revisados.ods  # Planilha para revisão manual
├── desenvolvimento/
│   ├── pilotis-briefing.md                      # Briefing completo
│   └── cadastrados_docomomo_2025_consolidado.csv # Base inicial
└── .env                  # Configurações (não commitado)
```

## Comandos Úteis

```bash
# Ativar ambiente
source venv/bin/activate

# Rodar servidor
uvicorn pilotis.main:app --reload

# Backup do banco (antes de commits)
./scripts/backup_db.sh

# Reimportar dados (limpa banco antes)
rm data/pilotis.db
python scripts/importar_csv.py desenvolvimento/cadastrados_docomomo_2025_consolidado.csv
python scripts/gerar_tokens.py

# Restaurar do backup SQL
sqlite3 data/pilotis.db < data/backup.sql
```

## Git

- Primeiro commit: `2c2d942`
- Branch: `master`
- **Ignorados:** `venv/`, `.env`, `data/*.db`
- **Versionado:** `data/backup.sql` (dump do banco)

## Banco de Dados

**Tabelas:**
- `cadastrados` — dados pessoais + token + seminario_2025 (BOOLEAN)
- `pagamentos` — histórico de pagamentos por ano
- `log` — registro de eventos

**View:**
- `filiados` — cadastrados com pagamento do ano corrente

**Estatísticas atuais:**
- 724 cadastrados (727 no CSV - 3 unificados por nome similar)
- Todos com tokens gerados
- 3 registros com múltiplos emails agregados
- 0 pagamentos (ainda não implementado)

**Importação com detecção de duplicatas:**
- O script `importar_csv.py` detecta duplicatas por email ou nome similar (>85%)
- Unifica registros mantendo dados mais completos
- Agrega múltiplos emails no formato `email1; email2`
- Lista de exceções em `EXCECOES` para nomes similares que são pessoas diferentes

**Dados limpos e normalizados:**
- CEP: formato `00000-000`
- Telefone: formato `(XX) XXXXX-XXXX`
- Estado: UF de 2 letras
- Endereços: sem duplicação de cidade/estado
- Planilha ODS disponível em `data/cadastrados_revisados.ods`

## Briefing Completo

Ver `desenvolvimento/pilotis-briefing.md` para:
- Modelo de dados detalhado
- Fluxo de filiação
- Integração PagBank (endpoints, payloads)
- Integração Brevo (emails)
- Valores de filiação por categoria

## Próximos Passos (Fase 3)

1. Criar `pilotis/routers/filiacao.py`:
   - GET /filiacao/{ano}/{token} — formulário pré-preenchido
   - POST /filiacao/{ano}/{token} — salva e redireciona para pagamento

2. Criar templates:
   - `templates/filiacao.html` — formulário
   - `templates/pagamento.html` — tela com QR Code (fase 4)

3. Testar fluxo completo com token real do banco
