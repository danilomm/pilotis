# Pilotis — Contexto para Claude

Sistema de gestão de filiados do Docomomo Brasil.

## Status Atual

**Fases concluídas:** 1, 2, 3, 4 e 5 ✓
**Pendente:** Configurar credenciais e testar em produção

## Estrutura do Projeto

```
pilotis/
├── pilotis/
│   ├── main.py
│   ├── config.py
│   ├── db.py
│   ├── models.py
│   ├── routers/
│   │   ├── filiacao.py      # Formulário e pagamento
│   │   ├── filiados.py      # Lista pública
│   │   └── webhook.py       # PagBank callbacks
│   ├── services/
│   │   ├── pagbank.py       # API PagBank (PIX)
│   │   ├── email.py         # API Brevo
│   │   └── pdf.py           # Declaração de filiação
│   ├── static/
│   │   ├── logo-docomomo.png
│   │   └── logo-docomomo.jpg
│   └── templates/
│       ├── base.html
│       ├── entrada.html
│       ├── filiacao.html
│       ├── pagamento.html
│       ├── confirmacao.html
│       ├── filiados.html
│       └── emails/
│           ├── confirmacao.html
│           ├── lembrete.html
│           ├── campanha_renovacao.html
│           ├── campanha_convite.html
│           └── campanha_seminario.html
├── scripts/
│   ├── importar_csv.py
│   ├── gerar_tokens.py
│   ├── backup_db.sh
│   ├── enviar_campanha.py
│   └── enviar_lembretes.py
└── data/
    └── pilotis.db
```

## Rotas

| Rota | Função |
|------|--------|
| `GET /filiacao/{ano}` | Entrada por email |
| `GET /filiacao/{ano}/{token}` | Formulário pré-preenchido |
| `POST /filiacao/{ano}/{token}` | Salvar e criar pagamento |
| `GET /filiacao/{ano}/{token}/pagamento` | QR Code PIX |
| `GET /filiados/{ano}` | Lista pública de filiados |
| `POST /webhook/pagbank` | Confirmação de pagamento |

## Categorias

| Interno | Display | Valor |
|---------|---------|-------|
| profissional_internacional | Filiado Pleno Internacional+Brasil | R$ 460 |
| profissional_nacional | Filiado Pleno Brasil | R$ 230 |
| estudante | Filiado Estudante Brasil | R$ 115 |

## Scripts

```bash
# Campanha
python scripts/enviar_campanha.py --ano 2026 --dry-run
python scripts/enviar_campanha.py --ano 2026 --tipo seminario

# Lembretes (rodar via cron)
python scripts/enviar_lembretes.py

# Backup
./scripts/backup_db.sh
```

## Fluxos

**Filiação:**
1. Pessoa acessa `/filiacao/2026` ou clica link do email
2. Informa email → sistema busca/cria cadastro
3. Preenche formulário → cria pagamento pendente
4. Gera PIX (3 dias validade) → mostra QR Code
5. Pagamento confirmado via webhook → email + PDF

**Campanhas:**
- `renovacao`: filiados do ano anterior
- `seminario`: participantes do 16º Seminário não filiados
- `convite`: outros cadastrados

**Lembretes:**
- No dia do vencimento
- Semanalmente após vencer

## Declaração PDF

Gerada com ReportLab, texto justificado:
- Logo JPG (sem transparência)
- Nome, categoria, valor, ano
- Assinatura: Marta Peixoto, Coordenadora, Gestão 2026-2027

## Comandos

```bash
source venv/bin/activate
uvicorn pilotis.main:app --reload
```

## Credenciais (.env)

```
PAGBANK_TOKEN=...
PAGBANK_SANDBOX=true
BREVO_API_KEY=...
BASE_URL=https://pilotis.docomomobrasil.com
```
