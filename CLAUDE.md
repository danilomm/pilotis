# Pilotis — Contexto para Claude

Sistema de gestão de filiados do Docomomo Brasil.

## Status Atual

**Fases concluídas:** 1, 2 e 3 de 5
**Próxima fase:** 4 (Integração PagBank)

## Estrutura do Projeto

```
pilotis/
├── pilotis/              # Módulo Python principal
│   ├── main.py           # FastAPI app
│   ├── config.py         # Settings do .env
│   ├── db.py             # SQLite + schema
│   ├── models.py         # Dataclasses
│   ├── routers/
│   │   └── filiacao.py   # Formulário de filiação
│   ├── services/         # PagBank, Email (a implementar)
│   ├── static/
│   │   └── logo-docomomo.png
│   └── templates/
│       ├── base.html
│       ├── filiacao.html
│       ├── pagamento.html
│       └── confirmacao.html
├── scripts/
│   ├── importar_csv.py
│   ├── gerar_tokens.py
│   └── backup_db.sh
├── data/
│   ├── pilotis.db
│   ├── backup.sql
│   └── cadastrados_revisados.ods
└── desenvolvimento/
    ├── pilotis-briefing.md
    ├── Logo-Docomomo-Br-768x184.png
    └── seminario-docomomo-2025-inscritos.xlsx
```

## Categorias de Filiação

| Categoria (interno) | Nome no formulário | Valor |
|---------------------|-------------------|-------|
| profissional_internacional | Docomomo. Filiado Pleno Internacional + Brasil | R$ 460,00 |
| profissional_nacional | Docomomo. Filiado Pleno Brasil | R$ 230,00 |
| estudante | Docomomo. Filiado Estudante (Graduação/Pós) Brasil | R$ 115,00 |
| participante_seminario | (não aparece no formulário) | — |
| cadastrado | (não aparece no formulário) | — |

## Banco de Dados

**Tabelas:**
- `cadastrados` — dados pessoais + token + seminario_2025 + observacoes_filiado
- `pagamentos` — histórico de pagamentos por ano
- `log` — registro de eventos

**Campos obrigatórios no formulário:**
- nome, email, cpf, telefone, endereco, cep, cidade, estado, pais, categoria

**Campos opcionais:**
- profissao, formacao, instituicao, observacoes_filiado

## Referência para Emails (Fase 5)

### Tom e estrutura sugerida:

**Email para filiados existentes (renovação):**
- Saudação e votos para o ano
- Resumo de eventos/atividades do ano
- Benefícios da filiação
- Link personalizado para renovação
- Valores e categorias
- Assinatura da diretoria

**Email para novos (participantes do seminário):**
- Convite para se filiar
- Benefícios da filiação
- Link personalizado para filiação
- Valores e categorias

**Benefícios a destacar (do email antigo):**
- Descontos em eventos do Docomomo Brasil e núcleos regionais
- Para internacional: Docomomo Journal, Docomomo Member Card, descontos em museus

### Lembretes:
- 3 dias após preenchimento sem pagamento
- 7 dias
- 15 dias (último aviso)

### Prazos da campanha 2026:
- A definir

## Comandos Úteis

```bash
source venv/bin/activate
uvicorn pilotis.main:app --reload
./scripts/backup_db.sh
```

## Próximos Passos (Fase 4)

1. Criar `pilotis/services/pagbank.py`
2. Integrar criação de cobrança PIX após submit do formulário
3. Exibir QR Code real na tela de pagamento
4. Implementar webhook para confirmação de pagamento
