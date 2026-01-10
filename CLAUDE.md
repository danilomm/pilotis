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
│   ├── importar_csv.py   # Importa cadastrados
│   └── gerar_tokens.py   # Gera tokens únicos
├── data/
│   └── pilotis.db        # Banco SQLite (727 cadastrados)
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

# Reimportar dados (limpa banco antes)
rm data/pilotis.db
python scripts/importar_csv.py desenvolvimento/cadastrados_docomomo_2025_consolidado.csv
python scripts/gerar_tokens.py
```

## Banco de Dados

**Tabelas:**
- `cadastrados` — dados pessoais + token
- `pagamentos` — histórico de pagamentos por ano
- `log` — registro de eventos

**View:**
- `filiados` — cadastrados com pagamento do ano corrente

**Estatísticas atuais:**
- 727 cadastrados importados
- Todos com tokens gerados
- 0 pagamentos (ainda não implementado)

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
