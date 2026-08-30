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
require_once SRC_DIR . '/Filiacao/FiliacaoController.php';
require_once SRC_DIR . '/Controllers/AdminController.php';
// Os controllers do admin ESTENDEM AdminController — a linha acima vem antes,
// senao a classe base nao existe na hora de definir as filhas.
require_once SRC_DIR . '/Campanha/AdminCampanhaController.php';
require_once SRC_DIR . '/Filiacao/AdminPessoasController.php';
require_once SRC_DIR . '/Eventos/AdminEventosController.php';
require_once SRC_DIR . '/Campanha/AdminTemplatesController.php';
require_once SRC_DIR . '/Eventos/EventosController.php';
require_once SRC_DIR . '/Eventos/ValidacaoController.php';

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
get('/admin/campanha', 'AdminCampanhaController::campanha');
post('/admin/campanha/criar', 'AdminCampanhaController::criarCampanha');
post('/admin/campanha/excluir', 'AdminCampanhaController::excluirCampanha');
post('/admin/campanha/fechar', 'AdminCampanhaController::fecharCampanha');
post('/admin/campanha/valores', 'AdminCampanhaController::salvarValores');
post('/admin/campanha/data-fim', 'AdminCampanhaController::salvarDataFim');
post('/admin/campanha/iniciar', 'AdminCampanhaController::iniciarCampanha');
post('/admin/campanha/enviar-lote', 'AdminCampanhaController::enviarLote');
post('/admin/campanha/preview-lote', 'AdminCampanhaController::previewLote');
post('/admin/campanha/grupo-teste', 'AdminCampanhaController::salvarGrupoTeste');
post('/admin/campanha/enviar-teste', 'AdminCampanhaController::enviarGrupoTeste');
post('/admin/lembretes/processar', 'AdminCampanhaController::processarLembretes');
post('/admin/lembretes/contar', 'AdminCampanhaController::contarLembretes');
// Eventos (admin) — rotas estaticas ANTES das dinamicas ({id})
get('/admin/eventos', 'AdminEventosController::eventos');
get('/admin/eventos/novo', 'AdminEventosController::eventoNovoForm');
post('/admin/eventos/novo', 'AdminEventosController::eventoNovoSalvar');
get('/admin/eventos/{id}', 'AdminEventosController::evento');
post('/admin/eventos/{id}', 'AdminEventosController::eventoSalvar');
post('/admin/eventos/{id}/status', 'AdminEventosController::eventoStatus');
post('/admin/eventos/{id}/excluir', 'AdminEventosController::eventoExcluir');
post('/admin/eventos/{id}/categoria', 'AdminEventosController::eventoCategoriaSalvar');
post('/admin/eventos/{id}/categoria/{cat_id}/excluir', 'AdminEventosController::eventoCategoriaExcluir');
get('/admin/eventos/{id}/inscritos', 'AdminEventosController::eventoInscritos');
get('/admin/eventos/{id}/inscritos.xlsx', 'AdminEventosController::eventoInscritosXlsx');
get('/admin/eventos/{id}/inscritos.csv', 'AdminEventosController::eventoInscritosCsv');
get('/admin/eventos/{id}/comprovante/{pessoa_id}', 'AdminEventosController::eventoComprovante');
get('/admin/eventos/{id}/categoria/{cat_id}/convites', 'AdminEventosController::eventoConvitesForm');
post('/admin/eventos/{id}/categoria/{cat_id}/convidar', 'AdminEventosController::eventoEnviarConvites');
get('/admin/login', 'AdminController::loginForm');
post('/admin/login', 'AdminController::login');
get('/admin/logout', 'AdminController::logout');
get('/admin/log', 'AdminController::log');
get('/admin/contatos', 'AdminController::contatos');
get('/admin/buscar', 'AdminController::buscar');
get('/admin/pessoa/{id}', 'AdminPessoasController::pessoa');
post('/admin/pessoa/{id}', 'AdminPessoasController::salvarPessoa');
get('/admin/filiacao/{id}', 'AdminPessoasController::filiacao');
post('/admin/filiacao/{id}', 'AdminPessoasController::salvarFiliacao');
get('/admin/novo', 'AdminPessoasController::novoForm');
post('/admin/novo', 'AdminPessoasController::novoSalvar');
post('/admin/pagar/{filiacao_id}', 'AdminPessoasController::marcarPago');
post('/admin/enviar-email/{filiacao_id}', 'AdminPessoasController::enviarEmail');
post('/admin/enviar-confirmacao/{filiacao_id}', 'AdminPessoasController::enviarConfirmacao');
post('/admin/eventos/inscricao/{inscricao_id}/enviar-confirmacao', 'AdminEventosController::enviarConfirmacaoInscricao');
post('/admin/excluir/pagamento/{filiacao_id}', 'AdminPessoasController::excluirPagamento');
post('/admin/excluir/pessoa/{pessoa_id}', 'AdminPessoasController::excluirPessoa');
get('/admin/envio/{id}', 'AdminCampanhaController::verEnvio');
get('/admin/templates', 'AdminTemplatesController::templates');
post('/admin/templates', 'AdminTemplatesController::salvarTemplate');
post('/admin/templates/resetar', 'AdminTemplatesController::resetarTemplate');
get('/admin/download/banco', 'AdminController::downloadBanco');
get('/admin/download/csv', 'AdminController::downloadCsv');
get('/admin/comprovante/{pessoa_id}/{ano}', 'AdminPessoasController::downloadComprovante');

// === Assets estaticos ===
// Servidos diretamente pelo Apache, nao passa pelo PHP

// Processa a requisicao
dispatch();
