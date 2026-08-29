# Plano de desenvolvimento — Módulo de Eventos do Pilotis

> Documento de planejamento em alto nível. Não é especificação detalhada.
> Decisões originais de 2026-06-20; revisão técnica e decisões A–H de 2026-07-30.

## Resumo

Módulo do Pilotis para cadastrar e gerenciar inscrições em eventos organizados por núcleos regionais do Docomomo Brasil, com **desconto para filiados adimplentes**. Gestão centralizada na tesouraria nacional. Pode haver mais de um evento com inscrições abertas ao mesmo tempo.

## Decisões tomadas (2026-06-20)

| # | Decisão |
|---|---------|
| 1 | Gestão centralizada na tesouraria nacional |
| 2 | Cada evento define suas próprias categorias de inscrição (variam evento a evento), com valores em centavos |
| 3 | Categoria com desconto de filiado: sistema verifica adimplência no submit do formulário |
| 4 | Ano de referência da adimplência: definido automaticamente pelo status da campanha — se a campanha do ano da data do evento está aberta, vale o ano do evento; senão, ano anterior |
| 5 | Sem limite de vagas; inscrições controladas só por data |
| 6 | Categoria "Estudante" exige comprovante de matrícula (mesmo modelo da filiação: PDF/JPG/PNG, max 5MB) |
| 7 | Pode haver categorias gratuitas (R$ 0) que pulam o PagBank e confirmam direto |
| 8 | Confirmação por email simples, sem PDF anexo |
| 9 | Comunicação operacional do evento (links Zoom, instruções, material) fora do sistema |
| 10 | Cadastro de evento genérico, sem diferenciar online/presencial/híbrido |
| 11 | Sem cancelamento pelo sistema — quem precisar, fala com tesouraria fora |
| 12 | Lista pública agregadora em `pilotis.docomomobrasil.com/eventos` + página individual por evento (link direto) |
| 13 | Lista pública mostra só eventos com inscrições abertas |
| 14 | Lista de inscritos é só interna (não vira página pública) |
| 15 | Fluxo de inscrição: email → link de acesso → formulário (igual ao de filiação) |
| 16 | Uma inscrição por pessoa por evento (`UNIQUE(pessoa_id, evento_id)`) |
| 17 | Reaproveita dados de cadastro existente da pessoa (ela confirma/edita) |
| 18 | Núcleo organizador é campo de texto livre (não há tabela de núcleos) |
| 19 | Slug do evento digitado manualmente pela tesouraria |
| 20 | Lembretes automáticos individuais (ver decisão C) |
| 21 | Sem notificações automáticas para tesouraria ou coordenador |
| 22 | Acesso do coordenador: sistema de login com usuários individuais — **adiado para depois do MVP** (ver decisão D) |

## Decisões da revisão (2026-07-30)

| # | Decisão |
|---|---------|
| A | Adimplência reprovada no submit: volta ao form com mensagem clara ("não encontramos filiação ativa para este CPF"); pessoa escolhe outra categoria. Sem bloqueio. Polimento futuro: link "filie-se primeiro" |
| B | Verificação de adimplência com fallback: CPF (buscado no banco INTEIRO, não só no cadastro da sessão) → senão, email do acesso. Se achou pessoa com filiação paga, aprova e grava o CPF no cadastro (muita gente adimplente não tem CPF no banco — evita falso negativo) |
| B2 | Cenário "email novo de pessoa antiga": form em branco + CPF preenchido → desconto aprovado pelo CPF global (B) e, no mesmo submit, oferta de vinculação de cadastro (`buscar_match_consolidacao` + view "É seu cadastro?") ANTES do pagamento. Vinculação aceita → `consolidar_pessoas()` funde no cadastro antigo. Recusada → segue separado, desconto mantido (critério é o CPF). Mesmo fluxo já rodado na filiação (precedente: Ana Valéria, jul/2026) |
| B3 | **Implementado em 2026-07-30 como constraint** (não só rotina de higiene): CPFs normalizados para só dígitos + índice `UNIQUE` parcial em `pessoas(cpf)` (ignora NULL/vazio). Pré-checagem `cpf_pertence_a_outra_pessoa()` nos 4 caminhos que gravam CPF (`atualizar_pessoa_filiacao`, `consolidar_pessoas`, admin `salvarPessoa`, admin `novoSalvar`): CPF de outra pessoa → não grava, dispara oferta de vinculação (público) ou erro amigável (admin). No "Não" por engano, o cadastro novo fica sem CPF — duplicata nunca nasce. Infraestrutura compartilhada: vale para campanha de filiação E eventos |
| C | Lembretes individuais espelhando a filiação: pessoa acessou e não concluiu → D+3; todos os incompletos → prazo_inscricao − 1 dia. Usa `LembreteService` |
| D | **MVP sem sistema de usuários.** Tesouraria exporta CSV e manda ao coordenador (como já se faz com as planilhas dos núcleos). Login/permissões viram última fase, quando houver demanda real |
| E | Slug: `UNIQUE(slug)` simples + convenção de incluir o ano (`seminario-sp-2027`). Slugs reservados proibidos: `inscrever` |
| F | Admin de inscritos inclui ações manuais: marcar como paga (pagamento por fora), reenviar confirmação, excluir inscrição |
| G | Edição de evento com 1+ inscrição paga: valores de categorias existentes ficam travados; pode editar descrição/prazo e adicionar categoria nova |
| H | Admin atual (senha única no `.env`) permanece. Migração para `usuarios` só se/quando a fase de logins acontecer |

## Correções técnicas obrigatórias (revisão 2026-07-30)

1. **Webhook + cron de verificação** precisam ramificar por tipo. `reference_id`
   de eventos: `PILOTIS-EVT-{inscricao_id}` (filiação continua
   `PILOTIS-{pessoa_id}-{ano}`). `WebhookController::pagbank()` e
   `cron-verificar-pagamentos.php` passam a reconhecer os dois formatos e
   confirmar em `inscricoes` ou `filiacoes` conforme o caso. O cron é a rede de
   segurança principal (webhook do PagBank é pouco confiável — histórico da
   campanha 2026).
2. **`inscricoes` precisa das colunas de pagamento** que a tela reaproveitada
   lê: `metodo`, `pagbank_charge_id`, `pagbank_boleto_link`,
   `pagbank_boleto_barcode`, `data_vencimento`.
3. **Flag da categoria é `verifica_adimplencia`** (não `requer_cpf`). CPF é
   obrigatório para TODA categoria paga — o PagBank exige `tax_id` de qualquer
   pagante. Só categorias gratuitas dispensam CPF.
4. **Ordem de registro de rotas importa**: estáticas antes de dinâmicas
   (`/eventos/{slug}/inscrever` antes de `/eventos/{slug}/{token}`;
   `/admin/eventos/novo` antes de `/admin/eventos/{id}`).
5. **Comprovantes de eventos** têm padrão próprio de nome:
   `evt{evento_id}_{pessoa_id}.{ext}` (o padrão da filiação
   `{pessoa_id}_{ano}.{ext}` colidiria com 2 eventos no mesmo ano).
6. **Proteção contra CANCELED sobrescrever pago** (mesma do webhook de
   filiação): `WHERE status != 'pago'` em qualquer update de cancelamento.
7. **Fluxo de eventos independe de `campanhas.status`** — nenhuma checagem de
   campanha aberta nos controllers de eventos.

## Modelo de dados

Novas tabelas:

- **`eventos`** — id, nome, slug (UNIQUE), descricao, organizador (texto livre),
  data_inicio, data_fim, prazo_inscricao, status (`rascunho` / `publicado` /
  `encerrado`), created_at.
- **`evento_categorias`** — id, evento_id, nome, valor (centavos),
  verifica_adimplencia (bool), requer_comprovante (bool), ordem.
- **`inscricoes`** — id, pessoa_id, evento_id, categoria_id, status (`enviado` /
  `acesso` / `pendente` / `pago` / `cancelado` / `gratuita_confirmada`), valor,
  comprovante_path, metodo, data_pagamento, data_vencimento, status_at,
  pagbank_order_id, pagbank_charge_id, pagbank_boleto_link,
  pagbank_boleto_barcode, created_at. `UNIQUE(pessoa_id, evento_id)`.

Adiadas (fase de logins): `usuarios`, `usuario_eventos`.

Reaproveita tabelas existentes:

- `pessoas`, `emails` — cadastros.
- `pagbank_pedidos` — coluna nova opcional `inscricao_id` (pedidos de evento
  têm `filiacao_id` NULL e `inscricao_id` preenchido).
- `lembretes_agendados` — coluna nova opcional `inscricao_id` + tipos novos
  (`inscricao_incompleta_d3`, `inscricao_prazo_d1`).
- `envios_destinatarios` / `envios_lotes` — para eventuais campanhas de email
  de evento.

## Rotas

### Público

- `GET /eventos` — lista de eventos com inscrições abertas (status `publicado`
  e hoje ≤ prazo_inscricao).
- `GET /eventos/{slug}` — página do evento (descrição, categorias, valores, prazo).
- `POST /eventos/{slug}/inscrever` — recebe email, manda link de acesso.
  (registrar ANTES da rota com {token})
- `GET /eventos/{slug}/{token}` — formulário de inscrição.
- `POST /eventos/{slug}/{token}` — submete; verifica adimplência (decisões A/B)
  se a categoria pede; gratuita confirma direto.
- `GET /eventos/{slug}/{token}/pagamento` — tela de pagamento (PIX/Boleto/Cartão).

### Admin (senha única atual)

- `GET /admin/eventos` — listagem.
- `GET /admin/eventos/novo` — criação. (registrar ANTES de {id})
- `GET /admin/eventos/{id}` — edição (com regras da decisão G).
- `GET /admin/eventos/{id}/inscritos` — lista, filtros, exportação CSV, ações
  manuais (decisão F): marcar pago, reenviar confirmação, excluir.

## Reaproveitamentos do sistema atual

- Fluxo de autenticação por email + token (mesmo da filiação).
- Integração PagBank (`PagBankService`) — muda só o `reference_id`.
- `LembreteService` — 2 tipos novos.
- Templates de email via Brevo (`email_templates`).
- Sistema de comprovantes (`dados/data/comprovantes/`, servidor
  `dados_privados/comprovantes/`).
- `buscar_match_consolidacao()` e `consolidar_pessoas()` para evitar duplicação
  de cadastros.

## Fases de desenvolvimento

1. **Modelo de dados e admin de eventos** — tabelas + migração, CRUD de evento
   e categorias no painel.
2. **Fluxo público de inscrição** — listagem, página do evento, entrada por
   email, formulário, verificação de adimplência (A/B).
3. **Pagamento** — reference_id `PILOTIS-EVT-`, ramificação no webhook e no
   cron-verificar-pagamentos, tela de pagamento, categoria gratuita.
4. **Confirmação por email + lembretes** — templates novos, tipos novos no
   `LembreteService`, processamento no cron-lembretes.
5. **Lista de inscritos no admin** — filtros, CSV, ações manuais (F).
6. **Polimentos** — mensagens de erro, página de evento encerrado, UX.
7. **(Sob demanda) Sistema de usuários e permissões** — `usuarios`,
   `usuario_eventos`, login bcrypt, reset por email, visão por coordenador.

## Pontos adiados

- **Campanha 2027 — grupo "participou de evento"**: inscritos de eventos que
  nunca se filiaram caem hoje no Grupo 4 (Novos/convite) automaticamente.
  Melhor: criar grupo próprio (análogo ao grupo Seminário) consultando
  `inscricoes`, com template específico. Para quem participou de 2+ eventos
  pagando preço de não-filiado, o template pode somar o que a pessoa gastou e
  mostrar que a filiação + preço de filiado teria saído mais barato. Uma pessoa
  recebe sempre 1 só email por campanha (grupos mutuamente exclusivos — já
  garantido pela lógica atual).
- Envio de email em massa para inscritos pelo sistema (comunicações pós-inscrição).
- Reforço de unicidade do CPF na tabela `pessoas` (hoje não tem `UNIQUE`;
  mesmo CPF em duas pessoas permite tecnicamente 2 inscrições no mesmo evento —
  risco aceito no MVP).
- Página pública de histórico de eventos passados.
