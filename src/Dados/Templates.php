<?php
/**
 * Pilotis — Templates de email e registro de lotes enviados.
 *
 * Extraido de src/db.php em 29/08/2026. O db.php continua existindo e
 * incluindo este arquivo, entao todo require antigo segue valendo.
 */

/**
 * Carrega template de email do banco
 * Substitui variáveis no formato {{variavel}}
 */
function carregar_template(string $tipo, array $vars = []): ?array {
    $tpl = db_fetch_one("SELECT assunto, html FROM email_templates WHERE tipo = ?", [$tipo]);
    if (!$tpl) return null;

    $assunto = $tpl['assunto'];
    $html = $tpl['html'];

    foreach ($vars as $key => $val) {
        $assunto = str_replace('{{' . $key . '}}', $val, $assunto);
        $html = str_replace('{{' . $key . '}}', $val, $html);
    }

    // Placeholder que ninguem substituiu vai LITERAL para o filiado — o
    // template semeado de ultima_chance trazia {{dias}} e nada passava a chave,
    // entao o assunto sairia "encerra em {{dias}} dias" para centenas de
    // pessoas. Como o texto e editavel no admin, isso se repete a cada erro de
    // digitacao. Nao interrompe o envio (um lembrete com um campo torto ainda
    // vale mais que lembrete nenhum), mas deixa rastro para alguem ver.
    $sobraram = [];
    if (preg_match_all('/\{\{([a-z_]+)\}\}/', $assunto . ' ' . $html, $m)) {
        $sobraram = array_unique($m[1]);
    }
    if ($sobraram) {
        registrar_log('template_variavel_orfa', null,
            "Template '$tipo' foi enviado com variavel nao substituida: {{"
            . implode('}}, {{', $sobraram) . "}}. Conferir o texto em /admin/templates.");
    }

    return ['assunto' => $assunto, 'html' => $html];
}

/**
 * Registra um lote de envio de emails
 * Retorna o ID do lote criado
 */
function registrar_envio_lote(string $tipo, int $ano, string $assunto, string $html, array $destinatarios): int {
    $total = count($destinatarios);
    $sucesso = count(array_filter($destinatarios, fn($d) => $d['sucesso']));
    $falha = $total - $sucesso;

    $lote_id = db_insert(
        "INSERT INTO envios_lotes (tipo, ano, assunto_snapshot, html_snapshot, total_enviados, total_sucesso, total_falha) VALUES (?, ?, ?, ?, ?, ?, ?)",
        [$tipo, $ano, $assunto, $html, $total, $sucesso, $falha]
    );

    $stmt = get_db()->prepare("INSERT INTO envios_destinatarios (lote_id, email, nome, sucesso) VALUES (?, ?, ?, ?)");
    foreach ($destinatarios as $d) {
        $stmt->execute([$lote_id, $d['email'], $d['nome'] ?? '', $d['sucesso'] ? 1 : 0]);
    }

    return $lote_id;
}
