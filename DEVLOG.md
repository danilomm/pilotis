# Pilotis — Development Log

## 2026-01-12

### Fase 3: Formulário de Filiação ✓

- Tela de entrada por email (`/filiacao/{ano}`)
- Formulário pré-preenchido com token (`/filiacao/{ano}/{token}`)
- Campos obrigatórios: nome, email, cpf, telefone, endereço, cep, cidade, estado, país, categoria
- Campo observações para mensagens à diretoria
- Branding Docomomo: logo, cores verdes, créditos no rodapé

### Fase 4: Integração PagBank ✓

- `services/pagbank.py` — criar cobrança PIX, consultar pedido
- Tela de pagamento com QR Code e código copia-cola
- Webhook `/webhook/pagbank` para confirmação de pagamento
- Vencimento configurável (3 dias padrão)

### Fase 5: Emails e PDF ✓

- `services/email.py` — integração Brevo (300 emails/dia gratuito)
- `services/pdf.py` — geração de declaração de filiação (texto justificado)
- Templates de email:
  - `confirmacao.html` — após pagamento, com PDF anexo
  - `lembrete.html` — pagamento pendente
  - `campanha_renovacao.html` — para filiados existentes
  - `campanha_convite.html` — para cadastrados
  - `campanha_seminario.html` — para participantes do 16º Seminário

### Página Pública de Filiados ✓

- Rota `/filiados/{ano}` lista filiados adimplentes
- Agrupado por categoria, ordem alfabética
- Formato: **Nome** (Instituição)

### Scripts CLI ✓

- `scripts/enviar_campanha.py --ano 2026 [--tipo renovacao|seminario|convite|todos] [--dry-run]`
- `scripts/enviar_lembretes.py [--dry-run]`

---

## 2026-01-10

### Fase 1: Estrutura Básica ✓

- FastAPI + SQLite + Pico CSS
- Dataclasses Cadastrado, Pagamento
- Schema com tabelas cadastrados, pagamentos, log + view filiados

### Fase 2: Importação de Dados ✓

- 724 cadastrados importados (727 - 3 unificados por duplicata)
- Detecção de duplicatas por email e nome similar (>85%)
- Tokens únicos gerados para todos
- Normalização: CEP, telefone, estado, país

---

## Próximos Passos

1. Configurar `.env` com credenciais (PagBank, Brevo)
2. Testar no sandbox do PagBank
3. Definir período da campanha 2026
4. Configurar cron para `enviar_lembretes.py`
