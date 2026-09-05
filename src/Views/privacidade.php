<?php
/**
 * Aviso de privacidade — o texto que o consentimento registra.
 *
 * REGRA: ao mudar QUALQUER coisa aqui, trocar POLITICA_PRIVACIDADE_VERSAO no
 * config.php. O consentimento e gravado por versao; sem trocar, o registro
 * aponta para um texto que a pessoa nao leu.
 *
 * O texto descreve o que o sistema FAZ, e nao o que seria bonito prometer.
 * Cada afirmacao aqui corresponde a codigo que existe:
 *
 *   - a lista de campos e a das colunas de `filiacoes` e `inscricoes`;
 *   - PagBank e Brevo sao os dois unicos servicos externos que recebem dado, e
 *     o Brevo recebe o PDF do comprovante junto (com CPF e valor);
 *   - a tabela `log` guarda acao, data e IP, e esta declarada em "o que e
 *     coletado" desde 01/09/2026 — IP e dado pessoal;
 *   - so o login que FALHA e registrado; o painel da organizacao registra todo
 *     acesso e download, com o email de quem fez;
 *   - o painel da organizacao nao mostra CPF, e mostra valor e total
 *     arrecadado desde 30/08/2026 (ver organizacao_inscritos.php);
 *   - a planilha anual aos coordenadores de nucleo sai SEM CPF (ver o
 *     procedimento de fim de campanha no CLAUDE.md);
 *   - a lista publica de filiados do Pilotis foi REMOVIDA em 29/08/2026.
 *
 *   - o email de contato sai do `ORG_EMAIL_CONTATO`, e em 04/09/2026 ele
 *     mudou: o `tesouraria@docomomobrasil.com` que estava ali desde antes desta
 *     reforma NAO EXISTE — as mensagens voltavam com "550 5.1.1 User unknown".
 *     A caixa real e o Gmail da tesouraria. Isto vale versao nova porque este
 *     texto aponta o canal dos direitos da LGPD, e quem consentiu ate 04/09 leu
 *     um endereco que nao recebia.
 *
 * Se algum desses fatos mudar, este texto muda junto — e a versao com ele.
 */
?>
<article>
    <h2>Aviso de privacidade</h2>
    <p><small>Versão <?= e(POLITICA_PRIVACIDADE_VERSAO) ?></small></p>

    <p>Este é o sistema de filiação e inscrições do <?= e(ORG_NOME) ?>. Ele
    guarda dados pessoais porque precisa deles para funcionar, e esta página diz
    exatamente quais, para quê, e quem os vê.</p>

    <h3>O que é coletado</h3>
    <ul>
        <li>Identificação: nome, CPF (ou passaporte, para quem
        não tem CPF) e email.</li>
        <li>Contato e endereço: telefone, endereço, CEP, cidade,
        estado e país.</li>
        <li>Dados acadêmicos e profissionais: profissão e instituição — e
        também formação, na filiação anual.</li>
        <li>Comprovante de matrícula, apenas de quem se inscreve
        em categoria de estudante — é o documento que comprova o desconto.</li>
        <li>Nas inscrições em eventos: o nome que você quer no
        crachá e o registro de presença, quando houver.</li>
        <li>Registro de uso: as ações feitas no sistema, com data, hora e o
        endereço IP de onde partiram. É o que permite responder depois por que
        um email saiu, ou quem baixou uma lista.</li>
        <li>Pagamento: valor, forma e data. O sistema
        não guarda número de cartão: ele é cifrado no seu navegador, pelo
        provedor de pagamento, antes de sair da tela. O que passa pelo nosso
        servidor é esse pacote já cifrado, mais o nome do titular, e os dois são
        retransmitidos ao provedor sem ficar guardados.</li>
    </ul>

    <h3>Por que o CPF é pedido logo na entrada</h3>
    <p>Por dois motivos:</p>
    <ul>
        <li>Para encontrar você no cadastro sem que precise
        lembrar com qual email se inscreveu em anos anteriores. Se o CPF
        constar, o link de acesso vai para o email que já está registrado —
        nunca para um endereço digitado por quem tem apenas o número.</li>
        <li>Porque o meio de pagamento exige. O PagBank só
        aceita CPF ou CNPJ para gerar PIX, boleto ou cobrança em cartão. É
        exigência dele. Quem não tem CPF pode se inscrever mesmo assim,
        informando passaporte, e a tesouraria combina o pagamento por outro
        caminho.</li>
    </ul>

    <h3>Quem vê</h3>
    <ul>
        <li>A tesouraria do <?= e(ORG_NOME) ?>, que administra o
        sistema.</li>
        <li>A coordenação do evento em que você se inscreveu, por
        um painel próprio que mostra nome, contato, endereço, categoria, valor e
        situação da inscrição — sem CPF. A coordenação precisa
        falar com as pessoas e prestar contas do próprio evento; identificar
        alguém na Receita não faz parte disso.</li>
        <li>A coordenação do seu núcleo regional, ao fim de cada
        campanha anual, numa planilha de filiados da região — também
        sem CPF.</li>
        <li>Quem receber de você um comprovante emitido pelo sistema: o
        documento traz um código, e a página que o confere mostra seu nome, o CPF
        parcialmente oculto, o evento, a categoria, o valor e a situação. Serve
        para um setor de reembolso verificar que o papel é verdadeiro. Só chega
        lá quem tiver o código impresso no documento.</li>
    </ul>

    <h3>Serviços externos</h3>
    <p>Dois, e só o necessário vai para cada um:</p>
    <ul>
        <li>PagBank (PagSeguro) — recebe nome, CPF, email,
        telefone e endereço para emitir a cobrança. É quem processa o pagamento.</li>
        <li>Brevo — entrega as mensagens do sistema, e por isso recebe
        nome, email e o conteúdo do que é enviado. Isso inclui o comprovante de
        inscrição em PDF anexado à confirmação de pagamento, que traz CPF,
        categoria, valor e o código de validação.</li>
    </ul>
    <p>Fora esses dois, os dados não são vendidos, cedidos nem compartilhados
    com ninguém.</p>

    <h3>O que é público</h3>
    <p>Nada deste sistema é publicado automaticamente. As listas de filiados que
    existem no site do <?= e(ORG_NOME) ?> trazem apenas nome e
    instituição de quem está em dia, são publicadas por decisão da
    diretoria e não incluem CPF, endereço, telefone nem email.</p>

    <h3>Por quanto tempo</h3>
    <p>O histórico de filiação é mantido enquanto o <?= e(ORG_NOME) ?> existir:
    é a memória da associação, e é o que permite reconhecer quem foi filiado em
    anos anteriores. Comprovantes de matrícula são guardados enquanto servirem à
    prestação de contas do ano a que se referem.</p>

    <h3>Seus direitos, na LGPD</h3>
    <p>A Lei Geral de Proteção de Dados Pessoais (LGPD, Lei 13.709/2018) garante
    a você o direito de saber quais dados uma organização tem sobre você, de
    corrigi-los e de pedir que sejam apagados. Você pode pedir, a qualquer
    momento e sem justificar:</p>
    <ul>
        <li>uma cópia dos seus dados;</li>
        <li>a correção do que estiver errado;</li>
        <li>a exclusão dos seus dados — ressalvado o que a associação precisa
        manter por obrigação contábil e fiscal, que se restringe ao registro dos
        pagamentos.</li>
    </ul>
    <p>Escreva para <a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a>.
    Hoje esses pedidos são atendidos manualmente pela tesouraria.</p>

    <h3>Segurança</h3>
    <p>O site funciona apenas por HTTPS e o banco de dados fica fora da área
    servida na web. A administração do sistema exige senha, e as tentativas de
    entrada que falham ficam registradas. O painel da coordenação do evento não
    usa senha compartilhada: cada pessoa autorizada recebe um link no próprio
    email, e todo acesso e download dela fica registrado com esse endereço.</p>

    <p style="margin-top: 2rem;"><small>Dúvidas sobre este aviso:
    <a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a>.</small></p>
</article>
