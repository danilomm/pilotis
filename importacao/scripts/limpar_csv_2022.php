<?php
/**
 * Script para limpar e preparar dados de 2022 para importação
 *
 * Diferenças vs 2023:
 * - Endereço em coluna única (precisa extrair CEP, cidade, estado)
 * - Colunas em posições diferentes
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';

$file_in = __DIR__ . '/../backup-python/desenvolvimento/Ficha de Inscrição Docomomo Brasil (respostas) - 2022.csv';
$file_out = __DIR__ . '/../public/data/filiados_2022_limpo.csv';

// Mapeamento manual de endereços (CEP, cidade, estado)
$enderecos_manual = require __DIR__ . '/enderecos_2022_manual.php';

// Mapeamento de normalização de instituições
$instituicoes_map = require __DIR__ . '/instituicoes_normalizadas.php';

// Mapeamento de categorias (mesmos valores de 2023)
$categorias_map = [
    'Pleno Internacional (R$ 290,00)' => ['categoria' => 'profissional_internacional', 'valor' => 29000],
    'Pleno Nacional (R$ 145,00)' => ['categoria' => 'profissional_nacional', 'valor' => 14500],
    'Estudante (R$ 50,00)' => ['categoria' => 'estudante', 'valor' => 5000],
];

// Funções de limpeza
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

    // Se não encontrou, retorna capitalizado
    return $inst;
}

function normalizar_formacao($formacao) {
    $formacao = trim($formacao);

    // Mapeamento para valores do sistema (config.php FORMACOES)
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

function formatar_telefone($tel) {
    // Se houver múltiplos números (separados por / ou ,), pega só o primeiro
    if (preg_match('/[\/,]/', $tel)) {
        $partes = preg_split('/[\/,]/', $tel);
        $tel = trim($partes[0]);
    }

    $tel = preg_replace('/[^0-9]/', '', $tel);

    // Remove código do país se presente
    if (strlen($tel) > 11 && substr($tel, 0, 2) == '55') {
        $tel = substr($tel, 2);
    }

    // Se ainda tiver mais de 11 dígitos, tenta extrair os primeiros 11 (celular) ou 10 (fixo)
    if (strlen($tel) > 11) {
        // Assume celular com DDD (11 dígitos)
        $tel = substr($tel, 0, 11);
    }

    if (strlen($tel) == 11) {
        return '(' . substr($tel, 0, 2) . ') ' . substr($tel, 2, 5) . '-' . substr($tel, 7);
    } elseif (strlen($tel) == 10) {
        return '(' . substr($tel, 0, 2) . ') ' . substr($tel, 2, 4) . '-' . substr($tel, 6);
    }
    return $tel;
}

/**
 * Extrai CEP, cidade e estado de um endereço completo
 * Exemplos de formatos:
 * - "Rua X, 123, Bairro, CEP 12345-678, Cidade, Estado"
 * - "Rua X, 123, 12345-678 Cidade-UF"
 * - "Rua X, Cidade - UF"
 */
function extrair_endereco_completo($endereco_raw) {
    $endereco = trim($endereco_raw);
    $cep = '';
    $cidade = '';
    $estado = '';

    // Extrai CEP (formatos: 12345-678, 12345678, CEP 12345-678, CEP: 12345-678)
    if (preg_match('/(?:CEP[:\s]*)?(\d{5}[-.]?\d{3})/i', $endereco, $m)) {
        $cep = preg_replace('/[^0-9]/', '', $m[1]);
        // Remove o CEP do endereço para facilitar extração de cidade/estado
        $endereco = preg_replace('/(?:CEP[:\s]*)?\d{5}[-.]?\d{3}/i', '', $endereco);
    }

    // Lista de estados brasileiros
    $estados = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];

    // Tenta extrair estado no final (formatos: ", SP", "- SP", "/SP", " SP")
    $pattern = '/[,\-\/\s]+(' . implode('|', $estados) . ')\.?\s*$/i';
    if (preg_match($pattern, $endereco, $m)) {
        $estado = strtoupper($m[1]);
        $endereco = preg_replace($pattern, '', $endereco);
    }

    // Tenta extrair cidade (última parte após vírgula ou hífen)
    $endereco = trim($endereco, " ,.-");
    $partes = preg_split('/[,\-]/', $endereco);
    if (count($partes) > 1) {
        $ultima = trim(end($partes));
        // Se a última parte parece ser uma cidade (não tem números de rua típicos)
        if (!preg_match('/^\d+/', $ultima) && strlen($ultima) > 2 && strlen($ultima) < 50) {
            $cidade = $ultima;
            array_pop($partes);
            $endereco = implode(', ', array_map('trim', $partes));
        }
    }

    return [
        'endereco' => trim($endereco, " ,.-"),
        'cep' => $cep,
        'cidade' => $cidade,
        'estado' => $estado,
    ];
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

// Indexa por nome normalizado
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
$seq_num = 0; // Contador sequencial para linhas válidas
while (($row = fgetcsv($handle_in)) !== false) {
    // Pula linhas vazias ou separadores (linha 2 tem "PLENO INTERNACIONAL...")
    if (empty($row[0]) || !is_numeric($row[0])) continue;

    $seq_num++; // Incrementa só para linhas válidas

    // Estrutura 2022:
    // 0: N°
    // 1: Carimbo de data/hora
    // 2: Nome completo
    // 3: E-mail (principal)
    // 4: Título de formação
    // 5: Profissão
    // 6: Vínculo Profissional (instituição)
    // 7: Cargo na rede Docomomo Brasil
    // 8: Endereço Completo (tudo junto)
    // 9: Número de Telefone
    // 10: Categoria de Inscrição
    // 11: Comprovante de matrícula
    // 12: Método de Pagamento
    // 13: Anexar comprovante

    $num = trim($row[0]);
    $data = trim($row[1] ?? '');
    $nome_raw = trim($row[2] ?? '');
    $email_raw = trim($row[3] ?? '');
    $formacao = normalizar_formacao(trim($row[4] ?? ''));
    $profissao = trim($row[5] ?? '');
    $instituicao_raw = trim($row[6] ?? '');
    $instituicao = normalizar_instituicao($instituicao_raw, $instituicoes_map);
    $endereco_raw = trim($row[8] ?? '');
    $telefone_raw = trim($row[9] ?? '');
    $categoria_raw = trim($row[10] ?? '');
    $metodo = normalizar_metodo_pagamento(trim($row[12] ?? ''));

    // Limpa dados
    $nome = capitalizar_nome($nome_raw);
    $email = strtolower(trim($email_raw));
    $telefone = formatar_telefone($telefone_raw);

    // Usa mapeamento manual para CEP, cidade, estado
    // NOTA: usa $seq_num (sequencial) pois o N° do CSV não é confiável
    $end_manual = $enderecos_manual[$seq_num] ?? ['cep' => '', 'cidade' => '', 'estado' => ''];
    $cep = formatar_cep($end_manual['cep']);
    $cidade = $end_manual['cidade'];
    $estado = $end_manual['estado'];
    $endereco = $endereco_raw; // Mantém endereço original

    // Categoria e valor
    $cat_info = $categorias_map[$categoria_raw] ?? ['categoria' => '', 'valor' => 0];
    $categoria = $cat_info['categoria'];
    $valor = $cat_info['valor'];

    // Aviso se categoria não reconhecida
    if (empty($categoria) && !empty($categoria_raw)) {
        echo "AVISO linha $seq_num: Categoria não reconhecida: '$categoria_raw'\n";
    }

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

// Estatísticas por categoria
echo "\nPor categoria:\n";
$por_cat = [];
foreach ($linhas as $l) {
    $cat = $l[3] ?: '(vazio)';
    $por_cat[$cat] = ($por_cat[$cat] ?? 0) + 1;
}
foreach ($por_cat as $cat => $qtd) {
    echo "  $cat: $qtd\n";
}
