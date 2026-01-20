<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($titulo ?? 'Pilotis') ?> - Docomomo Brasil</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@picocss/pico@2/css/pico.min.css">
    <style>
        :root {
            --pico-font-size: 100%;
            --pico-primary: #4a8c4a;
            --pico-primary-hover: #3d733d;
            --pico-primary-focus: rgba(74, 140, 74, 0.25);
            --docomomo-verde-claro: #7ab648;
            --docomomo-verde-escuro: #4a8c4a;
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
            max-width: 300px;
            height: auto;
        }
        .logo-text {
            font-weight: bold;
            font-size: 1.2rem;
            color: var(--docomomo-verde-escuro);
        }
        h1, h2, h3 {
            color: var(--docomomo-verde-escuro);
        }
        article {
            border-top: 4px solid var(--docomomo-verde-claro);
        }
        button[type="submit"],
        input[type="submit"],
        [role="button"].primary,
        .btn-primary {
            background-color: var(--docomomo-verde-escuro);
            border-color: var(--docomomo-verde-escuro);
        }
        button[type="submit"]:hover,
        input[type="submit"]:hover,
        [role="button"].primary:hover,
        .btn-primary:hover {
            background-color: var(--docomomo-verde-claro);
            border-color: var(--docomomo-verde-claro);
        }
        mark {
            background-color: var(--docomomo-verde-claro);
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
            border-left: 3px solid var(--docomomo-verde-claro);
            padding-left: 1rem;
        }
        legend {
            color: var(--docomomo-verde-escuro);
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
                <a href="/">
                    <img src="/assets/img/logo-docomomo.png" alt="Docomomo Brasil">
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
                Pilotis - Sistema de Gestao de Filiados v1.0.0 (PHP)<br>
                Desenvolvido por Danilo Matoso (Tesoureiro) com assistencia de Claude Code
            </small>
        </footer>
    </main>
    <?= $extra_scripts ?? '' ?>
</body>
</html>
