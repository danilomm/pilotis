<?php
/**
 * Pilotis — Consultas sobre eventos e inscricoes.
 *
 * inscritos_do_evento() e a consulta UNICA da tela do admin, dos dois CSV e
 * do painel da organizacao. Se divergirem, alguem cobra a pessoa errada.
 *
 * Extraido de src/db.php em 29/08/2026. O db.php continua existindo e
 * incluindo este arquivo, entao todo require antigo segue valendo.
 */

/**
 * Busca evento publicado pelo slug (null se não existe ou é rascunho)
 */
function buscar_evento_por_slug(string $slug): ?array {
    $ev = db_fetch_one("SELECT * FROM eventos WHERE slug = ? AND status != 'rascunho'", [$slug]);
    return $ev ?: null;
}

function buscar_inscricao(int $pessoa_id, int $evento_id): ?array {
    $i = db_fetch_one("SELECT * FROM inscricoes WHERE pessoa_id = ? AND evento_id = ?", [$pessoa_id, $evento_id]);
    return $i ?: null;
}

/**
 * Ano de referência da adimplência para um evento (decisão 4 do plano):
 * se a campanha do ano do evento está aberta, vale o ano do evento; senão, o anterior.
 */
/**
 * Inscritos de um evento, com filtro e busca.
 *
 * Vive aqui, e nao num controller, porque tres lugares dependem de ela ser a
 * MESMA: a tela do admin, o CSV que vai para a organizacao e o painel de
 * leitura da propria organizacao. Se divergirem, alguem cobra a pessoa errada.
 */

/**
 * Consulta UNICA da tela do admin, dos dois CSV e do painel da organizacao.
 * Se divergirem, alguem cobra a pessoa errada.
 *
 * ATENCAO: ela traz `p.cpf` e `i.valor`. **Nao e ela que protege o painel da
 * organizacao** — quem omite CPF e valores sao a view
 * (`Eventos/Views/organizacao_inscritos.php`) e a lista de colunas de
 * `exportarPainel()`. Quem escrever tela nova do painel a partir daqui recebe
 * os dois campos sem perceber, achando que a consulta ja filtrou.
 */
function inscritos_do_evento(int $evento_id, string $filtro = '', string $busca = ''): array {
    $where = ['i.evento_id = ?'];
    $args = [$evento_id];

    if ($filtro === 'confirmadas') {
        $where[] = "i.status IN ('pago','gratuita_confirmada')";
    } elseif ($filtro === 'pendentes') {
        $where[] = "i.status = 'pendente'";
    } elseif ($filtro === 'sem_resposta') {
        $where[] = "i.status IN ('enviado','acesso')";
    } elseif ($filtro === 'comprovante') {
        // Quem escolheu categoria que exige comprovante de matricula.
        $where[] = "c.requer_comprovante = 1";
    }

    if ($busca !== '') {
        $termo = '%' . strtolower($busca) . '%';
        $condicoes = ['LOWER(p.nome) LIKE ?', 'LOWER(e.email) LIKE ?'];
        $args[] = $termo;
        $args[] = $termo;

        // So procura por CPF se houver digito na busca: sem isso, buscar
        // "clarice" virava LIKE '%%' no CPF e trazia a lista inteira.
        $digitos = preg_replace('/\D/', '', $busca);
        if ($digitos !== '') {
            $condicoes[] = "REPLACE(REPLACE(IFNULL(p.cpf,''),'.',''),'-','') LIKE ?";
            $args[] = '%' . $digitos . '%';
        }
        $where[] = '(' . implode(' OR ', $condicoes) . ')';
    }

    return db_fetch_all("
        SELECT i.*, p.nome, p.cpf, p.documento, p.documento_tipo, p.id AS pessoa_id,
               e.email,
               c.nome AS categoria_nome, c.requer_comprovante
        FROM inscricoes i
        JOIN pessoas p ON p.id = i.pessoa_id
        LEFT JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
        LEFT JOIN evento_categorias c ON c.id = i.categoria_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
            CASE i.status
                WHEN 'pago' THEN 1 WHEN 'gratuita_confirmada' THEN 2
                WHEN 'pendente' THEN 3 ELSE 4
            END,
            p.nome
    ", $args);
}
