<?php
$titulo = 'Erro interno';
ob_start();
?>
<article>
    <h1>500 - Erro interno</h1>
    <p>Ocorreu um erro inesperado. Por favor, tente novamente mais tarde.</p>
    <?php if (!empty($error_message) && PAGBANK_SANDBOX): ?>
        <details>
            <summary>Detalhes do erro (ambiente de desenvolvimento)</summary>
            <pre><?= e($error_message) ?></pre>
        </details>
    <?php endif; ?>
    <p><a href="/" role="button">Voltar ao inicio</a></p>
</article>
<?php
$content = ob_get_clean();
require SRC_DIR . '/Views/layout.php';
