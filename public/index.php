<?php
/**
 * Pilotis - Front Controller
 *
 * Todas as requisicoes passam por aqui
 */

// Debug: mostra erros em desenvolvimento
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Carrega arquivos base
require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/routes.php';
require_once __DIR__ . '/../src/db.php';

// Autoload do Composer (para TCPDF e outras bibliotecas)
$autoload = BASE_DIR . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

// Carrega Controllers
require_once SRC_DIR . '/Controllers/FiliacaoController.php';
require_once SRC_DIR . '/Controllers/FiliadosController.php';
require_once SRC_DIR . '/Controllers/AdminController.php';

// === ROTAS ===

// Pagina inicial
get('/', function() {
    $ano = date('Y');
    redirect("/filiacao/$ano");
});

// === Filiacao ===

// Entrada por email
get('/filiacao/{ano}', 'FiliacaoController::entrada');
post('/filiacao/{ano}', 'FiliacaoController::processarEntrada');

// Formulario de filiacao
get('/filiacao/{ano}/{token}', 'FiliacaoController::formulario');
post('/filiacao/{ano}/{token}', 'FiliacaoController::salvar');

// Pagamento
get('/filiacao/{ano}/{token}/pagamento', 'FiliacaoController::pagamento');
post('/filiacao/{ano}/{token}/gerar-pix', 'FiliacaoController::gerarPix');
post('/filiacao/{ano}/{token}/gerar-boleto', 'FiliacaoController::gerarBoleto');
post('/filiacao/{ano}/{token}/pagar-cartao', 'FiliacaoController::pagarCartao');

// === Filiados (lista publica) ===
get('/filiados/{ano}', 'FiliadosController::listar');

// === Webhook PagBank ===
post('/webhook/pagbank', function() {
    require_once SRC_DIR . '/Controllers/WebhookController.php';
    WebhookController::pagbank();
});

// === Admin ===
get('/admin', 'AdminController::painel');
get('/admin/campanha', 'AdminController::campanha');
post('/admin/campanha/criar', 'AdminController::criarCampanha');
post('/admin/campanha/excluir', 'AdminController::excluirCampanha');
post('/admin/campanha/iniciar-envio', 'AdminController::iniciarEnvio');
post('/admin/campanha/pausar-envio', 'AdminController::pausarEnvio');
post('/admin/campanha/fechar', 'AdminController::fecharCampanha');
post('/admin/campanha/valores', 'AdminController::salvarValores');
post('/admin/campanha/data-fim', 'AdminController::salvarDataFim');
post('/admin/campanha/enviar', 'AdminController::enviarCampanha');
post('/admin/campanha/enviar-lote', 'AdminController::enviarLote');
post('/admin/campanha/preview-lote', 'AdminController::previewLote');
post('/admin/campanha/grupo-teste', 'AdminController::salvarGrupoTeste');
post('/admin/campanha/enviar-teste', 'AdminController::enviarGrupoTeste');
post('/admin/lembretes/processar', 'AdminController::processarLembretes');
get('/admin/login', 'AdminController::loginForm');
post('/admin/login', 'AdminController::login');
get('/admin/logout', 'AdminController::logout');
get('/admin/contatos', 'AdminController::contatos');
get('/admin/buscar', 'AdminController::buscar');
get('/admin/pessoa/{id}', 'AdminController::pessoa');
post('/admin/pessoa/{id}', 'AdminController::salvarPessoa');
get('/admin/filiacao/{id}', 'AdminController::filiacao');
post('/admin/filiacao/{id}', 'AdminController::salvarFiliacao');
get('/admin/novo', 'AdminController::novoForm');
post('/admin/novo', 'AdminController::novoSalvar');
post('/admin/pagar/{filiacao_id}', 'AdminController::marcarPago');
post('/admin/enviar-email/{filiacao_id}', 'AdminController::enviarEmail');
post('/admin/excluir/pagamento/{filiacao_id}', 'AdminController::excluirPagamento');
post('/admin/excluir/pessoa/{pessoa_id}', 'AdminController::excluirPessoa');
get('/admin/envio/{id}', 'AdminController::verEnvio');
get('/admin/templates', 'AdminController::templates');
post('/admin/templates', 'AdminController::salvarTemplate');
post('/admin/templates/resetar', 'AdminController::resetarTemplate');
get('/admin/download/banco', 'AdminController::downloadBanco');
get('/admin/download/csv', 'AdminController::downloadCsv');

// === Assets estaticos ===
// Servidos diretamente pelo Apache, nao passa pelo PHP

// Processa a requisicao
dispatch();
