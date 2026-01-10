# Pilotis — Development Log

## 2026-01-10

### Fase 1: Estrutura Básica ✓

Criada a estrutura inicial do projeto:

```
pilotis/
├── pilotis/
│   ├── __init__.py
│   ├── main.py           # FastAPI app
│   ├── config.py         # Configurações do .env
│   ├── db.py             # Conexão SQLite + schema
│   ├── models.py         # Dataclasses Cadastrado, Pagamento
│   ├── routers/
│   ├── services/
│   └── templates/
│       └── base.html     # Template base com Pico CSS
├── scripts/
├── data/
│   └── pilotis.db
├── venv/
├── .env
├── .env.example
└── requirements.txt
```

**Decisões técnicas:**
- FastAPI como framework web
- SQLite como banco (arquivo simples, fácil backup)
- Pico CSS para estilo mínimo sem build
- Dataclasses puras (sem ORM pesado)

**Testado:** Servidor inicia corretamente em http://localhost:8000

---

### Fase 2: Importação de Dados ✓

Criados scripts CLI:
- `scripts/importar_csv.py` — importa cadastrados de CSV
- `scripts/gerar_tokens.py` — gera tokens únicos (secrets.token_urlsafe)

**Dados importados de `desenvolvimento/cadastrados_docomomo_2025_consolidado.csv`:**

| Categoria | Quantidade |
|-----------|------------|
| participante_seminario | 560 |
| profissional | 72 |
| profissional_internacional | 56 |
| estudante | 39 |
| **Total** | **727** |

Todos os cadastrados possuem tokens únicos gerados.

---

### Git e Backup ✓

- Inicializado repositório git
- Criado `.gitignore` (ignora `venv/`, `.env`, `data/*.db`)
- Criado `scripts/backup_db.sh` — dump SQL versionável do banco
- Primeiro commit: `2c2d942`

**Estratégia de backup:**
- Banco SQLite (`data/pilotis.db`) fica fora do git
- Dump SQL (`data/backup.sql`) é versionado
- Antes de commits, rodar `./scripts/backup_db.sh`

---

## Próximas Fases

### Fase 3: Formulário de Filiação (pendente)
- Rota GET /filiacao/{ano}/{token}
- Template com formulário pré-preenchido
- Rota POST para salvar dados

### Fase 4: Integração PagBank (pendente)
- Service para criar cobranças PIX
- Tela de pagamento com QR Code
- Webhook para receber confirmações

### Fase 5: Emails via Brevo (pendente)
- Service de envio de emails
- Templates de campanha
- Scripts para envio em massa
