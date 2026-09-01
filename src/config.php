<?php
/**
 * Pilotis - Configurações
 *
 * Carrega variáveis do .env e define constantes globais
 */

date_default_timezone_set('America/Sao_Paulo');

// Carrega .env se existir
$envPath = dirname(__DIR__) . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    // file() devolve false quando o arquivo existe mas nao pode ser lido —
    // permissao errada depois de um upload por FTP, por exemplo. Sem esta
    // guarda o foreach so emitia warning e o sistema subia INTEIRO com os
    // valores padrao: banco no lugar errado, sem token de cron, sem chave do
    // Brevo. Falha silenciosa e o pior jeito de descobrir isso.
    if ($lines === false) {
        error_log('Pilotis: .env existe mas nao pode ser lido (permissao?): ' . $envPath);
        $lines = [];
    }
    foreach ($lines as $line) {
        // Ignora comentários
        if (strpos(trim($line), '#') === 0) continue;

        // Parse KEY=value
        if (strpos($line, '=') !== false) {
            list($key, $value) = array_map('trim', explode('=', $line, 2));
            // Remove aspas se houver
            $value = trim($value, '"\'');
            $_ENV[$key] = $value;
            // putenv pode estar desabilitado em hospedagem compartilhada
            if (function_exists('putenv')) {
                @putenv("$key=$value");
            }
        }
    }
}

// Função helper para obter configuração
function env(string $key, $default = null) {
    // Prioriza $_ENV (sempre funciona)
    if (isset($_ENV[$key])) {
        return $_ENV[$key];
    }
    // Fallback para getenv (pode estar desabilitado)
    if (function_exists('getenv')) {
        $val = @getenv($key);
        if ($val !== false) {
            return $val;
        }
    }
    return $default;
}

// Diretório base do projeto
define('BASE_DIR', dirname(__DIR__));
define('SRC_DIR', BASE_DIR . '/src');
// Os dois layouts divergem: local o public/ e irmao do src/; no servidor o
// src/ mora DENTRO do document root (www/), e nao existe www/public/. Sem esta
// deteccao, PUBLIC_DIR aponta para um diretorio inexistente em producao — o
// que hoje faz a declaracao em PDF sair sem logotipo (o file_exists engole em
// silencio) e faria o cartaz do evento ser gravado onde o Apache nao serve.
define('PUBLIC_DIR', is_dir(BASE_DIR . '/public') ? BASE_DIR . '/public' : BASE_DIR);
define('DATA_DIR', BASE_DIR . '/data');

// Diretório de comprovantes de matrícula (deve ficar fora do document root)
$comprovantes_dir = env('COMPROVANTES_DIR', '');
if (empty($comprovantes_dir)) {
    // Mesmo diretório do banco de dados
    $db_dir = dirname(env('DATABASE_PATH', 'dados/data/pilotis.db'));
    if ($db_dir[0] !== '/') {
        $db_dir = BASE_DIR . '/' . $db_dir;
    }
    $comprovantes_dir = $db_dir . '/comprovantes';
}
define('COMPROVANTES_DIR', $comprovantes_dir);

// Cartazes de eventos: PUBLICOS (servidos pelo navegador), ao contrario dos
// comprovantes. Ficam sob o document root, em assets/img/eventos/.
define('EVENTOS_IMG_DIR', PUBLIC_DIR . '/assets/img/eventos');
define('EVENTOS_IMG_URL', '/assets/img/eventos');

// Documentos publicos do evento (hoje so a programacao, em PDF). Pasta separada
// da de imagem porque o conteudo e de outra natureza e a validacao e outra.
define('EVENTOS_DOC_DIR', PUBLIC_DIR . '/assets/doc/eventos');
define('EVENTOS_DOC_URL', '/assets/doc/eventos');

// Banco de dados (resolve caminho relativo para absoluto)
$db_path = env('DATABASE_PATH', 'dados/data/pilotis.db');
if ($db_path[0] !== '/') {
    $db_path = BASE_DIR . '/' . $db_path;
}
define('DATABASE_PATH', $db_path);

// Prefixo de instalacao em subdiretorio (ex: '/teste'). Vazio = raiz do dominio.
// Em producao fica VAZIO; so o ambiente de teste define BASE_PATH no .env.
// Tambem serve a quem instalar o Pilotis em subpasta (o sistema e GPL).
$base_path = trim((string)env('BASE_PATH', ''));
$base_path = $base_path === '' ? '' : '/' . trim($base_path, '/');
define('BASE_PATH', $base_path);

// Modo ensaio de email: registra no log e devolve sucesso, SEM chamar o Brevo.
// Existe para o ambiente de teste poder percorrer o fluxo inteiro sem que um
// unico email escape para pessoa real. Em producao fica FALSE (ausente do .env).
// Trava de seguranca: em localhost NUNCA envia email de verdade, qualquer que
// seja o .env carregado. Sem isto, rodar um script de teste a partir da raiz do
// projeto (onde o .env tem a chave real do Brevo) dispara email para pessoa
// real do banco — foi o que quase aconteceu em 27/08/2026, ao testar entrada
// por CPF com o cadastro de um filiado de verdade.
$email_dry_run = filter_var(env('EMAIL_DRY_RUN', 'false'), FILTER_VALIDATE_BOOLEAN);
$em_localhost = (bool)preg_match('~^https?://(localhost|127\.0\.0\.1|\[::1\])(:|/|$)~i', (string)env('BASE_URL', ''));
define('EMAIL_DRY_RUN', $email_dry_run || $em_localhost);

// Instalacao local (nao e o mesmo que modo de teste do PagBank). Telas de
// desenvolvimento que imprimem token na pagina so podem aparecer aqui: em
// producao, com o sandbox ligado para reteste, elas entregariam o token da
// pessoa a quem digitasse o email dela.
define('AMBIENTE_LOCAL', $em_localhost);

// O .env deste servidor parece ser de outro ambiente?
//
// POR QUE: o .env nao esta em git, entao nao ha diff que pegue engano — e o
// roteiro de deploy manda "criar o .env no servidor", que e o momento exato do
// erro. Copiar o .env local para producao produz QUATRO falhas de uma vez, e
// TRES sao mudas:
//
//   BASE_URL=localhost      -> forca EMAIL_DRY_RUN: nenhum email sai, e a tela
//                              diz que saiu
//   BASE_URL=localhost      -> o pedido ao PagBank vai sem notification_urls:
//                              nenhum webhook chega
//   PAGBANK_SANDBOX=true    -> cobranca no ambiente de teste: dinheiro nenhum
//                              entra
//   APP_DEBUG=true          -> excecao impressa na pagina 500 publica
//
// A prova de ponta que o CLAUDE.md pede (pedir link para um email seu e ver se
// chega) cobre a primeira e nao as outras tres.
//
// A deteccao e barata e nao consulta banco: se estamos sendo servidos por um
// host de verdade e o .env descreve um ambiente local, alguma coisa esta errada.
// So se calcula; quem avisa e o painel do admin.
$host_servido = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$host_real = $host_servido !== ''
    && !preg_match('~^(localhost|127\.0\.0\.1|\[::1\])(:|$)~', $host_servido);

$avisos_ambiente = [];
if ($host_real && $em_localhost) {
    $avisos_ambiente[] = 'BASE_URL aponta para localhost, mas o site esta sendo '
        . 'servido por ' . $host_servido . '. Nenhum email sai e nenhum webhook chega.';
}
if ($host_real && filter_var(env('PAGBANK_SANDBOX', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    $avisos_ambiente[] = 'PAGBANK_SANDBOX esta ligado num servidor real: as '
        . 'cobrancas vao para o ambiente de teste e nenhum dinheiro entra.';
}
if ($host_real && filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    $avisos_ambiente[] = 'APP_DEBUG esta ligado num servidor real: a pagina de '
        . 'erro publica mostra detalhe de excecao.';
}
if ($host_real && filter_var(env('EMAIL_DRY_RUN', 'false'), FILTER_VALIDATE_BOOLEAN)) {
    $avisos_ambiente[] = 'EMAIL_DRY_RUN esta ligado: nenhum email sai, e a tela '
        . 'diz que saiu. Se as inscricoes ja abriram, isto quebra tudo em silencio.';
}
define('AVISOS_AMBIENTE', $avisos_ambiente);

// PagBank
define('PAGBANK_TOKEN', env('PAGBANK_TOKEN', ''));
// Padrao FALSE: omissao tem de cair no modo seguro. Antes caia em sandbox, o
// que significava que um deploy sem .env (ou com .env ilegivel) subia o sistema
// apontando para o ambiente de teste do PagBank — e, pior, ligava o
// display_errors e o bloco "Detalhes do erro" da pagina 500 publica.
define('PAGBANK_SANDBOX', filter_var(env('PAGBANK_SANDBOX', 'false'), FILTER_VALIDATE_BOOLEAN));

// Exibir detalhe de erro e outra decisao, e nao pode depender do flag de
// pagamento: quem religasse o sandbox para retestar o PagBank em producao
// reabria a mensagem de excecao ao publico sem perceber.
define('APP_DEBUG', filter_var(env('APP_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN));
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
// CNPJ e razao social — saem no rodape do comprovante de inscricao, que
// costuma ser usado para reembolso institucional (PROAP e afins). O nome de
// fantasia (ORG_NOME) nao serve nesse rodape: o CNPJ tem que aparecer ao lado
// da razao social registrada. Opcionais: se vazios, sao omitidos, e a razao
// social cai no ORG_NOME.
define('ORG_CNPJ', env('ORG_CNPJ', ''));
define('ORG_RAZAO_SOCIAL', env('ORG_RAZAO_SOCIAL', env('ORG_NOME', 'Minha Organização')));
define('ORG_SITE_URL', env('ORG_SITE_URL', ''));
define('ORG_INSTAGRAM', env('ORG_INSTAGRAM', ''));
define('ORG_ASSINANTE', env('ORG_ASSINANTE', ''));
define('ORG_CARGO', env('ORG_CARGO', ''));
define('ORG_GESTAO', env('ORG_GESTAO', ''));

// Brevo (Email)
define('BREVO_API_KEY', env('BREVO_API_KEY', ''));
define('EMAIL_FROM', env('EMAIL_FROM', ORG_EMAIL_CONTATO));
define('EMAIL_FROM_NAME', ORG_NOME);

// App
// `rtrim` na origem: `BASE_URL` com barra no fim produzia `//webhook/pagbank`
// na `notification_url` do PagBank — caminho que da 404, e que nao casa nem com
// a isencao de CSRF nem com a de manutencao, as duas por comparacao exata.
// Normalizar aqui conserta os tres lugares que concatenam sem pensar.
define('BASE_URL', rtrim(env('BASE_URL', 'http://localhost:8000'), '/'));
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
 * Retorna a data de término aplicável a uma categoria
 * Internacional usa data_fim_internacional; demais usam data_fim
 */
function data_fim_por_categoria(array $campanha, string $categoria): ?string {
    if ($categoria === 'profissional_internacional') {
        return $campanha['data_fim_internacional'] ?? null;
    }
    return $campanha['data_fim'] ?? null;
}

/**
 * Verifica se o prazo de uma categoria já expirou
 */
function categoria_expirada(array $campanha, string $categoria): bool {
    $data_fim = data_fim_por_categoria($campanha, $categoria);
    if (!$data_fim) {
        return false; // Sem prazo definido = não expirou
    }
    return $data_fim < date('Y-m-d');
}

/**
 * Formata valor de centavos para reais
 */
/**
 * 11144477735 -> 111.444.777-35. Devolve como veio se nao tiver 11 digitos,
 * para nao mutilar o que a pessoa digitou.
 */
function formatar_cpf(?string $cpf): string {
    $d = preg_replace('/\D/', '', (string)$cpf);
    if (strlen($d) !== 11) return trim((string)$cpf);
    return substr($d,0,3) . '.' . substr($d,3,3) . '.' . substr($d,6,3) . '-' . substr($d,9,2);
}

/**
 * Manda uma planilha para o navegador, em xlsx ou csv, e encerra.
 *
 * Existe para os dois exportadores (admin e painel da organizacao) nunca
 * divergirem no formato do arquivo — o que sai daqui vai para fora de casa.
 */
/**
 * Palpite de nome para cracha: primeiro nome + ultimo sobrenome.
 *
 * E so um PALPITE, para pre-preencher o campo — quem se inscreve corrige.
 * Preposicao fica de fora ("de", "da", "dos"), e nome de ate duas palavras
 * passa inteiro. Nao acerta sempre, e nao precisa: acerta o suficiente para a
 * maioria nao ter de digitar.
 */
function palpite_nome_cracha(?string $nome): string {
    $partes = preg_split('/\s+/', trim((string)$nome)) ?: [];
    $partes = array_values(array_filter($partes, fn($p) => $p !== ''));
    if (count($partes) <= 2) {
        return implode(' ', $partes);
    }
    $preposicoes = ['de','da','do','das','dos','e','del','la','du','van','von','di'];
    $ultimo = '';
    for ($i = count($partes) - 1; $i >= 1; $i--) {
        if (!in_array(mb_strtolower($partes[$i]), $preposicoes, true)) {
            $ultimo = $partes[$i];
            break;
        }
    }
    return trim($partes[0] . ' ' . $ultimo);
}

/**
 * Separa "Nome <email@dominio>" em nome e endereco.
 *
 * `eventos.email_contato` e escrito nesse formato porque no PDF ele sai como
 * uma linha so, do jeito que se assina um email. Na PAGINA ele precisa virar
 * link, e para isso o endereco tem de sair de dentro dos sinais.
 *
 * Sem os sinais, o valor inteiro e tratado como endereco — que e o caso de quem
 * digitar so o email no admin. Devolve ['nome' => ..., 'email' => ...], com
 * email vazio quando nao ha nada parecido com endereco.
 */
function contato_partes(?string $contato): array {
    $c = trim((string)$contato);
    if ($c === '') return ['nome' => '', 'email' => ''];

    if (preg_match('/^(.*?)\s*<\s*([^<>\s]+@[^<>\s]+)\s*>$/', $c, $m)) {
        return ['nome' => trim($m[1]), 'email' => trim($m[2])];
    }
    if (filter_var($c, FILTER_VALIDATE_EMAIL)) {
        return ['nome' => '', 'email' => $c];
    }
    return ['nome' => $c, 'email' => ''];
}

/**
 * Converte "8M", "512K", "2G" do php.ini em bytes.
 *
 * As diretivas de tamanho do PHP aceitam sufixo, e `(int)` sobre elas devolve
 * 8 para "8M" — numero que nao serve para comparar com CONTENT_LENGTH. Devolve
 * 0 quando nao ha limite (`post_max_size = 0`), que e o que o PHP entende por
 * ilimitado.
 */
function tamanho_para_bytes(?string $valor): int {
    $v = trim((string)$valor);
    if ($v === '') return 0;
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024; // cai para M
        case 'm': $n *= 1024; // cai para K
        case 'k': $n *= 1024;
    }
    return max(0, $n);
}

/**
 * O sistema esta em manutencao?
 *
 * Chave LIGADA PELO ADMIN, guardada em `configuracoes`. Fica atras da senha do
 * admin, e nao numa variavel do `.env`, porque emergencia e justamente quando
 * nao da para editar arquivo por FTP e esperar. Decisao do tesoureiro em
 * 31/08/2026: *"e so pedir senha pra tirar a pagina do ar"*.
 *
 * Isto NAO e a mesma coisa que encerrar as inscricoes de um evento. Encerrar
 * mantem a pagina do evento no ar — ela e a unica pagina oficial dele e a URL
 * vai impressa no cartaz. A manutencao para TUDO, e serve a deploy, migracao e
 * incidente. Nao acontece em condicao normal.
 */
function em_manutencao(): bool {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = false;
    // Conexao PROPRIA, somente leitura, SEM passar por get_db().
    //
    // Passando por get_db() esta funcao dispararia garantir_schema() — e a
    // migracao reconstroi `pagbank_pedidos` e `lembretes_agendados`, com dados
    // reais, em BEGIN IMMEDIATE. A pergunta "o site esta fora do ar?" e feita
    // em TODA requisicao publica, inclusive as do robo: seria a visita de um
    // desconhecido disparando a reconstrucao destrutiva, justamente enquanto o
    // sistema esta declarado fora do ar. Ler a chave nao precisa de migracao —
    // `configuracoes` existe desde a primeira versao do banco.
    //
    // Banco ausente, ilegivel ou sem a tabela vale como NAO em manutencao: o
    // erro maior aparece adiante, com mensagem propria, e nao se troca um
    // defeito de banco por uma pagina que mente dizendo "voltamos ja".
    try {
        $caminho = DATABASE_PATH;
        if (!is_file($caminho)) return $cache;
        $ro = new PDO('sqlite:' . $caminho, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2,
        ]);
        $st = $ro->query("SELECT valor FROM configuracoes WHERE chave = 'manutencao'");
        $v = $st ? $st->fetchColumn() : null;
        if ($st) $st->closeCursor();
        $cache = ((string)$v) === '1';
    } catch (Throwable $e) {
        $cache = false;
    }
    return $cache;
}

/**
 * Modalidade do evento em texto corrido, para o comprovante.
 *
 * NULL ou vazio vale como presencial: e o caso dominante, e os eventos
 * cadastrados antes da coluna existir nao seriam reeditados so por isso.
 */
function modalidade_evento(?string $modalidade): string {
    $m = strtolower(trim((string)$modalidade));
    if ($m === 'online')  return 'online';
    if ($m === 'hibrido' || $m === 'híbrido') return 'híbrido (presencial e online)';
    return 'presencial';
}

/**
 * Versao do aviso de privacidade em vigor.
 *
 * **Trocar SEMPRE que o texto de `/privacidade` mudar.** O consentimento e
 * gravado por VERSAO (`filiacoes.consentimento_versao`,
 * `inscricoes.consentimento_versao`): sem trocar aqui, o registro aponta para
 * um texto que a pessoa nunca leu, e ai a pergunta que o registro existe para
 * responder — "essas pessoas concordaram com o que?" — volta a nao ter resposta.
 *
 * Formato AAAA-MM-DD, a data da mudanca, com letra quando ha mais de uma
 * mudanca no mesmo dia (`2026-08-31b`) — mesma convencao do SCHEMA_VERSION.
 * Sem a letra, dois textos diferentes teriam a mesma versao, que e justamente o
 * que ela existe para impedir. Nao e semver: o que importa e achar o texto
 * daquele dia no historico do git.
 */
const POLITICA_PRIVACIDADE_VERSAO = '2026-09-01';

/**
 * Como esta pessoa se identifica num documento: "CPF 000.000.000-00" ou
 * "Passaporte XX0000000".
 *
 * Existe porque `pessoas.cpf` deixou de ser o unico documento em 30/08/2026
 * (ver `pessoas.documento` no CLAUDE.md). Sem isto, o comprovante de um filiado
 * estrangeiro identifica a pessoa **so pelo nome** — e esse papel vai para setor
 * de reembolso e secretaria de programa, onde nome sozinho nao casa com
 * cadastro nenhum.
 *
 * CPF tem precedencia porque e o que a maior parte de quem RECEBE o documento
 * espera conferir. Devolve string vazia quando nao ha nada: quem chama omite o
 * trecho inteiro, em vez de imprimir rotulo sem valor.
 *
 * So imprime CPF que passa no digito verificador — a mesma regra que o
 * `PdfService` ja aplicava. Numero invalido num documento de prestacao de
 * contas e pior do que documento sem numero.
 */
function documento_identificacao(?string $cpf, ?string $documento = null, ?string $tipo = null): string {
    $d = preg_replace('/\D/', '', (string)$cpf);

    // Zero a esquerda comido por planilha: 41 cadastros da base. Completar e
    // conferir recupera o numero certo sem inventar nenhum.
    if ($d !== '' && strlen($d) < 11 && cpf_valido(str_pad($d, 11, '0', STR_PAD_LEFT))) {
        $d = str_pad($d, 11, '0', STR_PAD_LEFT);
    }
    if (cpf_valido($d)) {
        return 'CPF ' . formatar_cpf($d);
    }

    $documento = trim((string)$documento);
    if ($documento !== '') {
        $tipo = trim((string)$tipo);
        // Tipo em minusculas na base ('passaporte'); no documento vai com
        // inicial maiuscula. Siglas (RNM, DNI) ja vem em caixa alta e a
        // ucfirst nao as estraga.
        $rotulo = $tipo !== '' ? ucfirst($tipo) : 'Documento';
        return $rotulo . ' ' . $documento;
    }

    return '';
}

/**
 * CPF valido? Confere os dois digitos verificadores.
 *
 * Serve para decidir se um numero pode ser IMPRESSO num documento oficial. A
 * base tem 41 cadastros com menos de 11 digitos — zero a esquerda perdido em
 * importacao de planilha — e todos os 41 ficam validos ao completar a esquerda,
 * enquanto os 227 com 11 digitos ja sao validos. Ou seja: completar e conferir
 * recupera os 41 sem inventar nenhum.
 */
function cpf_valido(?string $cpf): bool {
    $d = preg_replace('/\D/', '', (string)$cpf);
    if (strlen($d) !== 11 || preg_match('/^(\d)\1{10}$/', $d)) {
        return false;
    }
    foreach ([9, 10] as $n) {
        $soma = 0;
        for ($i = 0; $i < $n; $i++) {
            $soma += (int)$d[$i] * (($n + 1) - $i);
        }
        $dv = ($soma * 10) % 11;
        if ($dv === 10) { $dv = 0; }
        if ($dv !== (int)$d[$n]) { return false; }
    }
    return true;
}

/**
 * Escapa string para HTML.
 *
 * Mora aqui, e nao no routes.php, porque e auxiliar de VIEW — como
 * formatar_valor() e formatar_cpf() — e porque texto_formatado() depende dela.
 * O config.php e o unico arquivo que todo caminho de execucao carrega.
 */
function e(?string $string): string {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Neutraliza celula que o Excel executaria como formula.
 *
 * Celula comecando por = + - @ (ou tab/CR) e interpretada como formula ao
 * abrir. O conteudo vem de campo livre preenchido por qualquer inscrito, entao
 * o apostrofo a frente e a diferenca entre um texto e um comando.
 */
function neutralizar_celula($valor): string {
    $v = (string)$valor;
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}

function exportar_planilha(string $formato, string $nome_arquivo, string $aba, array $cabecalho, array $linhas): void {
    if ($formato === 'xlsx') {
        require_once SRC_DIR . '/Services/XlsxService.php';
        $bytes = XlsxService::gerar($aba, $cabecalho, $linhas);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nome_arquivo . '.xlsx"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nome_arquivo . '.csv"');

    // Celula que comeca com = + - @ e executada como formula pelo Excel ao
    // abrir o CSV, e o conteudo aqui vem de campo preenchido por qualquer um.
    // O apostrofo a frente faz o Excel tratar como texto e nao aparece na tela.

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));   // BOM, para o Excel ler o acento
    fputcsv($out, $cabecalho, ';');
    foreach ($linhas as $linha) {
        fputcsv($out, array_map('neutralizar_celula', $linha), ';');
    }
    fclose($out);
    exit;
}

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

/**
 * Salva comprovante de matrícula
 * Retorna o caminho relativo do arquivo ou null em caso de erro
 */
function salvar_comprovante(array $file, int $pessoa_id, int $ano): ?string {
    // Verifica se o arquivo foi enviado
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    // Valida tamanho (5MB)
    $max_size = 5 * 1024 * 1024;
    if ($file['size'] > $max_size) {
        return null;
    }

    // Valida tipo
    $tipos_permitidos = ['application/pdf', 'image/jpeg', 'image/png'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $tipos_permitidos)) {
        return null;
    }

    // Determina extensão
    $extensoes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];
    $ext = $extensoes[$mime] ?? 'bin';

    // Cria diretório se não existir
    if (!is_dir(COMPROVANTES_DIR)) {
        mkdir(COMPROVANTES_DIR, 0755, true);
    }

    // Nome do arquivo: {pessoa_id}_{ano}.{ext}
    $filename = "{$pessoa_id}_{$ano}.{$ext}";
    $filepath = COMPROVANTES_DIR . '/' . $filename;

    // Remove arquivo anterior se existir (pode ter extensão diferente)
    foreach (['pdf', 'jpg', 'png'] as $old_ext) {
        $old_file = COMPROVANTES_DIR . "/{$pessoa_id}_{$ano}.{$old_ext}";
        if (file_exists($old_file)) {
            unlink($old_file);
        }
    }

    // Move arquivo
    if (move_uploaded_file($file['tmp_name'], $filepath)) {
        return $filename;
    }

    return null;
}

/**
 * Retorna o caminho completo do comprovante se existir
 */
function obter_comprovante(int $pessoa_id, int $ano): ?string {
    foreach (['pdf', 'jpg', 'png'] as $ext) {
        $filepath = COMPROVANTES_DIR . "/{$pessoa_id}_{$ano}.{$ext}";
        if (file_exists($filepath)) {
            return $filepath;
        }
    }
    return null;
}

/**
 * Verifica se existe comprovante para a filiação
 */
function tem_comprovante(int $pessoa_id, int $ano): bool {
    return obter_comprovante($pessoa_id, $ano) !== null;
}

/**
 * Salva comprovante de matrícula de INSCRIÇÃO EM EVENTO.
 * Padrão de nome próprio (evt{evento_id}_{pessoa_id}.{ext}) para não colidir
 * com os comprovantes de filiação ({pessoa_id}_{ano}.{ext}).
 */
/**
 * Salva o cartaz/banner de um evento em assets/img/eventos/.
 *
 * Diferente do comprovante, este arquivo e PUBLICO (o navegador precisa
 * carrega-lo). Redimensiona para no maximo 1400px no maior lado — cartazes
 * costumam vir enormes (o do V Seminario tem 3277x4096) e nao ha razao para
 * servir isso a cada visita.
 *
 * Retorna o nome do arquivo (nao o caminho) ou null em caso de erro.
 */
/**
 * Guarda um documento publico do evento — hoje, a programacao em PDF.
 *
 * POR QUE EXISTE AGORA, e nao na etapa 2: a programacao sai semanas antes do
 * evento, nao na abertura das inscricoes. Mas o CAMPO precisa existir desde o
 * primeiro deploy — senao publicar a programacao em outubro exige outro deploy,
 * e deploy aqui e FTP arquivo a arquivo, sem SSH. Custa uma coluna hoje e uma
 * operacao de risco depois.
 *
 * Quem sobe, por enquanto, e o tesoureiro pelo /admin. A organizacao subir pelo
 * painel dela e a etapa 2, e depende da decisao sobre a credencial de escrita —
 * ver a restricao 3 do ROADMAP.
 *
 * So PDF: e o formato em que a programacao existe, e aceitar mais tipos so
 * ampliaria a superficie sem servir a ninguem.
 */
function salvar_programa_evento(array $file, string $slug): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 10 * 1024 * 1024) return null;

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if ($mime !== 'application/pdf') return null;

    if (!is_dir(EVENTOS_DOC_DIR) && !mkdir(EVENTOS_DOC_DIR, 0755, true) && !is_dir(EVENTOS_DOC_DIR)) {
        return null;
    }

    $slug_limpo = preg_replace('/[^a-z0-9-]/', '', strtolower($slug)) ?: 'evento';
    $filename = $slug_limpo . '-programa.pdf';
    $destino = EVENTOS_DOC_DIR . '/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destino)) {
        // Fora de requisicao HTTP (teste, CLI) move_uploaded_file recusa.
        if (!rename($file['tmp_name'], $destino)) return null;
    }
    @chmod($destino, 0644);

    return $filename;
}

function salvar_imagem_evento(array $file, string $slug, string $sufixo = ''): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 10 * 1024 * 1024) return null;

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    $extensoes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensoes[$mime])) return null;
    $ext = $extensoes[$mime];

    if (!is_dir(EVENTOS_IMG_DIR) && !mkdir(EVENTOS_IMG_DIR, 0755, true) && !is_dir(EVENTOS_IMG_DIR)) {
        return null;
    }

    $slug_limpo = preg_replace('/[^a-z0-9-]/', '', strtolower($slug)) ?: 'evento';
    // Sufixo separa o cartaz da faixa de apoiadores do mesmo evento.
    $sufixo = preg_replace('/[^a-z0-9-]/', '', strtolower($sufixo));
    if ($sufixo !== '') $slug_limpo .= '-' . $sufixo;
    $filename = $slug_limpo . '.' . $ext;
    $destino = EVENTOS_IMG_DIR . '/' . $filename;

    // Remove versoes anteriores em outras extensoes
    foreach (array_values($extensoes) as $e) {
        $antigo = EVENTOS_IMG_DIR . '/' . $slug_limpo . '.' . $e;
        if (file_exists($antigo)) @unlink($antigo);
    }

    // Redimensiona se der (GD disponivel e imagem grande); senao copia como veio
    $max = 1400;
    $redimensionou = false;
    if (function_exists('imagecreatetruecolor')) {
        $info = @getimagesize($file['tmp_name']);
        if ($info && max($info[0], $info[1]) > $max) {
            $criadores = ['image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp'];
            $criar = $criadores[$mime] ?? null;
            if ($criar && function_exists($criar)) {
                $src = @$criar($file['tmp_name']);
                if ($src) {
                    $escala = $max / max($info[0], $info[1]);
                    $nw = (int)round($info[0] * $escala);
                    $nh = (int)round($info[1] * $escala);
                    $dst = imagecreatetruecolor($nw, $nh);
                    if ($mime === 'image/png' || $mime === 'image/webp') {
                        imagealphablending($dst, false);
                        imagesavealpha($dst, true);
                    }
                    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $info[0], $info[1]);
                    if ($mime === 'image/jpeg')      $redimensionou = imagejpeg($dst, $destino, 85);
                    elseif ($mime === 'image/png')   $redimensionou = imagepng($dst, $destino, 6);
                    else                             $redimensionou = imagewebp($dst, $destino, 85);
                    imagedestroy($dst);
                    imagedestroy($src);
                }
            }
        }
    }

    if (!$redimensionou && !move_uploaded_file($file['tmp_name'], $destino)) {
        // move_uploaded_file falha fora de upload HTTP real (ex: scripts CLI)
        if (!@copy($file['tmp_name'], $destino)) return null;
    }

    return $filename;
}

function salvar_comprovante_evento(array $file, int $evento_id, int $pessoa_id): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if ($file['size'] > 5 * 1024 * 1024) return null;

    $tipos_permitidos = ['application/pdf', 'image/jpeg', 'image/png'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $tipos_permitidos)) return null;

    $extensoes = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    $ext = $extensoes[$mime] ?? 'bin';

    if (!is_dir(COMPROVANTES_DIR)) {
        mkdir(COMPROVANTES_DIR, 0755, true);
    }

    $filename = "evt{$evento_id}_{$pessoa_id}.{$ext}";
    foreach (['pdf', 'jpg', 'png'] as $old_ext) {
        $old_file = COMPROVANTES_DIR . "/evt{$evento_id}_{$pessoa_id}.{$old_ext}";
        if (file_exists($old_file)) unlink($old_file);
    }

    if (move_uploaded_file($file['tmp_name'], COMPROVANTES_DIR . '/' . $filename)) {
        return $filename;
    }
    return null;
}

/**
 * Caminho do comprovante de evento se existir
 */
function obter_comprovante_evento(int $evento_id, int $pessoa_id): ?string {
    foreach (['pdf', 'jpg', 'png'] as $ext) {
        $filepath = COMPROVANTES_DIR . "/evt{$evento_id}_{$pessoa_id}.{$ext}";
        if (file_exists($filepath)) return $filepath;
    }
    return null;
}

function tem_comprovante_evento(int $evento_id, int $pessoa_id): bool {
    return obter_comprovante_evento($evento_id, $pessoa_id) !== null;
}

/**
 * Texto de conteudo -> HTML, num subconjunto fechado de Markdown.
 *
 * Existe porque a pagina do evento no Pilotis E a pagina do evento: precisa de
 * titulo, paragrafo, lista e link, e quem escreve e a organizacao, pelo painel,
 * sem saber HTML.
 *
 * A escolha de formato se faz UMA vez: o conteudo fica gravado no banco e a
 * mesma funcao vai renderizar o que ja esta escrito. Por isso um subconjunto
 * pequeno e estavel, e nao uma biblioteca de Markdown com 200 casos de borda —
 * cada recurso a mais e um jeito a mais de a pagina sair torta em producao, num
 * dia em que ninguem vai poder consertar.
 *
 * A seguranca vem da ORDEM: escapa primeiro, converte depois. O texto vira
 * inerte antes de qualquer transformacao, entao nao ha marcacao que o autor
 * possa escrever, de proposito ou por acidente, que vire HTML executavel. Nao e
 * uma lista de tags proibidas — e a ausencia de qualquer caminho para tag.
 *
 * Aceita:
 *   ## Titulo        ### Subtitulo
 *   - item de lista  1. item numerado
 *   ---              (linha divisoria)
 *   **negrito**      *italico*      [texto](https://url)
 *   Linha em branco separa paragrafos; linhas seguidas se juntam.
 */
function texto_formatado(?string $texto): string {
    $texto = trim((string)$texto);
    if ($texto === '') {
        return '';
    }

    $texto = str_replace(["\r\n", "\r"], "\n", $texto);
    $blocos = preg_split('/\n{2,}/', $texto) ?: [];

    $html = '';
    foreach ($blocos as $bloco) {
        $bloco = trim($bloco, "\n");
        if (trim($bloco) === '') {
            continue;
        }

        $linhas = explode("\n", $bloco);
        $primeira = trim($linhas[0]);

        // Linha divisoria: tres tracos ou mais, sozinhos.
        if (preg_match('/^-{3,}$/', $primeira) && count($linhas) === 1) {
            $html .= "<hr>\n";
            continue;
        }

        // Titulo. Comeca em h3 porque o h2 da pagina e o nome do evento: pular
        // nivel quebra a navegacao por cabecalhos de quem usa leitor de tela.
        if (preg_match('/^(#{2,4})\s+(.+)$/', $primeira, $m) && count($linhas) === 1) {
            $nivel = min(strlen($m[1]) + 1, 5);
            $html .= "<h$nivel>" . texto_formatado_inline($m[2]) . "</h$nivel>\n";
            continue;
        }

        // Lista: o bloco inteiro tem de ser de itens, senao e paragrafo.
        $marcador = null;
        if (preg_match('/^[-*]\s+/', $primeira)) {
            $marcador = 'ul';
        } elseif (preg_match('/^\d+[.)]\s+/', $primeira)) {
            $marcador = 'ol';
        }
        if ($marcador !== null) {
            $itens = [];
            $todas_sao_itens = true;
            foreach ($linhas as $linha) {
                $linha = trim($linha);
                if ($linha === '') {
                    continue;
                }
                if ($marcador === 'ul' && preg_match('/^[-*]\s+(.*)$/', $linha, $m)) {
                    $itens[] = $m[1];
                } elseif ($marcador === 'ol' && preg_match('/^\d+[.)]\s+(.*)$/', $linha, $m)) {
                    $itens[] = $m[1];
                } elseif ($itens !== []) {
                    // Continuacao do item anterior (linha quebrada no editor).
                    $itens[count($itens) - 1] .= ' ' . $linha;
                } else {
                    $todas_sao_itens = false;
                    break;
                }
            }
            if ($todas_sao_itens && $itens !== []) {
                $html .= "<$marcador>\n";
                foreach ($itens as $item) {
                    $html .= '<li>' . texto_formatado_inline($item) . "</li>\n";
                }
                $html .= "</$marcador>\n";
                continue;
            }
        }

        // Paragrafo. Linhas seguidas se JUNTAM; linha em branco e que separa
        // paragrafo.
        //
        // A regra oposta — quebra digitada vira <br> — parecia melhor por ser
        // mais previsivel, ate haver conteudo real: o texto do edital, como
        // qualquer coisa colada de documento, vem quebrado em 80 colunas, e
        // sairia esfarrapado na tela. Prosa e o caso dominante aqui. Para
        // programacao e outras enumeracoes, onde a quebra importa, existe a
        // lista com "- ", que ainda por cima gera HTML melhor.
        $partes = array_filter(array_map(fn($l) => texto_formatado_inline(trim($l)), $linhas),
                               fn($p) => $p !== '');
        $html .= '<p>' . implode(' ', $partes) . "</p>\n";
    }

    return $html;
}

/**
 * Marcacao dentro de uma linha. Escapa ANTES de converter — ver texto_formatado().
 */
function texto_formatado_inline(string $linha): string {
    $linha = e($linha);

    // Link. So http, https e mailto: javascript: e data: nao passam, e um
    // esquema desconhecido tambem nao. O texto do link ja esta escapado.
    $linha = preg_replace_callback(
        '/\[([^\]]+)\]\(([^)\s]+)\)/',
        function (array $m): string {
            $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            $ok = preg_match('#^(https?://|mailto:)#i', $url) === 1;
            if (!$ok) {
                // Devolve a marcacao inteira, nao so o texto: assim quem
                // escreveu ve que o link nao foi aceito, em vez de achar que
                // funcionou. Ja esta escapada.
                return $m[0];
            }
            $externo = stripos($url, 'mailto:') !== 0;
            $attrs = $externo ? ' rel="noopener"' : '';
            return '<a href="' . e($url) . '"' . $attrs . '>' . $m[1] . '</a>';
        },
        $linha
    ) ?? $linha;

    // Negrito antes de italico: senao o ** de "**x**" e lido como dois *.
    $linha = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/s', '<strong>$1</strong>', $linha) ?? $linha;
    $linha = preg_replace('/(?<![\w*])\*(?=\S)([^*]+?)(?<=\S)\*(?![\w*])/s', '<em>$1</em>', $linha) ?? $linha;

    return $linha;
}

/**
 * Data do evento por extenso: "12 e 13 de novembro de 2026".
 *
 * Numero puro ("12/11/2026 a 13/11/2026") e a forma de formulario, nao a de
 * cartaz — e esta pagina agora E a pagina do evento. Dois dias seguidos ligam-se
 * por "e"; intervalo maior, por "a".
 */
function data_por_extenso(?string $inicio, ?string $fim = null): string {
    static $meses = [
        1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
        'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
    ];

    $ini = $inicio ? strtotime($inicio) : false;
    if ($ini === false) {
        return '';
    }
    $f = $fim ? strtotime($fim) : false;

    $dia_ini = (int)date('j', $ini);
    $mes_ini = (int)date('n', $ini);
    $ano_ini = (int)date('Y', $ini);

    if ($f === false || date('Y-m-d', $f) === date('Y-m-d', $ini)) {
        return "$dia_ini de {$meses[$mes_ini]} de $ano_ini";
    }

    $dia_fim = (int)date('j', $f);
    $mes_fim = (int)date('n', $f);
    $ano_fim = (int)date('Y', $f);

    if ($ano_ini !== $ano_fim) {
        return "$dia_ini de {$meses[$mes_ini]} de $ano_ini a $dia_fim de {$meses[$mes_fim]} de $ano_fim";
    }
    if ($mes_ini !== $mes_fim) {
        return "$dia_ini de {$meses[$mes_ini]} a $dia_fim de {$meses[$mes_fim]} de $ano_fim";
    }

    $ligacao = ($f - $ini) === 86400 ? 'e' : 'a';
    return "$dia_ini $ligacao $dia_fim de {$meses[$mes_ini]} de $ano_ini";
}
