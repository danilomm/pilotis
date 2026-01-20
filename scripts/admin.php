#!/usr/bin/env php
<?php
/**
 * CLI de administração do Pilotis
 *
 * Uso:
 *   php scripts/admin.php pendentes           # Lista pagamentos pendentes
 *   php scripts/admin.php buscar "termo"      # Busca pessoa por nome/email
 *   php scripts/admin.php pagar ID            # Marca pagamento como pago
 *   php scripts/admin.php novo                # Cadastra + pagamento manual
 *   php scripts/admin.php exportar ANO        # Exporta filiados CSV
 *   php scripts/admin.php stats [ANO]         # Estatísticas do ano
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';

// Funções auxiliares
function mostrar_ajuda() {
    echo <<<HELP
Pilotis - CLI de Administração

Comandos:
  pendentes              Lista pagamentos pendentes
  buscar "termo"         Busca pessoa por nome ou email
  pagar ID               Marca filiação como paga
  novo                   Cadastra nova pessoa + pagamento
  exportar ANO           Exporta filiados do ano em CSV
  stats [ANO]            Mostra estatísticas do ano

Exemplos:
  php scripts/admin.php pendentes
  php scripts/admin.php buscar "maria"
  php scripts/admin.php pagar 123
  php scripts/admin.php exportar 2026
  php scripts/admin.php stats 2026

HELP;
}

// Obtém comando
$comando = $argv[1] ?? null;
$argumento = $argv[2] ?? null;

if (!$comando || $comando === 'help' || $comando === '--help') {
    mostrar_ajuda();
    exit(0);
}

switch ($comando) {
    case 'pendentes':
        // Lista filiações pendentes
        $pendentes = db_fetch_all("
            SELECT f.id, f.ano, f.valor, f.created_at, p.nome, e.email
            FROM filiacoes f
            JOIN pessoas p ON p.id = f.pessoa_id
            LEFT JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
            WHERE f.status = 'pendente'
            ORDER BY f.ano DESC, f.created_at DESC
        ");

        if (empty($pendentes)) {
            echo "Nenhum pagamento pendente.\n";
        } else {
            echo sprintf("%-5s %-4s %-10s %-30s %s\n", "ID", "Ano", "Valor", "Nome", "Email");
            echo str_repeat('-', 90) . "\n";
            foreach ($pendentes as $p) {
                echo sprintf("%-5d %-4d %-10s %-30s %s\n",
                    $p['id'],
                    $p['ano'],
                    formatar_valor((int)$p['valor']),
                    substr($p['nome'], 0, 30),
                    $p['email'] ?? ''
                );
            }
            echo "\nTotal: " . count($pendentes) . " pendente(s)\n";
        }
        break;

    case 'buscar':
        if (!$argumento) {
            echo "Uso: php scripts/admin.php buscar \"termo\"\n";
            exit(1);
        }

        $resultados = db_fetch_all("
            SELECT DISTINCT p.id, p.nome, e.email, p.token
            FROM pessoas p
            LEFT JOIN emails e ON e.pessoa_id = p.id
            WHERE e.email LIKE ? OR p.nome LIKE ?
            ORDER BY p.nome
            LIMIT 20
        ", ["%$argumento%", "%$argumento%"]);

        if (empty($resultados)) {
            echo "Nenhum resultado para \"$argumento\".\n";
        } else {
            foreach ($resultados as $r) {
                echo sprintf("[%d] %s <%s>\n", $r['id'], $r['nome'] ?? '(sem nome)', $r['email'] ?? '');

                // Busca última filiação
                $filiacao = db_fetch_one("
                    SELECT categoria, ano FROM filiacoes
                    WHERE pessoa_id = ?
                    ORDER BY ano DESC LIMIT 1
                ", [$r['id']]);

                if ($filiacao) {
                    echo "    Categoria: " . (CATEGORIAS_DISPLAY[$filiacao['categoria'] ?? ''] ?? $filiacao['categoria'] ?? '-') . "\n";
                    echo "    Último ano: " . $filiacao['ano'] . "\n";
                }
                echo "    Token: " . ($r['token'] ?? '-') . "\n";
                echo "\n";
            }
        }
        break;

    case 'pagar':
        if (!$argumento || !is_numeric($argumento)) {
            echo "Uso: php scripts/admin.php pagar ID\n";
            exit(1);
        }

        $filiacao_id = (int)$argumento;
        $fil = db_fetch_one("
            SELECT f.*, p.nome, e.email
            FROM filiacoes f
            JOIN pessoas p ON p.id = f.pessoa_id
            LEFT JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
            WHERE f.id = ?
        ", [$filiacao_id]);

        if (!$fil) {
            echo "Filiação #$filiacao_id não encontrada.\n";
            exit(1);
        }

        if ($fil['status'] === 'pago') {
            echo "Filiação #$filiacao_id já está paga.\n";
            exit(0);
        }

        echo "Filiação #$filiacao_id\n";
        echo "  Nome: {$fil['nome']}\n";
        echo "  Email: " . ($fil['email'] ?? '-') . "\n";
        echo "  Ano: {$fil['ano']}\n";
        echo "  Valor: " . formatar_valor((int)$fil['valor']) . "\n";
        echo "\nMarcar como pago? (s/N): ";

        $resposta = trim(fgets(STDIN));
        if (strtolower($resposta) !== 's') {
            echo "Cancelado.\n";
            exit(0);
        }

        db_execute("
            UPDATE filiacoes
            SET status = 'pago', metodo = COALESCE(metodo, 'manual'), data_pagamento = ?
            WHERE id = ?
        ", [date('Y-m-d H:i:s'), $filiacao_id]);

        registrar_log('pagamento_manual', $fil['pessoa_id'], "Filiação #$filiacao_id marcada via CLI");
        echo "Filiação #$filiacao_id marcada como paga.\n";
        break;

    case 'novo':
        echo "=== Novo Cadastro + Filiação ===\n\n";

        echo "Nome: ";
        $nome = trim(fgets(STDIN));

        echo "Email: ";
        $email = strtolower(trim(fgets(STDIN)));

        echo "Categoria (1=Estudante, 2=Nacional, 3=Internacional): ";
        $cat_num = trim(fgets(STDIN));
        $categorias = ['1' => 'estudante', '2' => 'profissional_nacional', '3' => 'profissional_internacional'];
        $categoria = $categorias[$cat_num] ?? 'profissional_internacional';

        echo "Ano [" . date('Y') . "]: ";
        $ano_input = trim(fgets(STDIN));
        $ano = $ano_input ?: date('Y');

        // Verifica se já existe
        $existente = buscar_pessoa_por_email($email);

        if ($existente) {
            $pessoa_id = $existente['id'];
            db_execute("UPDATE pessoas SET nome = ? WHERE id = ?", [$nome, $pessoa_id]);
            echo "Cadastro existente atualizado (ID: $pessoa_id).\n";
        } else {
            $pessoa_id = criar_pessoa($email, $nome);
            echo "Novo cadastro criado (ID: $pessoa_id).\n";
        }

        // Verifica filiação
        $fil_existe = buscar_filiacao($pessoa_id, (int)$ano);

        $valor = valor_por_categoria($categoria);

        if ($fil_existe) {
            db_execute("UPDATE filiacoes SET status = 'pago', metodo = 'manual', categoria = ?, valor = ?, data_pagamento = ? WHERE id = ?",
                [$categoria, $valor, date('Y-m-d H:i:s'), $fil_existe['id']]);
        } else {
            db_insert("
                INSERT INTO filiacoes (pessoa_id, ano, categoria, valor, status, metodo, data_pagamento, created_at)
                VALUES (?, ?, ?, ?, 'pago', 'manual', ?, ?)
            ", [$pessoa_id, $ano, $categoria, $valor, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        }

        registrar_log('cadastro_manual', $pessoa_id, "Cadastro via CLI para $ano");
        echo "Filiação $ano registrada como paga (" . formatar_valor($valor) . ").\n";
        break;

    case 'exportar':
        $ano = $argumento ?: date('Y');

        $filiados = db_fetch_all("
            SELECT p.nome, e.email, p.cpf, f.telefone, f.categoria,
                   f.endereco, f.cep, f.cidade, f.estado, f.pais,
                   f.profissao, f.instituicao,
                   f.valor, f.metodo, f.status, f.data_pagamento
            FROM pessoas p
            JOIN filiacoes f ON f.pessoa_id = p.id
            LEFT JOIN emails e ON e.pessoa_id = p.id AND e.principal = 1
            WHERE f.ano = ?
            ORDER BY f.status DESC, p.nome
        ", [$ano]);

        $filename = "filiados_{$ano}.csv";
        $fp = fopen($filename, 'w');

        // BOM para Excel
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

        // Cabeçalho
        fputcsv($fp, ['Nome', 'Email', 'CPF', 'Telefone', 'Categoria',
            'Endereço', 'CEP', 'Cidade', 'Estado', 'País',
            'Profissão', 'Instituição', 'Valor', 'Método', 'Status', 'Data Pagamento'], ';');

        foreach ($filiados as $f) {
            fputcsv($fp, [
                $f['nome'],
                $f['email'] ?? '',
                $f['cpf'] ?? '',
                $f['telefone'] ?? '',
                CATEGORIAS_DISPLAY[$f['categoria'] ?? ''] ?? $f['categoria'] ?? '',
                $f['endereco'] ?? '',
                $f['cep'] ?? '',
                $f['cidade'] ?? '',
                $f['estado'] ?? '',
                $f['pais'] ?? '',
                $f['profissao'] ?? '',
                $f['instituicao'] ?? '',
                $f['valor'] ? formatar_valor((int)$f['valor']) : '',
                $f['metodo'] ?? '',
                $f['status'] ?? '',
                $f['data_pagamento'] ?? ''
            ], ';');
        }

        fclose($fp);
        echo "Exportado: $filename (" . count($filiados) . " registros)\n";
        break;

    case 'stats':
        $ano = $argumento ?: date('Y');

        $stats = db_fetch_one("
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pago' THEN 1 ELSE 0 END) as pagos,
                SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) as pendentes,
                SUM(CASE WHEN status = 'pago' THEN valor ELSE 0 END) as arrecadado
            FROM filiacoes WHERE ano = ?
        ", [$ano]);

        echo "=== Estatísticas $ano ===\n";
        echo "Total de registros: " . ($stats['total'] ?? 0) . "\n";
        echo "Pagos: " . ($stats['pagos'] ?? 0) . "\n";
        echo "Pendentes: " . ($stats['pendentes'] ?? 0) . "\n";
        echo "Arrecadado: " . formatar_valor((int)($stats['arrecadado'] ?? 0)) . "\n";
        break;

    default:
        echo "Comando desconhecido: $comando\n";
        mostrar_ajuda();
        exit(1);
}
