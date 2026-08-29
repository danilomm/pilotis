<?php
/**
 * Consolida cadastros que compartilham o mesmo CPF.
 *
 * Motivo: o indice unico parcial em pessoas(cpf) so e criado se a base ja
 * estiver limpa. Com duplicatas, a migracao registra o erro no log e segue
 * SEM o indice — o sistema fica sem backstop contra CPF repetido.
 *
 * Roda de dois jeitos, porque no servidor nao ha SSH:
 *
 *   LOCAL (CLI):
 *     php scripts/manutencao/dedup-cpf-duplicado.php --dry-run
 *     php scripts/manutencao/dedup-cpf-duplicado.php --executar
 *
 *   SERVIDOR (HTTP), depois de subir para /www/ via FTP:
 *     https://.../dedup-cpf-duplicado.php?token=CRON_TOKEN
 *     https://.../dedup-cpf-duplicado.php?token=CRON_TOKEN&executar=1
 *
 * Cuidados embutidos:
 *   - Por HTTP exige o CRON_TOKEN. Sem ele, 403.
 *   - O padrao e SEMPRE ensaio. So altera com --executar / &executar=1.
 *     (a versao anterior detectava o ensaio por $argv, que NAO existe em HTTP:
 *      chamada pelo navegador teria ido direto para a execucao real)
 *   - Tudo em transacao: qualquer falha faz rollback.
 *   - APAGAR o arquivo do servidor depois de usar.
 */

$via_http = PHP_SAPI !== 'cli';

// No servidor o src/ fica DENTRO de www/; local ele fica acima de scripts/.
$base = __DIR__ . '/../../src/config.php';
if (!file_exists($base)) {
    $base = __DIR__ . '/src/config.php';   // layout do servidor
}
require_once $base;
require_once dirname($base) . '/db.php';

if ($via_http) {
    header('Content-Type: text/plain; charset=utf-8');

    $esperado = env('CRON_TOKEN', '');
    if ($esperado === '' || !hash_equals($esperado, (string)($_GET['token'] ?? ''))) {
        http_response_code(403);
        exit("Acesso negado.\n");
    }
    $executar = !empty($_GET['executar']);
} else {
    $executar = in_array('--executar', $argv ?? [], true);
}

/**
 * Acha todos os grupos de CPF repetido e escolhe o canonico de cada um:
 * mais filiacoes pagas, depois mais emails, depois o id mais antigo.
 * Nao ha lista fixa de ids — o script se vira com o que encontrar.
 */
function grupos_cpf_duplicado(): array {
    $cpfs = db_fetch_all("
        SELECT REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(cpf,''),'.',''),'-',''),'/',''),' ','') AS cpf,
               COUNT(*) AS qtd
        FROM pessoas
        WHERE IFNULL(cpf,'') <> ''
        GROUP BY 1 HAVING COUNT(*) > 1
    ");

    $grupos = [];
    foreach ($cpfs as $c) {
        $pessoas = db_fetch_all("
            SELECT p.id, p.nome, p.ativo,
                   (SELECT COUNT(*) FROM filiacoes f WHERE f.pessoa_id = p.id AND f.status = 'pago') AS pagas,
                   (SELECT COUNT(*) FROM emails e WHERE e.pessoa_id = p.id) AS emails
            FROM pessoas p
            WHERE REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(p.cpf,''),'.',''),'-',''),'/',''),' ','') = ?
            ORDER BY pagas DESC, emails DESC, p.id ASC
        ", [$c['cpf']]);

        $grupos[] = [
            'cpf' => $c['cpf'],
            'canonico' => array_shift($pessoas),
            'descartar' => $pessoas,
        ];
    }
    return $grupos;
}

function tabela_existe(string $nome): bool {
    return (bool)db_fetch_one("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ?", [$nome]);
}

function retrato(int $id): string {
    $p = db_fetch_one("SELECT id, nome, cpf FROM pessoas WHERE id = ?", [$id]);
    if (!$p) return sprintf("    id %-5d (nao existe mais)\n", $id);

    $out = sprintf("    id %-5d %-34s cpf=%s\n", $p['id'], mb_substr($p['nome'], 0, 34), $p['cpf'] ?: '-');
    foreach (db_fetch_all("SELECT email, principal FROM emails WHERE pessoa_id = ? ORDER BY principal DESC", [$id]) as $e) {
        $out .= sprintf("          email: %s%s\n", $e['email'], $e['principal'] ? ' (principal)' : '');
    }
    foreach (db_fetch_all("SELECT ano, status, valor FROM filiacoes WHERE pessoa_id = ? ORDER BY ano", [$id]) as $f) {
        $out .= sprintf("          %d: %-9s R$ %s\n", $f['ano'], $f['status'], number_format($f['valor'] / 100, 2, ',', '.'));
    }
    // A tabela de inscricoes so existe onde o modulo de eventos ja foi
    // deployado. Este script roda em servidor com esquema mais antigo — e
    // manutencao nao pode depender do que ainda nao subiu.
    if (tabela_existe('inscricoes')) {
        foreach (db_fetch_all("SELECT COUNT(*) AS n FROM inscricoes WHERE pessoa_id = ?", [$id]) as $i) {
            if ((int)$i['n'] > 0) $out .= sprintf("          inscricoes em eventos: %d\n", $i['n']);
        }
    }
    return $out;
}

$grupos = grupos_cpf_duplicado();

echo $executar ? "=== EXECUCAO REAL ===\n\n" : "=== ENSAIO (nada sera alterado) ===\n\n";

if (empty($grupos)) {
    echo "Nenhum CPF duplicado. Nada a fazer.\n";
    exit;
}

foreach ($grupos as $g) {
    echo "CPF {$g['cpf']}\n";
    echo "  MANTER:\n" . retrato((int)$g['canonico']['id']);
    echo "  CONSOLIDAR NESTE (e apagar):\n";
    foreach ($g['descartar'] as $d) echo retrato((int)$d['id']);
    echo "\n";
}

if (!$executar) {
    echo "Para aplicar: acrescente --executar (CLI) ou &executar=1 (HTTP).\n";
    exit;
}

$db = get_db();
$db->exec('BEGIN IMMEDIATE');
try {
    $consolidados = 0;
    foreach ($grupos as $g) {
        $canonico = (int)$g['canonico']['id'];
        foreach ($g['descartar'] as $d) {
            // Zera o token do descartado ANTES: a consolidar_pessoas foi feita
            // para o fluxo ao vivo e propaga o token do cadastro novo para o
            // antigo. Numa limpeza isso rotacionaria o token do canonico sem
            // necessidade, invalidando links que ja estejam circulando.
            db_execute("UPDATE pessoas SET token = NULL WHERE id = ?", [(int)$d['id']]);
            consolidar_pessoas($canonico, (int)$d['id'], (int)date('Y'));
            registrar_log('consolidacao_cpf_duplicado', $canonico,
                "Cadastro {$d['id']} consolidado em $canonico (CPF {$g['cpf']})");
            $consolidados++;
        }
    }
    $db->exec('COMMIT');
    echo "OK: $consolidados cadastro(s) consolidado(s).\n\n";
} catch (Throwable $e) {
    $db->exec('ROLLBACK');
    http_response_code(500);
    exit("FALHOU — rollback aplicado, nada foi alterado.\n" . $e->getMessage() . "\n");
}

echo "=== DEPOIS ===\n\n";
foreach ($grupos as $g) {
    echo "CPF {$g['cpf']}\n" . retrato((int)$g['canonico']['id']);
    foreach ($g['descartar'] as $d) echo retrato((int)$d['id']);
    echo "\n";
}

$resto = grupos_cpf_duplicado();
echo "CPFs duplicados restantes: " . count($resto) . "\n";
echo "\nNAO ESQUECA DE APAGAR ESTE ARQUIVO DO SERVIDOR.\n";
