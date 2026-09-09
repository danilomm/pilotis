<?php
/**
 * Pilotis - Envia as carteiras de filiacao de um ano, uma pessoa por email.
 *
 *   php scripts/enviar_carteirinhas.php --ano=2026                 (ensaio)
 *   php scripts/enviar_carteirinhas.php --ano=2026 --so=x@y.z --enviar
 *   php scripts/enviar_carteirinhas.php --ano=2026 --so=x@y.z --para=outro@z.br --enviar
 *   php scripts/enviar_carteirinhas.php --ano=2026 --enviar --limite=50
 *
 * **RODA NO SERVIDOR.** As chaves do `.env` so funcionam de dentro dele: a API
 * do Brevo recusa por IP de origem, e da maquina local devolve 401. Os PDFs
 * sao gerados localmente (a Futura e a marca vetorial estao la) e sobem junto.
 *
 * ENSAIO E O PADRAO. Sem `--enviar` nada sai, e a saida mostra pessoa por
 * pessoa o que sairia, com os anexos resolvidos.
 *
 * Nao manda duas vezes: cada envio bem-sucedido grava `carteira_enviada` no log
 * com o id da filiacao, e quem ja tem some da lista. Rodar de novo depois de uma
 * interrupcao continua de onde parou, que e o que a cota diaria exige.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script so roda por linha de comando.');
}

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';
require_once SRC_DIR . '/Services/BrevoService.php';
require_once SRC_DIR . '/Services/ValidacaoService.php';

$op = getopt('', ['ano::', 'dir::', 'dir-intl::', 'so::', 'para::', 'limite::', 'enviar']);

$ano     = (int)($op['ano'] ?? date('Y'));
$enviar  = isset($op['enviar']);
$so      = $op['so'] ?? null;

/**
 * Manda para OUTRO endereco da mesma pessoa, em vez do principal.
 *
 * Existe porque quatro das cinco carteiras que nao chegaram em 09/09/2026 tem
 * endereco alternativo JA NO CADASTRO — inclusive o caso que resume a coisa:
 * o principal do Mauro e `@arquitetura.ufjf.br`, dominio que nao tem registro
 * MX e nao recebe email de ninguem, e o secundario dele e `@ufjf.br`, que e o
 * dominio real da UFJF.
 *
 * Nao ha recuo automatico para o secundario, de proposito: escolher por qual
 * endereco falar com uma pessoa e decisao de quem opera, nao do script.
 */
$para    = $op['para'] ?? null;
if ($para !== null && $so === null) {
    exit("--para so faz sentido junto de --so, para uma pessoa de cada vez.\n");
}
$limite  = isset($op['limite']) ? (int)$op['limite'] : 0;
$dir     = rtrim($op['dir'] ?? (BASE_DIR . "/carteiras/nacional-$ano"), '/');
$dir_int = rtrim($op['dir-intl'] ?? (BASE_DIR . "/carteiras/international-brazil -$ano"), '/');

/** Teto do Brevo, o mesmo numero e a mesma contagem do cron-campanha.php. */
const TETO_DIARIO = 290;

/**
 * Uma linha por PESSOA, e nao por email.
 *
 * O `GROUP BY` nao e zelo: tres cadastros da base tem DUAS linhas com
 * `principal = 1`, e um JOIN simples mandaria dois emails para cada um deles.
 * Foi assim que a contagem deu 173 para 172 filiacoes.
 */
$pessoas = db_fetch_all("
    SELECT f.id AS filiacao_id, f.categoria, p.id AS pessoa_id, p.nome,
           MIN(e.email) AS email
    FROM filiacoes f
    JOIN pessoas p ON p.id = f.pessoa_id
    JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
    WHERE f.ano = ? AND f.status = 'pago'
    GROUP BY f.id
    ORDER BY p.nome COLLATE NOCASE
", [$ano]);

// Ja enviados, do proprio log. Sem tabela nova: e a mesma tecnica que a trava
// de pedidos de link usa, e o id entre colchetes e o que a busca conta.
$ja = [];
foreach (db_fetch_all("SELECT mensagem FROM log WHERE tipo = 'carteira_enviada'") as $l) {
    if (preg_match('/\[(\d+)\]/', $l['mensagem'], $m)) $ja[(int)$m[1]] = true;
}

$fila = [];
$sem_arquivo = [];
foreach ($pessoas as $p) {
    if (isset($ja[(int)$p['filiacao_id']])) continue;
    if ($so !== null && strcasecmp($p['email'], $so) !== 0) continue;

    $anexos = [
        "carteira-docomomo-brasil-$ano.pdf"        => "$dir/tela/{$p['nome']}.pdf",
        "carteira-docomomo-brasil-$ano-cartao.pdf" => "$dir/cartao/{$p['nome']}.pdf",
    ];
    // A carteira de Delft nunca foi distribuida: vai neste mesmo email para
    // quem e da faixa Internacional+Brasil. O arquivo se chama pelo nome do
    // cadastro, conferido nos 54 em 09/09/2026.
    $intl = $p['categoria'] === 'profissional_internacional';
    if ($intl) {
        $anexos["carteira-docomomo-international-$ano.pdf"] = "$dir_int/{$p['nome']}.pdf";
    }

    $faltando = array_filter($anexos, fn($c) => !is_file($c));
    if ($faltando) {
        $sem_arquivo[] = $p['nome'] . ' — falta ' . implode(', ', array_map('basename', $faltando));
        continue;
    }

    $p['anexos'] = $anexos;
    $p['internacional'] = $intl;
    $fila[] = $p;
}

if ($limite > 0) $fila = array_slice($fila, 0, $limite);

$gasto = emails_gastos_hoje();
$sobra = TETO_DIARIO - $gasto;

echo ($enviar ? 'ENVIO REAL' : 'ENSAIO — nada sera enviado'), "\n";
echo count($pessoas), " adimplente(s) em $ano · ", count($ja), " ja receberam · ",
     count($fila), " na fila\n";
echo "cota do Brevo hoje: $gasto de ", TETO_DIARIO, " gastos, sobram $sobra\n\n";

foreach ($sem_arquivo as $s) fwrite(STDERR, "SEM ARQUIVO  $s\n");
if ($sem_arquivo) fwrite(STDERR, "\n");

if (!$fila) exit("Nada a enviar.\n");

if ($enviar && count($fila) > $sobra) {
    fwrite(STDERR, "A fila tem " . count($fila) . " e a cota permite $sobra hoje.\n"
        . "Rode com --limite=$sobra e repita amanha, ou o resto falharia no Brevo.\n");
    exit(1);
}

$enviados = 0;
$falhas = [];
foreach ($fila as $p) {
    $codigo = ValidacaoService::codigo('FIL', (int)$p['filiacao_id']);

    $tpl = carregar_template('carteirinha', [
        'nome' => $p['nome'],
        'ano' => $ano,
        'categoria' => CATEGORIAS_DISPLAY[$p['categoria']] ?? $p['categoria'],
        'codigo' => $codigo,
        'host_validacao' => preg_replace('~^https?://~', '', BASE_URL) . '/validar',
        'link_validacao' => ValidacaoService::url($codigo),
    ], [
        'nota_internacional' => $p['internacional'] ? nota_internacional() : '',
        'fecho' => '<p>Obrigado por fazer parte do '
            . ($p['internacional'] ? ORG_SIGLA : ORG_NOME) . '.</p>',
    ]);

    if (!$tpl) exit("Template 'carteirinha' nao existe no banco.\n");

    $destino = $para ?? $p['email'];

    printf("%-42s %-34s %d anexo(s)%s\n",
        mb_substr($p['nome'], 0, 42), $destino, count($p['anexos']),
        $p['internacional'] ? '  [internacional]' : '');

    if (!$enviar) continue;

    $anexos = [];
    foreach ($p['anexos'] as $nome_anexo => $caminho) {
        $anexos[] = ['name' => $nome_anexo, 'content' => base64_encode(file_get_contents($caminho))];
    }

    $ok = BrevoService::enviarEmail($destino, $tpl['assunto'], $tpl['html'], $anexos);

    // Grava DEPOIS do envio, de proposito. Marcar antes protegeria contra
    // execucao simultanea, que aqui nao existe — e criaria o caso pior: pessoa
    // marcada como atendida que nunca recebeu nada.
    if ($ok) {
        registrar_log('carteira_enviada', (int)$p['pessoa_id'],
            "Carteira $ano enviada para $destino [{$p['filiacao_id']}]");
        $enviados++;
    } else {
        $falhas[] = $p['nome'] . ' <' . $destino . '>';
    }
}

echo "\n";
if ($enviar) {
    echo "$enviados enviada(s)\n";
    foreach ($falhas as $f) fwrite(STDERR, "FALHOU  $f\n");
} else {
    echo count($fila), " sairiam. Repita com --enviar.\n";
}
exit($falhas ? 1 : 0);

/**
 * Tudo que ja saiu pelo Brevo hoje, na mesma contagem do cron-campanha.php.
 * Contar so as carteiras deixaria a soma estourar o teto junto com os emails
 * das inscricoes do evento, que gastam a MESMA cota.
 */
function emails_gastos_hoje(): int {
    $campanha = (int)(db_fetch_one("
        SELECT COALESCE(SUM(ed.sucesso), 0) AS total
        FROM envios_destinatarios ed
        JOIN envios_lotes el ON el.id = ed.lote_id
        WHERE DATE(el.created_at, '+3 hours') = DATE('now') AND ed.sucesso = 1
    ")['total'] ?? 0);

    $outros = (int)(db_fetch_one("
        SELECT COUNT(*) AS total FROM log
        WHERE DATE(timestamp, '+3 hours') = DATE('now')
        AND tipo IN ('lembrete_enviado', 'link_acesso_enviado', 'evento_link_enviado',
                     'email_confirmacao_enviado', 'evento_convite_enviado',
                     'painel_organizacao_link', 'email_confirmacao_inscricao',
                     'evento_confirmacao_enviada', 'evento_confirmacao_reenviada',
                     'consolidacao_confirmacao_enviada',
                     'evento_consolidacao_confirmacao_enviada', 'carteira_enviada')
    ")['total'] ?? 0);

    return $campanha + $outros;
}

function nota_internacional(): string {
    return "<p>Enviamos também a sua carteira do Docomomo International, emitida pela "
        . "TU Delft, que corresponde à faixa Internacional+Brasil da sua filiação.</p>"
        . "<p>As duas não se confundem. A de Delft registra a filiação internacional e dá "
        . "direito a descontos e outros benefícios, que o Docomomo International define e "
        . "atualiza — as condições estão em <a href='https://docomomo.com/join/' style='color: "
        . ORG_COR_PRIMARIA . "'>docomomo.com/join</a>. A brasileira registra que você está em "
        . "dia com o " . ORG_NOME . ", e pode ser conferida na hora por quem a receber.</p>";
}
