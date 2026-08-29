<?php
/**
 * Pilotis — Consultas sobre pessoas, emails e CPF.
 *
 * buscar_pessoa_por_cpf() NAO assume CPF unico: o indice unico parcial e um
 * backstop que pode nao existir. Desempata por filiacoes pagas, depois
 * emails, depois id mais antigo.
 *
 * Extraido de src/db.php em 29/08/2026. O db.php continua existindo e
 * incluindo este arquivo, entao todo require antigo segue valendo.
 */

/**
 * Busca pessoa por email
 * Dados cadastrais são buscados da última filiação que tenha dados preenchidos
 */
function buscar_pessoa_por_email(string $email): ?array {
    $email = strtolower(trim($email));

    // Busca na tabela emails
    $result = db_fetch_one("
        SELECT p.*, e.email
        FROM pessoas p
        JOIN emails e ON e.pessoa_id = p.id
        WHERE LOWER(e.email) = ?
    ", [$email]);

    if ($result) {
        // Busca última filiação COM dados cadastrais preenchidos
        // (evita herdar de registros vazios criados pelo envio de campanha)
        $filiacao = db_fetch_one("
            SELECT telefone, endereco, cep, cidade, estado, pais,
                   profissao, formacao, instituicao, categoria
            FROM filiacoes
            WHERE pessoa_id = ?
            AND (telefone IS NOT NULL OR endereco IS NOT NULL OR cidade IS NOT NULL
                 OR profissao IS NOT NULL OR instituicao IS NOT NULL)
            ORDER BY ano DESC
            LIMIT 1
        ", [$result['id']]);

        if ($filiacao) {
            $result = array_merge($result, $filiacao);
        }
    }

    return $result;
}

/**
 * Busca pessoa por token
 * Dados cadastrais são buscados da última filiação que tenha dados preenchidos
 */
function buscar_pessoa_por_token(string $token): ?array {
    $result = db_fetch_one("
        SELECT p.*, e.email
        FROM pessoas p
        LEFT JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
        WHERE p.token = ?
    ", [$token]);

    if ($result) {
        // Se não tem email principal, pega qualquer um
        if (!$result['email']) {
            $email = db_fetch_one("SELECT email FROM emails WHERE pessoa_id = ? ORDER BY principal DESC, id DESC LIMIT 1", [$result['id']]);
            $result['email'] = $email['email'] ?? '';
        }

        // Busca última filiação COM dados cadastrais preenchidos
        // (evita herdar de registros vazios criados pelo envio de campanha)
        $filiacao = db_fetch_one("
            SELECT telefone, endereco, cep, cidade, estado, pais,
                   profissao, formacao, instituicao, categoria
            FROM filiacoes
            WHERE pessoa_id = ?
            AND (telefone IS NOT NULL OR endereco IS NOT NULL OR cidade IS NOT NULL
                 OR profissao IS NOT NULL OR instituicao IS NOT NULL)
            ORDER BY ano DESC
            LIMIT 1
        ", [$result['id']]);

        if ($filiacao) {
            $result = array_merge($result, $filiacao);
        }
    }

    return $result;
}

/**
 * Busca pessoa pelo CPF (comparando so digitos, como o indice unico grava).
 * Traz o email principal junto — e por ele que o link de acesso e enviado.
 */
function buscar_pessoa_por_cpf(string $cpf): ?array {
    $cpf_limpo = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf_limpo) !== 11) return null;

    // O indice unico de CPF e um backstop que pode NAO existir: se a base ja
    // tinha duplicatas quando a migracao rodou, ele nao e criado (o db.php
    // registra isso no error_log). Entao aqui nao da para assumir unicidade —
    // e um LIMIT 1 sem criterio devolveria um cadastro qualquer.
    // Ordem de preferencia: quem tem filiacao paga, depois quem tem email,
    // depois o cadastro mais antigo (normalmente o canonico).
    return db_fetch_one("
        SELECT p.*, (
            SELECT email FROM emails
            WHERE pessoa_id = p.id ORDER BY principal DESC, id ASC LIMIT 1
        ) AS email
        FROM pessoas p
        WHERE REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(p.cpf,''),'.',''),'-',''),'/',''),' ','') = ?
          AND p.ativo = 1
        ORDER BY
            (SELECT COUNT(*) FROM filiacoes f WHERE f.pessoa_id = p.id AND f.status = 'pago') DESC,
            (SELECT COUNT(*) FROM emails e WHERE e.pessoa_id = p.id) DESC,
            p.id ASC
        LIMIT 1
    ", [$cpf_limpo]);
}

/**
 * Cria nova pessoa com email
 */
function criar_pessoa(string $email, string $nome = ''): int {
    $email = strtolower(trim($email));
    $token = gerar_token();

    // Cria pessoa
    $pessoa_id = db_insert(
        "INSERT INTO pessoas (nome, token, created_at) VALUES (?, ?, ?)",
        [$nome, $token, date('Y-m-d H:i:s')]
    );

    // Cria email principal
    db_insert(
        "INSERT INTO emails (pessoa_id, email, principal) VALUES (?, ?, 1)",
        [$pessoa_id, $email]
    );

    return $pessoa_id;
}

function buscar_cadastrado_por_email(string $email): ?array {
    return buscar_pessoa_por_email($email);
}

function buscar_cadastrado_por_token(string $token): ?array {
    return buscar_pessoa_por_token($token);
}

/**
 * O CPF (normalizado) já pertence a OUTRA pessoa?
 * Usado como pré-checagem antes de gravar CPF — se sim, não grava e
 * o fluxo oferece vinculação de cadastro.
 */
function cpf_pertence_a_outra_pessoa(?string $cpf, int $pessoa_id): ?array {
    $cpf_clean = $cpf ? preg_replace('/\D/', '', $cpf) : '';
    if (strlen($cpf_clean) !== 11) return null;
    $row = db_fetch_one(
        "SELECT id, nome FROM pessoas WHERE cpf = ? AND id != ?",
        [$cpf_clean, $pessoa_id]
    );
    return $row ?: null;
}

// === Helpers do módulo de Eventos ===
