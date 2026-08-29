<?php
/**
 * Pilotis - Front Controller
 *
 * Todas as requisicoes passam por aqui
 */

// Erros: logar sempre, exibir apenas em desenvolvimento
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);

// Carrega arquivos base
require_once __DIR__ . '/../src/config.php';

// Ativa display_errors apenas em desenvolvimento (APP_DEBUG, nao o flag do PagBank)
if (defined('APP_DEBUG') && APP_DEBUG) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
}
require_once __DIR__ . '/../src/routes.php';
require_once __DIR__ . '/../src/db.php';

// Autoload do Composer (para TCPDF e outras bibliotecas)
$autoload = BASE_DIR . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

// Carrega Controllers
require_once SRC_DIR . '/Controllers/FiliacaoController.php';
require_once SRC_DIR . '/Controllers/AdminController.php';
require_once SRC_DIR . '/Controllers/EventosController.php';
require_once SRC_DIR . '/Controllers/ValidacaoController.php';

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

// Vinculacao de cadastro antigo (oferecida apos salvar formulario)
get('/filiacao/{ano}/{token}/vincular-cadastro', 'FiliacaoController::vincularCadastro');
post('/filiacao/{ano}/{token}/vincular-cadastro', 'FiliacaoController::processarVinculacao');
// Confirmacao vinda do email do cadastro ANTIGO — o unico caminho que funde
// cadastros. Vem por GET porque quem clica esta no cliente de email.
get('/filiacao/{ano}/{token}/confirmar-vinculo', 'FiliacaoController::confirmarVinculo');

// Pagamento
get('/filiacao/{ano}/{token}/pagamento', 'FiliacaoController::pagamento');
post('/filiacao/{ano}/{token}/gerar-pix', 'FiliacaoController::gerarPix');
post('/filiacao/{ano}/{token}/gerar-boleto', 'FiliacaoController::gerarBoleto');
post('/filiacao/{ano}/{token}/pagar-cartao', 'FiliacaoController::pagarCartao');

// === Filiados (lista publica) ===

// Validacao publica de documento (QR do comprovante)
get('/validar/{codigo}', 'ValidacaoController::validar');

// === Eventos (fluxo publico de inscricao) ===
// Rotas estaticas ANTES das dinamicas ({token})
get('/eventos', 'EventosController::listar');
get('/eventos/{slug}', 'EventosController::pagina');
// Antes de /eventos/{slug}/{token}: o dispatcher casa na ordem, e a rota de
// token engoliria "inscricao" como se fosse um.
get('/eventos/{slug}/inscricao', 'EventosController::inscricao');
post('/eventos/{slug}/inscrever', 'EventosController::inscrever');
// Painel de leitura da organizacao do evento (acesso por email autorizado).
// ANTES das rotas com {token}: 'organizacao' casaria com {token} e cairia no
// formulario de inscricao.
get('/eventos/{slug}/organizacao', 'EventosController::organizacaoEntrada');
post('/eventos/{slug}/organizacao', 'EventosController::organizacaoEnviarLink');
get('/eventos/{slug}/organizacao/acesso/{token}', 'EventosController::organizacaoAcesso');
get('/eventos/{slug}/organizacao/inscritos', 'EventosController::organizacaoInscritos');
get('/eventos/{slug}/organizacao/planilha', 'EventosController::organizacaoXlsx');
get('/eventos/{slug}/organizacao/csv', 'EventosController::organizacaoCsv');
get('/eventos/{slug}/organizacao/sair', 'EventosController::organizacaoSair');

get('/eventos/{slug}/{token}', 'EventosController::formulario');
post('/eventos/{slug}/{token}', 'EventosController::salvar');
get('/eventos/{slug}/{token}/vincular', 'EventosController::vincular');
post('/eventos/{slug}/{token}/vincular', 'EventosController::processarVinculacao');
get('/eventos/{slug}/{token}/confirmar-vinculo', 'EventosController::confirmarVinculo');
get('/eventos/{slug}/{token}/pagamento', 'EventosController::pagamento');
post('/eventos/{slug}/{token}/gerar-pix', 'EventosController::gerarPix');
post('/eventos/{slug}/{token}/gerar-boleto', 'EventosController::gerarBoleto');
post('/eventos/{slug}/{token}/pagar-cartao', 'EventosController::pagarCartao');

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
post('/admin/campanha/fechar', 'AdminController::fecharCampanha');
post('/admin/campanha/valores', 'AdminController::salvarValores');
post('/admin/campanha/data-fim', 'AdminController::salvarDataFim');
post('/admin/campanha/iniciar', 'AdminController::iniciarCampanha');
post('/admin/campanha/enviar-lote', 'AdminController::enviarLote');
post('/admin/campanha/preview-lote', 'AdminController::previewLote');
post('/admin/campanha/grupo-teste', 'AdminController::salvarGrupoTeste');
post('/admin/campanha/enviar-teste', 'AdminController::enviarGrupoTeste');
post('/admin/lembretes/processar', 'AdminController::processarLembretes');
post('/admin/lembretes/contar', 'AdminController::contarLembretes');
// Eventos (admin) — rotas estaticas ANTES das dinamicas ({id})
get('/admin/eventos', 'AdminController::eventos');
get('/admin/eventos/novo', 'AdminController::eventoNovoForm');
post('/admin/eventos/novo', 'AdminController::eventoNovoSalvar');
get('/admin/eventos/{id}', 'AdminController::evento');
post('/admin/eventos/{id}', 'AdminController::eventoSalvar');
post('/admin/eventos/{id}/status', 'AdminController::eventoStatus');
post('/admin/eventos/{id}/excluir', 'AdminController::eventoExcluir');
post('/admin/eventos/{id}/categoria', 'AdminController::eventoCategoriaSalvar');
post('/admin/eventos/{id}/categoria/{cat_id}/excluir', 'AdminController::eventoCategoriaExcluir');
get('/admin/eventos/{id}/inscritos', 'AdminController::eventoInscritos');
get('/admin/eventos/{id}/inscritos.xlsx', 'AdminController::eventoInscritosXlsx');
get('/admin/eventos/{id}/inscritos.csv', 'AdminController::eventoInscritosCsv');
get('/admin/eventos/{id}/comprovante/{pessoa_id}', 'AdminController::eventoComprovante');
get('/admin/eventos/{id}/categoria/{cat_id}/convites', 'AdminController::eventoConvitesForm');
post('/admin/eventos/{id}/categoria/{cat_id}/convidar', 'AdminController::eventoEnviarConvites');
get('/admin/login', 'AdminController::loginForm');
post('/admin/login', 'AdminController::login');
get('/admin/logout', 'AdminController::logout');
get('/admin/log', 'AdminController::log');
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
post('/admin/enviar-confirmacao/{filiacao_id}', 'AdminController::enviarConfirmacao');
post('/admin/eventos/inscricao/{inscricao_id}/enviar-confirmacao', 'AdminController::enviarConfirmacaoInscricao');
post('/admin/excluir/pagamento/{filiacao_id}', 'AdminController::excluirPagamento');
post('/admin/excluir/pessoa/{pessoa_id}', 'AdminController::excluirPessoa');
get('/admin/envio/{id}', 'AdminController::verEnvio');
get('/admin/templates', 'AdminController::templates');
post('/admin/templates', 'AdminController::salvarTemplate');
post('/admin/templates/resetar', 'AdminController::resetarTemplate');
get('/admin/download/banco', 'AdminController::downloadBanco');
get('/admin/download/csv', 'AdminController::downloadCsv');
get('/admin/comprovante/{pessoa_id}/{ano}', 'AdminController::downloadComprovante');

// === Assets estaticos ===
// Servidos diretamente pelo Apache, nao passa pelo PHP

// Processa a requisicao
dispatch();
