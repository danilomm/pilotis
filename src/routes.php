<?php
/**
 * Pilotis - Sistema de Rotas
 *
 * Roteamento simples sem framework
 */

// Armazena as rotas registradas
$_routes = [
    'GET' => [],
    'POST' => [],
];

/**
 * Registra uma rota GET
 */
function get(string $pattern, callable $handler): void {
    global $_routes;
    $_routes['GET'][$pattern] = $handler;
}

/**
 * Registra uma rota POST
 */
function post(string $pattern, callable $handler): void {
    global $_routes;
    $_routes['POST'][$pattern] = $handler;
}

/**
 * Processa a requisição atual
 */
function dispatch(): void {
    global $_routes;

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // parse_url devolve false em URI malformada e null quando não há caminho.
    // Sem esta guarda o valor segue para strpos/substr/match_route, que tipa
    // $uri como string: nenhuma rota casa e a pessoa vê 404 no lugar da página.
    $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (!is_string($uri) || $uri === '') {
        $uri = '/';
    }

    // Instalacao em subdiretorio: tira o prefixo antes de casar as rotas,
    // para que elas continuem escritas como '/eventos/...' etc.
    if (BASE_PATH !== '' && strpos($uri, BASE_PATH) === 0) {
        $uri = substr($uri, strlen(BASE_PATH)) ?: '/';
    }

    // Remove trailing slash (exceto para /)
    if ($uri !== '/' && substr($uri, -1) === '/') {
        $uri = rtrim($uri, '/');
    }

    // Procura rota correspondente
    $routes = $_routes[$method] ?? [];

    // Rotas que NAO podem exigir CSRF: quem posta e um servidor de fora, sem
    // sessao e sem como carregar um token nosso. O webhook tem a propria
    // defesa — nao acredita no corpo, consulta a API do PagBank e processa a
    // resposta dela.
    $isentas_csrf = ['/webhook/pagbank'];

    foreach ($routes as $pattern => $handler) {
        $params = match_route($pattern, $uri);
        if ($params !== false) {
            if ($method === 'POST' && !in_array($uri, $isentas_csrf, true) && !csrf_valido()) {
                registrar_log('csrf_recusado', null, 'POST sem token valido em ' . $uri);
                // Sessao vencida e o caso comum e inocente: a pessoa deixou o
                // formulario aberto por horas. A mensagem trata disso, nao de
                // ataque, e o caminho de volta e recarregar a pagina.
                if (str_starts_with($uri, '/admin/') && !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
                    // 419 so no ramo JSON: no ramo do formulario o redirect
                    // escreve o proprio 303 por cima, e ficaria so enganando
                    // quem lesse o codigo.
                    http_response_code(419);
                    header('Content-Type: application/json');
                    echo json_encode(['erro' => 'Sessão expirada. Recarregue a página.']);
                    return;
                }
                flash('error', 'A página expirou por inatividade. Recarregue e tente de novo.');
                // Volta pelo Referer SO se ele for deste site. Ele e escrito
                // pelo navegador de quem manda o POST, entao aceita-lo cru
                // transformaria esta tela num redirecionador para fora.
                $volta = '/';
                $ref = $_SERVER['HTTP_REFERER'] ?? '';
                if ($ref !== '') {
                    $p_ref = parse_url($ref);
                    $host_ref = $p_ref['host'] ?? '';
                    $host_nosso = parse_url(BASE_URL, PHP_URL_HOST) ?: '';
                    if ($host_ref !== '' && strcasecmp($host_ref, $host_nosso) === 0) {
                        $caminho = $p_ref['path'] ?? '/';
                        if (BASE_PATH !== '' && strpos($caminho, BASE_PATH) === 0) {
                            $caminho = substr($caminho, strlen(BASE_PATH)) ?: '/';
                        }
                        $volta = $caminho;
                    }
                }
                redirect($volta);
                return;
            }
            try {
                // Workaround: forca status 200 antes de chamar o handler.
                // Necessario em alguns servidores FastCGI onde o status padrao
                // apos rewrite e incorretamente definido como 404.
                //
                // http_response_code(), e NAO header('HTTP/1.1 200 OK'): a
                // segunda forma grava uma linha de status CRUA, que um
                // http_response_code(404) posterior nao substitui. Com ela,
                // todo error_404() e todo error_500() levantado de dentro de
                // uma rota que casou respondia 200 — o 404 do slug errado era
                // indexavel, e a excecao no pagamento aparecia como pagina sa
                // para qualquer monitor de disponibilidade.
                http_response_code(200);
                call_user_func_array($handler, $params);
                return;
            } catch (Throwable $e) {
                error_log("Pilotis Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine() . "\nTrace: " . $e->getTraceAsString());
                error_500($e->getMessage());
                return;
            }
        }
    }

    // Nenhuma rota encontrada
    error_404();
}

/**
 * Verifica se URI corresponde ao padrão e extrai parâmetros
 *
 * Padrões:
 *   /filiacao/{ano}         -> ['ano' => valor]
 *   /filiacao/{ano}/{token} -> ['ano' => valor, 'token' => valor]
 */
function match_route(string $pattern, string $uri): array|false {
    // Converte padrão em regex
    // {param} -> captura grupo nomeado
    $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
    $regex = '#^' . $regex . '$#';

    if (preg_match($regex, $uri, $matches)) {
        // Retorna apenas os grupos nomeados
        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }
        return $params;
    }

    return false;
}

/**
 * Redireciona para outra URL
 */
function redirect(string $url, int $code = 303): void {
    // http_response_code() resolve os dois SAPI sozinho, entao o ramo por
    // php_sapi_name() sai. Alem disso a forma antiga escrevia "See Other"
    // fixo — um redirect(301) saia como "HTTP/1.1 301 See Other".
    http_response_code($code);
    // Instalacao em subdiretorio: prefixa caminhos internos ('/x'), nunca
    // URLs absolutas ('http://...') nem protocolo-relativas ('//host').
    if (BASE_PATH !== '' && isset($url[0]) && $url[0] === '/' && substr($url, 0, 2) !== '//') {
        $url = BASE_PATH . $url;
    }

    header("Location: $url");
    exit;
}

/**
 * Retorna resposta JSON
 */
function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Erro 404
 */
function error_404(): void {
    http_response_code(404);
    require SRC_DIR . '/Views/errors/404.php';
    exit;
}

/**
 * Erro 500
 */
function error_500(string $message = ''): void {
    http_response_code(500);
    $error_message = $message;
    require SRC_DIR . '/Views/errors/500.php';
    exit;
}

/**
 * Obtém dados do POST (form ou JSON)
 */
function get_post_data(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';

    if (strpos($contentType, 'application/json') !== false) {
        $json = file_get_contents('php://input');
        // Corpo ilegível vira lista vazia, e não string coagida de false.
        return is_string($json) ? (json_decode($json, true) ?? []) : [];
    }

    return $_POST;
}

/**
 * Inicia sessão se não iniciada
 */
function start_session(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // O cookie de sessao nascia sem flag nenhuma (so path=/), o que significa:
    // trafega em HTTP e da para capturar; e legivel por JavaScript, entao
    // qualquer XSS vira roubo de sessao do admin. Sao tres atributos.
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => BASE_PATH !== '' ? BASE_PATH . '/' : '/',
        'httponly' => true,
        // Secure so quando a requisicao e https: marcar sempre quebraria o
        // desenvolvimento local em http://localhost.
        'secure'   => $https,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * CSRF: prova de que o POST saiu de uma pagina NOSSA, e nao de outro site.
 *
 * POR QUE: ate 29/08/2026 nenhuma das 44 rotas POST conferia origem. Com o
 * tesoureiro logado no /admin, uma pagina qualquer aberta noutra aba podia
 * disparar "marcar como pago", "excluir pessoa" ou o envio de um lote de
 * campanha — o navegador manda o cookie de sessao sozinho.
 *
 * O SameSite=Lax do cookie ajuda mas nao basta: docomomobrasil.com e
 * pilotis.docomomobrasil.com sao o MESMO site para essa regra, entao um
 * WordPress comprometido — cuja senha esta no .env de producao — passaria por
 * ela sem esforco.
 */
function csrf_token(): string {
    start_session();
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/**
 * Campo escondido para colar dentro de <form method="POST">.
 */
function campo_csrf(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

/**
 * Confere o token do POST. Aceita tambem o cabecalho X-CSRF-Token, que e como
 * o painel de campanha manda os lotes por fetch().
 *
 * hash_equals, e nao ===: comparacao de segredo nao pode vazar por tempo.
 */
function csrf_valido(): bool {
    start_session();
    $guardado = $_SESSION['_csrf'] ?? '';
    if ($guardado === '') return false;
    $enviado = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($enviado) && $enviado !== '' && hash_equals($guardado, $enviado);
}

/**
 * Define flash message
 */
function flash(string $key, string $message): void {
    start_session();
    $_SESSION['_flash'][$key] = $message;
}

/**
 * Obtém e limpa flash message
 */
function get_flash(string $key): ?string {
    start_session();
    $message = $_SESSION['_flash'][$key] ?? null;
    unset($_SESSION['_flash'][$key]);
    return $message;
}

/**
 * Verifica se há flash message
 */
function has_flash(string $key): bool {
    start_session();
    return isset($_SESSION['_flash'][$key]);
}

/**
 * Inicia a sessao no bootstrap, ANTES de qualquer saida.
 *
 * As flash messages sao lidas em Views/layout.php (get_flash), que roda depois
 * de o HTML do cabecalho ja ter sido impresso. Sem isto, o session_start() de
 * get_flash() acontece com os headers ja enviados: falha, emite warning e o
 * $_SESSION nunca e populado — ou seja, TODA mensagem de erro do fluxo publico
 * era silenciosamente perdida (a pessoa voltava ao formulario sem explicacao).
 * Em producao o warning ficava escondido (display_errors off) mas continuava
 * poluindo o php_errors.log.
 */
if (PHP_SAPI !== 'cli') {
    start_session();

    // Instalacao em subdiretorio: as views tem ~180 caminhos absolutos
    // ('href="/eventos/..."'). Em vez de reescrever todos, prefixamos na saida.
    // Inerte quando BASE_PATH esta vazio (producao).
    if (BASE_PATH !== '') {
        ob_start(function (string $html): string {
            return preg_replace(
                '~\b(href|action|src)="/(?!/)~',
                '$1="' . BASE_PATH . '/',
                $html
            );
        });
    }
}
