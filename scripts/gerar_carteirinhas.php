<?php
/**
 * Pilotis - Gera as carteiras de filiado de um ano, uma por pessoa.
 *
 *   php scripts/gerar_carteirinhas.php --ano=2026 --lista
 *   php scripts/gerar_carteirinhas.php --ano=2026 --formato=ambos
 *   php scripts/gerar_carteirinhas.php --ano=2026 --sem-internacionais
 *
 * Entra TODO MUNDO que pagou o ano, inclusive quem e
 * `profissional_internacional` e ja recebe a carteira de Delft. Decisao do
 * tesoureiro em 09/09/2026, e o argumento e dele: a validacao daqui e a que
 * funciona. A carteira internacional traz um QR que e a mesma URL para as 54
 * pessoas e nao prova filiacao nenhuma -- entao, sem esta, quem pagou a faixa
 * MAIS CARA seria justamente quem nao consegue provar que esta em dia.
 *
 * O `--sem-internacionais` fica para quem quiser o recorte inverso.
 *
 * A saida vai para `carteiras/`, que esta no `.gitignore`: sao PDFs com o nome
 * da pessoa no proprio nome do arquivo, e dado pessoal nao entra em
 * repositorio nenhum, nem no privado.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script so roda por linha de comando.');
}

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once BASE_DIR . '/vendor/autoload.php';
require_once SRC_DIR . '/Services/PilotisTcpdf.php';
require_once SRC_DIR . '/Filiacao/Carteirinha.php';

$op = getopt('', ['ano::', 'formato::', 'saida::', 'limite::', 'lista', 'sem-internacionais', 'forcar-base-url', 'sem-prova']);

$ano     = (int)($op['ano'] ?? date('Y'));
$formato = $op['formato'] ?? 'ambos';
$limite  = isset($op['limite']) ? (int)$op['limite'] : 0;
$lista   = isset($op['lista']);
$todos   = !isset($op['sem-internacionais']);

if (!in_array($formato, ['tela', 'cartao', 'ambos'], true)) {
    exit("--formato aceita tela, cartao ou ambos.\n");
}

/**
 * A trava que importa, e ela e a razao de este bloco existir.
 *
 * O QR carrega a BASE_URL de quem gerou. Com o `.env` de desenvolvimento, cada
 * carteira sai apontando para `http://localhost:8000/validar/...` -- um
 * endereco que so existe na maquina de quem gerou. Nada avisa: o PDF fica
 * bonito, o QR le, e a pessoa que apontar a camera nao chega a lugar nenhum.
 *
 * O sistema ja foi mordido por essa familia de erro mais de uma vez (o
 * ORG_EMAIL_CONTATO que apontava para uma caixa inexistente, o EMAIL_DRY_RUN
 * que devolvia sucesso sem enviar). Configuracao se prova, nao se lembra.
 */
$local = (bool)preg_match('~^https?://(localhost|127\.0\.0\.1|\[::1\])(:|/|$)~i', BASE_URL);
if ($local && !isset($op['forcar-base-url'])) {
    fwrite(STDERR,
        "BASE_URL e " . BASE_URL . ".\n" .
        "Toda carteira sairia com um QR apontando para essa maquina, e nada acusaria isso depois.\n" .
        "Aponte BASE_URL para o endereco publico antes de gerar, ou use --forcar-base-url para uma previa.\n");
    exit(1);
}

$pessoas = db_fetch_all("
    SELECT f.id AS filiacao_id, f.ano, f.categoria, p.nome
    FROM filiacoes f
    JOIN pessoas p ON p.id = f.pessoa_id
    WHERE f.ano = ? AND f.status = 'pago'
      " . ($todos ? "" : "AND f.categoria <> 'profissional_internacional'") . "
    ORDER BY p.nome COLLATE NOCASE
", [$ano]);

if (!$pessoas) {
    exit("Nenhuma filiacao paga em $ano" . ($todos ? '' : ' fora da faixa internacional') . ".\n");
}
if ($limite > 0) {
    $pessoas = array_slice($pessoas, 0, $limite);
}

$familia = is_file(K_PATH_FONTS . 'futuram.php') ? 'Futura (do manual)' : 'Helvetica (a Futura nao esta instalada no TCPDF)';

echo count($pessoas), " carteira(s) de $ano · formato: $formato\n";
echo "QR aponta para: ", BASE_URL, "/validar/\n";
echo "tipografia: $familia\n\n";

if ($lista) {
    foreach ($pessoas as $p) {
        printf("  %-45s %-32s %s\n", mb_substr($p['nome'], 0, 45),
            CATEGORIAS_DISPLAY[$p['categoria']] ?? $p['categoria'],
            ValidacaoService::codigo('FIL', (int)$p['filiacao_id']));
    }
    exit(0);
}

/**
 * Prova de ponta antes de gerar 118 arquivos: o QR so vale se a assinatura
 * bater com a SECRET_KEY DO SERVIDOR, e ela nao e a mesma do `.env` local.
 *
 * Assinatura errada nao produz erro em lugar nenhum. O PDF sai bonito, o QR
 * le, e a pagina do servidor responde "Documento nao localizado" -- que e a
 * mesma resposta que ela da a um codigo forjado, de proposito. So se descobre
 * quando alguem aponta a camera, o que aqui significa depois de a carteira
 * estar na mao de todo mundo.
 *
 * Entao se pergunta ao servidor, uma vez, com o codigo de quem esta na lista.
 * E uma leitura publica de um registro proprio, e deixa a linha
 * `validacao_consultada` no log -- o preco de nao mandar 118 carteiras mortas.
 */
function provar_assinatura(array $primeiro): void {
    $codigo = ValidacaoService::codigo('FIL', (int)$primeiro['filiacao_id']);
    $url = ValidacaoService::url($codigo);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'Pilotis/carteirinhas',
    ]);
    $corpo = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erro = curl_error($ch);
    curl_close($ch);

    if ($corpo === false || $http !== 200) {
        fwrite(STDERR, "Nao consegui conferir a assinatura em $url"
            . ($erro ? " ($erro)" : " (HTTP $http)") . ".\n"
            . "Use --sem-prova para gerar assim mesmo, sabendo do risco.\n");
        exit(1);
    }
    if (strpos($corpo, 'Documento não localizado') !== false) {
        fwrite(STDERR,
            "O servidor NAO reconhece o codigo $codigo.\n"
            . "A SECRET_KEY deste .env nao e a do servidor, entao toda carteira sairia\n"
            . "com um QR que a propria pagina de validacao recusa.\n"
            . "Gere com o .env de producao (BASE_URL e SECRET_KEY), nao so com a BASE_URL.\n");
        exit(1);
    }
    if (strpos($corpo, 'Documento válido') === false) {
        fwrite(STDERR, "Resposta inesperada de $url — confira a pagina a mao antes de gerar.\n");
        exit(1);
    }
    echo "assinatura conferida no servidor: $codigo\n\n";
}

if (!isset($op['sem-prova'])) {
    provar_assinatura($pessoas[0]);
}

$raiz = rtrim($op['saida'] ?? (BASE_DIR . "/carteiras/nacional-$ano"), '/');
$formatos = $formato === 'ambos' ? ['tela', 'cartao'] : [$formato];
foreach ($formatos as $f) {
    if (!is_dir("$raiz/$f") && !mkdir("$raiz/$f", 0700, true)) {
        exit("Nao consegui criar $raiz/$f\n");
    }
}

$feitas = 0;
$erros  = [];
foreach ($pessoas as $p) {
    foreach ($formatos as $f) {
        try {
            $bytes = Carteirinha::gerar([
                'filiacao_id' => (int)$p['filiacao_id'],
                'nome'        => $p['nome'],
                'categoria'   => $p['categoria'],
                'ano'         => (int)$p['ano'],
            ], $f);
            file_put_contents("$raiz/$f/" . nome_de_arquivo($p['nome']) . '.pdf', $bytes);
            $feitas++;
        } catch (Throwable $e) {
            $erros[] = $p['nome'] . " ($f): " . $e->getMessage();
        }
    }
}

echo "$feitas arquivo(s) em $raiz\n";
foreach ($erros as $e) {
    fwrite(STDERR, "FALHOU  $e\n");
}
exit($erros ? 1 : 0);

/**
 * Nome do arquivo a partir do nome da pessoa. Mantem acento e maiuscula --
 * as carteiras internacionais fazem o mesmo, e o arquivo e para a propria
 * pessoa reconhecer. Tira so o que quebra sistema de arquivos.
 */
function nome_de_arquivo(string $nome): string {
    $nome = preg_replace('~[/\\\\:*?"<>|\x00-\x1F]~u', '', trim($nome));
    $nome = preg_replace('/\s+/u', ' ', $nome);
    return $nome === '' ? 'sem-nome' : mb_substr($nome, 0, 120);
}
