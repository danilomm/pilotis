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
 *   - PagBank e Brevo sao os dois unicos servicos externos que recebem dado;
 *   - o painel da organizacao nao mostra CPF, e mostra valor e total
 *     arrecadado desde 30/08/2026 (ver organizacao_inscritos.php);
 *   - a planilha anual aos coordenadores de nucleo sai SEM CPF (ver o
 *     procedimento de fim de campanha no CLAUDE.md);
 *   - a lista publica de filiados do Pilotis foi REMOVIDA em 29/08/2026.
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
        <li>Dados acadêmicos e profissionais: profissão,
        formação e instituição.</li>
        <li>Comprovante de matrícula, apenas de quem se inscreve
        em categoria de estudante — é o documento que comprova o desconto.</li>
        <li>Nas inscrições em eventos: o nome que você quer no
        crachá e o registro de presença, quando houver.</li>
        <li>Pagamento: valor, forma e data. O sistema
        não guarda número de cartão: os dados do cartão vão
        direto para o provedor de pagamento e não passam por aqui.</li>
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
    </ul>

    <h3>Serviços externos</h3>
    <p>Dois, e só o necessário vai para cada um:</p>
    <ul>
        <li>PagBank (PagSeguro) — recebe nome, CPF, email,
        telefone e endereço para emitir a cobrança. É quem processa o pagamento.</li>
        <li>Brevo — recebe nome e email para entregar as
        mensagens do sistema.</li>
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

    <h3>Seus direitos</h3>
    <p>Você pode pedir, a qualquer momento e sem justificar:</p>
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
    <p>O site funciona apenas por HTTPS, o banco de dados fica fora da área
    servida na web, e todo acesso administrativo exige senha e fica registrado.</p>

    <p style="margin-top: 2rem;"><small>Dúvidas sobre este aviso:
    <a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a>.</small></p>
</article>
