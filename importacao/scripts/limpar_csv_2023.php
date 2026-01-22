<?php
/**
 * Script para limpar e preparar dados de 2023 para importação
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';

$file_in = __DIR__ . '/../backup-python/desenvolvimento/Ficha de Inscrição Docomomo Brasil (respostas) - 2023.csv';
$file_out = __DIR__ . '/../public/data/filiados_2023_limpo.csv';

// Mapeamento de categorias
$categorias_map = [
    'Pleno Internacional (R$ 290,00)' => ['categoria' => 'profissional_internacional', 'valor' => 29000],
    'Pleno Nacional (R$ 145,00)' => ['categoria' => 'profissional_nacional', 'valor' => 14500],
    'Estudante (R$ 50,00)' => ['categoria' => 'estudante', 'valor' => 5000],
];

// Mapeamento de normalização de instituições
$instituicoes_map = require __DIR__ . '/instituicoes_normalizadas.php';

// Funções de limpeza
function capitalizar_nome($nome) {
    $nome = trim($nome);
    // Remove espaços extras
    $nome = preg_replace('/\s+/', ' ', $nome);

    // Palavras que devem ficar minúsculas
    $minusculas = ['de', 'da', 'do', 'das', 'dos', 'e', 'del', 'van', 'von'];

    // Capitaliza cada palavra
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

function formatar_telefone($tel) {
    // Se houver múltiplos números (separados por / ou ,), pega só o primeiro
    if (preg_match('/[\/,]/', $tel)) {
        $partes = preg_split('/[\/,]/', $tel);
        $tel = trim($partes[0]);
    }

    // Remove texto extra como [WhatsApp]
    $tel = preg_replace('/\[.*?\]/', '', $tel);

    // Remove tudo que não é número
    $tel = preg_replace('/[^0-9]/', '', $tel);

    // Remove código do país se presente
    if (strlen($tel) > 11 && substr($tel, 0, 2) == '55') {
        $tel = substr($tel, 2);
    }

    // Se ainda tiver mais de 11 dígitos, trunca
    if (strlen($tel) > 11) {
        $tel = substr($tel, 0, 11);
    }

    // Formata
    if (strlen($tel) == 11) {
        return '(' . substr($tel, 0, 2) . ') ' . substr($tel, 2, 5) . '-' . substr($tel, 7);
    } elseif (strlen($tel) == 10) {
        return '(' . substr($tel, 0, 2) . ') ' . substr($tel, 2, 4) . '-' . substr($tel, 6);
    }
    return $tel;
}

function normalizar_instituicao($inst, $map) {
    $inst = trim($inst);
    if (empty($inst)) return '';

    // Remove aspas e espaços extras
    $inst = trim($inst, '"\'');
    $inst = preg_replace('/\s+/', ' ', $inst);

    // Remove ponto e vírgula (conflita com separador CSV)
    $inst = str_replace(';', ',', $inst);

    // Busca no mapa (lowercase)
    $key = mb_strtolower($inst);
    if (isset($map[$key])) {
        return $map[$key];
    }

    return $inst;
}

function normalizar_formacao($formacao) {
    $formacao = trim($formacao);

    $map = [
        // Ensino Médio
        'ensino médio' => 'Ensino Médio',
        'ensino medio' => 'Ensino Médio',
        // Graduação
        'graduando' => 'Graduação em andamento',
        'graduando(a)' => 'Graduação em andamento',
        'graduação em andamento' => 'Graduação em andamento',
        'graduacao em andamento' => 'Graduação em andamento',
        'graduação' => 'Graduação',
        'graduacao' => 'Graduação',
        // Especialização
        'especialista' => 'Especialização / MBA',
        'especialização' => 'Especialização / MBA',
        'especializacao' => 'Especialização / MBA',
        'mba' => 'Especialização / MBA',
        // Mestrado
        'mestrando' => 'Mestrado em andamento',
        'mestrando(a)' => 'Mestrado em andamento',
        'mestrado em andamento' => 'Mestrado em andamento',
        'mestrado' => 'Mestrado',
        // Doutorado
        'doutorando' => 'Doutorado em andamento',
        'doutorando(a)' => 'Doutorado em andamento',
        'doutorado em andamento' => 'Doutorado em andamento',
        'doutorado' => 'Doutorado',
        // Pós-Doutorado
        'pós-doutorado' => 'Pós-Doutorado',
        'pos-doutorado' => 'Pós-Doutorado',
        'pós-doutorando' => 'Pós-Doutorado',
        'pós-doutorando(a)' => 'Pós-Doutorado',
    ];

    $key = mb_strtolower($formacao);
    return $map[$key] ?? $formacao;
}

function normalizar_metodo_pagamento($metodo) {
    $metodo = trim($metodo);
    $metodo = mb_strtoupper($metodo);

    if (strpos($metodo, 'PIX') !== false) return 'PIX';
    if (strpos($metodo, 'DEPÓSITO') !== false || strpos($metodo, 'DEPOSITO') !== false) return 'Depósito';
    if (strpos($metodo, 'TRANSFERÊNCIA') !== false || strpos($metodo, 'TRANSFERENCIA') !== false) return 'Depósito';
    if (strpos($metodo, 'BOLETO') !== false) return 'Boleto';
    if (strpos($metodo, 'CARTÃO') !== false || strpos($metodo, 'CARTAO') !== false) return 'Cartão';

    return $metodo;
}

function extrair_cidade_estado($cidade_estado) {
    $cidade_estado = trim($cidade_estado);

    // Padrões comuns: "Cidade/UF", "Cidade-UF", "Cidade, UF", "Cidade - UF"
    if (preg_match('/^(.+?)[\s]*[\/\-,][\s]*([A-Za-z]{2})\.?$/', $cidade_estado, $m)) {
        return [trim($m[1]), strtoupper(trim($m[2]))];
    }

    return [$cidade_estado, ''];
}

// Carrega pessoas do banco para comparação
$pessoas_db = db_fetch_all("
    SELECT p.id, p.nome, e.email
    FROM pessoas p
    LEFT JOIN emails e ON e.pessoa_id = p.id
");

// Indexa por email (lowercase)
$por_email = [];
foreach ($pessoas_db as $p) {
    if ($p['email']) {
        $por_email[strtolower($p['email'])] = $p;
    }
}

// Indexa por nome normalizado (para busca aproximada)
$por_nome = [];
foreach ($pessoas_db as $p) {
    if ($p['nome']) {
        $nome_norm = mb_strtolower(preg_replace('/\s+/', ' ', trim($p['nome'])));
        $por_nome[$nome_norm] = $p;
    }
}

// Lê CSV de entrada
$handle_in = fopen($file_in, 'r');
$header = fgetcsv($handle_in);

// Prepara CSV de saída
if (!is_dir(dirname($file_out))) {
    mkdir(dirname($file_out), 0755, true);
}
$handle_out = fopen($file_out, 'w');

// BOM para UTF-8
fprintf($handle_out, chr(0xEF).chr(0xBB).chr(0xBF));

// Cabeçalho de saída
fputcsv($handle_out, [
    'num',
    'nome',
    'email',
    'categoria',
    'valor',
    'telefone',
    'cep',
    'cidade',
    'estado',
    'endereco',
    'profissao',
    'formacao',
    'instituicao',
    'metodo',
    'data',
    // Colunas de verificação
    'email_existe',
    'pessoa_id_email',
    'nome_banco_email',
    'nome_similar',
    'pessoa_id_nome',
    'nome_banco_similar',
    'acao_sugerida'
], ';');

$linhas = [];
while (($row = fgetcsv($handle_in)) !== false) {
    if (empty($row[0])) continue; // pula linhas vazias/separadores

    $num = trim($row[0]);
    $data = trim($row[1]);
    $nome_raw = trim($row[2]);
    $email_raw = trim($row[3]);
    $formacao_raw = trim($row[4]);
    $profissao = trim($row[5]);
    $instituicao_raw = trim($row[6]);
    $endereco = trim($row[8]) . ($row[9] ? ', ' . trim($row[9]) : '') . ($row[12] ? ' ' . trim($row[12]) : '');
    $cep_raw = trim($row[10]);
    $cidade_estado_raw = trim($row[11]);
    $telefone_raw = trim($row[13]);
    $categoria_raw = trim($row[14]);
    $metodo_raw = trim($row[16]);

    // Limpa dados
    $nome = capitalizar_nome($nome_raw);
    $email = strtolower(trim($email_raw));
    $telefone = formatar_telefone($telefone_raw);
    $cep = formatar_cep($cep_raw);
    list($cidade, $estado) = extrair_cidade_estado($cidade_estado_raw);
    $cidade = capitalizar_nome($cidade);

    // Normaliza instituição, formação e método
    $instituicao = normalizar_instituicao($instituicao_raw, $instituicoes_map);
    $formacao = normalizar_formacao($formacao_raw);
    $metodo = normalizar_metodo_pagamento($metodo_raw);

    // Categoria e valor
    $cat_info = $categorias_map[$categoria_raw] ?? ['categoria' => '', 'valor' => 0];
    $categoria = $cat_info['categoria'];
    $valor = $cat_info['valor'];

    // Verifica duplicados por email
    $email_existe = '';
    $pessoa_id_email = '';
    $nome_banco_email = '';
    if (isset($por_email[$email])) {
        $email_existe = 'SIM';
        $pessoa_id_email = $por_email[$email]['id'];
        $nome_banco_email = $por_email[$email]['nome'];
    }

    // Verifica duplicados por nome similar
    $nome_similar = '';
    $pessoa_id_nome = '';
    $nome_banco_similar = '';
    $nome_norm = mb_strtolower(preg_replace('/\s+/', ' ', trim($nome)));

    // Busca nome exato
    if (isset($por_nome[$nome_norm])) {
        $nome_similar = 'EXATO';
        $pessoa_id_nome = $por_nome[$nome_norm]['id'];
        $nome_banco_similar = $por_nome[$nome_norm]['nome'];
    } else {
        // Busca nome parcial (primeiro e último nome)
        $partes = explode(' ', $nome_norm);
        if (count($partes) >= 2) {
            $primeiro = $partes[0];
            $ultimo = end($partes);
            foreach ($por_nome as $n => $p) {
                $partes_db = explode(' ', $n);
                if ($partes_db[0] == $primeiro && end($partes_db) == $ultimo) {
                    $nome_similar = 'PARCIAL';
                    $pessoa_id_nome = $p['id'];
                    $nome_banco_similar = $p['nome'];
                    break;
                }
            }
        }
    }

    // Sugere ação
    $acao = '';
    if ($email_existe == 'SIM') {
        // Verifica se nome no banco é mais completo
        if (strlen($nome_banco_email) >= strlen($nome)) {
            $acao = 'USAR_EXISTENTE';
        } else {
            $acao = 'ATUALIZAR_NOME';
        }
    } elseif ($nome_similar) {
        $acao = 'VERIFICAR_MANUAL';
    } else {
        $acao = 'CRIAR_NOVO';
    }

    $linhas[] = [
        $num,
        $nome,
        $email,
        $categoria,
        $valor,
        $telefone,
        $cep,
        $cidade,
        $estado,
        $endereco,
        $profissao,
        $formacao,
        $instituicao,
        $metodo,
        $data,
        $email_existe,
        $pessoa_id_email,
        $nome_banco_email,
        $nome_similar,
        $pessoa_id_nome,
        $nome_banco_similar,
        $acao
    ];
}

// Ordena por nome para facilitar revisão
usort($linhas, fn($a, $b) => strcmp($a[1], $b[1]));

// Escreve linhas
foreach ($linhas as $linha) {
    fputcsv($handle_out, $linha, ';');
}

fclose($handle_in);
fclose($handle_out);

echo "Arquivo gerado: $file_out\n";
echo "Total de registros: " . count($linhas) . "\n";

// Estatísticas
$stats = [
    'USAR_EXISTENTE' => 0,
    'ATUALIZAR_NOME' => 0,
    'VERIFICAR_MANUAL' => 0,
    'CRIAR_NOVO' => 0,
];
foreach ($linhas as $l) {
    $stats[$l[21]]++;
}

echo "\nAções sugeridas:\n";
foreach ($stats as $acao => $qtd) {
    echo "  $acao: $qtd\n";
}
