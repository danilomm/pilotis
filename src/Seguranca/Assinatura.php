<?php
/**
 * Pilotis — HMAC sobre a SECRET_KEY. Nada guardado no banco.
 *
 * Duas familias: assinar_match() ata a oferta de fusao a pessoa da vez;
 * assinar_consolidacao() ata a CONFIRMACAO, com prazo, e e ela que exige
 * posse do email antigo. Comparacao sempre por hash_equals.
 *
 * Extraido de src/db.php em 29/08/2026. O db.php continua existindo e
 * incluindo este arquivo, entao todo require antigo segue valendo.
 */

/**
 * Assina o match que o servidor ofereceu, atado a pessoa da vez.
 *
 * POR QUE: salvar() calcula o match certo e redireciona com ?match=ID, mas a
 * tela de destino nao tem os dados do formulario para recalcular — e ate
 * 28/08/2026 aceitava o ID cru. Trocar o numero no GET imprimia nome, email
 * mascarado e ultimo ano pago de QUALQUER cadastro; no POST, consolidar_pessoas()
 * gravava o token de quem ataca no cadastro da vitima, sobrescrevia o CPF dela e
 * podia apagar filiacoes. Com a assinatura, so o ID que o servidor ofereceu a
 * ESTA pessoa passa. Nada guardado no banco: HMAC sobre a SECRET_KEY, como no
 * ValidacaoService.
 */
function assinar_match(int $pessoa_id, int $match_id): string {
    return substr(hash_hmac('sha256', "match|$pessoa_id|$match_id", SECRET_KEY), 0, 32);
}

function match_assinado(int $pessoa_id, int $match_id, string $sig): bool {
    return $match_id > 0 && $sig !== '' && hash_equals(assinar_match($pessoa_id, $match_id), $sig);
}

/**
 * Assina o CONVITE de consolidacao, com prazo.
 *
 * POR QUE: assinar_match() prova que o servidor ofereceu aquele match a ESTA
 * pessoa — e nao prova que quem esta clicando e dono do cadastro antigo. O
 * criterio de match inclui email e nome, e os dois sao publicos no meio
 * academico: bastava abrir uma inscricao, digitar o nome ou o email de alguem,
 * receber a oferta de fusao e aceitar. consolidar_pessoas() entao gravava o
 * token de quem clicou no cadastro da vitima, sobrescrevia o CPF dela e podia
 * apagar filiacao.
 *
 * O que falta em toda oferta e PROVA DE POSSE. Por isso o "sim" nao consolida
 * mais: manda um link para o email do cadastro ANTIGO, e a consolidacao
 * acontece quando esse link e aberto. Quem nao le aquela caixa nao passa.
 *
 * O prazo entra na assinatura para o link nao valer para sempre.
 */
function assinar_consolidacao(int $pessoa_id, int $match_id, int $expira): string {
    return substr(hash_hmac('sha256', "consolidacao|$pessoa_id|$match_id|$expira", SECRET_KEY), 0, 32);
}

function consolidacao_assinada(int $pessoa_id, int $match_id, int $expira, string $sig): bool {
    if ($match_id <= 0 || $sig === '' || $expira < time()) return false;
    return hash_equals(assinar_consolidacao($pessoa_id, $match_id, $expira), $sig);
}
