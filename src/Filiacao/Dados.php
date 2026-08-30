<?php
/**
 * Pilotis — Consultas sobre filiacoes, pagamentos e campanhas.
 *
 * listar_filiados() nao tem rota: a lista publica foi removida em
 * 29/08/2026. E o jeito de gerar as listas que o site institucional publica
 * deliberadamente.
 *
 * Extraido de src/db.php em 29/08/2026. O db.php continua existindo e
 * incluindo este arquivo, entao todo require antigo segue valendo.
 */

/**
 * Busca filiação por pessoa e ano
 */
function buscar_filiacao(int $pessoa_id, int $ano): ?array {
    return db_fetch_one(
        "SELECT * FROM filiacoes WHERE pessoa_id = ? AND ano = ?",
        [$pessoa_id, $ano]
    );
}

/**
 * Lista filiados pagos de um ano
 */

/**
 * Filiados adimplentes de um ano: nome, categoria, instituicao.
 *
 * NAO ha rota publica para isto desde 29/08/2026 — a pagina /filiados/{ano} foi
 * removida a pedido do tesoureiro. A funcao fica porque e o jeito de gerar a
 * lista que o site institucional publica manualmente, e nao expoe nada por si.
 */
function listar_filiados(int $ano): array {
    return db_fetch_all("
        SELECT p.nome, f.categoria, f.instituicao
        FROM pessoas p
        JOIN filiacoes f ON p.id = f.pessoa_id
        WHERE f.ano = ? AND (f.data_pagamento IS NOT NULL OR f.status = 'pago')
        ORDER BY p.nome
    ", [$ano]);
}

/**
 * Atualiza dados da pessoa e filiação
 */
function atualizar_pessoa_filiacao(
    int $pessoa_id,
    int $ano,
    array $dados
): void {
    // Atualiza pessoa. CPF: normaliza e só grava se não pertencer a outra pessoa
    // (se pertencer, fica NULL aqui e o fluxo oferece vinculação de cadastro).
    $cpf_gravar = $dados['cpf'] ? preg_replace('/\D/', '', $dados['cpf']) : null;
    if ($cpf_gravar && cpf_pertence_a_outra_pessoa($cpf_gravar, $pessoa_id)) {
        $cpf_gravar = null;
    }
    db_execute(
        "UPDATE pessoas SET nome = ?, cpf = ?, updated_at = ? WHERE id = ?",
        [$dados['nome'], $cpf_gravar, date('Y-m-d H:i:s'), $pessoa_id]
    );

    // Verifica se filiação existe
    $filiacao = buscar_filiacao($pessoa_id, $ano);

    // Comprovante (opcional, só atualiza se fornecido)
    $comprovante_sql = '';
    $comprovante_params = [];
    if (isset($dados['comprovante_path']) && $dados['comprovante_path']) {
        $comprovante_sql = ', comprovante_path = ?';
        $comprovante_params = [$dados['comprovante_path']];
    }

    if ($filiacao) {
        // Trocar de categoria muda o VALOR, e a cobranca ja criada continua
        // valendo o valor antigo. Ate 28/08/2026 nada limpava o pagbank_order_id
        // aqui: a tela de pagamento imprimia o valor NOVO e mostrava o QR do
        // VELHO, porque pagamento() so gera cobranca quando o order_id esta
        // vazio. Quem escolhia Estudante, gerava o PIX, voltava pelo link do
        // email e trocava para Internacional pagava R$ 120 e recebia declaracao
        // de R$ 480 — e o painel somava R$ 480. Acontece sem ma-fe, na troca que
        // o proprio formulario oferece.
        //
        // So havia tratamento para categoria EXPIRADA (FiliacaoController), que
        // e outro caminho. Aqui a cobranca velha e invalidada sempre que o valor
        // muda, e nunca quando ja esta pago.
        $limpar_cobranca = '';
        if (($filiacao['status'] ?? '') !== 'pago'
            && (int)($filiacao['valor'] ?? 0) !== (int)$dados['valor']) {
            $limpar_cobranca = ', pagbank_order_id = NULL, pagbank_charge_id = NULL,'
                . ' pagbank_boleto_link = NULL, pagbank_boleto_barcode = NULL,'
                . ' data_vencimento = NULL, metodo = NULL';
            registrar_log('cobranca_invalidada', $pessoa_id,
                "Valor da filiacao $ano mudou de {$filiacao['valor']} para {$dados['valor']}: cobranca anterior descartada");
        }

        // Atualiza filiação existente
        db_execute("
            UPDATE filiacoes SET
                categoria = ?, valor = ?, telefone = ?, endereco = ?,
                cep = ?, cidade = ?, estado = ?, pais = ?,
                profissao = ?, formacao = ?, instituicao = ?
                $comprovante_sql
                $limpar_cobranca
            WHERE pessoa_id = ? AND ano = ?
        ", array_merge([
            $dados['categoria'],
            $dados['valor'],
            $dados['telefone'] ?: null,
            $dados['endereco'] ?: null,
            $dados['cep'] ?: null,
            $dados['cidade'] ?: null,
            $dados['estado'] ?: null,
            $dados['pais'] ?: 'Brasil',
            $dados['profissao'] ?: null,
            $dados['formacao'] ?: null,
            $dados['instituicao'] ?: null,
        ], $comprovante_params, [
            $pessoa_id,
            $ano
        ]));
    } else {
        // Cria nova filiação
        $campos_extra = $comprovante_sql ? ', comprovante_path' : '';
        $valores_extra = $comprovante_sql ? ', ?' : '';
        db_insert("
            INSERT INTO filiacoes (
                pessoa_id, ano, categoria, valor, status,
                telefone, endereco, cep, cidade, estado, pais,
                profissao, formacao, instituicao, status_at, created_at $campos_extra
            ) VALUES (?, ?, ?, ?, 'pendente', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ? $valores_extra)
        ", array_merge([
            $pessoa_id,
            $ano,
            $dados['categoria'],
            $dados['valor'],
            $dados['telefone'] ?: null,
            $dados['endereco'] ?: null,
            $dados['cep'] ?: null,
            $dados['cidade'] ?: null,
            $dados['estado'] ?: null,
            $dados['pais'] ?: 'Brasil',
            $dados['profissao'] ?: null,
            $dados['formacao'] ?: null,
            $dados['instituicao'] ?: null,
            date('Y-m-d H:i:s'),
            date('Y-m-d H:i:s')
        ], $comprovante_params));
    }
}

// === Aliases para compatibilidade ===

function buscar_pagamento(int $pessoa_id, int $ano): ?array {
    return buscar_filiacao($pessoa_id, $ano);
}

/**
 * Procura possivel cadastro antigo da mesma pessoa, para oferecer consolidacao.
 * Ordem de forca: email do formulario -> CPF normalizado -> nome exato com filiacao paga anterior.
 * Exclui a propria pessoa atual (pelo $pessoa_id_atual).
 * Retorna ['pessoa_id' => N, 'motivo' => 'email|cpf|nome', 'nome' => ..., 'email_principal' => ...] ou null.
 */
/**
 * Limite de pedidos de link por hora, contado no proprio log.
 *
 * POR QUE: as telas de entrada mandam email para um endereco que quem preenche
 * o formulario nao precisa controlar — na entrada por CPF, o link vai para o
 * email do CADASTRO. Sem limite, uma lista de CPFs enche a caixa de quem esta
 * na base, e cada acerto ainda revela que aquele CPF consta. De quebra, esgota
 * a cota diaria do Brevo e derruba campanha e lembretes junto.
 *
 * Mesmo desenho do painel da organizacao, que ja fazia isso: contagem no log,
 * sem tabela nova. A chave e o que identifica quem pede — IP ou o proprio
 * documento — e nunca aparece na resposta, para nao denunciar a trava.
 */

/**
 * Retorna texto HTML com prazos da campanha para uso em emails e paginas
 */
function prazo_campanha(int $ano): string {
    $campanha = db_fetch_one("SELECT data_fim, data_fim_internacional FROM campanhas WHERE ano = ?", [$ano]);
    if (!$campanha) return '';

    $prazos = [];
    if (!empty($campanha['data_fim_internacional'])) {
        $prazos[] = 'Internacional até <strong>' . date('d/m/Y', strtotime($campanha['data_fim_internacional'])) . '</strong>';
    }
    if (!empty($campanha['data_fim'])) {
        $prazos[] = 'Nacional e Estudante até <strong>' . date('d/m/Y', strtotime($campanha['data_fim'])) . '</strong>';
    }
    if (empty($prazos)) return '';

    return '<p><strong>Prazos:</strong> ' . implode(' | ', $prazos) . '</p>';
}
