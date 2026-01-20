#!/usr/bin/env php
<?php
/**
 * CLI de administracao do Pilotis
 *
 * Uso:
 *   php scripts/admin.php pendentes           # Lista pagamentos pendentes
 *   php scripts/admin.php buscar "termo"      # Busca pessoa por nome/email
 *   php scripts/admin.php pagar ID            # Marca pagamento como pago
 *   php scripts/admin.php novo                # Cadastra + pagamento manual
 *   php scripts/admin.php exportar ANO        # Exporta filiados CSV
 *   php scripts/admin.php stats [ANO]         # Estatisticas do ano
 */

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/../src/db.php';

// Funcoes auxiliares
function mostrar_ajuda() {
    echo <<<HELP
Pilotis - CLI de Administracao

Comandos:
  pendentes              Lista pagamentos pendentes
  buscar "termo"         Busca pessoa por nome ou email
  pagar ID               Marca pagamento como pago
  novo                   Cadastra nova pessoa + pagamento
  exportar ANO           Exporta filiados do ano em CSV
  stats [ANO]            Mostra estatisticas do ano

Exemplos:
  php scripts/admin.php pendentes
  php scripts/admin.php buscar "maria"
  php scripts/admin.php pagar 123
  php scripts/admin.php exportar 2026
  php scripts/admin.php stats 2026

HELP;
}

// Obtem comando
$comando = $argv[1] ?? null;
$argumento = $argv[2] ?? null;

if (!$comando || $comando === 'help' || $comando === '--help') {
    mostrar_ajuda();
    exit(0);
}

switch ($comando) {
    case 'pendentes':
        // Lista pagamentos pendentes
        $pendentes = db_fetch_all("
            SELECT p.id, p.ano, p.valor, p.data_criacao, c.nome, c.email
            FROM pagamentos p
            JOIN cadastrados c ON c.id = p.cadastrado_id
            WHERE p.status = 'pendente'
            ORDER BY p.ano DESC, p.data_criacao DESC
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
                    $p['email']
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
            SELECT c.id, c.nome, c.email, c.categoria, c.token
            FROM cadastrados c
            WHERE c.email LIKE ? OR c.nome LIKE ?
            ORDER BY c.nome
            LIMIT 20
        ", ["%$argumento%", "%$argumento%"]);

        if (empty($resultados)) {
            echo "Nenhum resultado para \"$argumento\".\n";
        } else {
            foreach ($resultados as $r) {
                echo sprintf("[%d] %s <%s>\n", $r['id'], $r['nome'], $r['email']);
                echo "    Categoria: " . (CATEGORIAS_DISPLAY[$r['categoria'] ?? ''] ?? $r['categoria'] ?? '-') . "\n";
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

        $pagamento_id = (int)$argumento;
        $pag = db_fetch_one("
            SELECT p.*, c.nome, c.email
            FROM pagamentos p
            JOIN cadastrados c ON c.id = p.cadastrado_id
            WHERE p.id = ?
        ", [$pagamento_id]);

        if (!$pag) {
            echo "Pagamento #$pagamento_id nao encontrado.\n";
            exit(1);
        }

        if ($pag['status'] === 'pago') {
            echo "Pagamento #$pagamento_id ja esta pago.\n";
            exit(0);
        }

        echo "Pagamento #$pagamento_id\n";
        echo "  Nome: {$pag['nome']}\n";
        echo "  Email: {$pag['email']}\n";
        echo "  Ano: {$pag['ano']}\n";
        echo "  Valor: " . formatar_valor((int)$pag['valor']) . "\n";
        echo "\nMarcar como pago? (s/N): ";

        $resposta = trim(fgets(STDIN));
        if (strtolower($resposta) !== 's') {
            echo "Cancelado.\n";
            exit(0);
        }

        db_execute("
            UPDATE pagamentos
            SET status = 'pago', metodo = COALESCE(metodo, 'manual'), data_pagamento = ?
            WHERE id = ?
        ", [date('Y-m-d H:i:s'), $pagamento_id]);

        registrar_log('pagamento_manual', $pag['cadastrado_id'], "Pagamento #$pagamento_id marcado via CLI");
        echo "Pagamento #$pagamento_id marcado como pago.\n";
        break;

    case 'novo':
        echo "=== Novo Cadastro + Pagamento ===\n\n";

        echo "Nome: ";
        $nome = trim(fgets(STDIN));

        echo "Email: ";
        $email = strtolower(trim(fgets(STDIN)));

        echo "Categoria (1=Estudante, 2=Nacional, 3=Internacional): ";
        $cat_num = trim(fgets(STDIN));
        $categorias = ['1' => 'estudante', '2' => 'profissional_nacional', '3' => 'profissional_internacional'];
        $categoria = $categorias[$cat_num] ?? 'profissional_nacional';

        echo "Ano [" . date('Y') . "]: ";
        $ano_input = trim(fgets(STDIN));
        $ano = $ano_input ?: date('Y');

        // Verifica se ja existe
        $existente = db_fetch_one("SELECT id FROM cadastrados WHERE email = ?", [$email]);

        if ($existente) {
            $cadastrado_id = $existente['id'];
            db_execute("UPDATE cadastrados SET nome = ?, categoria = ? WHERE id = ?", [$nome, $categoria, $cadastrado_id]);
            echo "Cadastro existente atualizado (ID: $cadastrado_id).\n";
        } else {
            $cadastrado_id = db_insert("
                INSERT INTO cadastrados (nome, email, categoria, token)
                VALUES (?, ?, ?, ?)
            ", [$nome, $email, $categoria, gerar_token()]);
            echo "Novo cadastro criado (ID: $cadastrado_id).\n";
        }

        // Verifica pagamento
        $pag_existe = db_fetch_one("SELECT id FROM pagamentos WHERE cadastrado_id = ? AND ano = ?", [$cadastrado_id, $ano]);

        $valor = valor_por_categoria($categoria);

        if ($pag_existe) {
            db_execute("UPDATE pagamentos SET status = 'pago', metodo = 'manual', data_pagamento = ? WHERE id = ?",
                [date('Y-m-d H:i:s'), $pag_existe['id']]);
        } else {
            db_insert("
                INSERT INTO pagamentos (cadastrado_id, ano, valor, status, metodo, data_pagamento)
                VALUES (?, ?, ?, 'pago', 'manual', ?)
            ", [$cadastrado_id, $ano, $valor, date('Y-m-d H:i:s')]);
        }

        registrar_log('cadastro_manual', $cadastrado_id, "Cadastro via CLI para $ano");
        echo "Pagamento $ano registrado como pago (" . formatar_valor($valor) . ").\n";
        break;

    case 'exportar':
        $ano = $argumento ?: date('Y');

        $filiados = db_fetch_all("
            SELECT c.nome, c.email, c.cpf, c.telefone, c.categoria,
                   c.endereco, c.cep, c.cidade, c.estado, c.pais,
                   c.profissao, c.instituicao,
                   p.valor, p.metodo, p.status, p.data_pagamento
            FROM cadastrados c
            JOIN pagamentos p ON p.cadastrado_id = c.id
            WHERE p.ano = ?
            ORDER BY p.status DESC, c.nome
        ", [$ano]);

        $filename = "filiados_{$ano}.csv";
        $fp = fopen($filename, 'w');

        // BOM para Excel
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF));

        // Cabecalho
        fputcsv($fp, ['Nome', 'Email', 'CPF', 'Telefone', 'Categoria',
            'Endereco', 'CEP', 'Cidade', 'Estado', 'Pais',
            'Profissao', 'Instituicao', 'Valor', 'Metodo', 'Status', 'Data Pagamento'], ';');

        foreach ($filiados as $f) {
            fputcsv($fp, [
                $f['nome'],
                $f['email'],
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
            FROM pagamentos WHERE ano = ?
        ", [$ano]);

        echo "=== Estatisticas $ano ===\n";
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
