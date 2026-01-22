<?php
/**
 * Limpa e normaliza dados de filiação 2019
 * Fonte: Excel "2019 campanha filiação.xls"
 *
 * Estrutura do arquivo com 3 seções:
 * 1. Linhas 1-66: "Dupla Nacional R$ 198,00" (~60 registros)
 * 2. Linhas 67-145: "Pleno Nacional R$ 60,00" (~64 registros)
 * 3. Linhas 148-214: "ESTUDANTE" (~59 registros)
 *
 * Formato das colunas de dados:
 * 0: No.
 * 1: Title (formação)
 * 2: Name
 * 3: Special position on Docomomo?
 * 4: e-mail
 * 5: Phone
 * 6: Address (ou vazio na seção Dupla)
 * 7: Zip Code (ou Address na seção Dupla)
 * 8: City (ou Zip Code na seção Dupla)
 * 9: Province (ou City na seção Dupla)
 * 10: Country (ou Province na seção Dupla)
 */

require_once __DIR__ . '/../../src/config.php';
require_once __DIR__ . '/../../src/db.php';

$file_in = __DIR__ . '/../originais/2019 campanha filiação.xls - Folha1.csv';
$file_out = __DIR__ . '/../limpos/filiados_2019_limpo.csv';

// Mapeamento de normalização
$instituicoes_map = require __DIR__ . '/instituicoes_normalizadas.php';
$cidades_map = require __DIR__ . '/cidades_normalizadas.php';

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

function normalizar_cidade($cidade, $map) {
    $cidade = trim($cidade);
    if (empty($cidade)) return '';

    // Remove tabs e espaços extras
    $cidade = preg_replace('/\s+/', ' ', $cidade);

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
        'RONDÔNIA' => 'RO', 'RONDONIA' => 'RO', 'RORAIMA' => 'RR', 'ALAGOAS' => 'AL',
        'SERGIPE' => 'SE', 'MATO GROSSO' => 'MT', 'MATO GROSSO DO SUL' => 'MS',
        'PARAÍBA' => 'PB', 'PARAIBA' => 'PB', 'PIAUÍ' => 'PI', 'PIAUI' => 'PI',
    ];

    if (isset($map[$estado])) {
        return $map[$estado];
    }

    if (strlen($estado) == 2) {
        return $estado;
    }

    return '';
}

function formatar_telefone($tel) {
    $tel = preg_replace('/[^0-9+]/', '', $tel);

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

    if (strpos($lower, 'doutor') !== false || strpos($lower, 'dra') !== false || strpos($lower, 'dr.') !== false || strpos($lower, 'drª') !== false) {
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
        if (strpos($lower, 'ando') !== false || strpos($lower, 'estudante') !== false) {
            return 'Graduação em andamento';
        }
        return 'Graduação';
    }
    if (strpos($lower, 'arquitet') !== false) {
        return 'Graduação';
    }
    if (strpos($lower, 'estudante') !== false) {
        return 'Graduação em andamento';
    }

    return $formacao;
}

function limpar_email($email) {
    $email = trim($email);
    $email = preg_replace('/\s+/', '', $email);
    // Remove tabs e caracteres especiais
    $email = preg_replace('/[\t\r\n]/', '', $email);
    // Remove espaços antes de @
    $email = preg_replace('/\s*@/', '@', $email);
    // Se tem múltiplos emails, pega o primeiro
    if (strpos($email, ';') !== false) {
        $email = explode(';', $email)[0];
    }
    return strtolower(trim($email));
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

// Lê todas as linhas do arquivo
$linhas = file($file_in, FILE_IGNORE_NEW_LINES);

$registros = [];
$secao_atual = '';
$linha_num = 0;

foreach ($linhas as $linha_raw) {
    $linha_num++;

    // Detecta mudança de seção
    if (strpos($linha_raw, 'Dupla Nacional') !== false) {
        $secao_atual = 'dupla';
        continue;
    }
    if (strpos($linha_raw, 'Pleno Nacional') !== false) {
        $secao_atual = 'pleno';
        continue;
    }
    if (strpos($linha_raw, 'ESTUDANTE') !== false || strpos($linha_raw, 'estudante') !== false) {
        $secao_atual = 'estudante';
        continue;
    }

    // Pula headers e linhas vazias
    if (strpos($linha_raw, 'No.,Title,Name') !== false) continue;
    if (strpos($linha_raw, 'DOCOMOMO Brasil') !== false) continue;
    if (strpos($linha_raw, '2018 Filiados') !== false) continue;
    if (empty(trim($linha_raw))) continue;

    // Parse CSV
    $row = str_getcsv($linha_raw);

    // Verifica se é uma linha de dados (começa com número ou tem nome na posição correta)
    $num = trim($row[0] ?? '');
    $nome_raw = trim($row[2] ?? '');

    // Pula se não tem nome
    if (empty($nome_raw)) continue;

    // Pula linhas de remanejamento ou vazias
    if (strpos(mb_strtolower($nome_raw), 'remanejamento') !== false) continue;

    // Limpa nome de quebras de linha e tabs
    $nome_raw = preg_replace('/\s+/', ' ', $nome_raw);

    // Determina categoria e valor baseado na seção
    if ($secao_atual === 'dupla') {
        $categoria = 'profissional_nacional';
        $valor = 19800; // R$ 198,00 (dupla = 2 x R$ 99)
    } elseif ($secao_atual === 'pleno') {
        $categoria = 'profissional_nacional';
        $valor = 6000; // R$ 60,00
    } elseif ($secao_atual === 'estudante') {
        $categoria = 'estudante';
        $valor = 3000; // R$ 30,00 (estimado)
    } else {
        // Seção inicial (antes do marcador "Dupla Nacional")
        $categoria = 'profissional_nacional';
        $valor = 19800;
    }

    // Extrai campos (posições variam um pouco entre seções)
    $formacao_raw = trim($row[1] ?? '');
    $email_raw = trim($row[4] ?? '');
    $telefone_raw = trim($row[5] ?? '');

    // Endereço pode estar em posições diferentes
    $endereco_raw = '';
    $cep_raw = '';
    $cidade_raw = '';
    $estado_raw = '';

    // Tenta extrair endereço das posições 6-10
    if (isset($row[6])) $endereco_raw = trim($row[6]);
    if (isset($row[7])) $cep_raw = trim($row[7]);
    if (isset($row[8])) $cidade_raw = trim($row[8]);
    if (isset($row[9])) $estado_raw = trim($row[9]);

    // Se CEP parece ser endereço (não começa com número), ajusta
    if (!empty($cep_raw) && !preg_match('/^\d/', $cep_raw)) {
        $endereco_raw = $cep_raw;
        $cep_raw = trim($row[8] ?? '');
        $cidade_raw = trim($row[9] ?? '');
        $estado_raw = trim($row[10] ?? '');
    }

    // Limpa dados
    $nome = capitalizar_nome($nome_raw);
    $email = limpar_email($email_raw);
    $telefone = formatar_telefone($telefone_raw);
    $cep = formatar_cep($cep_raw);
    $cidade = normalizar_cidade($cidade_raw, $cidades_map);
    $estado = normalizar_estado($estado_raw);
    $formacao = normalizar_formacao($formacao_raw);

    // Pula se não tem email válido
    if (empty($email) || strpos($email, '@') === false) {
        continue;
    }

    // Verifica se existe no banco
    $email_existe = isset($por_email[$email]) ? 'SIM' : '';
    $pessoa_id_email = $por_email[$email]['id'] ?? '';
    $nome_banco = $por_email[$email]['nome'] ?? '';

    $registros[] = [
        'nome' => $nome,
        'email' => $email,
        'categoria' => $categoria,
        'valor' => $valor,
        'data_pagamento' => '2019-01-01', // Data aproximada
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

// Remove duplicatas por email (mantém primeiro)
$emails_vistos = [];
$registros_unicos = [];
foreach ($registros as $r) {
    if (!isset($emails_vistos[$r['email']])) {
        $emails_vistos[$r['email']] = true;
        $registros_unicos[] = $r;
    }
}
$registros = $registros_unicos;

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

echo "=== Resumo 2019 ===\n";
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
