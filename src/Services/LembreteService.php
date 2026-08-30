<?php
/**
 * Pilotis - Servico de lembretes agendados
 *
 * Agenda, cancela e processa lembretes individuais.
 * Cada lembrete e um registro na tabela lembretes_agendados com flag de envio.
 * Rodar o processamento N vezes produz o mesmo resultado (idempotente).
 */

class LembreteService {

    /**
     * Agenda lembrete de vencimento (1 dia antes)
     * Chamado apos gerar PIX ou Boleto
     */
    public static function agendarVencimento(int $filiacao_id, string $data_vencimento): void {
        // Agenda para 1 dia antes do vencimento
        $data_lembrete = date('Y-m-d', strtotime($data_vencimento . ' -1 day'));

        // So agenda se a data ainda nao passou
        if ($data_lembrete >= date('Y-m-d')) {
            self::agendar($filiacao_id, 'vencimento_amanha', $data_lembrete);
        }
    }

    /**
     * Agenda lembretes de formulario incompleto (3 lembretes semanais)
     * Chamado quando status muda para 'acesso'
     */
    public static function agendarFormularioIncompleto(int $filiacao_id): void {
        $hoje = date('Y-m-d');
        self::agendar($filiacao_id, 'formulario_incompleto', date('Y-m-d', strtotime($hoje . ' +7 days')));
        self::agendar($filiacao_id, 'formulario_incompleto', date('Y-m-d', strtotime($hoje . ' +14 days')));
        self::agendar($filiacao_id, 'formulario_incompleto', date('Y-m-d', strtotime($hoje . ' +21 days')));
    }

    /**
     * Agenda lembrete de vencimento de uma INSCRICAO em evento (1 dia antes).
     * Chamado depois de gerar PIX ou boleto da inscricao.
     */
    public static function agendarVencimentoInscricao(int $inscricao_id, string $data_vencimento): void {
        $data_lembrete = date('Y-m-d', strtotime($data_vencimento . ' -1 day'));

        if ($data_lembrete >= date('Y-m-d')) {
            self::agendarInscricao($inscricao_id, 'evento_vencimento_amanha', $data_lembrete);
        }
    }

    /**
     * Agenda lembretes de inscricao comecada e nao concluida.
     *
     * Dois, e nao tres como na filiacao: evento tem prazo curto, e lembrete
     * que chega depois do prazo so irrita. Ambos ficam presos ao prazo de
     * inscricao — nada e agendado para depois dele.
     */
    public static function agendarInscricaoIncompleta(int $inscricao_id, ?string $prazo_inscricao = null): void {
        $hoje = date('Y-m-d');

        foreach ([7, 14] as $dias) {
            $data = date('Y-m-d', strtotime("$hoje +$dias days"));
            if ($prazo_inscricao && $data >= $prazo_inscricao) continue;
            self::agendarInscricao($inscricao_id, 'evento_incompleta', $data);
        }
    }

    /**
     * Agenda um lembrete de inscricao. Mesma protecao contra duplicata do
     * `agendar()`, mas na coluna inscricao_id.
     */
    public static function agendarInscricao(int $inscricao_id, string $tipo, string $data_agendada): void {
        $existente = db_fetch_one("
            SELECT id FROM lembretes_agendados
            WHERE inscricao_id = ? AND tipo = ? AND data_agendada = ? AND enviado = 0
        ", [$inscricao_id, $tipo, $data_agendada]);

        if ($existente) return;

        db_insert("
            INSERT INTO lembretes_agendados (inscricao_id, tipo, data_agendada)
            VALUES (?, ?, ?)
        ", [$inscricao_id, $tipo, $data_agendada]);
    }

    /**
     * Agenda lembrete de ultima chance para todos com status != pago
     * Chamado quando data_fim da campanha e definida
     * $tipo_lembrete: 'ultima_chance' ou 'ultima_chance_internacional'
     */
    public static function agendarUltimaChance(int $ano, string $data_fim, string $tipo_lembrete = 'ultima_chance'): void {
        // D-3, e nao D-7: a prorrogacao curta e o caso comum (a repescagem de
        // dirigentes de 2026 esticou o prazo em poucos dias) e com -7 o lembrete
        // simplesmente nao era agendado. A documentacao ja dizia D-3.
        $data_lembrete = date('Y-m-d', strtotime($data_fim . ' -3 days'));

        if ($data_lembrete < date('Y-m-d')) {
            // Nao da mais para avisar com antecedencia. Registra, porque antes
            // isto era um return mudo: prorrogar a campanha por menos dias que a
            // antecedencia do lembrete o desligava sem ninguem saber.
            registrar_log('ultima_chance_nao_agendada', null,
                "Campanha $ano: data_fim $data_fim deixa o lembrete $tipo_lembrete em $data_lembrete, ja passado");
            return;
        }

        // Cancela lembretes pendentes do mesmo tipo (caso a data tenha mudado)
        db_execute("
            UPDATE lembretes_agendados SET enviado = 2
            WHERE tipo = ? AND enviado = 0
            AND filiacao_id IN (SELECT id FROM filiacoes WHERE ano = ?)
        ", [$tipo_lembrete, $ano]);

        // Busca filiacoes nao pagas do ano (todos, independente da categoria)
        $filiacoes = db_fetch_all("
            SELECT f.id FROM filiacoes f
            JOIN pessoas p ON p.id = f.pessoa_id
            WHERE f.ano = ?
            AND f.status NOT IN ('pago', 'nao_pago')
            AND p.ativo = 1
        ", [$ano]);

        foreach ($filiacoes as $f) {
            self::agendar($f['id'], $tipo_lembrete, $data_lembrete);
        }
    }

    /**
     * Agenda um lembrete individual
     * Evita duplicatas: nao cria se ja existe lembrete pendente do mesmo tipo/data/filiacao
     */
    public static function agendar(int $filiacao_id, string $tipo, string $data_agendada): void {
        // Verifica se ja existe lembrete pendente com mesmos parametros
        $existente = db_fetch_one("
            SELECT id FROM lembretes_agendados
            WHERE filiacao_id = ? AND tipo = ? AND data_agendada = ? AND enviado = 0
        ", [$filiacao_id, $tipo, $data_agendada]);

        if ($existente) {
            return; // Ja agendado
        }

        db_insert("
            INSERT INTO lembretes_agendados (filiacao_id, tipo, data_agendada)
            VALUES (?, ?, ?)
        ", [$filiacao_id, $tipo, $data_agendada]);
    }

    /**
     * Cancela todos os lembretes pendentes de uma filiacao
     * Chamado quando pagamento e confirmado
     */
    public static function cancelar(int $filiacao_id): void {
        db_execute("
            UPDATE lembretes_agendados
            SET enviado = 2
            WHERE filiacao_id = ? AND enviado = 0
        ", [$filiacao_id]);
    }

    /**
     * Cancela todos os lembretes pendentes de uma inscricao em evento
     * Chamado quando o pagamento da inscricao e confirmado
     */
    public static function cancelarInscricao(int $inscricao_id): void {
        db_execute("
            UPDATE lembretes_agendados
            SET enviado = 2
            WHERE inscricao_id = ? AND enviado = 0
        ", [$inscricao_id]);
    }

    /**
     * Cancela todos os lembretes pendentes de um ano
     * Chamado quando campanha e fechada
     */
    public static function cancelarPorAno(int $ano): void {
        db_execute("
            UPDATE lembretes_agendados
            SET enviado = 2
            WHERE enviado = 0
            AND filiacao_id IN (
                SELECT id FROM filiacoes WHERE ano = ?
            )
        ", [$ano]);
    }

    /**
     * Processa lembretes pendentes cuja data ja chegou
     * Retorna array com resultado do processamento
     */
    public static function processar(int $limite = 50, ?string $tipo_filtro = null): array {
        require_once SRC_DIR . '/Services/BrevoService.php';

        $where_tipo = '';
        if ($tipo_filtro === 'ultima_chance') {
            $where_tipo = "AND la.tipo LIKE 'ultima_chance%'";
        } elseif ($tipo_filtro === 'outros') {
            $where_tipo = "AND la.tipo NOT LIKE 'ultima_chance%'";
        }

        // LEFT JOIN nos dois lados: o lembrete e de uma filiacao OU de uma
        // inscricao em evento. Com INNER JOIN em filiacoes — como era antes —
        // todo lembrete de inscricao sumia da fila em silencio, porque o
        // filiacao_id dele e NULL.
        $pendentes = db_fetch_all("
            SELECT la.*,
                   COALESCE(f.pessoa_id, i.pessoa_id) AS pessoa_id,
                   f.ano, f.valor, f.categoria, f.data_vencimento, f.status AS filiacao_status,
                   i.status AS inscricao_status, i.valor AS inscricao_valor,
                   ev.nome AS evento_nome, ev.slug AS evento_slug, ev.prazo_inscricao,
                   p.nome, p.token, e.email
            FROM lembretes_agendados la
            LEFT JOIN filiacoes f ON f.id = la.filiacao_id
            LEFT JOIN inscricoes i ON i.id = la.inscricao_id
            LEFT JOIN eventos ev ON ev.id = i.evento_id
            JOIN pessoas p ON p.id = COALESCE(f.pessoa_id, i.pessoa_id)
            LEFT JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
            WHERE la.enviado = 0 AND la.data_agendada <= DATE('now','localtime')
            $where_tipo
            ORDER BY la.data_agendada ASC
            LIMIT ?
        ", [$limite]);

        $resultado = [
            'processados' => 0,
            'enviados' => 0,
            'erros' => 0,
            'pulados' => 0,
            'interrompido' => false,
            'detalhes' => [],
        ];

        // Falhas consecutivas nesta execucao. Distingue canal fora do ar (cota
        // do Brevo) de lembrete individualmente ruim — ver o bloco de erro
        // adiante.
        $falhas_seguidas = 0;

        foreach ($pendentes as $lembrete) {
            $resultado['processados']++;

            // Marca ANTES de enviar (idempotente)
            $atualizado = db_execute("
                UPDATE lembretes_agendados
                SET enviado = 1, enviado_at = datetime('now','localtime')
                WHERE id = ? AND enviado = 0
            ", [$lembrete['id']]);

            if ($atualizado === 0) {
                // Ja foi processado por outro processo
                $resultado['pulados']++;
                continue;
            }

            // Ja resolvido? Vale para filiacao e para inscricao.
            $resolvido = $lembrete['filiacao_status'] === 'pago'
                || in_array($lembrete['inscricao_status'] ?? '', ['pago', 'gratuita_confirmada'], true);

            if ($resolvido) {
                $resultado['pulados']++;
                $resultado['detalhes'][] = [
                    'id' => $lembrete['id'],
                    'tipo' => $lembrete['tipo'],
                    'status' => 'pulado',
                    'motivo' => 'ja resolvido',
                ];
                continue;
            }

            // Prazo do evento vencido: cobrar inscricao que nao da mais para
            // fazer so irrita quem recebe.
            if (!empty($lembrete['inscricao_id']) && !empty($lembrete['prazo_inscricao'])
                && $lembrete['prazo_inscricao'] < date('Y-m-d')) {
                $resultado['pulados']++;
                $resultado['detalhes'][] = [
                    'id' => $lembrete['id'],
                    'tipo' => $lembrete['tipo'],
                    'status' => 'pulado',
                    'motivo' => 'prazo do evento vencido',
                ];
                continue;
            }

            // Sem email
            if (empty($lembrete['email'])) {
                // Tenta buscar qualquer email
                $email_row = db_fetch_one("SELECT email FROM emails WHERE pessoa_id = ? ORDER BY principal DESC, id DESC LIMIT 1", [$lembrete['pessoa_id']]);
                $lembrete['email'] = $email_row['email'] ?? null;
            }

            if (empty($lembrete['email'])) {
                $resultado['pulados']++;
                $resultado['detalhes'][] = [
                    'id' => $lembrete['id'],
                    'tipo' => $lembrete['tipo'],
                    'status' => 'pulado',
                    'motivo' => 'sem email',
                ];
                continue;
            }

            // Gera token se nao tiver
            $token = $lembrete['token'];
            if (!$token) {
                $token = gerar_token();
                db_execute("UPDATE pessoas SET token = ? WHERE id = ?", [$token, $lembrete['pessoa_id']]);
            }

            // Envia baseado no tipo
            $enviado = self::enviarPorTipo($lembrete, $token);

            if ($enviado) {
                $falhas_seguidas = 0;
                $resultado['enviados']++;
                registrar_log('lembrete_enviado', $lembrete['pessoa_id'],
                    empty($lembrete['inscricao_id'])
                        ? "Lembrete {$lembrete['tipo']} filiacao {$lembrete['ano']}"
                        : "Lembrete {$lembrete['tipo']} inscricao no evento {$lembrete['evento_slug']}");
                $resultado['detalhes'][] = [
                    'id' => $lembrete['id'],
                    'tipo' => $lembrete['tipo'],
                    'email' => $lembrete['email'],
                    'status' => 'enviado',
                ];
            } else {
                // Marcar ANTES de enviar da a idempotencia, mas deixava o
                // lembrete morto quando o envio falhava: nenhum caminho do
                // codigo devolvia `enviado` a 0. Passada a cota diaria do
                // Brevo, os lembretes daquele lote sumiam em silencio, sem
                // ninguem receber o aviso de prazo e sem como reprocessar.
                //
                // Volta para a fila, com teto: 3 tentativas. Sem o teto, um
                // lembrete que falha sempre (email invalido, template quebrado)
                // seria retentado em toda execucao, para sempre.
                // FALHA GLOBAL x FALHA DESTE LEMBRETE.
                //
                // O teto de 3 existe para o caso PERMANENTE — email invalido,
                // template quebrado. Mas a falha mais comum e global e
                // transitoria: cota do Brevo estourada, que reprova o lote
                // inteiro. Contando tentativa nesse caso, tres execucoes em
                // dias de cota cheia queimavam a fila toda em
                // `lembrete_desistido` — e a fila e o aviso de vencimento de
                // PIX e boleto.
                //
                // Heuristica: falhas consecutivas nesta mesma execucao indicam
                // problema do canal, nao dos destinatarios. A partir do
                // terceiro seguido, para a execucao SEM contar tentativa: a
                // fila fica intacta para a proxima rodada.
                $falhas_seguidas++;
                if ($falhas_seguidas >= 3) {
                    registrar_log('lembrete_canal_indisponivel', null,
                        "Tres falhas seguidas de envio: interrompendo a execucao sem gastar tentativa. "
                        . "Causa provavel: cota do Brevo ou chave invalida. A fila fica intacta.");
                    $resultado['erros']++;
                    $resultado['interrompido'] = true;
                    break;
                }

                $tentativas = (int)($lembrete['tentativas'] ?? 0) + 1;
                if ($tentativas < 3) {
                    db_execute("
                        UPDATE lembretes_agendados
                        SET enviado = 0, enviado_at = NULL, tentativas = ?
                        WHERE id = ?
                    ", [$tentativas, $lembrete['id']]);
                    registrar_log('lembrete_retorna_fila', $lembrete['pessoa_id'],
                        "Lembrete {$lembrete['id']} ({$lembrete['tipo']}) falhou na tentativa $tentativas: volta para a fila");
                } else {
                    db_execute("UPDATE lembretes_agendados SET tentativas = ? WHERE id = ?",
                        [$tentativas, $lembrete['id']]);
                    registrar_log('lembrete_desistido', $lembrete['pessoa_id'],
                        "Lembrete {$lembrete['id']} ({$lembrete['tipo']}) falhou $tentativas vezes: desistindo. Conferir manualmente.");
                }

                $resultado['erros']++;
                $resultado['detalhes'][] = [
                    'id' => $lembrete['id'],
                    'tipo' => $lembrete['tipo'],
                    'email' => $lembrete['email'],
                    'status' => 'erro',
                    'tentativa' => $tentativas,
                ];
            }
        }

        return $resultado;
    }

    /**
     * Dias inteiros de hoje ate a data, minimo 0.
     */
    private static function diasAte(?string $data): int {
        if (!$data) {
            return 0;
        }
        $alvo = strtotime($data);
        if ($alvo === false) {
            return 0;
        }
        $dias = (int)floor(($alvo - strtotime(date('Y-m-d'))) / 86400);
        return max(0, $dias);
    }

    /**
     * Envia lembrete baseado no tipo
     */
    private static function enviarPorTipo(array $lembrete, string $token): bool {
        $link_pagamento = BASE_URL . "/filiacao/{$lembrete['ano']}/$token/pagamento";
        $link_formulario = BASE_URL . "/filiacao/{$lembrete['ano']}/$token";

        $slug = $lembrete['evento_slug'] ?? '';
        $link_evento_pagamento = BASE_URL . "/eventos/$slug/$token/pagamento";
        $link_evento_formulario = BASE_URL . "/eventos/$slug/$token";

        try {
            switch ($lembrete['tipo']) {
                case 'evento_vencimento_amanha':
                    $template = carregar_template('evento_lembrete_vencimento', [
                        'nome' => $lembrete['nome'],
                        'evento' => $lembrete['evento_nome'] ?? '',
                        'valor' => formatar_valor((int)($lembrete['inscricao_valor'] ?? 0)),
                        'link' => $link_evento_pagamento,
                    ]);
                    break;

                case 'evento_incompleta':
                    $prazo = !empty($lembrete['prazo_inscricao'])
                        ? date('d/m/Y', strtotime($lembrete['prazo_inscricao'])) : '';
                    $template = carregar_template('evento_lembrete_incompleta', [
                        'nome' => $lembrete['nome'],
                        'evento' => $lembrete['evento_nome'] ?? '',
                        'prazo' => $prazo,
                        'link' => $link_evento_formulario,
                    ]);
                    break;

                case 'vencimento_amanha':
                    $template = carregar_template('lembrete', [
                        'nome' => $lembrete['nome'],
                        'ano' => $lembrete['ano'],
                        'valor' => formatar_valor((int)$lembrete['valor']),
                        'link' => $link_pagamento,
                        'urgencia' => '',
                        'dias_info' => 'Seu pagamento vence amanha.',
                    ]);
                    break;

                case 'pagamento_vencido':
                    $template = carregar_template('lembrete_vencido', [
                        'nome' => $lembrete['nome'],
                        'ano' => $lembrete['ano'],
                        'valor' => formatar_valor((int)$lembrete['valor']),
                        'link' => $link_pagamento,
                    ]);
                    break;

                case 'formulario_incompleto':
                    $template = carregar_template('lembrete_acesso', [
                        'nome' => $lembrete['nome'],
                        'ano' => $lembrete['ano'],
                        'link' => $link_formulario,
                    ]);
                    break;

                case 'ultima_chance':
                    // Busca data_fim da campanha
                    $campanha = db_fetch_one("SELECT data_fim FROM campanhas WHERE ano = ?", [$lembrete['ano']]);
                    $data_fim = $campanha['data_fim'] ?? '';
                    $data_fim_formatada = $data_fim ? date('d/m/Y', strtotime($data_fim)) : '';

                    $template = carregar_template('ultima_chance', [
                        'nome' => $lembrete['nome'],
                        'ano' => $lembrete['ano'],
                        // {{dias}} estava nos templates semeados e ninguem
                        // passava a chave: o assunto saia com "encerra em
                        // {{dias}} dias", literal, para centenas de pessoas.
                        'dias' => self::diasAte($data_fim),
                        'data_fim' => $data_fim_formatada,
                        'link' => $link_formulario,
                    ]);
                    break;

                case 'ultima_chance_internacional':
                    // Busca data_fim_internacional da campanha
                    $campanha = db_fetch_one("SELECT data_fim_internacional FROM campanhas WHERE ano = ?", [$lembrete['ano']]);
                    $data_fim_int = $campanha['data_fim_internacional'] ?? '';
                    $data_fim_int_formatada = $data_fim_int ? date('d/m/Y', strtotime($data_fim_int)) : '';

                    $template = carregar_template('ultima_chance_internacional', [
                        'nome' => $lembrete['nome'],
                        'ano' => $lembrete['ano'],
                        'dias' => self::diasAte($data_fim_int),
                        'data_fim' => $data_fim_int_formatada,
                        'link' => $link_formulario,
                    ]);
                    break;

                default:
                    return false;
            }

            if (!$template) return false;

            return BrevoService::enviarEmail(
                $lembrete['email'],
                $template['assunto'],
                $template['html']
            );
        } catch (Exception $e) {
            registrar_log('erro_lembrete', $lembrete['pessoa_id'],
                "Erro ao enviar lembrete {$lembrete['tipo']}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Conta lembretes pendentes (para exibicao no admin)
     *
     * LEFT JOIN nos dois lados, como em processar(): lembrete de inscricao tem
     * filiacao_id NULL, e o INNER JOIN em filiacoes que estava aqui ate
     * 28/08/2026 escondia TODOS eles. Nao era so cosmetico — o
     * scripts/processar_lembretes.php sai com exit(0) quando o total e zero,
     * ANTES de chamar processar(). Com a campanha fechada e um evento aberto, o
     * script anunciava "nenhum lembrete pendente" e nao mandava nenhum lembrete
     * de evento; o painel do admin mostrava o mesmo zero.
     *
     * O filtro por $ano continua excluindo evento de proposito: ano e conceito
     * de campanha de filiacao, e com LEFT JOIN o f.ano NULL ja nao casa.
     */
    public static function contarPendentes(?int $ano = null): array {
        $where_ano = $ano ? "AND f.ano = ?" : "";
        $params = $ano ? [$ano] : [];

        $result = db_fetch_all("
            SELECT la.tipo, COUNT(*) as total
            FROM lembretes_agendados la
            LEFT JOIN filiacoes f ON f.id = la.filiacao_id
            LEFT JOIN inscricoes i ON i.id = la.inscricao_id
            WHERE la.enviado = 0 AND la.data_agendada <= DATE('now','localtime')
            AND COALESCE(f.id, i.id) IS NOT NULL
            $where_ano
            GROUP BY la.tipo
        ", $params);

        $contagem = [
            'vencimento_amanha' => 0,
            'pagamento_vencido' => 0,
            'formulario_incompleto' => 0,
            'ultima_chance' => 0,
            'ultima_chance_internacional' => 0,
            'evento_vencimento_amanha' => 0,
            'evento_incompleta' => 0,
            'total' => 0,
        ];

        foreach ($result as $row) {
            $contagem[$row['tipo']] = (int)$row['total'];
            $contagem['total'] += (int)$row['total'];
        }

        // Tambem conta agendados para o futuro
        $futuros = db_fetch_one("
            SELECT COUNT(*) as total
            FROM lembretes_agendados la
            LEFT JOIN filiacoes f ON f.id = la.filiacao_id
            LEFT JOIN inscricoes i ON i.id = la.inscricao_id
            WHERE la.enviado = 0 AND la.data_agendada > DATE('now','localtime')
            AND COALESCE(f.id, i.id) IS NOT NULL
            $where_ano
        ", $params);
        $contagem['agendados_futuro'] = (int)($futuros['total'] ?? 0);

        // Conta ja enviados por tipo
        $enviados = db_fetch_all("
            SELECT la.tipo, COUNT(*) as total
            FROM lembretes_agendados la
            LEFT JOIN filiacoes f ON f.id = la.filiacao_id
            LEFT JOIN inscricoes i ON i.id = la.inscricao_id
            WHERE la.enviado = 1
            AND COALESCE(f.id, i.id) IS NOT NULL
            $where_ano
            GROUP BY la.tipo
        ", $params);

        $contagem['enviados'] = [];
        $contagem['total_enviados'] = 0;
        foreach ($enviados as $row) {
            $contagem['enviados'][$row['tipo']] = (int)$row['total'];
            $contagem['total_enviados'] += (int)$row['total'];
        }

        return $contagem;
    }
}
