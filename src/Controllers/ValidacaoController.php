<?php
/**
 * Pilotis - Pagina publica de validacao de documento
 *
 * Aberta por quem recebeu um comprovante em PDF e quer conferir se e
 * verdadeiro (o QR do documento aponta para ca). Nao exige login: a
 * autenticacao e o proprio codigo assinado.
 *
 * O que mostra: o suficiente para casar o papel com o registro -- nome, CPF
 * mascarado, evento, categoria, valor, data e forma de pagamento -- e a
 * situacao ATUAL. Nao mostra endereco, telefone nem email.
 */

require_once SRC_DIR . '/Services/ValidacaoService.php';

class ValidacaoController {

    public static function validar(string $codigo): void {
        $ref = ValidacaoService::resolver($codigo);

        // Formato errado e assinatura errada dao a mesma resposta: nao ha por
        // que ajudar quem esta tentando adivinhar codigo.
        $doc = $ref ? self::carregar($ref['tipo'], $ref['id']) : null;

        if (!$doc) {
            registrar_log('validacao_negada', null, "Codigo invalido ou inexistente");
        } else {
            registrar_log('validacao_consultada', $doc['pessoa_id'] ?? null,
                "Documento {$codigo} consultado (" . ($doc['valido'] ? 'valido' : 'sem efeito') . ")");
        }

        $codigo_exibicao = strtoupper(trim($codigo));
        $titulo = 'Validação de documento';
        ob_start();
        require SRC_DIR . '/Views/validacao/resultado.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Le a situacao atual no banco. Devolve null quando o registro nao existe
     * (documento de um registro apagado, por exemplo).
     */
    private static function carregar(string $tipo, int $id): ?array {
        return $tipo === 'EVT' ? self::inscricao($id) : self::filiacao($id);
    }

    private static function inscricao(int $id): ?array {
        $i = db_fetch_one("
            SELECT i.id, i.pessoa_id, i.status, i.valor, i.metodo, i.data_pagamento,
                   p.nome, p.cpf,
                   e.nome AS evento_nome, e.data_inicio, e.data_fim,
                   c.nome AS categoria_nome
            FROM inscricoes i
            JOIN pessoas p ON p.id = i.pessoa_id
            JOIN eventos e ON e.id = i.evento_id
            LEFT JOIN evento_categorias c ON c.id = i.categoria_id
            WHERE i.id = ?
        ", [$id]);

        if (!$i) return null;

        $confirmada = in_array($i['status'], ['pago', 'gratuita_confirmada'], true);
        $metodos = ['pix' => 'PIX', 'boleto' => 'Boleto bancário', 'cartao' => 'Cartão de crédito'];

        return [
            'tipo' => 'Comprovante de inscrição',
            'valido' => $confirmada,
            'pessoa_id' => (int)$i['pessoa_id'],
            'nome' => $i['nome'],
            'cpf' => mascarar_cpf($i['cpf']),
            'situacao' => $confirmada
                ? ((int)$i['valor'] === 0 ? 'Inscrição confirmada (isenta de taxa)' : 'Inscrição confirmada, pagamento recebido')
                : 'Inscrição registrada, mas sem pagamento confirmado',
            'linhas' => array_filter([
                'Evento' => $i['evento_nome'],
                'Categoria' => $i['categoria_nome'] ?? '',
                'Valor' => $i['valor'] !== null ? formatar_valor((int)$i['valor']) : '',
                'Pagamento' => $confirmada && $i['data_pagamento']
                    ? date('d/m/Y', strtotime($i['data_pagamento'])) .
                      (isset($metodos[$i['metodo']]) ? ' — ' . $metodos[$i['metodo']] : '')
                    : '',
            ]),
        ];
    }

    private static function filiacao(int $id): ?array {
        $f = db_fetch_one("
            SELECT f.id, f.pessoa_id, f.ano, f.status, f.valor, f.categoria, f.data_pagamento,
                   p.nome, p.cpf
            FROM filiacoes f
            JOIN pessoas p ON p.id = f.pessoa_id
            WHERE f.id = ?
        ", [$id]);

        if (!$f) return null;

        $paga = $f['status'] === 'pago';

        return [
            'tipo' => 'Declaração de filiação',
            'valido' => $paga,
            'pessoa_id' => (int)$f['pessoa_id'],
            'nome' => $f['nome'],
            'cpf' => mascarar_cpf($f['cpf']),
            'situacao' => $paga
                ? "Filiação {$f['ano']} quitada"
                : "Não há filiação quitada em {$f['ano']} para este cadastro",
            'linhas' => array_filter([
                'Ano' => (string)$f['ano'],
                'Categoria' => CATEGORIAS_DISPLAY[$f['categoria']] ?? ($f['categoria'] ?? ''),
                'Valor' => $paga && $f['valor'] ? formatar_valor((int)$f['valor']) : '',
                'Pagamento' => $paga && $f['data_pagamento']
                    ? date('d/m/Y', strtotime($f['data_pagamento'])) : '',
            ]),
        ];
    }
}
