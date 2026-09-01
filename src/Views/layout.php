<?php
// Workaround: força status 200 antes de enviar HTML
// Necessário em alguns servidores onde o status após rewrite é 404.
//
// Mas SÓ quando ninguém definiu um status de propósito: error_404() e
// error_500() chamam http_response_code() antes de renderizar, e como a view usa
// ob_start/ob_get_clean os headers ainda não saíram — o 200 daqui vencia. Toda
// página de erro respondia 200, o que faz o Google indexar URL errada e um
// monitor de disponibilidade nunca ver o 500 de uma exceção no pagamento.
if (!headers_sent() && http_response_code() === 200) {
    http_response_code(200);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($titulo ?? 'Pilotis') ?> - <?= e(ORG_NOME) ?></title>
<?php if (!empty($meta)): ?>
    <?php
    // Previa do link. Vale para onde o link do evento efetivamente circula —
    // WhatsApp, Telegram, Facebook, LinkedIn, Bluesky — que leem Open Graph.
    //
    // A previa que o aplicativo monta na PRIMEIRA vez fica em cache do lado
    // dele, por dias, fora do nosso alcance. Nao e ajuste que se faz depois de
    // alguem reclamar: e antes de o link sair.
    ?>
    <meta name="description" content="<?= e($meta['descricao'] ?? '') ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e(ORG_NOME) ?>">
    <meta property="og:title" content="<?= e($meta['titulo'] ?? $titulo ?? '') ?>">
    <meta property="og:description" content="<?= e($meta['descricao'] ?? '') ?>">
    <meta property="og:url" content="<?= e($meta['url'] ?? '') ?>">
    <meta property="og:locale" content="pt_BR">
    <?php if (!empty($meta['imagem'])): ?>
        <meta property="og:image" content="<?= e($meta['imagem']) ?>">
        <meta property="og:image:alt" content="Cartaz — <?= e($meta['titulo'] ?? '') ?>">
        <meta name="twitter:card" content="summary_large_image">
    <?php else: ?>
        <meta name="twitter:card" content="summary">
    <?php endif; ?>
    <meta name="twitter:title" content="<?= e($meta['titulo'] ?? $titulo ?? '') ?>">
    <meta name="twitter:description" content="<?= e($meta['descricao'] ?? '') ?>">
<?php endif; ?>
    <?php
    // Pico servido LOCAL, e nao pelo CDN. A pagina do evento e a pagina do
    // seminario, com a URL impressa no cartaz: um CDN fora do ar deixaria o
    // conteudo legivel mas sem formatacao nenhuma, e nao ha como consertar de
    // fora. A tag flutuante (@2) era o segundo problema — publicar uma versao
    // menor no npm mudava o visual sozinho, sem ninguem tocar em nada.
    // Atualizar: baixar de cdn.jsdelivr.net/npm/@picocss/pico@<versao>/css/pico.min.css
    ?>
    <link rel="stylesheet" href="/assets/css/pico.min.css">
    <style>
        :root {
            --pico-font-size: 100%;
            --pico-primary: <?= ORG_COR_PRIMARIA ?>;
            --pico-primary-hover: <?= ORG_COR_PRIMARIA ?>cc;
            --pico-primary-focus: <?= ORG_COR_PRIMARIA ?>40;
            --org-cor-primaria: <?= ORG_COR_PRIMARIA ?>;
            --org-cor-secundaria: <?= ORG_COR_SECUNDARIA ?>;
        }
        /* Alvo de toque nos <details>.
           O Pico define `details summary { line-height: 1rem }`, ou seja 16px de
           altura, contra os 44px do iOS e 48px do Android. A maioria se inscreve
           pelo celular, e nas telas do evento o que esta ATRAS desses toques e a
           saida de emergencia: "Nao tenho CPF brasileiro" (unico caminho para
           estrangeiro, num formulario onde o CPF e obrigatorio) e "Ver as
           categorias de filiado" (a explicacao de por que o desconto sumiu, com
           o email da tesouraria para contestar). Errar o toque ali faz a pessoa
           concluir que nao ha alternativa. */
        details > summary {
            line-height: 1.5;
            min-height: 44px;
            padding: .6rem 0;
            display: block;
        }
        details > summary > small {
            font-size: .9rem;
        }

        body {
            padding: 1rem;
        }
        header {
            margin-bottom: 2rem;
        }
        .logo-container {
            text-align: center;
            margin-bottom: 1rem;
        }
        .logo-container img {
            /* min() porque .logo-container img e mais especifico que o
               img{max-width:100%} do Pico e o anulava: em tela de 360px sobram
               296px, e 300px estouravam o corpo por 4px. */
            max-width: min(300px, 100%);
            height: auto;
        }
        .logo-text {
            font-weight: bold;
            font-size: 1.2rem;
            color: var(--org-cor-primaria);
        }
        h1, h2, h3 {
            color: var(--org-cor-primaria);
        }
        article {
            border-top: 4px solid var(--org-cor-secundaria);
        }
        /* A regra pinta o botao de acao com o verde da instituicao — mas NAO
           pode alcancar as variantes `.outline` e `.secondary` do Pico. Sem o
           :not(), o fundo virava verde e o texto continuava verde (que e o que
           `outline` faz): os botoes "reenviar" e "lançar pago" da lista de
           inscritos sumiam, verde no verde. */
        button[type="submit"]:not(.outline):not(.secondary),
        input[type="submit"]:not(.outline):not(.secondary),
        [role="button"].primary,
        .btn-primary {
            background-color: var(--org-cor-primaria);
            border-color: var(--org-cor-primaria);
        }
        button[type="submit"]:not(.outline):not(.secondary):hover,
        input[type="submit"]:not(.outline):not(.secondary):hover,
        [role="button"].primary:hover,
        .btn-primary:hover {
            background-color: var(--org-cor-secundaria);
            border-color: var(--org-cor-secundaria);
        }

        /* Botao vazado: fundo transparente, texto e borda na cor. O Pico ja faz
           isso, mas a regra acima ganhava dele pela especificidade. */
        button[type="submit"].outline,
        input[type="submit"].outline {
            background-color: transparent;
        }
        mark {
            background-color: var(--org-cor-secundaria);
            color: white;
            padding: 0.1rem 0.4rem;
            border-radius: 4px;
        }
        footer {
            margin-top: 2rem;
            text-align: center;
            color: #666;
        }
        fieldset {
            border-left: 3px solid var(--org-cor-secundaria);
            padding-left: 1rem;
        }
        legend {
            color: var(--org-cor-primaria);
            font-weight: bold;
        }
        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
        }
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .alert-error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .alert-warning {
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
        }
        .text-center {
            text-align: center;
        }
        .text-right {
            text-align: right;
        }
        .mt-1 { margin-top: 0.5rem; }
        .mt-2 { margin-top: 1rem; }
        .mb-1 { margin-bottom: 0.5rem; }
        .mb-2 { margin-bottom: 1rem; }
        <?= $extra_css ?? '' ?>
    </style>
    <?= $extra_head ?? '' ?>
</head>
<body>
    <main class="container">
        <header>
            <div class="logo-container">
                <a href="<?= e(ORG_SITE_URL) ?>">
                    <?php
                    // Se o arquivo nao esta la, sai o NOME, e nao um <img>
                    // quebrado. O padrao de ORG_LOGO e 'logo.png', que o
                    // projeto nao distribui — sem esta conferencia, toda
                    // instalacao nova (e a previa local, que foi onde
                    // apareceu em 30/08) abre com o icone de imagem faltando
                    // no alto de TODAS as paginas, sem erro nenhum no log.
                    // O `.logo-text` ja estava no CSS deste arquivo desde
                    // sempre, escrito para este caso e nunca ligado.
                    ?>
                    <?php if (is_file(PUBLIC_DIR . '/assets/img/' . ORG_LOGO)): ?>
                        <img src="/assets/img/<?= e(ORG_LOGO) ?>" alt="<?= e(ORG_NOME) ?>">
                    <?php else: ?>
                        <span class="logo-text"><?= e(ORG_NOME) ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </header>

        <?php if ($flash_success = get_flash('success')): ?>
            <div class="alert alert-success"><?= e($flash_success) ?></div>
        <?php endif; ?>

        <?php if ($flash_error = get_flash('error')): ?>
            <div class="alert alert-error"><?= e($flash_error) ?></div>
        <?php endif; ?>

        <?= $content ?? '' ?>

        <footer>
            <hr>
            <small>
                Pilotis — Sistema de Gestão de Filiados v1.0.0 (PHP)
                <?php if (ORG_CNPJ || ORG_EMAIL_CONTATO): ?>
                    <br>
                    <?= e(ORG_NOME) ?><?php if (ORG_CNPJ): ?> — CNPJ <?= e(ORG_CNPJ) ?><?php endif; ?>
                    <?php if (ORG_EMAIL_CONTATO): ?>
                        <br><a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a>
                    <?php endif; ?>
                <?php endif; ?>
            </small>
        </footer>
    </main>
    <?= $extra_scripts ?? '' ?>
</body>
</html>
