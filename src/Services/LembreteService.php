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
     * Agenda lembretes de formulario incompleto (3 lembretes quinzenais)
     * Chamado quando status muda para 'acesso'
     */
    public static function agendarFormularioIncompleto(int $filiacao_id): void {
        $hoje = date('Y-m-d');
        self::agendar($filiacao_id, 'formulario_incompleto', date('Y-m-d', strtotime($hoje . ' +14 days')));
        self::agendar($filiacao_id, 'formulario_incompleto', date('Y-m-d', strtotime($hoje . ' +28 days')));
        self::agendar($filiacao_id, 'formulario_incompleto', date('Y-m-d', strtotime($hoje . ' +42 days')));
    }

    /**
     * Agenda lembrete de ultima chance para todos com status != pago
     * Chamado quando data_fim da campanha e definida
     */
    public static function agendarUltimaChance(int $ano, string $data_fim): void {
        $data_lembrete = date('Y-m-d', strtotime($data_fim . ' -3 days'));

        if ($data_lembrete < date('Y-m-d')) {
            return; // Data ja passou
        }

        // Busca filiacoes nao pagas do ano
        $filiacoes = db_fetch_all("
            SELECT f.id FROM filiacoes f
            JOIN pessoas p ON p.id = f.pessoa_id
            WHERE f.ano = ?
            AND f.status NOT IN ('pago', 'nao_pago')
            AND p.ativo = 1
        ", [$ano]);

        foreach ($filiacoes as $f) {
            self::agendar($f['id'], 'ultima_chance', $data_lembrete);
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
    public static function processar(int $limite = 50): array {
        require_once SRC_DIR . '/Services/BrevoService.php';

        $pendentes = db_fetch_all("
            SELECT la.*, f.pessoa_id, f.ano, f.valor, f.data_vencimento, f.status as filiacao_status,
                   p.nome, p.token, e.email
            FROM lembretes_agendados la
            JOIN filiacoes f ON f.id = la.filiacao_id
            JOIN pessoas p ON p.id = f.pessoa_id
            LEFT JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
            WHERE la.enviado = 0 AND la.data_agendada <= DATE('now')
            ORDER BY la.data_agendada ASC
            LIMIT ?
        ", [$limite]);

        $resultado = [
            'processados' => 0,
            'enviados' => 0,
            'erros' => 0,
            'pulados' => 0,
            'detalhes' => [],
        ];

        foreach ($pendentes as $lembrete) {
            $resultado['processados']++;

            // Marca ANTES de enviar (idempotente)
            $atualizado = db_execute("
                UPDATE lembretes_agendados
                SET enviado = 1, enviado_at = CURRENT_TIMESTAMP
                WHERE id = ? AND enviado = 0
            ", [$lembrete['id']]);

            if ($atualizado === 0) {
                // Ja foi processado por outro processo
                $resultado['pulados']++;
                continue;
            }

            // Verifica se filiacao ja foi paga (double-check)
            if ($lembrete['filiacao_status'] === 'pago') {
                $resultado['pulados']++;
                $resultado['detalhes'][] = [
                    'id' => $lembrete['id'],
                    'tipo' => $lembrete['tipo'],
                    'status' => 'pulado',
                    'motivo' => 'ja pago',
                ];
                continue;
            }

            // Sem email
            if (empty($lembrete['email'])) {
                // Tenta buscar qualquer email
                $email_row = db_fetch_one("SELECT email FROM emails WHERE pessoa_id = ? LIMIT 1", [$lembrete['pessoa_id']]);
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
                $resultado['enviados']++;
                registrar_log('lembrete_enviado', $lembrete['pessoa_id'],
                    "Lembrete {$lembrete['tipo']} filiacao {$lembrete['ano']}");
                $resultado['detalhes'][] = [
                    'id' => $lembrete['id'],
                    'tipo' => $lembrete['tipo'],
                    'email' => $lembrete['email'],
                    'status' => 'enviado',
                ];
            } else {
                $resultado['erros']++;
                $resultado['detalhes'][] = [
                    'id' => $lembrete['id'],
                    'tipo' => $lembrete['tipo'],
                    'email' => $lembrete['email'],
                    'status' => 'erro',
                ];
            }
        }

        return $resultado;
    }

    /**
     * Envia lembrete baseado no tipo
     */
    private static function enviarPorTipo(array $lembrete, string $token): bool {
        $link_pagamento = BASE_URL . "/filiacao/{$lembrete['ano']}/$token/pagamento";
        $link_formulario = BASE_URL . "/filiacao/{$lembrete['ano']}/$token";

        try {
            switch ($lembrete['tipo']) {
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
                    $dias = $data_fim ? max(0, (int)((strtotime($data_fim) - strtotime('today')) / 86400)) : 3;

                    $template = carregar_template('ultima_chance', [
                        'nome' => $lembrete['nome'],
                        'ano' => $lembrete['ano'],
                        'dias' => (string)$dias,
                        'data_fim' => $data_fim_formatada,
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
     */
    public static function contarPendentes(?int $ano = null): array {
        $where_ano = $ano ? "AND f.ano = ?" : "";
        $params = $ano ? [$ano] : [];

        $result = db_fetch_all("
            SELECT la.tipo, COUNT(*) as total
            FROM lembretes_agendados la
            JOIN filiacoes f ON f.id = la.filiacao_id
            WHERE la.enviado = 0 AND la.data_agendada <= DATE('now')
            $where_ano
            GROUP BY la.tipo
        ", $params);

        $contagem = [
            'vencimento_amanha' => 0,
            'pagamento_vencido' => 0,
            'formulario_incompleto' => 0,
            'ultima_chance' => 0,
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
            JOIN filiacoes f ON f.id = la.filiacao_id
            WHERE la.enviado = 0 AND la.data_agendada > DATE('now')
            $where_ano
        ", $params);
        $contagem['agendados_futuro'] = (int)($futuros['total'] ?? 0);

        return $contagem;
    }
}
