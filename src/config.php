<?php
/**
 * Pilotis - Configurações
 *
 * Carrega variáveis do .env e define constantes globais
 */

// Carrega .env se existir
$envPath = dirname(__DIR__) . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        // Ignora comentários
        if (strpos(trim($line), '#') === 0) continue;

        // Parse KEY=value
        if (strpos($line, '=') !== false) {
            list($key, $value) = array_map('trim', explode('=', $line, 2));
            // Remove aspas se houver
            $value = trim($value, '"\'');
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

// Função helper para obter configuração
function env(string $key, $default = null) {
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

// Diretório base do projeto
define('BASE_DIR', dirname(__DIR__));
define('SRC_DIR', BASE_DIR . '/src');
define('PUBLIC_DIR', BASE_DIR . '/public');
define('DATA_DIR', BASE_DIR . '/data');

// Banco de dados
define('DATABASE_PATH', env('DATABASE_PATH', DATA_DIR . '/pilotis.db'));

// PagBank
define('PAGBANK_TOKEN', env('PAGBANK_TOKEN', ''));
define('PAGBANK_SANDBOX', filter_var(env('PAGBANK_SANDBOX', 'true'), FILTER_VALIDATE_BOOLEAN));
define('PAGBANK_API_URL', PAGBANK_SANDBOX
    ? 'https://sandbox.api.pagseguro.com'
    : 'https://api.pagseguro.com');

// Brevo (Email)
define('BREVO_API_KEY', env('BREVO_API_KEY', ''));
define('EMAIL_FROM', env('EMAIL_FROM', 'tesouraria@docomomobrasil.com'));
define('EMAIL_FROM_NAME', 'Docomomo Brasil');

// App
define('BASE_URL', env('BASE_URL', 'http://localhost:8000'));
define('SECRET_KEY', env('SECRET_KEY', 'chave_secreta_padrao'));

// Admin
define('ADMIN_PASSWORD', env('ADMIN_PASSWORD', ''));

// Valores de filiação (em centavos)
define('VALOR_ESTUDANTE', (int) env('VALOR_ESTUDANTE', 11500));
define('VALOR_PROFISSIONAL', (int) env('VALOR_PROFISSIONAL', 23000));
define('VALOR_INTERNACIONAL', (int) env('VALOR_INTERNACIONAL', 46000));

// Categorias de filiação
define('CATEGORIAS_FILIACAO', [
    'profissional_internacional' => [
        'nome' => 'Docomomo. Filiado Pleno Internacional + Brasil',
        'valor' => VALOR_INTERNACIONAL
    ],
    'profissional_nacional' => [
        'nome' => 'Docomomo. Filiado Pleno Brasil',
        'valor' => VALOR_PROFISSIONAL
    ],
    'estudante' => [
        'nome' => 'Docomomo. Filiado Estudante (Graduacao/Pos) Brasil',
        'valor' => VALOR_ESTUDANTE
    ],
]);

// Nomes de categorias para exibição
define('CATEGORIAS_DISPLAY', [
    'profissional_internacional' => 'Filiação Plena Docomomo Internacional+Brasil',
    'profissional_nacional' => 'Filiação Plena Docomomo Brasil',
    'estudante' => 'Filiação Estudante Docomomo Brasil',
]);

/**
 * Retorna valor da filiação por categoria (em centavos)
 */
function valor_por_categoria(string $categoria): int {
    return CATEGORIAS_FILIACAO[$categoria]['valor'] ?? VALOR_PROFISSIONAL;
}

/**
 * Formata valor de centavos para reais
 */
function formatar_valor(int $centavos): string {
    $valor = $centavos / 100;
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

/**
 * Gera token seguro
 */
function gerar_token(int $length = 22): string {
    return bin2hex(random_bytes($length));
}

/**
 * Debug: imprime variável formatada
 */
function dd($var): void {
    echo '<pre>';
    var_dump($var);
    echo '</pre>';
    exit;
}
