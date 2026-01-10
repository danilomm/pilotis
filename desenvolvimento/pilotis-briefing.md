# Pilotis — Briefing para Desenvolvimento

Sistema de gestão de filiados do Docomomo Brasil.

---

## Filosofia

**Unix puro:**

- Fazer uma coisa bem feita
- Texto como interface universal (CSV, JSON, logs legíveis)
- Componentes pequenos que se compõem
- Configuração em arquivos simples (.env)
- Falhar cedo e ruidosamente
- Sem mágica — código explícito e legível

---

## Glossário

| Termo | Significado |
|-------|-------------|
| **Cadastrado** | Qualquer pessoa no banco de dados |
| **Filiado** | Cadastrado adimplente no ano corrente |
| **Participante do seminário** | Pessoa que participou do seminário mas não é filiada |

Um cadastrado se torna filiado quando paga a anuidade. No ano seguinte, volta a ser cadastrado até pagar novamente.

---

## Stack

- **Python 3.11+**
- **FastAPI** (backend)
- **SQLite** (banco de dados — arquivo simples, `cp` faz backup)
- **Jinja2** (templates HTML)
- **httpx** (requisições async para PagBank e Brevo)
- **Pico CSS ou Simple.css** (estilo mínimo, sem build)

Sem ORM pesado. SQLModel ou SQL puro com parâmetros.

---

## Modelo de Dados

```sql
CREATE TABLE cadastrados (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    nome TEXT NOT NULL,
    email TEXT NOT NULL,  -- pode conter múltiplos separados por "; "
    cpf TEXT,
    telefone TEXT,
    endereco TEXT,
    cep TEXT,
    cidade TEXT,
    estado TEXT CHECK(length(estado) <= 2 OR estado IS NULL),
    pais TEXT DEFAULT 'Brasil',
    profissao TEXT,
    formacao TEXT,
    instituicao TEXT,
    categoria TEXT CHECK(categoria IN ('estudante', 'profissional', 'profissional_internacional', 'participante_seminario')),
    data_cadastro DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_atualizacao DATETIME,
    token TEXT UNIQUE,
    token_expira DATETIME,
    observacoes TEXT
);

CREATE TABLE pagamentos (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cadastrado_id INTEGER NOT NULL REFERENCES cadastrados(id),
    ano INTEGER NOT NULL,
    valor DECIMAL(10,2) NOT NULL,
    status TEXT DEFAULT 'pendente' CHECK(status IN ('pendente', 'pago', 'cancelado', 'expirado')),
    metodo TEXT CHECK(metodo IN ('pix', 'boleto', 'cartao')),
    pagbank_order_id TEXT,
    pagbank_charge_id TEXT,
    data_criacao DATETIME DEFAULT CURRENT_TIMESTAMP,
    data_pagamento DATETIME,
    data_vencimento DATETIME,
    UNIQUE(cadastrado_id, ano)
);

CREATE TABLE log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    tipo TEXT NOT NULL,
    cadastrado_id INTEGER,
    mensagem TEXT
);

CREATE INDEX idx_pagamentos_status ON pagamentos(status);
CREATE INDEX idx_pagamentos_ano ON pagamentos(ano);
CREATE INDEX idx_cadastrados_email ON cadastrados(email);
CREATE INDEX idx_cadastrados_token ON cadastrados(token);
```

**Filiado é uma view, não uma tabela:**

```sql
CREATE VIEW filiados AS
SELECT c.* FROM cadastrados c
JOIN pagamentos p ON c.id = p.cadastrado_id
WHERE p.ano = strftime('%Y', 'now') AND p.status = 'pago';
```

---

## Base de Dados Inicial

Arquivo `cadastrados_docomomo_2025_consolidado.csv` contém 727 registros:

| Categoria | Quantidade |
|-----------|------------|
| estudante | 39 |
| profissional | 72 |
| profissional_internacional | 56 |
| participante_seminario | 560 |

**Filiados 2025:** 167 (estudante + profissional + profissional_internacional)
**Potenciais novos filiados:** 560 (participantes do seminário)

Colunas: nome, email, cpf, telefone, endereco, cep, cidade, estado, pais, profissao, formacao, instituicao, categoria, fonte, seminario_2025

Observações:
- Campo `email` pode conter múltiplos emails separados por `; `
- Campo `seminario_2025` indica se participou do seminário (sim/não)
- Muitos registros de `participante_seminario` têm apenas nome, email e telefone

---

## Fluxo Principal

```
1. Admin importa base existente (CSV)

2. Sistema gera token único para cada cadastrado

3. Admin dispara campanha de emails (via Brevo)
   → Filiados 2025: mensagem de renovação
   → Participantes do seminário: mensagem de convite à filiação
   → Cada email contém link personalizado:
     https://pilotis.docomomobrasil.com/filiacao/2026/{token}

4. Cadastrado clica no link
   → Formulário pré-preenchido com dados atuais
   → Atualiza/completa o que precisar
   → Submete

5. Sistema salva dados atualizados imediatamente
   → Cria cobrança no PagBank (PIX + boleto)
   → Exibe tela com QR Code e link do boleto

6. Se paga na hora:
   → Webhook do PagBank notifica
   → Status → 'pago'
   → Cadastrado vira filiado 2026
   → Email de confirmação

7. Se não paga:
   → Dados ficam salvos (não se perdem!)
   → Lembrete após 3 dias
   → Lembrete após 7 dias
   → Lembrete após 15 dias (último aviso)
```

---

## Integração PagBank

**API:** https://api.pagseguro.com (produção) ou https://sandbox.api.pagseguro.com (testes)

**Autenticação:** Bearer token no header

**Endpoints usados:**

- `POST /orders` — criar pedido com PIX e/ou boleto
- `GET /orders/{id}` — consultar status
- Webhook — receber notificações de pagamento

**Exemplo de payload PIX:**

```json
{
  "reference_id": "PILOTIS-{cadastrado_id}-{ano}",
  "customer": {
    "name": "Nome do Cadastrado",
    "email": "email@exemplo.com",
    "tax_id": "12345678900"
  },
  "items": [{
    "reference_id": "filiacao-2026",
    "name": "Filiação Docomomo Brasil 2026",
    "quantity": 1,
    "unit_amount": 10000
  }],
  "qr_codes": [{
    "amount": {"value": 10000},
    "expiration_date": "2026-02-15T23:59:59-03:00"
  }],
  "notification_urls": ["https://pilotis.docomomobrasil.com/webhook/pagbank"]
}
```

**Webhook handler:**

```python
@router.post("/webhook/pagbank")
async def webhook(request: Request):
    payload = await request.json()
    reference_id = payload.get("reference_id", "")
    # formato: PILOTIS-{cadastrado_id}-{ano}
    
    charges = payload.get("charges", [])
    if charges and charges[0].get("status") == "PAID":
        # atualizar pagamento no banco
        # enviar email de confirmação
    
    return {"status": "ok"}
```

---

## Integração Brevo (Email)

**API:** https://api.brevo.com/v3/smtp/email

**Limite gratuito:** 300 emails/dia

**Exemplo de envio:**

```python
async def enviar_email(para: str, assunto: str, html: str):
    async with httpx.AsyncClient() as client:
        response = await client.post(
            "https://api.brevo.com/v3/smtp/email",
            headers={
                "api-key": settings.BREVO_API_KEY,
                "Content-Type": "application/json",
            },
            json={
                "sender": {"name": "Docomomo Brasil", "email": "tesouraria@docomomobrasil.com"},
                "to": [{"email": para}],
                "subject": assunto,
                "htmlContent": html,
            }
        )
        return response.status_code == 201
```

---

## Estrutura de Diretórios

```
pilotis/
├── pilotis/
│   ├── __init__.py
│   ├── main.py           # FastAPI app
│   ├── config.py         # settings do .env
│   ├── db.py             # conexão SQLite
│   ├── models.py         # dataclasses ou SQLModel
│   │
│   ├── routers/
│   │   ├── filiacao.py   # formulário público
│   │   ├── webhook.py    # PagBank callbacks
│   │   └── admin.py      # painel admin (futuro)
│   │
│   ├── services/
│   │   ├── pagbank.py    # integração API
│   │   └── email.py      # envio via Brevo
│   │
│   └── templates/
│       ├── base.html
│       ├── filiacao.html
│       ├── pagamento.html
│       └── emails/
│           ├── campanha_renovacao.html
│           ├── campanha_convite.html
│           ├── confirmacao.html
│           └── lembrete.html
│
├── scripts/
│   ├── importar_csv.py       # importa cadastrados
│   ├── gerar_tokens.py       # gera tokens únicos
│   ├── enviar_campanha.py    # dispara emails
│   └── enviar_lembretes.py   # cron diário
│
├── data/
│   ├── pilotis.db                            # banco SQLite
│   └── cadastrados_docomomo_2025_consolidado.csv  # base inicial
│
├── .env.example
├── requirements.txt
└── README.md
```

---

## Scripts CLI (filosofia Unix)

Cada script faz uma coisa:

```bash
# Importar cadastrados de um CSV
python scripts/importar_csv.py data/cadastrados_docomomo_2025_consolidado.csv

# Gerar tokens para quem não tem
python scripts/gerar_tokens.py

# Enviar campanha de filiação (dois tipos de mensagem)
python scripts/enviar_campanha.py --ano 2026 --dry-run
python scripts/enviar_campanha.py --ano 2026

# Enviar lembretes (rodar via cron diariamente)
python scripts/enviar_lembretes.py

# Exportar filiados do ano
python scripts/exportar_filiados.py 2026 > filiados_2026.csv

# Consultar status de um cadastrado
python scripts/consultar.py email@exemplo.com
```

---

## Configuração (.env)

```bash
# Banco
DATABASE_PATH=data/pilotis.db

# PagBank
PAGBANK_TOKEN=seu_token_aqui
PAGBANK_SANDBOX=true

# Email (Brevo - ex-Sendinblue)
# Plano gratuito: 300 emails/dia
# Criar conta em https://www.brevo.com
BREVO_API_KEY=sua_chave_aqui
EMAIL_FROM=Docomomo Brasil <tesouraria@docomomobrasil.com>

# App
BASE_URL=https://pilotis.docomomobrasil.com
SECRET_KEY=chave_secreta_para_tokens

# Valores de filiação (centavos)
VALOR_ESTUDANTE=11500
VALOR_PROFISSIONAL=23000
VALOR_INTERNACIONAL=46000
```

---

## Valores de Filiação 2025 (referência)

| Categoria | Valor |
|-----------|-------|
| Estudante | R$ 115,00 |
| Profissional (nacional) | R$ 230,00 |
| Profissional (internacional) | R$ 460,00 |

---

## Ordem de Desenvolvimento

1. **Estrutura básica**
   - Criar diretórios
   - config.py + .env
   - db.py + schema SQL
   - models.py

2. **Importação de dados**
   - scripts/importar_csv.py
   - scripts/gerar_tokens.py

3. **Formulário de filiação**
   - rota GET /filiacao/{ano}/{token}
   - template com formulário pré-preenchido
   - rota POST para salvar

4. **Integração PagBank**
   - services/pagbank.py
   - criar cobrança após submit do formulário
   - template pagamento.html com QR Code

5. **Webhook**
   - rota POST /webhook/pagbank
   - atualizar status no banco

6. **Emails**
   - services/email.py (via Brevo)
   - templates de email (renovação vs convite)
   - scripts/enviar_campanha.py
   - scripts/enviar_lembretes.py

7. **Admin (futuro)**
   - dashboard com estatísticas
   - lista de cadastrados/filiados
   - exportação

8. **WhatsApp (futuro)**
   - avaliar se taxa de pagamento por email é suficiente
   - se precisar: Twilio ou WhatsApp Business API
   - requer conta Business verificada pela Meta + templates pré-aprovados

---

## Desenvolvimento Local

```bash
# Setup
python -m venv venv
source venv/bin/activate
pip install -r requirements.txt
cp .env.example .env
# editar .env com credenciais sandbox

# Criar banco e importar dados
python -c "from pilotis.db import init_db; init_db()"
python scripts/importar_csv.py data/cadastrados_docomomo_2025_consolidado.csv
python scripts/gerar_tokens.py

# Rodar
uvicorn pilotis.main:app --reload

# Testar
open http://localhost:8000
```

---

## Notas Importantes

1. **Dados nunca se perdem** — o submit do formulário salva antes de mostrar pagamento

2. **Token é único por cadastrado**, não por campanha — simplifica

3. **Webhook deve ser idempotente** — PagBank pode enviar mais de uma vez

4. **Logs em texto** — print ou logging, nada de ferramentas complexas

5. **SQLite é suficiente** — ~700 cadastrados, baixa concorrência

6. **Testar no sandbox** antes de produção — PagBank tem ambiente de testes completo

7. **Dois tipos de mensagem na campanha:**
   - Filiados 2025: "Renove sua filiação"
   - Participantes do seminário: "Convidamos você a se filiar"

8. **Campo email pode ter múltiplos valores** separados por `; ` — enviar para todos
