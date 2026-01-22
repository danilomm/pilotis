<?php
/**
 * Limpa e normaliza dados de filiação 2021
 * Fonte: Google Forms "Filiação Brasil 2021"
 *
 * Colunas:
 * 0: Carimbo de data/hora
 * 1: Endereço de e-mail
 * 2: Nome completo
 * 3: Título de Formação
 * 4: Cargo na rede Docomomo Brasil
 * 5: Endereço (Logradouro, Bairro, CEP)
 * 6: Endereço 2 (Cidade, Estado)
 * 7: Número de Telefone (com DDD)
 * 8: Categoria de Inscrição
 */

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';

$file_in = __DIR__ . '/../originais/Filiação Brasil 2021 (respostas) - Respostas ao formulário 1.csv';
$file_out = __DIR__ . '/../limpos/filiados_2021_limpo.csv';

// Mapeamento de normalização
$cidades_map = require __DIR__ . '/cidades_normalizadas.php';

// Mapeamento de categorias (valores de 2021)
$categorias_map = [
    'pleno internacional' => ['categoria' => 'profissional_internacional', 'valor' => 29000],
    'pleno nacional' => ['categoria' => 'profissional_nacional', 'valor' => 14500],
    'estudante' => ['categoria' => 'estudante', 'valor' => 5000],
];

// Funções auxiliares (mesmo código de 2020)
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

function formatar_telefone($tel) {
    $tel = preg_replace('/[^0-9+]/', '', $tel);

    if (substr($tel, 0, 3) === '+55') {
        $tel = substr($tel, 3);
    }
    if (strlen($tel) > 11 && substr($tel, 0, 2) == '55') {
        $tel = substr($tel, 2);
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
        if (strpos($lower, 'ando') !== false) {
            return 'Doutorado em andamento';
        }
        return 'Doutorado';
    }
    if (strpos($lower, 'mestr') !== false || strpos($lower, 'msc') !== false) {
        if (strpos($lower, 'ando') !== false) {
            return 'Mestrado em andamento';
        }
        return 'Mestrado';
    }
    if (strpos($lower, 'especial') !== false) {
        return 'Especialização / MBA';
    }
    if (strpos($lower, 'gradua') !== false || strpos($lower, 'bachar') !== false) {
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

function extrair_cidade_estado($endereco2, $cidades_map) {
    $endereco2 = trim($endereco2);
    if (empty($endereco2)) return ['cidade' => '', 'estado' => ''];

    $endereco2 = rtrim($endereco2, '. ');

    $partes = array_map('trim', explode(',', $endereco2));

    $cidade = $partes[0] ?? '';
    $estado = $partes[1] ?? '';

    $cidade_key = mb_strtolower($cidade);
    if (isset($cidades_map[$cidade_key])) {
        $cidade = $cidades_map[$cidade_key];
    }

    $estado = strtoupper(trim($estado));
    $estados_map = [
        'SÃO PAULO' => 'SP', 'SAO PAULO' => 'SP', 'RIO DE JANEIRO' => 'RJ',
        'MINAS GERAIS' => 'MG', 'BAHIA' => 'BA', 'PERNAMBUCO' => 'PE',
        'RIO GRANDE DO SUL' => 'RS', 'PARANÁ' => 'PR', 'PARANA' => 'PR',
        'SANTA CATARINA' => 'SC', 'CEARÁ' => 'CE', 'CEARA' => 'CE',
        'DISTRITO FEDERAL' => 'DF', 'GOIÁS' => 'GO', 'GOIAS' => 'GO',
        'MARANHÃO' => 'MA', 'MARANHAO' => 'MA', 'PARÁ' => 'PA', 'PARA' => 'PA',
        'AMAZONAS' => 'AM', 'SERGIPE' => 'SE', 'ALAGOAS' => 'AL',
        'PARAÍBA' => 'PB', 'PARAIBA' => 'PB', 'PIAUÍ' => 'PI', 'PIAUI' => 'PI',
        'RIO GRANDE DO NORTE' => 'RN', 'MATO GROSSO' => 'MT', 'MATO GROSSO DO SUL' => 'MS',
        'ESPÍRITO SANTO' => 'ES', 'ESPIRITO SANTO' => 'ES', 'RONDÔNIA' => 'RO',
        'RORAIMA' => 'RR', 'TOCANTINS' => 'TO', 'ACRE' => 'AC', 'AMAPÁ' => 'AP',
    ];

    if (isset($estados_map[$estado])) {
        $estado = $estados_map[$estado];
    }

    if (strlen($estado) > 2) {
        if (preg_match('/\b([A-Z]{2})\.?\s*$/', $estado, $m)) {
            $estado = $m[1];
        } else {
            $estado = '';
        }
    }

    return ['cidade' => $cidade, 'estado' => $estado];
}

function extrair_cep($endereco1) {
    if (preg_match('/(\d{5}[-.]?\d{3})/', $endereco1, $m)) {
        $cep = preg_replace('/[^0-9]/', '', $m[1]);
        if (strlen($cep) == 8) {
            return substr($cep, 0, 5) . '-' . substr($cep, 5, 3);
        }
    }
    return '';
}

function converter_data($data) {
    if (preg_match('#(\d{2})/(\d{2})/(\d{4})#', $data, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    return null;
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
$header = fgetcsv($handle_in);

$registros = [];

while (($row = fgetcsv($handle_in)) !== false) {
    $data_raw = trim($row[0] ?? '');
    $email_raw = trim($row[1] ?? '');
    $nome_raw = trim($row[2] ?? '');
    $formacao_raw = trim($row[3] ?? '');
    $endereco1_raw = trim($row[5] ?? '');
    $endereco2_raw = trim($row[6] ?? '');
    $telefone_raw = trim($row[7] ?? '');
    $categoria_raw = trim($row[8] ?? '');

    if (empty($nome_raw) || empty($email_raw)) continue;

    // Limpa dados
    $nome = capitalizar_nome($nome_raw);
    $email = strtolower(trim($email_raw));
    $telefone = formatar_telefone($telefone_raw);
    $formacao = normalizar_formacao($formacao_raw);
    $cep = extrair_cep($endereco1_raw);
    $cidade_estado = extrair_cidade_estado($endereco2_raw, $cidades_map);
    $data_pagamento = converter_data($data_raw);

    // Categoria e valor
    $cat_key = mb_strtolower($categoria_raw);
    $cat_info = $categorias_map[$cat_key] ?? ['categoria' => '', 'valor' => 0];

    if (empty($cat_info['categoria']) && !empty($categoria_raw)) {
        echo "AVISO: Categoria não reconhecida: '$categoria_raw'\n";
    }

    // Verifica se existe no banco
    $email_existe = isset($por_email[$email]) ? 'SIM' : '';
    $pessoa_id_email = $por_email[$email]['id'] ?? '';
    $nome_banco = $por_email[$email]['nome'] ?? '';

    $registros[] = [
        'nome' => $nome,
        'email' => $email,
        'categoria' => $cat_info['categoria'],
        'valor' => $cat_info['valor'],
        'data_pagamento' => $data_pagamento,
        'metodo' => 'Desconhecido',
        'cpf' => '',
        'telefone' => $telefone,
        'endereco' => $endereco1_raw,
        'cep' => $cep,
        'cidade' => $cidade_estado['cidade'],
        'estado' => $cidade_estado['estado'],
        'pais' => 'Brasil',
        'profissao' => $formacao_raw,
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

echo "=== Resumo 2021 ===\n";
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
