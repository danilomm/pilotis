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
function carregar_template(string $tipo, array $vars = [], array $vars_html = []): ?array {
    $tpl = db_fetch_one("SELECT assunto, html FROM email_templates WHERE tipo = ?", [$tipo]);
    if (!$tpl) return null;

    $assunto = $tpl['assunto'];
    $html = $tpl['html'];

    // ESCAPA ao entrar no HTML. O valor mais comum aqui e {{nome}}, que vem de
    // $_POST com trim() e mais nada — e o destino nao e so email: o
    // PdfService usa o mesmo template, e o TCPDF **interpreta <img> e carrega o
    // arquivo do disco**. Um nome com uma tag de imagem fazia o comprovante
    // sair com o comprovante de MATRICULA de outra pessoa embutido, e o sistema
    // manda esse PDF por email para quem se inscreveu. Sem credencial nenhuma.
    //
    // A revisao de 29/08 conferiu 693 saidas de view com e() e nao viu isto: o
    // TCPDF e um SEGUNDO renderizador de HTML, e ninguem o contou.
    //
    // O ASSUNTO nao se escapa: e texto puro de cabecalho de email, e escapar
    // faria "Joao & Maria" chegar como "Joao &amp; Maria" na caixa de entrada.
    foreach ($vars as $key => $val) {
        $cru = (string)$val;
        $seguro = htmlspecialchars($cru, ENT_QUOTES, 'UTF-8');
        $assunto = str_replace('{{' . $key . '}}', $cru, $assunto);
        $html    = str_replace('{{' . $key . '}}', $seguro, $html);
    }

    // Valores que o SISTEMA monta como HTML, e que por isso entram crus — hoje
    // so o 'dias_info' do lembrete, que traz um <span> de destaque. Nunca
    // passar dado de formulario por aqui.
    foreach ($vars_html as $key => $val) {
        $cru = (string)$val;
        $assunto = str_replace('{{' . $key . '}}', strip_tags($cru), $assunto);
        $html    = str_replace('{{' . $key . '}}', $cru, $html);
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
