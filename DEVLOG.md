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
- `scripts/importar_csv.py` — importa cadastrados de CSV com detecção de duplicatas
- `scripts/gerar_tokens.py` — gera tokens únicos (secrets.token_urlsafe)

**Lógica de unificação implementada:**
- Detecta duplicatas por email (individual, quando há múltiplos separados por "; ")
- Detecta duplicatas por similaridade de nome (>85%)
- Mantém dados mais completos ao unificar
- Agrega todos os emails em um único campo
- Prioriza categoria de filiado sobre participante

**Registros unificados (3):**
- Ana Carolina Pellegrini + Ana Carolina Santos Pellegrini → profissional_internacional
- Hermógenes Moussallem Vasconcelos + Hermógenes Moussallem-vasconcelos
- Rafael D'andrea + Rafael M Dandrea

**Exceções (não unificados):**
- Adriana Monzillo de Oliveira ≠ Luciana Monzillo de Oliveira (pessoas diferentes)

**Dados finais de `desenvolvimento/cadastrados_docomomo_2025_consolidado.csv`:**

| Categoria | Quantidade |
|-----------|------------|
| participante_seminario | 557 |
| profissional | 72 |
| profissional_internacional | 56 |
| estudante | 39 |
| **Total** | **724** (727 no CSV - 3 unificados) |

Todos os cadastrados possuem tokens únicos gerados.

---

### Limpeza e Normalização de Dados ✓

Revisão geral da tabela com 81 correções:

**Normalizações aplicadas:**
- CEP: formato `00000-000`, extraído do endereço quando duplicado
- Telefone: formato `(XX) XXXXX-XXXX`
- Estado: UF de 2 letras
- País: `Brazil` → `Brasil`, `Italia` → `Itália`
- Endereço: remoção de cidade/estado duplicados, padronização de abreviações

**Exportação:**
- Arquivo `data/cadastrados_revisados.ods` para revisão manual

---

### Coluna seminario_2025 ✓

Adicionada coluna `seminario_2025 BOOLEAN` ao banco para identificar quem participou do seminário:
- 663 participaram (incluindo 106 filiados)
- 61 não participaram (filiados que não foram ao seminário)

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
