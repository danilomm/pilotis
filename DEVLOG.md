# Pilotis — Development Log

## 2026-01-16

### Importação de Dados 2024 ✓

**Arquivo fonte:** `desenvolvimento/filiacao_2024_final.csv`

**Metodologia:**
1. Para cada registro com status "pago":
   - Buscar pessoa por email
   - Se não encontrar, buscar por nome **manualmente**
   - Criar filiação 2024 com dados do formulário

2. Para "pago_sem_form" (6 pessoas já no DB):
   - Buscar pessoa por email/nome
   - Criar filiação 2024 com nota "Não preencheu formulário em 2024"

3. Para "novo_sem_form" (6 pessoas novas):
   - Criar pessoa nova
   - Criar filiação 2024 com nota "Não preencheu formulário em 2024"

4. Ignorar "nao_pago" (1 pessoa)

**Resultado:**
- 166 filiações "pago" importadas (55 pessoas novas criadas)
- 6 filiações "pago_sem_form" importadas
- 6 filiações "novo_sem_form" importadas (6 pessoas novas)
- Total: 178 filiações 2024, 786 pessoas no banco

**Filiados 2024 por categoria:**
| Categoria | Qtd | Valor |
|-----------|-----|-------|
| Internacional | 67 | R$ 26.800 |
| Nacional | 52 | R$ 10.400 |
| Estudante | 59 | R$ 5.900 |
| **Total** | **178** | **R$ 43.100** |

### Campo `seminario` na tabela `filiacoes` ✓

- Adicionado campo booleano `seminario` para marcar participantes do seminário
- Populado com 401 participantes do 16º Seminário (2025) via planilha `seminario-docomomo-2025-inscritos.xlsx`
- 7 emails adicionados para pessoas com emails diferentes na planilha

**Filiações 2025:**
| Categoria | Não Seminário | Seminário | Total |
|-----------|---------------|-----------|-------|
| estudante | 21 | 18 | 39 |
| nao_filiado | 308 | 311 | 619 |
| profissional_internacional | 24 | 31 | 55 |
| profissional_nacional | 32 | 41 | 73 |
| **Total** | **385** | **401** | **786** |

### Atualização WordPress 2024 ✓

- Adicionados: Celma Chaves Pont Vidal, Luís Salvador Petrucci Gnoato
- Removidas: Maria Cristina Da Silva Leme, Maria Cristina Werneck (sem pagamento encontrado)
- Nomes removidos salvos em `desenvolvimento/verificar_pagamento_2024.md`

### Consolidação de Duplicatas ✓

Verificação manual de nomes duplicados no banco. Critério: sempre manter o nome mais completo.

**16 duplicatas consolidadas:**
- Bianca Oresko → Bianca de Freitas Oresko
- Fernando G. Vazquez Ramos → Fernando Guillermo Vázquez Ramos
- Luiz Amorim → Luiz Manuel do Eirado Amorim
- Maisa F. Almeida → Maisa Fonseca de Almeida
- Manuella Andrade → Manuella Marianna Carvalho Rodrigues de Andrade
- Marcos Petroli → Marcos Amado Petroli
- Marcos Cereto → Marcos Paulo Cereto
- Marcus Deusdedit → Marcus Vinícius Barbosa Deusdedit
- Márcio Fontão → Márcio Barbosa Fontão
- Renato Anelli → Renato Luiz Sobral Anelli
- Thiago Turchi → Thiago Pacheco Turchi
- Yan Azevedo → Yan Fábio Leite de Azevedo
- Andrea Tourinho → Andréa de Oliveira Tourinho
- Denise Nunes → Denise Vianna Nunes
- Mariana Brandão → Mariana Guimaraes Brandao
- Mariana Jardim → Mariana Comerlato Jardim

**Resultado:** 785 → 769 pessoas

**Backups:**
- `data/pilotis_backup_pre_import_2024.db`
- `data/pilotis_backup_pos_import_2024.db`
- `data/pilotis_backup_pos_consolidacao.db`

---

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
- 2 pessoas adicionadas manualmente (match de nome falso positivo corrigido)
- Total: 773 cadastrados, 175 pagamentos 2024

**Discrepância analisada:**
- PagBank tinha 175 aprovados, mas 1 era duplicata (Cristiane Galhardo Biazin pagou 2x)
- 2 pessoas foram incorretamente ignoradas por match de nome falso positivo (João Marcello e Ana Karina)
- Corrigido: 174 únicos do PagBank + 1 de fonte anterior = 175 pagamentos

**Totais por categoria:**
| Categoria | Quantidade |
|-----------|------------|
| cadastrado | 320 |
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
