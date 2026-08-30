<?php
/**
 * Pilotis — Regras de inscricao em evento: preco, categoria, adimplencia, acesso.
 *
 * Tres eixos independentes na categoria (verifica_adimplencia,
 * independe_filiacao, requer_comprovante) mais a lista de liberados. Nada
 * aqui toca o banco diretamente alem do necessario: sao decisoes, nao
 * consultas.
 *
 * Extraido de src/db.php em 29/08/2026. O db.php continua existindo e
 * incluindo este arquivo, entao todo require antigo segue valendo.
 */

/**
 * Inscrições abertas? (publicado e dentro do prazo)
 */
function evento_inscricoes_abertas(array $evento): bool {
    if ($evento['status'] !== 'publicado') return false;
    if (!empty($evento['prazo_inscricao']) && $evento['prazo_inscricao'] < date('Y-m-d')) return false;
    return true;
}

/**
 * Busca inscrição de uma pessoa num evento
 */

/**
 * Valor vigente de uma categoria na data de hoje.
 *
 * Antes de data_valor_cheio (ou se ela nao existir) vale `valor` (reduzido).
 * A partir dela, vale `valor_cheio` — quando preenchido.
 * Gratuitas (valor 0 sem valor_cheio) seguem gratuitas.
 */
function valor_vigente_categoria(array $categoria, array $evento, ?string $data = null): int {
    $hoje = $data ?: date('Y-m-d');
    $cheio = isset($categoria['valor_cheio']) ? (int)$categoria['valor_cheio'] : 0;

    if ($cheio > 0 && !empty($evento['data_valor_cheio']) && $hoje >= $evento['data_valor_cheio']) {
        return $cheio;
    }
    return (int)$categoria['valor'];
}

/**
 * A categoria tem duas faixas de preco configuradas?
 */
function categoria_restrita(array $categoria): bool {
    return trim((string)($categoria['cpfs_liberados'] ?? '')) !== '';
}

/**
 * Lista de uma categoria restrita, separada em CPFs (so digitos) e emails.
 * Aceita como vier: um por linha, virgula, com ou sem pontuacao.
 *
 * Os dois formatos convivem porque servem a gente diferente: CPF para quem ja
 * esta na base do Docomomo, email para o convidado de fora, que muitas vezes
 * so tem email conhecido.
 */
function itens_liberados_categoria(array $categoria): array {
    $bruto = (string)($categoria['cpfs_liberados'] ?? '');

    // UMA LINHA = UMA PESSOA. E o que permite pedir CPF e email do mesmo
    // convidado: com os dois, um cadastro achado pelo CPF recebe o email que
    // faltava, em vez de virar cadastro novo. Separar tudo por espaco perderia
    // esse vinculo.
    $pares = [];
    $cpfs = [];
    $emails = [];

    foreach (preg_split('/\R/', $bruto) ?: [] as $linha) {
        $cpf = null;
        $email = null;
        foreach (preg_split('/[\s,;]+/', trim($linha), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
            if ($email === null && strpos($item, '@') !== false) {
                $e = strtolower(trim($item));
                if (filter_var($e, FILTER_VALIDATE_EMAIL)) $email = $e;
                continue;
            }
            if ($cpf === null) {
                $d = preg_replace('/\D/', '', $item);
                if (strlen($d) === 11) $cpf = $d;
            }
        }
        if ($cpf === null && $email === null) continue;

        $pares[] = ['cpf' => $cpf, 'email' => $email];
        // Nao usar o valor como CHAVE de array: o PHP converte chave numerica
        // em int, e a comparacao estrita depois falha (string vs int).
        if ($cpf !== null) $cpfs[] = $cpf;
        if ($email !== null) $emails[] = $email;
    }

    return [
        'pares' => $pares,
        'cpfs' => array_values(array_unique($cpfs)),
        'emails' => array_values(array_unique($emails)),
    ];
}

/**
 * A pessoa pode se inscrever nesta categoria?
 *
 * Categoria sem lista e aberta. Com lista, so entra quem esta nela.
 *
 * A conferencia e SEMPRE contra o cadastro — o CPF gravado e os emails que
 * pertencem aquela pessoa —, nunca contra o que ela digita no formulario.
 * A diferenca importa: os campos do formulario sao editaveis, entao conferir
 * o que foi digitado deixaria qualquer um reivindicar a isencao alheia. Um
 * email do cadastro, ao contrario, e prova de posse: foi para ele que o link
 * de acesso foi enviado.
 */
function pessoa_liberada_na_categoria(array $categoria, ?array $pessoa): bool {
    if (!categoria_restrita($categoria)) return true;
    if (!$pessoa) return false;

    $lista = itens_liberados_categoria($categoria);

    $d = preg_replace('/\D/', '', (string)($pessoa['cpf'] ?? ''));
    if (strlen($d) === 11 && in_array($d, $lista['cpfs'], true)) return true;

    if (empty($lista['emails'])) return false;

    $emails = db_fetch_all("SELECT LOWER(TRIM(email)) AS email FROM emails WHERE pessoa_id = ?",
                           [(int)($pessoa['id'] ?? 0)]);
    foreach ($emails as $linha) {
        if (in_array($linha['email'], $lista['emails'], true)) return true;
    }
    return false;
}

function categoria_tem_duas_faixas(array $categoria, array $evento): bool {
    return !empty($categoria['valor_cheio'])
        && (int)$categoria['valor_cheio'] > 0
        && !empty($evento['data_valor_cheio']);
}

function evento_ano_referencia(array $evento): int {
    $base = $evento['data_inicio'] ?: ($evento['prazo_inscricao'] ?: date('Y-m-d'));
    $ano = (int)substr($base, 0, 4);

    // Vale o ano do evento sempre que a campanha daquele ano EXISTIR — aberta
    // ou fechada. Fechada quer dizer que o prazo de filiacao acabou, nao que a
    // exigencia caiu: quem nao renovou nao e adimplente, e nao pode pagar
    // preco de filiado no seminario.
    //
    // A versao anterior olhava o status e, com a campanha fechada, caia para o
    // ano anterior — dando desconto a quem tinha pago so no ano passado.
    // Estava invertido: era justamente ao fechar que a regra tinha de apertar.
    //
    // O recuo so faz sentido quando a campanha do ano do evento NAO EXISTE
    // ainda: evento em fevereiro, antes de abrir a filiacao do ano, em que
    // ninguem teve chance de renovar. Ai vale a filiacao do ano anterior.
    $camp = db_fetch_one("SELECT ano FROM campanhas WHERE ano = ?", [$ano]);
    return $camp ? $ano : $ano - 1;
}

/**
 * Verifica adimplência para desconto de filiado (decisões A/B do plano).
 * Busca por CPF no banco INTEIRO; fallback por email. Aceita filiação paga
 * no ano de referência ou no ano do evento.
 * Retorna ['pessoa_id', 'ano'] ou null.
 */
function verificar_adimplencia_evento(?string $cpf, ?string $email, array $evento): ?array {
    $ano_ref = evento_ano_referencia($evento);
    $base = $evento['data_inicio'] ?: ($evento['prazo_inscricao'] ?: date('Y-m-d'));
    $ano_evt = (int)substr($base, 0, 4);
    $anos = array_values(array_unique([$ano_ref, $ano_evt]));
    $in = implode(',', array_fill(0, count($anos), '?'));

    $cpf_clean = $cpf ? preg_replace('/\D/', '', $cpf) : '';
    if (strlen($cpf_clean) === 11) {
        $row = db_fetch_one("
            SELECT p.id as pessoa_id, f.ano
            FROM pessoas p
            JOIN filiacoes f ON f.pessoa_id = p.id AND f.status = 'pago' AND f.ano IN ($in)
            WHERE REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(p.cpf,''),'.',''),'-',''),'/',''),' ','') = ?
            AND p.ativo = 1
            ORDER BY f.ano DESC LIMIT 1
        ", array_merge($anos, [$cpf_clean]));
        if ($row) return $row;
    }

    $email = $email ? strtolower(trim($email)) : '';
    if ($email) {
        $row = db_fetch_one("
            SELECT p.id as pessoa_id, f.ano
            FROM emails e
            JOIN pessoas p ON p.id = e.pessoa_id
            JOIN filiacoes f ON f.pessoa_id = p.id AND f.status = 'pago' AND f.ano IN ($in)
            WHERE LOWER(TRIM(e.email)) = ?
            AND p.ativo = 1
            ORDER BY f.ano DESC LIMIT 1
        ", array_merge($anos, [$email]));
        if ($row) return $row;
    }

    return null;
}

/**
 * Retorna valores únicos para autocomplete de campos do formulário
 * Usa view autocomplete_valores (criada em init_extra_tables)
 */

/**
 * O painel da organizacao esta valendo? Precisa de pelo menos um email
 * autorizado, e para de valer na data de expiracao.
 */
function painel_organizacao_ativo(array $evento): bool {
    if (empty(emails_organizacao($evento))) return false;
    if (!empty($evento['organizacao_expira_em']) && $evento['organizacao_expira_em'] < date('Y-m-d')) return false;
    return true;
}

/** Emails autorizados a ver o painel, em minusculas. */
function emails_organizacao(array $evento): array {
    $itens = preg_split('/[\s,;]+/', (string)($evento['emails_organizacao'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $emails = [];
    foreach ($itens as $item) {
        $e = strtolower(trim($item));
        if (filter_var($e, FILTER_VALIDATE_EMAIL)) $emails[] = $e;
    }
    return array_values(array_unique($emails));
}

function email_autorizado_no_painel(array $evento, string $email): bool {
    return in_array(strtolower(trim($email)), emails_organizacao($evento), true);
}
