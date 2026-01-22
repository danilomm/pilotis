<?php
/**
 * Limpa e normaliza dados de filiação 2018
 * Fonte: Excel "2018 Filiação Nacional.xls"
 *
 * Estrutura do arquivo:
 * - Linhas 1-4: Metadata
 * - Linha 5: Header (zzz,,,,Title,Name,Special position on Docomomo?,e-mail,Phone,Address,Zip Code,City,Province,Country)
 * - Linhas 6-29: Dados (PN = Pleno Nacional R$60, E = Estudante R$30)
 * - Resto: Despesas e linhas vazias
 *
 * Colunas de dados:
 * 0: Número (pode estar vazio)
 * 1: Categoria (PN ou E)
 * 2: Valor
 * 3: Número sequencial
 * 4: Title (formação)
 * 5: Name
 * 6: Special position on Docomomo?
 * 7: e-mail
 * 8: Phone
 * 9: Address
 * 10: Zip Code
 * 11: City
 * 12: Province
 * 13: Country
 */

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';

$file_in = __DIR__ . '/../originais/2018 Filiação Nacional.xls - Folha1.csv';
$file_out = __DIR__ . '/../limpos/filiados_2018_limpo.csv';

// Mapeamento de normalização
$instituicoes_map = require __DIR__ . '/instituicoes_normalizadas.php';
$cidades_map = require __DIR__ . '/cidades_normalizadas.php';

// Mapeamento de categorias
$categorias_map = [
    'PN' => ['categoria' => 'profissional_nacional', 'valor' => 6000],
    'E' => ['categoria' => 'estudante', 'valor' => 3000],
];

// Funções auxiliares (copiadas do script anterior)
function capitalizar_nome($nome) {
    $nome = trim($nome);
    $nome = preg_replace('/\s+/', ' ', $nome);

    $minusculas = ['de', 'da', 'do', 'das', 'dos', 'e', 'del', 'van', 'von'];

    $palavras = explode(' ', mb_strtolower($nome));
    $resultado = [];
    foreach ($palavras as $i => $p) {
        if ($i > 0 && in_array($p, $minusculas)) {
            $resultado[] = $p;
        } else {
            $resultado[] = mb_convert_case($p, MB_CASE_TITLE, 'UTF-8');
        }
    }
    return implode(' ', $resultado);
}

function formatar_cep($cep) {
    $cep = preg_replace('/[^0-9]/', '', $cep);
    if (strlen($cep) == 8) {
        return substr($cep, 0, 5) . '-' . substr($cep, 5, 3);
    }
    return $cep;
}

function normalizar_cidade($cidade, $map) {
    $cidade = trim($cidade);
    if (empty($cidade)) return '';

    $key = mb_strtolower($cidade);
    if (isset($map[$key])) {
        return $map[$key];
    }

    return $cidade;
}

function normalizar_estado($estado) {
    $estado = trim($estado);
    if (empty($estado)) return '';

    $estado = strtoupper($estado);

    $map = [
        'BAHIA' => 'BA', 'PERNAMBUCO' => 'PE', 'SÃO PAULO' => 'SP', 'SAO PAULO' => 'SP',
        'RIO DE JANEIRO' => 'RJ', 'MINAS GERAIS' => 'MG', 'PARANÁ' => 'PR', 'PARANA' => 'PR',
        'RIO GRANDE DO SUL' => 'RS', 'SANTA CATARINA' => 'SC', 'CEARÁ' => 'CE', 'CEARA' => 'CE',
        'GOIÁS' => 'GO', 'GOIAS' => 'GO', 'DISTRITO FEDERAL' => 'DF', 'AMAZONAS' => 'AM',
        'MARANHÃO' => 'MA', 'MARANHAO' => 'MA', 'PARÁ' => 'PA', 'PARA' => 'PA',
        'RONDÔNIA' => 'RO', 'RONDONIA' => 'RO', 'RORAIMA' => 'RR',
    ];

    if (isset($map[$estado])) {
        return $map[$estado];
    }

    if (strlen($estado) == 2) {
        return $estado;
    }

    return $estado;
}

function formatar_telefone($tel) {
    $tel = preg_replace('/[^0-9]/', '', $tel);

    if (strlen($tel) > 11 && substr($tel, 0, 2) == '55') {
        $tel = substr($tel, 2);
    }

    // Remove código de país estrangeiro (00351 = Portugal)
    if (strlen($tel) > 11 && substr($tel, 0, 5) == '00351') {
        return '+351 ' . substr($tel, 5);
    }

    if (strlen($tel) > 11) {
        $tel = substr($tel, 0, 11);
    }

    if (strlen($tel) == 11) {
        return '(' . substr($tel, 0, 2) . ') ' . substr($tel, 2, 5) . '-' . substr($tel, 7);
    } elseif (strlen($tel) == 10) {
        return '(' . substr($tel, 0, 2) . ') ' . substr($tel, 2, 4) . '-' . substr($tel, 6);
    }
    return $tel;
}

function normalizar_formacao($formacao) {
    $formacao = trim($formacao);
    if (empty($formacao)) return '';

    $lower = mb_strtolower($formacao);

    if (strpos($lower, 'doutor') !== false || strpos($lower, 'dra') !== false || strpos($lower, 'dr.') !== false) {
        return 'Doutorado';
    }
    if (strpos($lower, 'mestr') !== false) {
        return 'Mestrado';
    }
    if (strpos($lower, 'gradua') !== false) {
        if (strpos($lower, 'ando') !== false) {
            return 'Graduação em andamento';
        }
        return 'Graduação';
    }
    if (strpos($lower, 'arquitet') !== false) {
        return 'Graduação';
    }

    return $formacao;
}

// Carrega pessoas do banco
$pessoas_db = db_fetch_all("
    SELECT p.id, p.nome, e.email
    FROM pessoas p
    LEFT JOIN emails e ON e.pessoa_id = p.id
");

$por_email = [];
foreach ($pessoas_db as $p) {
    if ($p['email']) {
        $por_email[strtolower($p['email'])] = $p;
    }
}

// Lê CSV de entrada
$handle_in = fopen($file_in, 'r');

// Pula as primeiras 5 linhas (metadata + header)
for ($i = 0; $i < 5; $i++) {
    fgetcsv($handle_in);
}

$registros = [];

while (($row = fgetcsv($handle_in)) !== false) {
    // Para quando encontrar linha de despesas ou vazia
    $cat_code = trim($row[1] ?? '');
    if (!in_array($cat_code, ['PN', 'E'])) {
        continue;
    }

    $nome_raw = trim($row[5] ?? '');
    if (empty($nome_raw)) continue;

    // Limpa nome de quebras de linha
    $nome_raw = preg_replace('/\s+/', ' ', $nome_raw);

    $formacao_raw = trim($row[4] ?? '');
    $email_raw = trim($row[7] ?? '');
    $telefone_raw = trim($row[8] ?? '');
    $endereco_raw = trim($row[9] ?? '');
    $cep_raw = trim($row[10] ?? '');
    $cidade_raw = trim($row[11] ?? '');
    $estado_raw = trim($row[12] ?? '');

    // Limpa email de espaços e caracteres estranhos
    $email_raw = preg_replace('/\s+/', '', $email_raw);
    $email_raw = str_replace(['Y.ah o o com'], ['yahoo.com'], $email_raw);

    // Limpa dados
    $nome = capitalizar_nome($nome_raw);
    $email = strtolower(trim($email_raw));
    $telefone = formatar_telefone($telefone_raw);
    $cep = formatar_cep($cep_raw);
    $cidade = normalizar_cidade($cidade_raw, $cidades_map);
    $estado = normalizar_estado($estado_raw);
    $formacao = normalizar_formacao($formacao_raw);

    // Categoria e valor
    $cat_info = $categorias_map[$cat_code] ?? ['categoria' => '', 'valor' => 0];

    // Verifica se existe no banco
    $email_existe = isset($por_email[$email]) ? 'SIM' : '';
    $pessoa_id_email = $por_email[$email]['id'] ?? '';
    $nome_banco = $por_email[$email]['nome'] ?? '';

    $registros[] = [
        'nome' => $nome,
        'email' => $email,
        'categoria' => $cat_info['categoria'],
        'valor' => $cat_info['valor'],
        'data_pagamento' => '2018-01-01', // Data aproximada (não temos data exata)
        'metodo' => 'Desconhecido',
        'cpf' => '',
        'telefone' => $telefone,
        'endereco' => $endereco_raw,
        'cep' => $cep,
        'cidade' => $cidade,
        'estado' => $estado,
        'pais' => 'Brasil',
        'profissao' => '',
        'formacao' => $formacao,
        'instituicao' => '',
        'seminario' => '',
        'email_existe' => $email_existe,
        'pessoa_id' => $pessoa_id_email,
        'nome_banco' => $nome_banco,
    ];
}
fclose($handle_in);

// Escreve CSV
$handle = fopen($file_out, 'w');
fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM

fputcsv($handle, [
    'nome', 'email', 'categoria', 'valor', 'data_pagamento', 'metodo',
    'cpf', 'telefone', 'endereco', 'cep', 'cidade', 'estado', 'pais',
    'profissao', 'formacao', 'instituicao', 'seminario',
    'email_existe', 'pessoa_id', 'nome_banco'
], ';');

usort($registros, fn($a, $b) => strcmp($a['nome'], $b['nome']));

foreach ($registros as $r) {
    fputcsv($handle, [
        $r['nome'], $r['email'], $r['categoria'], $r['valor'], $r['data_pagamento'], $r['metodo'],
        $r['cpf'], $r['telefone'], $r['endereco'], $r['cep'], $r['cidade'], $r['estado'], $r['pais'],
        $r['profissao'], $r['formacao'], $r['instituicao'], $r['seminario'],
        $r['email_existe'], $r['pessoa_id'], $r['nome_banco']
    ], ';');
}
fclose($handle);

echo "=== Resumo 2018 ===\n";
echo "Registros: " . count($registros) . " -> $file_out\n";

// Estatísticas
$stats = [];
foreach ($registros as $r) {
    $cat = $r['categoria'] ?: '(vazio)';
    $stats[$cat] = ($stats[$cat] ?? 0) + 1;
}
echo "\nPor categoria:\n";
foreach ($stats as $cat => $qtd) {
    echo "  $cat: $qtd\n";
}

$existe = count(array_filter($registros, fn($r) => $r['email_existe'] === 'SIM'));
echo "\nJá existem no banco: $existe de " . count($registros) . "\n";
