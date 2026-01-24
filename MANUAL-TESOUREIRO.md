# Manual do Tesoureiro — Pilotis

Guia de operação do sistema de filiação para tesoureiros da associação.

---

## Acesso ao sistema

1. Abra o navegador e acesse o endereço do painel (ex.: `https://pilotis.docomomobrasil.com/admin`)
2. Digite a senha fornecida e clique em **Entrar**
3. A sessão dura 24 horas — depois disso, basta fazer login novamente

> Para sair a qualquer momento, clique em **Sair** no menu.

---

## Visão geral do painel

Ao entrar, você verá o **Painel** com:

- **Filtro de ano** — selecione o ano desejado (ex.: 2026)
- **Filtro de status** — veja todos, pagos, pendentes, enviados ou com acesso
- **Cartões de resumo** — total de filiações, pagos, não pagos e valor arrecadado
- **Tabela de filiações** — lista com nome, email, categoria, valor, status, método e data

### O que significa cada status

| Status | Significado |
|--------|-------------|
| **Enviado** | O email da campanha foi enviado, mas a pessoa ainda não clicou |
| **Acesso** | A pessoa clicou no link e acessou o formulário |
| **Pendente** | O formulário foi preenchido, aguardando pagamento |
| **Pago** | Pagamento confirmado |
| **Não pago** | A campanha encerrou sem pagamento |

### O que significa cada método de pagamento

| Método | Significado |
|--------|-------------|
| **pix** | Pagamento via QR Code PIX |
| **boleto** | Pagamento via boleto bancário |
| **cartao** | Pagamento via cartão de crédito |
| **manual** | Registrado manualmente pelo admin (depósito, transferência, etc.) |

---

## Tarefas do dia a dia

### Consultar quem pagou

1. No painel, selecione o **ano** desejado
2. Clique em **Pagos** no filtro de status
3. A tabela mostra todos os filiados com pagamento confirmado

### Consultar pagamentos pendentes

1. Selecione o ano
2. Clique em **Pendentes**
3. Aqui estão as pessoas que preencheram o formulário mas ainda não pagaram

### Buscar uma pessoa

1. Clique em **Buscar** no menu
2. Digite parte do nome ou email
3. Clique no nome da pessoa para ver os detalhes completos

---

## Registrar pagamento manual

Quando alguém paga por fora do sistema (depósito, transferência, Pix direto, etc.):

### Se a pessoa já existe no sistema

1. **Buscar** a pessoa pelo nome ou email
2. Clicar no nome para abrir a ficha
3. Localizar a filiação do ano corrente
4. Clicar no botão **Pagar** (verde)
5. Confirmar quando solicitado

O sistema marca a filiação como paga com método "manual" e data de hoje.

### Se a pessoa é nova (não está no sistema)

1. Clicar em **+ Novo** no menu
2. Preencher: nome, email, categoria e ano
3. Clicar em **Salvar**

O sistema cria o cadastro e já registra o pagamento como efetuado.

---

## Editar dados de uma pessoa

1. Busque e abra a ficha da pessoa
2. Altere os campos desejados (nome, email, CPF, notas)
3. Clique em **Salvar**

### Marcar como inativo

Se uma pessoa não deve mais receber emails (faleceu, pediu para sair, email inválido):

1. Abra a ficha da pessoa
2. Desmarque a caixa **Ativo**
3. (Opcional) Escreva o motivo no campo **Notas**
4. Clique em **Salvar**

Pessoas inativas não recebem campanhas nem lembretes.

---

## Editar dados de uma filiação

Cada pessoa pode ter filiações em vários anos. Para editar uma filiação específica:

1. Abra a ficha da pessoa
2. Na seção **Histórico de filiações**, localize o ano desejado
3. Clique em **Editar**
4. Altere categoria, valor, status, método, data de pagamento, dados de contato ou endereço
5. Clique em **Salvar**

---

## Excluir registros

### Excluir uma filiação

1. Abra a ficha da pessoa
2. No cartão da filiação, clique em **Excluir** (vermelho)
3. Confirme

Isso remove apenas aquela filiação. A pessoa e outras filiações permanecem.

### Excluir uma pessoa

1. Abra a ficha da pessoa
2. No final da página, clique em **Excluir Pessoa**
3. Confirme

**Atenção:** isso apaga a pessoa e **todas** as suas filiações permanentemente.

---

## Campanhas de email

A campanha é o envio em massa dos convites de filiação para o ano.

### Criar uma campanha

1. Clique em **Campanha** no menu
2. Selecione o ano e clique em **Criar Campanha**
3. A campanha é criada com status "Aberta" e os valores padrão

### Definir data de encerramento

1. Na página da campanha, localize **Data de encerramento**
2. Escolha a data limite
3. Clique em **Salvar**

Três dias antes dessa data, o sistema envia automaticamente um lembrete "última chance" para quem ainda não pagou.

### Alterar valores da filiação

1. Na campanha, clique em **Editar** na seção de valores
2. Ajuste os valores de cada categoria (em reais)
3. Clique em **Salvar**

### Enviar emails da campanha

O envio é feito automaticamente pelo servidor (via cron, diariamente). O sistema envia até 290 emails por dia, na seguinte ordem de prioridade:

1. **Filiados do ano anterior** — recebem convite de renovação
2. **Participantes do seminário** — recebem convite específico
3. **Ex-filiados** — recebem convite de renovação
4. **Contatos sem filiação** — recebem convite geral
5. **Contatos pendentes** — recebem convite geral

Cada pessoa recebe apenas um email por campanha. Quem já pagou não recebe novamente.

### Enviar manualmente (pelo painel)

Se precisar disparar emails fora do horário automático:

1. Na campanha, use a seção **Enviar para grupo**
2. Selecione o grupo de destinatários
3. Digite a senha de admin para confirmar
4. Aguarde a mensagem com o resultado

### Testar antes de enviar

1. Na campanha, adicione seu email na **Lista de teste**
2. Clique em **Enviar para teste**
3. Verifique se o email chegou corretamente na sua caixa

### Encerrar a campanha

Quando decidir que a campanha acabou:

1. Clique em **Fechar Campanha**
2. Confirme

Isso marca todas as filiações não pagas como "não pago" e exibe as estatísticas finais (novos, renovações, retornos, desistências).

---

## Lembretes automáticos

O sistema envia lembretes automaticamente (via cron diário):

- **No dia do vencimento** — lembrete de que o pagamento vence hoje
- **Semanalmente (domingos)** — para pagamentos já vencidos
- **3 dias antes do fim da campanha** — lembrete "última chance"

Você não precisa fazer nada. Os lembretes são enviados para quem tem filiação com status "pendente".

---

## Templates de email

Para personalizar os textos dos emails:

1. Clique em **Templates** no menu
2. Escolha o template desejado
3. Edite o assunto e o corpo (HTML)
4. Clique em **Salvar**

### Variáveis disponíveis

Use estas marcações no texto — o sistema substitui automaticamente:

| Variável | Será substituída por |
|----------|---------------------|
| `{nome}` | Nome da pessoa |
| `{ano}` | Ano da filiação |
| `{link}` | Link pessoal de filiação |
| `{valor}` | Valor da filiação |
| `{dias}` | Dias restantes |
| `{data_fim}` | Data de encerramento da campanha |

### Restaurar template original

Se um template foi editado incorretamente, clique em **Restaurar ao Padrão** para voltar à versão original.

---

## Contatos

Para ver a lista completa de pessoas no sistema:

1. Clique em **Contatos** no menu
2. Use os filtros: **Ativos**, **Inativos** ou **Todos**
3. Ordene por nome ou por última filiação paga

---

## Exportar dados

### Lista de filiados (CSV)

1. No painel, selecione o ano
2. Clique em **Exportar CSV**
3. Abra o arquivo no Excel ou Google Planilhas

O arquivo contém: nome, email, CPF, telefone, categoria, endereço, profissão, instituição, valor, método, status e data de pagamento.

### Backup do banco de dados

1. No painel, clique em **Baixar Banco (.db)**
2. Salve o arquivo em local seguro

Este arquivo contém **todos** os dados do sistema. Guarde-o como cópia de segurança.

---

## Reenviar email para uma pessoa

Se alguém disse que não recebeu o email:

1. Busque e abra a ficha da pessoa
2. Na filiação do ano corrente, clique em **Email** (azul)
3. Confirme o envio

---

## Lista pública de filiados

A página `/filiados/2026` (por exemplo) mostra publicamente quem está adimplente naquele ano. Essa lista é atualizada automaticamente conforme os pagamentos são confirmados.

---

## Fluxo do filiado (o que a pessoa vê)

Para entender o que acontece do lado de quem está se filiando:

1. **Acessa o link** — recebido por email ou no site
2. **Informa o email** — o sistema busca ou cria o cadastro
3. **Preenche o formulário** — dados pessoais, endereço, categoria
4. **Escolhe a forma de pagamento** — PIX, Boleto ou Cartão
5. **Paga** — PIX (validade 3 dias), Boleto (validade 15 dias) ou Cartão (imediato)
6. **Recebe confirmação** — email automático com PDF de declaração de filiação

---

## Categorias de filiação

| Categoria | Valor padrão |
|-----------|-------------|
| Estudante Brasil | R$ 115,00 |
| Filiado Pleno Brasil | R$ 230,00 |
| Filiado Pleno Internacional+Brasil | R$ 460,00 |

Os valores podem ser alterados por campanha (veja seção Campanhas).

---

## Perguntas frequentes

### Uma pessoa quer mudar de categoria. O que faço?

1. Abra a ficha da pessoa
2. Edite a filiação do ano
3. Altere a categoria e o valor
4. Se já pagou a diferença, mantenha status "pago"

### Alguém pagou duas vezes. Como estornar?

O estorno deve ser feito diretamente no PagBank. No Pilotis, você pode excluir a filiação duplicada ou alterar as notas para registrar a situação.

### Como sei quantos emails foram enviados na campanha?

Na página da campanha, a seção **Histórico de envios** mostra cada lote: data, quantidade, sucessos e falhas.

### Posso reabrir uma campanha fechada?

Não pelo painel. Uma vez fechada, as estatísticas são geradas e os registros marcados. Se necessário, fale com o suporte técnico.

### O sistema parou de enviar emails. O que pode ser?

- Verifique se a campanha está com status **Aberta**
- Verifique se há uma **data de encerramento** configurada (se já passou, os lembretes param)
- Se o problema persistir, contate o suporte técnico para verificar o cron e o serviço de email (Brevo)

### Alguém pediu para sair da lista. Como faço?

1. Busque a pessoa
2. Desmarque **Ativo**
3. Escreva nas notas: "Pediu para não receber emails em DD/MM/AAAA"
4. Salve

A pessoa não receberá mais nenhum email do sistema.

---

## Segurança e dados sensíveis

- O sistema armazena dados pessoais (CPF, endereço, email). Trate-os com sigilo.
- A senha do painel é pessoal. Não compartilhe.
- Ao passar a gestão, troque a senha e informe a nova ao sucessor.
- Os backups do banco contêm todos os dados. Armazene-os com segurança.

---

## Suporte técnico

Para problemas que fogem deste manual (erros do sistema, servidor fora do ar, problemas com PagBank ou Brevo), entre em contato com o responsável técnico do sistema.

---

*Pilotis — Sistema de gestão de filiados*
