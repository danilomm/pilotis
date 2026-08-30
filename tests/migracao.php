<?php
/**
 * Guarda a migracao de schema.
 *
 * roda: php tests/migracao.php
 *
 * POR QUE: ate 29/08/2026 as 31 colunas novas eram criadas assim —
 *
 *     try { $db->exec("ALTER TABLE x ADD COLUMN y TEXT"); } catch (PDOException $e) {}
 *
 * — excecao como controle de fluxo, sem distinguir "ja existe" de "banco
 * travado" ou "tabela inexistente". Desde que a migracao passou a rodar UMA vez
 * por versao, um ALTER que falhe por outro motivo nao volta a ser tentado: a
 * coluna nunca nasce e o erro que explicaria isso foi descartado.
 *
 * O teste roda contra bancos TEMPORARIOS, nunca contra o banco configurado, e
 * cobre os dois caminhos que existem na vida real: banco novo (instalacao de
 * terceiro) e banco antigo que ainda nao tem as tabelas de evento (a producao,
 * ate o deploy da etapa 1). Os dois tem de terminar com a mesma estrutura.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Somente CLI.');
}

$raiz = dirname(__DIR__);
require_once $raiz . '/src/db.php';   // define as funcoes; nao abre o banco configurado

$falhas = 0;
$temporarios = [];

function banco_temporario(string $sufixo): PDO {
    global $temporarios;
    $caminho = sys_get_temp_dir() . '/pilotis-teste-' . $sufixo . '-' . getmypid() . '.db';
    @unlink($caminho);
    $temporarios[] = $caminho;
    $db = new PDO("sqlite:$caminho");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $db;
}

function tabelas(PDO $db): array {
    $r = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll();
    return array_column($r, 'name');
}

// Estrutura que a etapa 1 exige. Se alguem apagar uma dessas linhas da
// migracao, o teste cai antes de o deploy descobrir.
$exigidas = [
    'pessoas'           => ['id', 'nome', 'cpf', 'token'],
    'filiacoes'         => ['status', 'pagbank_order_id', 'pagbank_charge_id', 'status_at'],
    'campanhas'         => ['valor_estudante', 'data_fim', 'data_fim_internacional'],
    'eventos'           => ['slug', 'conteudo', 'local', 'imagem_path', 'assinantes',
                            'email_contato', 'emails_organizacao', 'data_valor_cheio'],
    'evento_categorias' => ['valor_cheio', 'cpfs_liberados', 'independe_filiacao'],
    'inscricoes'        => ['pessoa_id', 'evento_id', 'nome_cracha', 'telefone', 'instituicao',
                            'presenca_em', 'presenca_por'],
    'lembretes_agendados' => ['tentativas'],
    'pagbank_pedidos'   => ['order_id', 'filiacao_id'],
    'configuracoes'     => ['chave', 'valor'],
    'log'               => ['tipo', 'mensagem'],
];

echo "== banco novo (instalacao limpa) ==\n";
$novo = banco_temporario('novo');
$novo->exec(file_get_contents($raiz . '/schema.sql'));
garantir_schema($novo);
$tab_novo = tabelas($novo);
echo "  " . count($tab_novo) . " tabelas\n";

foreach ($exigidas as $tabela => $colunas) {
    $tem = colunas_da_tabela($novo, $tabela);
    if (!$tem) {
        echo "  FALHA  tabela ausente: $tabela\n";
        $falhas++;
        continue;
    }
    foreach ($colunas as $c) {
        if (!in_array($c, $tem, true)) {
            echo "  FALHA  coluna ausente: $tabela.$c\n";
            $falhas++;
        }
    }
}
if (!$falhas) echo "  todas as tabelas e colunas exigidas existem\n";

echo "\n== a marca de versao foi gravada ==\n";
$v = $novo->query("SELECT valor FROM configuracoes WHERE chave='schema_version'")->fetch();
if (($v['valor'] ?? '') === SCHEMA_VERSION) {
    echo "  ok     schema_version = " . SCHEMA_VERSION . "\n";
} else {
    echo "  FALHA  schema_version gravada: '" . ($v['valor'] ?? '') . "', esperada '" . SCHEMA_VERSION . "'\n";
    $falhas++;
}

echo "\n== idempotencia: rodar de novo nao muda nada ==\n";
$antes = tabelas($novo);
garantir_schema($novo);
$depois = tabelas($novo);
if ($antes === $depois) {
    echo "  ok     estrutura estavel\n";
} else {
    echo "  FALHA  a segunda passada mudou a estrutura\n";
    $falhas++;
}

echo "\n== banco REALMENTE vazio: recusa com mensagem util ==\n";
// Cenario do .env errado no deploy: o PDO cria arquivo vazio em silencio, e o
// site inteiro passava a responder 500 com "no such table: filiacoes" vindo de
// dentro da migracao. Agora tem de recusar dizendo o que fazer.
$vazio = banco_temporario('vazio');
try {
    garantir_schema($vazio);
    echo "  FALHA  migrou um banco vazio, em vez de recusar\n";
    $falhas++;
} catch (RuntimeException $e) {
    $msg = $e->getMessage();
    $tem_pessoas = strpos($msg, 'pessoas') !== false;
    $tem_caminho = strpos($msg, 'DATABASE_PATH') !== false || strpos($msg, '.env') !== false;
    $tem_saida   = strpos($msg, 'install.php') !== false;
    if ($tem_pessoas && $tem_caminho && $tem_saida) {
        echo "  ok     recusa nomeando a tabela, o caminho e a saida\n";
    } else {
        echo "  FALHA  mensagem pouco util: $msg\n";
        $falhas++;
    }
} catch (Throwable $e) {
    echo "  FALHA  erro nao tratado (" . get_class($e) . "): " . $e->getMessage() . "\n";
    $falhas++;
}

echo "\n== banco antigo: sem as tabelas de evento, com dados ==\n";
// Reproduz a forma da producao ate o deploy da etapa 1: pessoas e filiacoes
// existem, o modulo de eventos nao. Dados ficticios — nunca de associado real.
$velho = banco_temporario('velho');
$velho->exec("
    CREATE TABLE pessoas (id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, cpf TEXT,
                          token TEXT, ativo INTEGER DEFAULT 1, created_at DATETIME);
    CREATE TABLE emails (id INTEGER PRIMARY KEY AUTOINCREMENT, pessoa_id INTEGER,
                         email TEXT UNIQUE, principal INTEGER DEFAULT 1);
    CREATE TABLE filiacoes (id INTEGER PRIMARY KEY AUTOINCREMENT, pessoa_id INTEGER,
                            ano INTEGER, categoria TEXT, valor INTEGER, data_pagamento TEXT);
    CREATE TABLE campanhas (ano INTEGER PRIMARY KEY, status TEXT DEFAULT 'aberta');
");
$velho->exec("INSERT INTO pessoas (nome, cpf, token) VALUES ('Fulana de Tal Ficticia', '00000000191', 'tok-ficticio-1')");
$velho->exec("INSERT INTO emails (pessoa_id, email) VALUES (1, 'ficticio@example.invalid')");
$velho->exec("INSERT INTO filiacoes (pessoa_id, ano, categoria, valor, data_pagamento) VALUES (1, 2025, 'profissional_nacional', 24000, '2025-03-01')");

garantir_schema($velho);

$tab_velho = tabelas($velho);
$faltando = array_diff(array_keys($exigidas), $tab_velho);
if ($faltando) {
    echo "  FALHA  nao criou: " . implode(', ', $faltando) . "\n";
    $falhas++;
} else {
    echo "  ok     todas as tabelas exigidas existem depois de migrar\n";
}

$n = $velho->query("SELECT COUNT(*) c FROM pessoas")->fetch()['c'];
$f = $velho->query("SELECT COUNT(*) c FROM filiacoes")->fetch()['c'];
if ((int)$n === 1 && (int)$f === 1) {
    echo "  ok     dados preservados (1 pessoa, 1 filiacao)\n";
} else {
    echo "  FALHA  dados alterados: $n pessoas, $f filiacoes\n";
    $falhas++;
}

$i = $velho->query("PRAGMA integrity_check")->fetch();
if (($i['integrity_check'] ?? '') === 'ok') {
    echo "  ok     integrity_check\n";
} else {
    echo "  FALHA  integrity_check: " . json_encode($i) . "\n";
    $falhas++;
}

echo "\n== garantir_coluna() nao mente ==\n";
$t = banco_temporario('coluna');
$t->exec("CREATE TABLE alvo (id INTEGER PRIMARY KEY)");
$casos = [
    ['cria coluna nova',            fn() => garantir_coluna($t, 'alvo', 'nova', 'TEXT'),      true],
    ['nao recria a que ja existe',  fn() => garantir_coluna($t, 'alvo', 'nova', 'TEXT'),      false],
    ['tabela inexistente da false', fn() => garantir_coluna($t, 'nao_existe', 'x', 'TEXT'),   false],
];
foreach ($casos as [$nome, $fn, $esperado]) {
    $obtido = $fn();
    if ($obtido === $esperado) {
        echo "  ok     $nome\n";
    } else {
        echo "  FALHA  $nome: esperado " . var_export($esperado, true) . ", obtido " . var_export($obtido, true) . "\n";
        $falhas++;
    }
}

foreach ($temporarios as $c) @unlink($c);

echo "\n";
if ($falhas > 0) {
    echo "FALHOU: $falhas problema(s).\n";
    exit(1);
}
echo "Tudo certo.\n";
exit(0);
