<?php
$titulo = 'Pagina nao encontrada';
ob_start();
?>
<article>
    <h1>404 - Pagina nao encontrada</h1>
    <p>A pagina que voce procura nao existe ou foi movida.</p>
    <p><a href="/" role="button">Voltar ao inicio</a></p>
</article>
<?php
$content = ob_get_clean();
require SRC_DIR . '/Views/layout.php';
