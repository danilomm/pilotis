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

// Banco de dados (resolve caminho relativo para absoluto)
$db_path = env('DATABASE_PATH', 'dados/data/pilotis.db');
if ($db_path[0] !== '/') {
    $db_path = BASE_DIR . '/' . $db_path;
}
define('DATABASE_PATH', $db_path);

// PagBank
define('PAGBANK_TOKEN', env('PAGBANK_TOKEN', ''));
define('PAGBANK_SANDBOX', filter_var(env('PAGBANK_SANDBOX', 'true'), FILTER_VALIDATE_BOOLEAN));
define('PAGBANK_API_URL', PAGBANK_SANDBOX
    ? 'https://sandbox.api.pagseguro.com'
    : 'https://api.pagseguro.com');

// Organização
define('ORG_NOME', env('ORG_NOME', 'Minha Organização'));
define('ORG_SIGLA', env('ORG_SIGLA', 'ORG'));
define('ORG_LOGO', env('ORG_LOGO', 'logo.png'));
define('ORG_COR_PRIMARIA', env('ORG_COR_PRIMARIA', '#4a8c4a'));
define('ORG_COR_SECUNDARIA', env('ORG_COR_SECUNDARIA', '#7ab648'));
define('ORG_EMAIL_CONTATO', env('ORG_EMAIL_CONTATO', ''));
define('ORG_SITE_URL', env('ORG_SITE_URL', ''));
define('ORG_INSTAGRAM', env('ORG_INSTAGRAM', ''));

// Brevo (Email)
define('BREVO_API_KEY', env('BREVO_API_KEY', ''));
define('EMAIL_FROM', env('EMAIL_FROM', ORG_EMAIL_CONTATO));
define('EMAIL_FROM_NAME', ORG_NOME);

// App
define('BASE_URL', env('BASE_URL', 'http://localhost:8000'));
define('SECRET_KEY', env('SECRET_KEY', 'chave_secreta_padrao'));

// Admin
define('ADMIN_PASSWORD', env('ADMIN_PASSWORD', ''));

// Categorias de filiação (parseadas do .env)
// Formato: chave:label:valor_centavos,chave:label:valor,...
$_categorias_env = env('CATEGORIAS', 'profissional_internacional:Pleno Internacional+Brasil:46000,profissional_nacional:Pleno Brasil:23000,estudante:Estudante:11500');
$_categorias_filiacao = [];
$_categorias_display = [];
$_valores = [];

foreach (explode(',', $_categorias_env) as $cat_str) {
    $parts = explode(':', trim($cat_str), 3);
    if (count($parts) === 3) {
        $key = trim($parts[0]);
        $label = trim($parts[1]);
        $valor = (int) trim($parts[2]);
        $_categorias_filiacao[$key] = [
            'nome' => ORG_SIGLA . '. ' . $label,
            'valor' => $valor,
        ];
        $_categorias_display[$key] = $label;
        $_valores[$key] = $valor;
    }
}

define('CATEGORIAS_FILIACAO', $_categorias_filiacao);
define('CATEGORIAS_DISPLAY', $_categorias_display);

// Valores legados (compatibilidade) — usa primeiro, segundo e terceiro da lista
$_vals = array_values($_valores);
define('VALOR_ESTUDANTE', $_vals[2] ?? $_vals[0] ?? 11500);
define('VALOR_PROFISSIONAL', $_vals[1] ?? $_vals[0] ?? 23000);
define('VALOR_INTERNACIONAL', $_vals[0] ?? 46000);

// Opções de formação acadêmica
define('FORMACOES', [
    'Ensino Médio',
    'Graduação em andamento',
    'Graduação',
    'Especialização / MBA em andamento',
    'Especialização / MBA',
    'Mestrado em andamento',
    'Mestrado',
    'Doutorado em andamento',
    'Doutorado',
    'Pós-Doutorado',
]);

/**
 * Retorna valor da filiação por categoria (em centavos)
 * Se o ano for informado, busca valores específicos da campanha
 */
function valor_por_categoria(string $categoria, ?int $ano = null): int {
    if ($ano) {
        $valores = valores_campanha($ano);
        if ($valores) {
            return match($categoria) {
                'estudante' => $valores['valor_estudante'],
                'profissional_nacional' => $valores['valor_profissional'],
                'profissional_internacional' => $valores['valor_internacional'],
                default => $valores['valor_internacional'],
            };
        }
    }
    return CATEGORIAS_FILIACAO[$categoria]['valor'] ?? VALOR_PROFISSIONAL;
}

/**
 * Retorna valores de filiação para um ano específico
 * Busca na tabela campanhas; se não definidos, usa valores do .env
 */
function valores_campanha(int $ano): array {
    $campanha = db_fetch_one(
        "SELECT valor_estudante, valor_profissional, valor_internacional FROM campanhas WHERE ano = ?",
        [$ano]
    );

    return [
        'valor_estudante' => (int)($campanha['valor_estudante'] ?? VALOR_ESTUDANTE),
        'valor_profissional' => (int)($campanha['valor_profissional'] ?? VALOR_PROFISSIONAL),
        'valor_internacional' => (int)($campanha['valor_internacional'] ?? VALOR_INTERNACIONAL),
    ];
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
