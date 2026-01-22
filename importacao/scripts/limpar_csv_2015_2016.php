<?php
/**
 * Limpa e normaliza dados de filiação 2015-2016
 * Fonte: Google Forms "Ficha de Inscrição Docomomo Brasil"
 *
 * Colunas originais:
 * 0: Carimbo de data/hora
 * 1: Nome completo
 * 2: Instituição
 * 3: E-mail
 * 4: Endereço
 * 5: Telefone (com DDD)
 * 6: Categoria de Filiação
 * 7: Observações
 * 8: Titulação
 * 9: Cidade
 * 10: Estado
 * 11: CEP
 * 12: CPF
 * 13: RG
 * 14: Área de atuação
 * 15: Estado (duplicado)
 */

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';

$file_in = __DIR__ . '/../originais/filiacao-2015-2016.csv';
$file_out_2015 = __DIR__ . '/../limpos/filiados_2015_limpo.csv';
$file_out_2016 = __DIR__ . '/../limpos/filiados_2016_limpo.csv';

// Mapeamento de normalização de instituições
$instituicoes_map = require __DIR__ . '/instituicoes_normalizadas.php';

// Mapeamento de normalização de cidades
$cidades_map = require __DIR__ . '/cidades_normalizadas.php';

// Mapeamento de categorias
$categorias_map = [
    'pleno nacional (r$160,00)' => ['categoria' => 'profissional_nacional', 'valor' => 16000],
    'estudante nacional (r$80,00)' => ['categoria' => 'estudante', 'valor' => 8000],
    'pleno internacional (enviar comprovante da filiação ao docomomo international)' => ['categoria' => 'profissional_internacional', 'valor' => 16000],
    'pleno internacional (enviar comprovante)' => ['categoria' => 'profissional_internacional', 'valor' => 16000],
    'estudante internacional (enviar comprovante da filiação ao docomomo international)' => ['categoria' => 'estudante', 'valor' => 8000],
];

// Funções auxiliares
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

function normalizar_instituicao($inst, $map) {
    $inst = trim($inst);
    if (empty($inst)) return '';

    $inst = trim($inst, '"\'');
    $inst = preg_replace('/\s+/', ' ', $inst);
    $inst = str_replace(';', ',', $inst);

    $key = mb_strtolower($inst);
    if (isset($map[$key])) {
        return $map[$key];
    }

    return $inst;
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
    $estado = strtoupper($estado);

    // Mapeamento de nomes completos para siglas
    $map = [
        'ACRE' => 'AC', 'ALAGOAS' => 'AL', 'AMAPÁ' => 'AP', 'AMAZONAS' => 'AM',
        'BAHIA' => 'BA', 'CEARÁ' => 'CE', 'DISTRITO FEDERAL' => 'DF', 'ESPÍRITO SANTO' => 'ES',
        'GOIÁS' => 'GO', 'MARANHÃO' => 'MA', 'MATO GROSSO' => 'MT', 'MATO GROSSO DO SUL' => 'MS',
        'MINAS GERAIS' => 'MG', 'PARÁ' => 'PA', 'PARAÍBA' => 'PB', 'PARANÁ' => 'PR',
        'PERNAMBUCO' => 'PE', 'PIAUÍ' => 'PI', 'RIO DE JANEIRO' => 'RJ', 'RIO GRANDE DO NORTE' => 'RN',
        'RIO GRANDE DO SUL' => 'RS', 'RONDÔNIA' => 'RO', 'RORAIMA' => 'RR', 'SANTA CATARINA' => 'SC',
        'SÃO PAULO' => 'SP', 'SERGIPE' => 'SE', 'TOCANTINS' => 'TO',
        'ESPIRITO SANTO' => 'ES', 'GOIAS' => 'GO', 'MARANHAO' => 'MA', 'PARA' => 'PA',
        'PARAIBA' => 'PB', 'PIAUI' => 'PI', 'RONDONIA' => 'RO', 'SAO PAULO' => 'SP',
    ];

    if (isset($map[$estado])) {
        return $map[$estado];
    }

    // Se já é sigla de 2 letras, retorna
    if (strlen($estado) == 2) {
        return $estado;
    }

    return $estado;
}

function formatar_telefone($tel) {
    if (preg_match('/[\/,]/', $tel)) {
        $partes = preg_split('/[\/,]/', $tel);
        $tel = trim($partes[0]);
    }

    $tel = preg_replace('/[^0-9]/', '', $tel);

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

    $map = [
        'ensino médio' => 'Ensino Médio',
        'graduando' => 'Graduação em andamento',
        'graduando(a)' => 'Graduação em andamento',
        'graduação' => 'Graduação',
        'especialista' => 'Especialização / MBA',
        'especialização' => 'Especialização / MBA',
        'mba' => 'Especialização / MBA',
        'mestrando' => 'Mestrado em andamento',
        'mestrando(a)' => 'Mestrado em andamento',
        'mestrado' => 'Mestrado',
        'mestre' => 'Mestrado',
        'mestra' => 'Mestrado',
        'doutorando' => 'Doutorado em andamento',
        'doutorando(a)' => 'Doutorado em andamento',
        'doutorado' => 'Doutorado',
        'doutor' => 'Doutorado',
        'doutora' => 'Doutorado',
        'dr.' => 'Doutorado',
        'dra.' => 'Doutorado',
        'drª' => 'Doutorado',
        'pós-doutorado' => 'Pós-Doutorado',
        'pós-doutorando' => 'Pós-Doutorado',
    ];

    $key = mb_strtolower($formacao);
    return $map[$key] ?? $formacao;
}

// Extrai ano da data (formato DD/MM/YYYY)
function extrair_ano($data) {
    if (preg_match('#(\d{2})/(\d{2})/(\d{4})#', $data, $m)) {
        return (int)$m[3];
    }
    return null;
}

// Converte data para formato ISO
function converter_data($data) {
    if (preg_match('#(\d{2})/(\d{2})/(\d{4})#', $data, $m)) {
        return "{$m[3]}-{$m[2]}-{$m[1]}";
    }
    return null;
}

// Carrega pessoas do banco para comparação
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

echo "Colunas: " . implode(" | ", $header) . "\n\n";

$registros_2015 = [];
$registros_2016 = [];
$outros_anos = [];

while (($row = fgetcsv($handle_in)) !== false) {
    if (empty($row[0]) || !preg_match('/^\d/', $row[0])) continue;

    $data_raw = trim($row[0]);
    $nome_raw = trim($row[1] ?? '');
    $instituicao_raw = trim($row[2] ?? '');
    $email_raw = trim($row[3] ?? '');
    $endereco_raw = trim($row[4] ?? '');
    $telefone_raw = trim($row[5] ?? '');
    $categoria_raw = trim($row[6] ?? '');
    $titulacao_raw = trim($row[8] ?? '');
    $cidade_raw = trim($row[9] ?? '');
    $estado_raw = trim($row[10] ?? '');
    $cep_raw = trim($row[11] ?? '');
    $cpf_raw = trim($row[12] ?? '');
    $profissao_raw = trim($row[14] ?? '');

    // Extrai ano
    $ano = extrair_ano($data_raw);
    if (!$ano) continue;

    // Limpa dados
    $nome = capitalizar_nome($nome_raw);
    $email = strtolower(trim($email_raw));
    $telefone = formatar_telefone($telefone_raw);
    $cep = formatar_cep($cep_raw);
    $instituicao = normalizar_instituicao($instituicao_raw, $instituicoes_map);
    $cidade = normalizar_cidade($cidade_raw, $cidades_map);
    $estado = normalizar_estado($estado_raw);
    $formacao = normalizar_formacao($titulacao_raw);
    $data_pagamento = converter_data($data_raw);

    // Categoria e valor
    $cat_key = mb_strtolower($categoria_raw);
    $cat_info = $categorias_map[$cat_key] ?? ['categoria' => '', 'valor' => 0];
    $categoria = $cat_info['categoria'];
    $valor = $cat_info['valor'];

    if (empty($categoria) && !empty($categoria_raw)) {
        echo "AVISO: Categoria não reconhecida: '$categoria_raw'\n";
    }

    // Verifica se existe no banco
    $email_existe = isset($por_email[$email]) ? 'SIM' : '';
    $pessoa_id_email = $por_email[$email]['id'] ?? '';
    $nome_banco = $por_email[$email]['nome'] ?? '';

    $registro = [
        'nome' => $nome,
        'email' => $email,
        'categoria' => $categoria,
        'valor' => $valor,
        'data_pagamento' => $data_pagamento,
        'metodo' => 'Desconhecido',
        'cpf' => $cpf_raw,
        'telefone' => $telefone,
        'endereco' => $endereco_raw,
        'cep' => $cep,
        'cidade' => $cidade,
        'estado' => $estado,
        'pais' => 'Brasil',
        'profissao' => $profissao_raw,
        'formacao' => $formacao,
        'instituicao' => $instituicao,
        'seminario' => '',
        'email_existe' => $email_existe,
        'pessoa_id' => $pessoa_id_email,
        'nome_banco' => $nome_banco,
    ];

    if ($ano == 2015) {
        $registros_2015[] = $registro;
    } elseif ($ano == 2016) {
        $registros_2016[] = $registro;
    } else {
        $outros_anos[$ano][] = $registro;
    }
}
fclose($handle_in);

// Função para escrever CSV
function escrever_csv($file_out, $registros) {
    $handle = fopen($file_out, 'w');
    fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM

    fputcsv($handle, [
        'nome', 'email', 'categoria', 'valor', 'data_pagamento', 'metodo',
        'cpf', 'telefone', 'endereco', 'cep', 'cidade', 'estado', 'pais',
        'profissao', 'formacao', 'instituicao', 'seminario',
        'email_existe', 'pessoa_id', 'nome_banco'
    ], ';');

    // Ordena por nome
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
}

// Escreve CSVs
escrever_csv($file_out_2015, $registros_2015);
escrever_csv($file_out_2016, $registros_2016);

echo "=== Resumo ===\n";
echo "2015: " . count($registros_2015) . " registros -> $file_out_2015\n";
echo "2016: " . count($registros_2016) . " registros -> $file_out_2016\n";

if (!empty($outros_anos)) {
    echo "\nOutros anos encontrados:\n";
    foreach ($outros_anos as $ano => $regs) {
        echo "  $ano: " . count($regs) . " registros\n";
    }
}

// Estatísticas por categoria
echo "\n=== 2015 por categoria ===\n";
$stats = [];
foreach ($registros_2015 as $r) {
    $cat = $r['categoria'] ?: '(vazio)';
    $stats[$cat] = ($stats[$cat] ?? 0) + 1;
}
foreach ($stats as $cat => $qtd) {
    echo "  $cat: $qtd\n";
}

echo "\n=== 2016 por categoria ===\n";
$stats = [];
foreach ($registros_2016 as $r) {
    $cat = $r['categoria'] ?: '(vazio)';
    $stats[$cat] = ($stats[$cat] ?? 0) + 1;
}
foreach ($stats as $cat => $qtd) {
    echo "  $cat: $qtd\n";
}

// Quantos já existem no banco
$existe_2015 = count(array_filter($registros_2015, fn($r) => $r['email_existe'] === 'SIM'));
$existe_2016 = count(array_filter($registros_2016, fn($r) => $r['email_existe'] === 'SIM'));
echo "\n=== Já existem no banco ===\n";
echo "  2015: $existe_2015 de " . count($registros_2015) . "\n";
echo "  2016: $existe_2016 de " . count($registros_2016) . "\n";
