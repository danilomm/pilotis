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

    $method = $_SERVER['REQUEST_METHOD'];
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    // Remove trailing slash (exceto para /)
    if ($uri !== '/' && substr($uri, -1) === '/') {
        $uri = rtrim($uri, '/');
    }

    // Procura rota correspondente
    $routes = $_routes[$method] ?? [];

    foreach ($routes as $pattern => $handler) {
        $params = match_route($pattern, $uri);
        if ($params !== false) {
            try {
                call_user_func_array($handler, $params);
                return;
            } catch (Exception $e) {
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
    header("Location: $url", true, $code);
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
        return json_decode($json, true) ?? [];
    }

    return $_POST;
}

/**
 * Escapa string para HTML
 */
function e(string $string): string {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Inicia sessão se não iniciada
 */
function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
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
