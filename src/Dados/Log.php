<?php
/**
 * Pilotis — Registro de eventos do sistema e leitura do que pede acao.
 *
 * A lista de tipos criticos mora AQUI, num lugar so, porque duas telas a
 * consultam (o contador do painel e a pagina de log) e lista repetida
 * diverge.
 *
 * Extraido de src/db.php em 29/08/2026. O db.php continua existindo e
 * incluindo este arquivo, entao todo require antigo segue valendo.
 */

/**
 * Registra entrada no log
 */
function registrar_log(string $tipo, ?int $pessoa_id = null, string $mensagem = ''): void {
    db_execute(
        "INSERT INTO log (tipo, pessoa_id, mensagem, timestamp) VALUES (?, ?, ?, ?)",
        [$tipo, $pessoa_id, $mensagem, date('Y-m-d H:i:s')]
    );
}

// === Funções de busca ===

/**
 * Tipos de log que significam "alguem precisa olhar isto".
 *
 * Fica AQUI, e nao no controller, porque duas telas consultam a mesma lista (o
 * contador do painel e a pagina de log) e lista repetida diverge — que e
 * exatamente a classe de erro que a revisao de 29/08/2026 encontrou espalhada
 * pelo sistema.
 *
 * Curta de proposito: cada item representa dinheiro, dado pessoal ou aviso
 * perdido. Tipo que aparece todo dia sem exigir acao (csrf_recusado, quase
 * sempre sessao vencida) fica de fora, senao a pessoa aprende a ignorar a
 * lista inteira.
 */
function tipos_log_criticos(): array {
    return [
        'pagamento_orfao'            => 'Dinheiro entrou e o registro nao existe',
        'consolidacao_cobranca_orfa' => 'Cobranca viva numa linha apagada por fusao',
        'valor_divergente'           => 'Valor pago diferente do devido',
        'erro_consolidacao'          => 'Falha ao unificar cadastros',
        'evento_erro_consolidacao'   => 'Falha ao unificar cadastros (evento)',
        'lembrete_desistido'         => 'Lembrete falhou 3 vezes e foi abandonado',
        'ultima_chance_nao_agendada' => 'Aviso de fim de prazo nao pode ser agendado',
        'match_assinatura_invalida'  => 'Tentativa de fusao com assinatura invalida',
        'erro_pagbank'               => 'Falha ao falar com o PagBank',
        'cron_campanha_bloqueado'    => 'Envio automatico barrado por trava',

        // Acrescentados em 30/08/2026. Todos significam que ALGUEM PRECISA
        // FAZER ALGUMA COISA, e nenhum estava na lista — o bloco de pendencias
        // dava a impressao de cobrir, e nao cobria.
        'erro_confirmacao_inscricao' => 'A pessoa PAGOU e o comprovante nao saiu — reenviar pelo admin',
        'erro_email_confirmacao'     => 'A pessoa PAGOU e a declaracao nao saiu — reenviar pelo admin',
        'erro_confirmacao'           => 'Falha ao processar confirmacao de pagamento',
        'erro_comprovante_inscricao' => 'O PDF do comprovante nao foi gerado',
        'erro_registro_lote'         => 'Lote enviado e nao registrado: DESARMA a trava de 24h e a cota do dia seguinte',
        'template_variavel_orfa'     => 'Email saiu com {{variavel}} literal — corrigir em /admin/templates',
        'erro_verificacao_pagamento' => 'Falha ao verificar pagamento no PagBank',
        'evento_convite_falhou'      => 'Convite de evento nao foi enviado',
        'convite_identificadores_divergentes' => 'CPF e email do convite apontam para pessoas diferentes',
        'erro_upload_comprovante'    => 'Comprovante de matricula nao pode ser recebido',
        'admin_login_bloqueado'      => 'Login do admin bloqueado por excesso de tentativas',
        // Nao e erro: e alguem ESPERANDO a tesouraria. Sem CPF nao ha cobranca
        // online (o PagBank exige CPF ou CNPJ), entao a inscricao fica pendente
        // ate alguem combinar o pagamento e lancar. Se ninguem olhar, a pessoa
        // preencheu tudo e some.
        'inscricao_sem_cpf'          => 'Inscricao sem CPF: combinar pagamento e lancar a mao',
        'erro_aviso_sem_cpf'         => 'Inscricao sem CPF registrada e a TESOURARIA NAO FOI AVISADA',
        'cpf_de_terceiro'            => 'CPF informado pertence a outro cadastro e a fusao foi recusada',
        'inscricao_excluida_com_cobranca' => 'Inscricao apagada COM cobranca aberta: conferir o extrato do PagBank',
        'post_grande_demais'         => 'Envio descartado por exceder o limite do PHP — provavelmente foto de comprovante',
        'exclusao_inscricao_confirmada' => 'Pessoa excluida TINHA inscricao paga ou isenta: conferir no extrato',
    ];
}

/**
 * Quantos criticos na janela. Usado pelo contador do painel.
 */
function contar_log_criticos(int $dias = 30): int {
    $tipos = array_keys(tipos_log_criticos());
    $marcadores = implode(',', array_fill(0, count($tipos), '?'));
    $r = db_fetch_one(
        "SELECT COUNT(*) AS n FROM log
         WHERE tipo IN ($marcadores)
         AND timestamp >= datetime('now','localtime','-' || ? || ' days')",
        array_merge($tipos, [$dias]));
    return (int)($r['n'] ?? 0);
}
