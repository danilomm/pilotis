<?php
/**
 * Pilotis — Unificacao de cadastros duplicados.
 *
 * A fusao so acontece com PROVA DE POSSE do email do cadastro antigo — ver
 * Seguranca/Assinatura.php. O criterio de match inclui nome e email, ambos
 * publicos, e sozinhos nao provam nada.
 *
 * Extraido de src/db.php em 29/08/2026. O db.php continua existindo e
 * incluindo este arquivo, entao todo require antigo segue valendo.
 */

function buscar_match_consolidacao(int $pessoa_id_atual, ?string $email_form, ?string $cpf_form, ?string $nome_form, int $ano_atual): ?array {
    $email_form = $email_form ? strtolower(trim($email_form)) : null;
    $cpf_clean = $cpf_form ? preg_replace('/\D/', '', $cpf_form) : null;
    $nome_clean = $nome_form ? trim(preg_replace('/\s+/', ' ', $nome_form)) : null;

    $excluidos_anos = "pessoa_id != ? AND pessoa_id NOT IN (SELECT pessoa_id FROM filiacoes WHERE ano = ? AND pessoa_id != ?)";

    // 1) Email do formulario bate com email de outra pessoa
    if ($email_form) {
        $row = db_fetch_one("
            SELECT e.pessoa_id, p.nome,
                   (SELECT email FROM emails WHERE pessoa_id=p.id AND principal=1 LIMIT 1) AS email_principal
            FROM emails e
            JOIN pessoas p ON p.id = e.pessoa_id
            WHERE LOWER(TRIM(e.email)) = ?
            AND e.pessoa_id != ?
            AND p.ativo = 1
            AND TRIM(IFNULL(p.nome,'')) != ''
            LIMIT 1
        ", [$email_form, $pessoa_id_atual]);
        if ($row) {
            return ['pessoa_id' => (int)$row['pessoa_id'], 'motivo' => 'email', 'nome' => $row['nome'], 'email_principal' => $row['email_principal']];
        }
    }

    // 2) CPF normalizado
    if ($cpf_clean && strlen($cpf_clean) >= 11) {
        $row = db_fetch_one("
            SELECT p.id as pessoa_id, p.nome,
                   (SELECT email FROM emails WHERE pessoa_id=p.id AND principal=1 LIMIT 1) AS email_principal
            FROM pessoas p
            WHERE REPLACE(REPLACE(REPLACE(REPLACE(IFNULL(p.cpf,''),'.',''),'-',''),'/',''),' ','') = ?
            AND p.id != ?
            AND p.ativo = 1
            AND TRIM(IFNULL(p.nome,'')) != ''
            LIMIT 1
        ", [$cpf_clean, $pessoa_id_atual]);
        if ($row) {
            return ['pessoa_id' => (int)$row['pessoa_id'], 'motivo' => 'cpf', 'nome' => $row['nome'], 'email_principal' => $row['email_principal']];
        }
    }

    // 3) Nome exato (case-insensitive, normalizado) com pelo menos uma filiacao paga anterior
    if ($nome_clean && strlen($nome_clean) >= 6) {
        $row = db_fetch_one("
            SELECT p.id as pessoa_id, p.nome,
                   (SELECT email FROM emails WHERE pessoa_id=p.id AND principal=1 LIMIT 1) AS email_principal
            FROM pessoas p
            WHERE LOWER(TRIM(p.nome)) = LOWER(?)
            AND p.id != ?
            AND p.ativo = 1
            AND EXISTS (SELECT 1 FROM filiacoes WHERE pessoa_id = p.id AND status = 'pago' AND ano < ?)
            LIMIT 1
        ", [$nome_clean, $pessoa_id_atual, $ano_atual]);
        if ($row) {
            return ['pessoa_id' => (int)$row['pessoa_id'], 'motivo' => 'nome', 'nome' => $row['nome'], 'email_principal' => $row['email_principal']];
        }
    }

    return null;
}

/**
 * Mascara um email para exibicao parcial: i****@u***.br
 */

/**
 * Consolida pessoa_atual em pessoa_antiga, preservando dados recem-preenchidos.
 * Move emails, filiacoes, comprovantes e o token (assume token do atual fica no antigo).
 * Apaga pessoa_atual ao final.
 */
function consolidar_pessoas(int $id_antigo, int $id_atual, int $ano_atual): void {
    if ($id_antigo === $id_atual) return;

    // Tudo em UMA transacao. Sao ~15 escritas em cinco tabelas terminando num
    // DELETE FROM pessoas, e quem dispara e um clique do publico: falha no meio
    // (UNIQUE de email, disco cheio, timeout do SQLite) deixava emails movidos,
    // filiacoes penduradas e as duas pessoas vivas — estado que ninguem sabe
    // desfazer depois, porque nao ha registro do que ja tinha sido movido.
    $db = get_db();
    $ja_em_transacao = $db->inTransaction();
    if (!$ja_em_transacao) $db->beginTransaction();

    try {
        consolidar_pessoas_interno($id_antigo, $id_atual, $ano_atual);
        if (!$ja_em_transacao) $db->commit();
    } catch (Throwable $e) {
        if (!$ja_em_transacao && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function consolidar_pessoas_interno(int $id_antigo, int $id_atual, int $ano_atual): void {
    $pAntigo = db_fetch_one("SELECT * FROM pessoas WHERE id=?", [$id_antigo]);
    $pAtual  = db_fetch_one("SELECT * FROM pessoas WHERE id=?", [$id_atual]);
    if (!$pAntigo || !$pAtual) {
        throw new RuntimeException("consolidar_pessoas: pessoa nao encontrada");
    }

    $rank = function($s) { return $s === 'pago' ? 3 : ($s === 'pendente' ? 2 : ($s === 'nao_pago' ? 1 : 0)); };

    /**
     * Filiacao perdedora do merge.
     *
     * POR QUE registra: o reference_id da cobranca carrega pessoa_id e ano.
     * Apagar a linha nao cancela o QR nem o boleto, que seguem pagaveis. A
     * pessoa gera o PIX, volta e vincula o cadastro, a linha some — e quando
     * ela paga, o webhook chega com uma referencia que nao existe mais. E o
     * caso da Maisa (R$ 460 orfaos por 15 meses, no CLAUDE.md), so que
     * produzido pelo proprio sistema.
     *
     * POR QUE nao preserva a linha: filiacoes tem UNIQUE(pessoa_id, ano), e a
     * vencedora ja ocupa aquele ano no cadastro canonico. Nao ha para onde
     * mover. O que resta e deixar rastro achavel, com o order_id por extenso —
     * que e como se reconcilia contra o extrato do PagBank. O webhook tambem
     * passa a gritar quando nao acha o registro (ver pagamento_orfao).
     */
    $descartar_filiacao = function(array $f) use ($id_antigo) {
        $cobranca = db_fetch_one(
            "SELECT pagbank_order_id FROM filiacoes WHERE id=? AND pagbank_order_id IS NOT NULL AND pagbank_order_id!=''",
            [$f['id']]
        );
        $pedido = db_fetch_one(
            "SELECT order_id FROM pagbank_pedidos WHERE filiacao_id=? ORDER BY id DESC LIMIT 1",
            [$f['id']]
        );
        $order = $cobranca['pagbank_order_id'] ?? ($pedido['order_id'] ?? '');
        if ($order !== '') {
            registrar_log('consolidacao_cobranca_orfa', $id_antigo,
                "Filiacao {$f['id']} ({$f['ano']}) apagada na consolidacao COM cobranca aberta"
                . " order_id=$order — se esse pagamento entrar, o webhook nao vai achar a filiacao."
                . " Conferir no extrato do PagBank.");
        }
        db_execute("DELETE FROM lembretes_agendados WHERE filiacao_id=?", [$f['id']]);
        db_execute("DELETE FROM filiacoes WHERE id=?", [$f['id']]);
    };

    $filA = db_fetch_all("SELECT id, ano, status FROM filiacoes WHERE pessoa_id=?", [$id_antigo]);
    $filN = db_fetch_all("SELECT id, ano, status FROM filiacoes WHERE pessoa_id=?", [$id_atual]);
    $byA = []; foreach ($filA as $f) $byA[$f['ano']] = $f;
    $byN = []; foreach ($filN as $f) $byN[$f['ano']] = $f;
    $anos = array_unique(array_merge(array_keys($byA), array_keys($byN)));

    foreach ($anos as $ano) {
        $fa = $byA[$ano] ?? null;
        $fn = $byN[$ano] ?? null;
        if ($fa && $fn) {
            if ($rank($fn['status']) >= $rank($fa['status'])) {
                // Vence o atual: descarta a antiga, move a atual
                $descartar_filiacao($fa);
                db_execute("UPDATE filiacoes SET pessoa_id=? WHERE id=?", [$id_antigo, $fn['id']]);
            } else {
                $descartar_filiacao($fn);
            }
        } elseif ($fn) {
            db_execute("UPDATE filiacoes SET pessoa_id=? WHERE id=?", [$id_antigo, $fn['id']]);
        }
    }

    // Move emails (atual vira secundario)
    foreach (db_fetch_all("SELECT id, email FROM emails WHERE pessoa_id=?", [$id_atual]) as $e) {
        $dup = db_fetch_one("SELECT id FROM emails WHERE pessoa_id=? AND LOWER(email)=LOWER(?)", [$id_antigo, $e['email']]);
        if ($dup) {
            db_execute("DELETE FROM emails WHERE id=?", [$e['id']]);
        } else {
            db_execute("UPDATE emails SET pessoa_id=?, principal=0 WHERE id=?", [$id_antigo, $e['id']]);
        }
    }

    // CPF: prefere o atual (foi preenchido agora).
    // Limpa o CPF do atual ANTES de gravar no antigo (índice único de CPF).
    if (!empty($pAtual['cpf'])) {
        $cpf_clean = preg_replace('/\D/', '', $pAtual['cpf']);
        db_execute("UPDATE pessoas SET cpf=NULL WHERE id=?", [$id_atual]);
        db_execute("UPDATE pessoas SET cpf=? WHERE id=?", [$cpf_clean, $id_antigo]);
    }

    // Token: o token do atual passa a ser o token da pessoa antiga (URL continua valida).
    // Libera o token do atual ANTES de gravar no antigo (UNIQUE em pessoas.token —
    // era a causa do erro que travou a consolidacao da Ana Valeria em 2026-07-02).
    if (!empty($pAtual['token'])) {
        db_execute("UPDATE pessoas SET token=NULL WHERE id=?", [$id_atual]);
        db_execute("UPDATE pessoas SET token=? WHERE id=?", [$pAtual['token'], $id_antigo]);
    }

    // Nome: se o antigo nao tinha nome, herda do atual
    if (empty(trim($pAntigo['nome'] ?? ''))) {
        db_execute("UPDATE pessoas SET nome=? WHERE id=?", [$pAtual['nome'], $id_antigo]);
    }

    // Move inscricoes de eventos: se ambos tem inscricao no mesmo evento, vence a de status mais avancado
    $rankI = function($s) {
        return in_array($s, ['pago', 'gratuita_confirmada']) ? 3
            : ($s === 'pendente' ? 2 : (in_array($s, ['enviado', 'acesso']) ? 1 : 0));
    };
    /**
     * Inscricao perdedora do merge.
     *
     * Aqui NAO da para preservar a linha como se faz com filiacao: inscricoes
     * tem UNIQUE(pessoa_id, evento_id), e mover a perdedora para o cadastro
     * canonico colidiria com a vencedora. Entao registra alto.
     *
     * O rastro nao some por completo: PRAGMA foreign_keys nunca e ligado nesta
     * conexao, entao o ON DELETE CASCADE do schema e decorativo e a linha de
     * pagbank_pedidos sobrevive. So que o cron junta com INNER JOIN e nao a
     * enxerga — por isso o log precisa trazer o order_id por extenso, que e o
     * que permite achar o pagamento no extrato depois.
     */
    $descartar_inscricao = function(array $i) use ($id_antigo) {
        $pedido = db_fetch_one(
            "SELECT order_id FROM pagbank_pedidos WHERE inscricao_id=? ORDER BY id DESC LIMIT 1",
            [$i['id']]
        );
        $cobranca = db_fetch_one(
            "SELECT pagbank_order_id FROM inscricoes WHERE id=? AND pagbank_order_id IS NOT NULL AND pagbank_order_id!=''",
            [$i['id']]
        );
        $order = $cobranca['pagbank_order_id'] ?? ($pedido['order_id'] ?? '');
        if ($order !== '') {
            registrar_log('consolidacao_cobranca_orfa', $id_antigo,
                "Inscricao {$i['id']} (evento {$i['evento_id']}) apagada na consolidacao COM cobranca aberta"
                . " order_id=$order — se esse pagamento entrar, o webhook nao vai achar a inscricao."
                . " Conferir no extrato do PagBank.");
        }
        db_execute("DELETE FROM lembretes_agendados WHERE inscricao_id=?", [$i['id']]);
        db_execute("DELETE FROM inscricoes WHERE id=?", [$i['id']]);
    };

    // O arquivo do comprovante de matricula acompanha a inscricao.
    //
    // O nome dele e derivado — `evt{evento}_{pessoa}.{ext}` — e nao lido da
    // coluna. Mudar a inscricao de dono sem renomear o arquivo desliga os dois
    // lados de uma vez: a leitura procura pelo id NOVO e nao acha, e a exclusao
    // apaga pelo id novo, que nao existe, deixando o arquivo do id velho orfao
    // na pasta para sempre. Quem paga por isso e o estudante, cuja inscricao
    // passa a parecer sem comprovante depois de uma fusao que ele nao viu
    // acontecer.
    $mover_comprovante = function (int $evento_id) use ($id_atual, $id_antigo) {
        foreach (['pdf', 'jpg', 'png'] as $ext) {
            $de = COMPROVANTES_DIR . "/evt{$evento_id}_{$id_atual}.{$ext}";
            if (!is_file($de)) continue;
            $para = COMPROVANTES_DIR . "/evt{$evento_id}_{$id_antigo}.{$ext}";
            // Comprovante ja existente no cadastro que FICA tem precedencia:
            // e o do dono do registro que sobrevive.
            if (is_file($para)) { @unlink($de); continue; }
            if (!@rename($de, $para)) {
                registrar_log('erro_consolidacao', $id_antigo,
                    "Nao foi possivel mover o comprovante evt{$evento_id}_{$id_atual}.{$ext}");
            }
        }
    };

    $insA = db_fetch_all("SELECT id, evento_id, status FROM inscricoes WHERE pessoa_id=?", [$id_antigo]);
    $insN = db_fetch_all("SELECT id, evento_id, status FROM inscricoes WHERE pessoa_id=?", [$id_atual]);
    $byEvA = []; foreach ($insA as $i) $byEvA[$i['evento_id']] = $i;
    foreach ($insN as $i) {
        $ia = $byEvA[$i['evento_id']] ?? null;
        if ($ia) {
            if ($rankI($i['status']) >= $rankI($ia['status'])) {
                $descartar_inscricao($ia);
                db_execute("UPDATE inscricoes SET pessoa_id=? WHERE id=?", [$id_antigo, $i['id']]);
                $mover_comprovante((int)$i['evento_id']);
            } else {
                $descartar_inscricao($i);
            }
        } else {
            db_execute("UPDATE inscricoes SET pessoa_id=? WHERE id=?", [$id_antigo, $i['id']]);
            $mover_comprovante((int)$i['evento_id']);
        }
    }

    // Deleta pessoa atual
    db_execute("DELETE FROM pessoas WHERE id=?", [$id_atual]);
}
