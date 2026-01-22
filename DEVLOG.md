# Pilotis — Development Log

## 2026-01-22

### Estrutura de Importação Consolidada ✓

Criada pasta `importacao/` versionada no git para preservar memória do processo:

```
importacao/
├── README.md              # Documentação completa do processo
├── originais/             # CSVs originais do Google Forms
│   ├── Ficha de Inscrição Docomomo Brasil (respostas) - 2022.csv
│   ├── Ficha de Inscrição Docomomo Brasil (respostas) - 2023.csv
│   ├── Docomomo Brasil Filiação 2024 (respostas) - Respostas ao formulário 1.csv
│   └── cadastrados_docomomo_2025_consolidado.csv
├── limpos/                # CSVs limpos e normalizados
│   ├── filiados_2022_limpo.csv
│   └── filiados_2023_limpo.csv
└── scripts/               # Scripts usados na importação
    ├── limpar_csv_2022.php
    ├── limpar_csv_2023.php
    ├── importar_csv_2022.php
    ├── importar_csv_2023.php
    ├── instituicoes_normalizadas.php
    ├── normalizar_2024_2025.php
    ├── atualizar_normalizacao.php
    └── enderecos_2022_manual.php
```

**Por que preservar:** Os arquivos originais e scripts são necessários para:
- Reprocessar dados se descobrir erros
- Servir de template para futuros anos
- Manter rastreabilidade das decisões tomadas

### Normalização de Instituições ✓

**Decisão importante:** Preservar unidades das universidades ao normalizar.

| Original | Normalizado | Motivo |
|----------|-------------|--------|
| Faculdade de Arquitetura e Urbanismo da USP | FAU-USP | São Paulo |
| Instituto de Arquitetura e Urbanismo da USP | IAU-USP | São Carlos (diferente!) |
| PROPAR UFRGS | PROPAR-UFRGS | Programa de pós específico |
| Faculdade de Arquitetura da UFBA | FAUFBA | Unidade específica |

Se a pessoa informa apenas "USP" sem unidade, mantemos "USP".

**Mapa de normalização:** `scripts/instituicoes_normalizadas.php` (~400 entradas)

**Resultado da normalização 2024/2025:**
- 2024: 58 instituições normalizadas
- 2025: 269 instituições normalizadas

### Formações Atualizadas ✓

Adicionadas variantes "em andamento" em `src/config.php`:
- Graduação em andamento
- Especialização / MBA em andamento
- Mestrado em andamento
- Doutorado em andamento

**Decisão:** Não diferenciar mestrado acadêmico de profissional.

### Importação de Dados 2022 ✓

**Arquivo fonte:** `importacao/originais/Ficha de Inscrição Docomomo Brasil (respostas) - 2022.csv`

**Procedimento:** Mesmo das etapas 2023 (ver abaixo).

**Particularidades 2022:**
- Endereço em campo único → criado `enderecos_2022_manual.php` para extrair CEP/cidade/estado
- Telefones múltiplos separados por vírgula → usar apenas o primeiro

**Resultado 2022:**
- 154 filiações importadas (186 no CSV - 32 duplicatas de email)
- 57 pessoas novas criadas
- 1 duplicata consolidada: Ademir Rodrigo Beserra Figueiredo
- Arrecadado: R$ 24.650

### Importação de Dados 2023 ✓

**Arquivo fonte:** `backup-python/desenvolvimento/Ficha de Inscrição Docomomo Brasil (respostas) - 2023.csv`

**Procedimento em 4 etapas:**

> **Nota:** Cada ano pode ter planilhas com colunas diferentes. Os scripts são específicos por ano e servem como template para adaptar a futuros anos.

#### Etapa 1: Limpeza e verificação

Criar script `scripts/limpar_csv_YYYY.php` baseado em `limpar_csv_2023.php`:
1. Identificar colunas da planilha original (índices podem variar)
2. Mapear categorias do ano para formato interno
3. Adaptar valores de filiação do ano

Exemplo para 2023: `scripts/limpar_csv_2023.php`

```bash
php scripts/limpar_csv_2023.php
```

Gera `public/data/filiados_2023_limpo.csv` com:
- Dados normalizados (nomes capitalizados, telefone, CEP, cidade/estado)
- Categorias mapeadas para formato interno
- Colunas de verificação: `email_existe`, `nome_similar`, `acao_sugerida`

**Mapeamento de categorias 2023:**
| Original | Interno | Valor |
|----------|---------|-------|
| Pleno Internacional (R$ 290,00) | profissional_internacional | R$ 290 |
| Pleno Nacional (R$ 145,00) | profissional_nacional | R$ 145 |
| Estudante (R$ 50,00) | estudante | R$ 50 |

#### Etapa 2: Revisão manual do CSV

Abrir `public/data/filiados_2023_limpo.csv` e verificar:
- Linhas com `acao_sugerida = VERIFICAR_MANUAL` (nomes similares)
- Linhas com `acao_sugerida = ATUALIZAR_NOME` (nome no banco menos completo)

#### Etapa 3: Importação

Script: `scripts/importar_csv_2023.php`

```bash
php scripts/importar_csv_2023.php
```

O script:
1. Cria campanha 2023 como 'fechada'
2. Para cada linha do CSV limpo:
   - Se email existe: usa pessoa existente
   - Se nome similar: usa pessoa existente (verificado na etapa 2)
   - Senão: cria pessoa nova
3. Cria filiação 2023 com dados do formulário

**Resultado 2023:**
- 123 filiações importadas
- 25 pessoas novas criadas
- Categorias: 29 estudante, 56 nacional, 38 internacional
- Arrecadado: R$ 27.330

#### Etapa 4: Verificação pós-importação

Buscar duplicatas por nome similar:
```sql
SELECT p1.id, p1.nome, p2.id, p2.nome
FROM pessoas p1, pessoas p2
WHERE p1.id < p2.id
AND (
  LOWER(SUBSTR(p1.nome, 1, INSTR(p1.nome || ' ', ' '))) =
  LOWER(SUBSTR(p2.nome, 1, INSTR(p2.nome || ' ', ' ')))
)
ORDER BY p1.nome;
```

**3 duplicatas consolidadas:**
- Larissa Alves Nasaré / Larissa Nasaré → **Larissa Nasaré** (usa mais no email)
- Marcio Cotrim / Marcio Cotrim Cunha → **Marcio Cotrim Cunha** (mais completo)
- Raquel Byrro / Raquel Elizabeth Byrro Oliveira → **Raquel Elizabeth Byrro Oliveira** (mais completo)

**Consolidação:**
```sql
-- Mover emails e filiações para pessoa principal, depois deletar duplicata
UPDATE emails SET pessoa_id = ? WHERE pessoa_id = ?;
DELETE FROM filiacoes WHERE pessoa_id = ? AND ano = ?; -- se tiver duplicata
UPDATE filiacoes SET pessoa_id = ? WHERE pessoa_id = ?;
DELETE FROM pessoas WHERE id = ?;
```

**Resultado final:** 791 pessoas (794 - 3 consolidadas)

### Métricas de Campanhas Fechadas ✓

Adicionadas métricas detalhadas para campanhas fechadas:
- **Emails enviados**: armazenado na tabela `campanhas`
- **Novos**: primeira filiação da pessoa
- **Retornaram**: filiação anterior, mas não no ano imediatamente anterior
- **Renovaram**: filiação no ano anterior
- **Não renovaram**: filiação no ano anterior, sem filiação no ano atual

Percentuais:
- Novos/Retornaram/Renovaram: % do total de filiados do ano
- Não renovaram: % dos filiados do ano anterior

### Consolidação de Dados Históricos (ZIPs) ✓

Processados 3 arquivos ZIP do Google Drive com dados históricos:
- `filiação 2018-*.zip` — certificados e comprovantes 2018
- `filiação 2019-*.zip` — certificados e comprovantes 2019
- `FILIAÇÃO-*.zip` — dados gerais 2015-2023

**Dados extraídos e salvos:**
- `importacao/consolidado_planilhas.csv` — 388 pessoas únicas das planilhas
- `importacao/certificados_emitidos.csv` — 224 certificados (pagamentos confirmados)
  - 2018: 20 certificados
  - 2019: 94 certificados (dupla/pleno/estudante)
  - 2021: 110 certificados

**Cruzamento com banco:**
- 382 já existiam (email bate)
- 3 duplicatas consolidadas (mesmo nome, email diferente)
- 4 novos cadastros adicionados

### Consolidação de Duplicatas ✓

**Duplicatas por nome exato (41):** Consolidadas automaticamente via `scripts/consolidar_duplicatas.php`

**Duplicatas por revisão manual (11):**

Por nome similar:
- Fernando Guillermo Vázquez/Vazquez Ramos
- Isabela (Ferreira) Milagre
- Lúcia Siqueira/Squeira de Queiroz Varella
- Margareth (Campos) da Silva Pereira
- Mirthes (Ivany Soares) Baffi

Por acento/typo:
- Luis/Luís Salvador Petrucci Gnoato
- Jose/José Carlos Huapaya Espinoza
- Lucia/Lúcia Moreira do Nascimento
- Marcia/Márcia Gadelha Cavalcante
- Erica/Érica Maria de Barros Martins
- Evelyn Furquim Werneck Lima(-C.)

### Novos Scripts de Verificação ✓

- `scripts/verificar_emails.php` — typos de domínio, duplicados, inválidos
- `scripts/revisar_nomes.php` — VERIFICAR_MANUAL, nomes curtos, estranhos
- `scripts/emails_typos.php` — mapa de typos conhecidos (gmal→gmail, etc)
- `scripts/consolidar_duplicatas.php` — unifica pessoas por nome exato

### Estado Final do Banco

| Métrica | Valor |
|---------|-------|
| Pessoas | 1.070 |
| Emails | 1.217 |
| Filiações | 1.674 |
| Pessoas com 2+ emails | 132 |

---

## 2026-01-20

### Testes do Fluxo de Pagamento ✓

**PIX:**
- ✅ Geração de QR Code funcionando
- ✅ Código copia-cola funcionando

**Boleto:**
- ✅ Geração de boleto funcionando
- ⚠️ PDF do sandbox mostra nome fictício ("Caroline Luz") — limitação do ambiente de teste
- 📋 **Pendente:** Testar em produção para confirmar que nome real aparece

**Cartão de Crédito:**
- ✅ Criptografia PagBank.js funcionando
- ✅ Pagamento aprovado imediatamente
- ✅ Email de confirmação enviado com PDF anexo

### Correções de Segurança ✓

- Fluxo de entrada alterado: agora envia link por email em vez de redirecionar direto
- Evita que alguém veja dados de terceiros apenas informando o email
- Nova view `email_enviado.php` com instruções

### Correções de Bugs ✓

- `WebhookController`: corrigido nomes de tabelas (`cadastrados` → `pessoas`, `pagamentos` → `filiacoes`)
- `config.php`: corrigido resolução de caminho relativo do banco de dados
- `db.php`: corrigido nome de coluna na tabela log (`pessoa_id` → `cadastrado_id`)
- `routes.php`: função `e()` agora aceita valores null

### Melhorias no Formulário ✓

- Todos os campos obrigatórios marcados com asterisco (*)
- Nota explicativa sobre campos obrigatórios
- Validação server-side de todos os campos obrigatórios
- CPF obrigatório (exigência do PagBank)
- Explicação sobre endereço de correspondência

### PDF da Declaração ✓

- Instalado TCPDF via Composer para geração profissional
- Corrigidos acentos: DECLARAÇÃO, período, é, Gestão, Associação
- Autoload do Composer adicionado ao index.php

### Limpeza ✓

- Removido `public/data/pilotis.db` (cópia antiga em local inseguro)
- Adicionado `public/data/` ao `.gitignore`

---

## Pendências

### Para testar em produção:
- [ ] Boleto: confirmar que nome real aparece no PDF (não o fictício do sandbox)

### Para testar localmente:
- [ ] Painel Admin (`/admin`)
- [ ] Lista pública de filiados (`/filiados/2026`)
- [ ] Scripts de campanha (`scripts/enviar_campanha.php`)
- [ ] Scripts de lembretes (`scripts/enviar_lembretes.php`)

### Para deploy:
- [ ] Upload via FTP para KingHost
- [ ] Configurar `.env` com credenciais de produção
- [ ] Configurar `PAGBANK_SANDBOX=false`
- [ ] Testar webhook em produção
- [ ] Configurar cron para lembretes

---

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
