# Pilotis — Development Log

## 2026-01-15

### Consolidação de Dados 2024 ✓

Análise completa dos filiados 2024:
- Cruzamento de 3 fontes: site WordPress, formulário Google, PagBank
- Identificadas 7 pessoas no site sem registro de pagamento (removidas)
- Identificada 1 pessoa com pagamento que não estava no site (Luis Salvador Petrucci Gnoato - adicionada)

**Atualizações no site WordPress:**
- Removidas 7 pessoas sem pagamento da página 2024
- Adicionado Luis Salvador Petrucci Gnoato (Internacional)
- Criada página "Filiados 2025" com 167 membros
- Adicionado menu sob "Filie-se!"

**Consolidação no banco local:**
- 89 pagamentos 2024 criados para cadastrados existentes (anterior)
- 37 pagamentos 2024 adicionados para mais cadastrados existentes
- 47 novos cadastrados inseridos com categoria "cadastrado"
- Total: 771 cadastrados, 173 pagamentos 2024

**Totais por categoria:**
| Categoria | Quantidade |
|-----------|------------|
| cadastrado | 318 |
| participante_seminario | 286 |
| profissional_nacional | 72 |
| profissional_internacional | 56 |
| estudante | 39 |

---

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

### Preparação para Deploy ✓

- Servidor: KingHost (via Labasoft), FTP funciona, SSH não
- URL planejada: `https://pilotis.docomomobrasil.com`
- Tecnologia: mod_wsgi (Apache) + Python

**Arquivos criados em `deploy/`:**
- `pilotis.wsgi` — Entry point WSGI com adaptador a2wsgi (ASGI→WSGI)
- `.env.producao` — Template de configuração para produção
- `DEPLOY.md` — Instruções completas de deploy via FTP
- `preparar_deploy.sh` — Script que prepara arquivos para upload
- `servidor.yaml` — Configuração e credenciais do servidor

**Pendente do provedor (Labasoft):**
- Criar subdomínio `pilotis.docomomobrasil.com`
- Configurar VHost Apache para WSGI
- Confirmar Python 3.10+ disponível
- Instalar dependências (requirements.txt)
- Criar diretório `/dados_privados/` fora do www

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

1. **Aguardando Labasoft:** Configurar subdomínio e VHost
2. **Deploy:** Executar `./deploy/preparar_deploy.sh` e fazer upload via FTP
3. **Produção:** Configurar `.env` com credenciais reais (PagBank, Brevo, senha admin)
4. **Webhook:** Configurar URL de produção no painel PagBank
5. **Cron:** Configurar `enviar_lembretes.py` no servidor
6. **Campanha:** Definir valores e disparar emails para filiação 2026
