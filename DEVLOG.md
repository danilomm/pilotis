# Pilotis — Development Log

## 2026-01-13

### Painel Administrativo ✓

- Login com senha (configurável via `.env`)
- Rota `/admin` com estatísticas (pagos, pendentes, arrecadado)
- Busca de pessoa por nome/email
- Edição de cadastros (todos os campos)
- Marcar pagamento como pago manualmente
- Cadastrar nova pessoa + pagamento
- Excluir pagamento ou pessoa
- Download do banco (.db) para backup
- Download de tabela CSV para compartilhar com diretoria

### Correção de Bugs ✓

- Bug de valores: código multiplicava por 100 quando valor já estava em centavos
- Afetava PIX, boleto e cartão (gerava R$ 46.000 em vez de R$ 460)
- Corrigido em `filiacao.py` e `webhook.py`

### Melhorias ✓

- Email de confirmação com PDF enviado no pagamento por cartão
- Script de backup lê caminho do banco do `.env`
- Documentação de segurança (banco fora do diretório web)
- Formulário de edição no admin (em vez de visualização somente)

### Atualização de Dados 2025 ✓

- Importados data e método de pagamento das planilhas PagBank
- 151 pagamentos atualizados (PIX: 100, Cartão: 58, Boleto: 9)

---

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

1. Definir hospedagem (aguardando retorno do provedor)
   - Se tiver Python: deploy direto
   - Se tiver Node.js: migração ~1-2 dias
   - Se não: VPS ou PaaS
2. Configurar `.env` de produção (PagBank produção, Brevo)
3. Definir valores e período da campanha 2026
4. Configurar cron para `enviar_lembretes.py`
5. Configurar webhook PagBank (URL de produção)
