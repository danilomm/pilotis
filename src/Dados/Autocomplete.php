<?php
/**
 * Pilotis — Valores sugeridos nos formularios.
 *
 * Ao consultar autocomplete_valores por qtd, usar CAST(? AS INTEGER): o PDO
 * liga o parametro como TEXTO e no SQLite comparar coluna inteira com texto
 * e sempre falso — a lista vinha vazia em silencio.
 *
 * Extraido de src/db.php em 29/08/2026. O db.php continua existindo e
 * incluindo este arquivo, entao todo require antigo segue valendo.
 */

/**
 * Instituicoes conhecidas, para a lista fechada do formulario.
 *
 * POR QUE lista fechada e nao sugestao: o formulario de filiacao ja tem
 * <datalist>, que SUGERE mas deixa digitar qualquer coisa — e a base mostra que
 * nao basta. Ha 263 grafias distintas, das quais 140 aparecem UMA vez so, e o
 * problema continua nos anos recentes: 39 unicas em 490 preenchimentos de 2025,
 * 19 em 176 de 2026. Sao variacoes da mesma instituicao, e cada uma quebra a
 * planilha dos nucleos, a lista publicada e qualquer contagem por instituicao.
 *
 * O corte em 2+ ocorrencias cobre 88% dos preenchimentos e deixa de fora
 * justamente as grafias avulsas que nao queremos perpetuar.
 */
function instituicoes_conhecidas(int $minimo = 2): array {
    // CAST porque o PDO liga o parametro como TEXTO, e no SQLite comparar uma
    // coluna inteira com texto e sempre falso — a lista vinha vazia em silencio,
    // e o formulario mostrava so a opcao "Outra".
    $linhas = db_fetch_all(
        "SELECT valor FROM autocomplete_valores
         WHERE campo = 'instituicao' AND qtd >= CAST(? AS INTEGER)
         ORDER BY valor COLLATE NOCASE",
        [$minimo]
    );
    return array_column($linhas, 'valor');
}

function obter_autocomplete(): array {
    $campos = [
        'instituicao' => ['chave' => 'instituicoes', 'limite' => 500],
        'cidade'      => ['chave' => 'cidades',      'limite' => 200],
        'estado'      => ['chave' => 'estados',      'limite' => 50],
        'profissao'   => ['chave' => 'profissoes',   'limite' => 100],
    ];

    $resultado = [];
    foreach ($campos as $campo => $config) {
        $valores = db_fetch_all(
            "SELECT valor FROM autocomplete_valores WHERE campo = ? ORDER BY qtd DESC LIMIT ?",
            [$campo, $config['limite']]
        );
        $resultado[$config['chave']] = array_column($valores, 'valor');
    }

    return $resultado;
}
