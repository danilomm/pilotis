<?php
/**
 * Endpoint para envio automatico de campanha via GitHub Actions
 *
 * Travas de seguranca:
 * 1. Token obrigatorio
 * 2. Campanha deve estar aberta
 * 3. Limite diario da cota do Brevo, contando TODO email que sai (campanha,
 *    lembretes, links, convites, confirmacoes) e nao so a campanha
 * 4. Intervalo minimo de 24h entre lotes
 *
 * A trava 4 e a resposta ao incidente de 25/01/2026: o cron do provedor rodou
 * 3 vezes e gerou 870 emails para 258 pessoas. Ficou prometida neste cabecalho
 * e sem implementacao ate 28/08/2026.
 */

// O layout local (public/ + src/ irmaos) e o do servidor (src/ dentro de www/)
// diferem; resolver os dois evita que o endpoint quebre em um deles — e permite
// exercitar as travas fora de producao, que e onde nao se pode testa-las.
require_once is_file(__DIR__ . '/src/config.php')
    ? __DIR__ . '/src/config.php'
    : __DIR__ . '/../src/config.php';
require_once SRC_DIR . '/db.php';

header('Content-Type: application/json');

// Verifica token (obrigatorio via .env, sem fallback)
$token_esperado = env("CRON_TOKEN", "");
if (empty($token_esperado)) {
    http_response_code(500);
    echo json_encode(['erro' => 'CRON_TOKEN nao configurado no .env']);
    exit;
}

$token = $_GET['token'] ?? '';
if (!hash_equals($token_esperado, $token)) {
    http_response_code(403);
    echo json_encode(['erro' => 'Acesso negado']);
    exit;
}

$ano = (int)($_GET['ano'] ?? date('Y'));

// 1. Verifica se campanha esta aberta
$campanha = db_fetch_one("SELECT status, data_fim, data_fim_internacional FROM campanhas WHERE ano = ?", [$ano]);
if (!$campanha || $campanha['status'] !== 'aberta') {
    echo json_encode([
        'status' => 'bloqueado',
        'motivo' => 'Campanha nao esta aberta',
        'campanha_status' => $campanha['status'] ?? 'inexistente'
    ]);
    exit;
}

// 1b. Verifica se todas as categorias expiraram
$todas_expiradas = true;
foreach (CATEGORIAS_FILIACAO as $cat_key => $info) {
    if (!categoria_expirada($campanha, $cat_key)) {
        $todas_expiradas = false;
        break;
    }
}
if ($todas_expiradas) {
    echo json_encode([
        'status' => 'bloqueado',
        'motivo' => 'Todos os prazos de filiacao expiraram',
        'data_fim' => $campanha['data_fim'] ?? null,
        'data_fim_internacional' => $campanha['data_fim_internacional'] ?? null
    ]);
    exit;
}

// 2. Verifica se campanha foi iniciada manualmente
// A flag 'campanha_iniciada' e definida pelo botao "Iniciar Campanha" no admin
$iniciada = db_fetch_one("
    SELECT valor FROM configuracoes
    WHERE chave = ?
", ["campanha_iniciada_{$ano}"]);

if (!$iniciada || $iniciada['valor'] !== '1') {
    echo json_encode([
        'status' => 'bloqueado',
        'motivo' => 'Aguardando primeiro envio manual (botao Iniciar Campanha)'
    ]);
    exit;
}

// 3. Verifica limite diario (290 emails por dia UTC, conforme cota Brevo)
// Brevo reseta a meia-noite UTC (21h BRT). Converte created_at local para UTC para comparar.
// Campanha
$enviados_campanha = (int)(db_fetch_one("
    SELECT COALESCE(SUM(ed.sucesso), 0) as total
    FROM envios_destinatarios ed
    JOIN envios_lotes el ON el.id = ed.lote_id
    WHERE DATE(el.created_at, '+3 hours') = DATE('now')
    AND ed.sucesso = 1
")['total'] ?? 0);

// Todo o resto que tambem gasta a MESMA cota do Brevo e nao passava por
// envios_destinatarios: lembretes, links de acesso, convites de evento,
// confirmacoes de pagamento. Contar so a campanha permitia 290 aqui + 50 de
// links + 280 de lembretes no mesmo dia, contra um teto de 300.
//
// Os cinco ultimos tipos entraram em 30/08/2026: o conserto de 28/08 — contar
// TODO email que sai pelo Brevo — foi feito ANTES de o modulo de eventos
// existir, e a lista nao acompanhou. Na semana de abertura das inscricoes,
// confirmacao de inscricao e justamente o volume novo.
$enviados_outros = (int)(db_fetch_one("
    SELECT COUNT(*) as total FROM log
    WHERE DATE(timestamp, '+3 hours') = DATE('now')
    AND tipo IN ('lembrete_enviado', 'link_acesso_enviado', 'evento_link_enviado',
                 'email_confirmacao_enviado', 'evento_convite_enviado',
                 'painel_organizacao_link',
                 'email_confirmacao_inscricao', 'evento_confirmacao_enviada',
                 'evento_confirmacao_reenviada', 'consolidacao_confirmacao_enviada',
                 'evento_consolidacao_confirmacao_enviada')
")['total'] ?? 0);

$enviados_hoje_utc = $enviados_campanha + $enviados_outros;

if ($enviados_hoje_utc >= 290) {
    echo json_encode([
        'status' => 'bloqueado',
        'motivo' => 'Limite diario atingido (290 emails)',
        'enviados_hoje' => $enviados_hoje_utc,
        'campanha' => $enviados_campanha,
        'outros' => $enviados_outros
    ]);
    exit;
}

// 4. Intervalo minimo de 24h entre lotes.
//
// O cabecalho deste arquivo promete esta trava desde sempre e ela NUNCA existiu
// no codigo. E a trava que responde ao incidente de 25/01/2026, quando o cron do
// provedor rodou 3 vezes e gerou 870 emails para 258 pessoas. As consultas de
// grupo excluem quem ja tem filiacao no ano, o que cobre execucoes SEQUENCIAIS
// — mas nao cobre duas execucoes sobrepostas, porque as duas leem a lista antes
// de qualquer marca. E sobreposicao e exatamente o que acontece quando alguem
// clica "Run workflow" enquanto o agendado roda, ou quando um job falha no meio
// e e re-executado.
// COMO A TRAVA FUNCIONA, e por que nao le mais `envios_lotes`:
//
// Ate 30/08/2026 ela consultava a ultima linha de `envios_lotes` — e essa linha
// so e escrita DEPOIS de o lote inteiro ter sido enviado (`registrar_envio_lote`).
// Duas execucoes sobrepostas liam antes de qualquer uma escrever, e as duas
// passavam. Ou seja: a trava escrita para responder ao incidente de 25/01 **nao
// teria impedido o incidente**, cujas execucoes foram as 21:22, 21:28 e 21:30 —
// com usleep por email mais a latencia do Brevo, um lote de 258 leva minutos, e
// a das 21:30 quase certamente comecou com a das 21:28 ainda rodando.
//
// Agora a marca e escrita ANTES do envio, e a escrita e o proprio teste: um
// UPDATE condicional, atomico no SQLite. Duas execucoes simultaneas disputam a
// mesma linha e so uma afeta linha alguma — e a outra para aqui. E o mesmo
// padrao de compare-and-swap que o LembreteService usa para nao duplicar
// lembrete ("marca ANTES de enviar", conferindo as linhas afetadas).
$agora  = date('Y-m-d H:i:s');
$limite = date('Y-m-d H:i:s', strtotime('-24 hours'));

db_execute("INSERT OR IGNORE INTO configuracoes (chave, valor) VALUES ('cron_campanha_inicio', '')");

$ganhou = db_execute("
    UPDATE configuracoes
    SET valor = ?, updated_at = ?
    WHERE chave = 'cron_campanha_inicio'
      AND (valor = '' OR valor IS NULL OR valor < ?)
", [$agora, $agora, $limite]);

if ($ganhou === 0) {
    $marca = db_fetch_one("SELECT valor FROM configuracoes WHERE chave = 'cron_campanha_inicio'");
    $desde = $marca['valor'] ?? '(desconhecido)';
    registrar_log('cron_campanha_bloqueado', null,
        "Tentativa de lote bloqueada: ja houve inicio em $desde (intervalo minimo 24h, ou execucao sobreposta)");
    echo json_encode([
        'status' => 'bloqueado',
        'motivo' => 'Intervalo minimo de 24h entre lotes nao cumprido, ou outra execucao em andamento',
        'ultimo_inicio' => $desde
    ]);
    exit;
}

// 5. Executa envio do lote
require_once SRC_DIR . '/Services/BrevoService.php';

$limite_diario = 290 - $enviados_hoje_utc;

// Obtem grupos e envia.
//
// A classe e AdminCampanhaController desde 29/08/2026, quando o AdminController
// foi dividido por assunto. ReflectionMethod NAO sobe para a classe filha: com
// 'AdminController' aqui, isto lancava ReflectionException — HTTP 500, corpo
// vazio, sem try/catch.
//
// E o defeito era LATENTE: esta linha vem depois das quatro travas, que dao
// exit. Com a campanha fechada o endpoint respondia "bloqueado" normalmente, e
// o erro so apareceria no dia em que as travas passassem — isto e, no dia em
// que o envio automatico da campanha devesse comecar. Nenhum email sairia, e o
// diagnostico seria um Action vermelho sem corpo, sem SSH para ler o log.
require_once SRC_DIR . '/Controllers/AdminController.php';
require_once SRC_DIR . '/Campanha/AdminCampanhaController.php';

$reflection = new ReflectionMethod('AdminCampanhaController', 'obterGruposCampanha');
$reflection->setAccessible(true);
$grupos = $reflection->invoke(null, $ano);

$total_enviados = 0;
$total_erros = 0;
$grupo_atual = '';
$log_destinatarios = [];

foreach ($grupos as $grupo) {
    $destinatarios = db_fetch_all($grupo['query'], $grupo['params']);
    $destinatarios = array_filter($destinatarios, fn($d) => !empty($d['email']));
    $destinatarios = array_values($destinatarios);

    if (empty($destinatarios)) continue;

    // Envia 1 grupo por execucao, respeitando limite diario
    $restante = $limite_diario - $total_enviados;
    $enviar_agora = array_slice($destinatarios, 0, $restante);
    $grupo_atual = $grupo['nome'];

    foreach ($enviar_agora as $d) {
        // Gera token se nao tiver
        $token_pessoa = $d['token'];
        if (!$token_pessoa) {
            $token_pessoa = gerar_token();
            db_execute("UPDATE pessoas SET token = ? WHERE id = ?", [$token_pessoa, $d['id']]);
        }

        // Cria filiacao se nao existir (INSERT OR IGNORE evita crash por UNIQUE)
        $filiacao = db_fetch_one(
            "SELECT id FROM filiacoes WHERE pessoa_id = ? AND ano = ?",
            [$d['id'], $ano]
        );
        if (!$filiacao) {
            db_execute("
                INSERT OR IGNORE INTO filiacoes (pessoa_id, ano, categoria, status, status_at, created_at)
                VALUES (?, ?, '', 'enviado', datetime('now','localtime'), datetime('now','localtime'))
            ", [$d['id'], $ano]);
        }

        try {
            $enviado = false;
            switch ($grupo['template']) {
                case 'renovacao':
                    $enviado = BrevoService::enviarCampanhaRenovacao($d['email'], $d['nome'], $ano, $token_pessoa);
                    break;
                case 'seminario':
                    $enviado = BrevoService::enviarCampanhaSeminario($d['email'], $d['nome'], $ano, $token_pessoa);
                    break;
                case 'convite':
                    $enviado = BrevoService::enviarCampanhaConvite($d['email'], $d['nome'], $ano, $token_pessoa);
                    break;
            }

            $log_destinatarios[] = [
                'email' => $d['email'],
                'nome' => $d['nome'] ?? '',
                'sucesso' => (bool)$enviado,
            ];

            if ($enviado) {
                $total_enviados++;
            } else {
                $total_erros++;
            }
        } catch (Exception $e) {
            $total_erros++;
            $log_destinatarios[] = [
                'email' => $d['email'],
                'nome' => $d['nome'] ?? '',
                'sucesso' => false,
            ];
        }

        usleep(100000); // 100ms entre envios
    }

    // 1 grupo por execucao
    break;
}

// Registra lote (try/catch para não perder o resultado se falhar)
try {
    if (!empty($log_destinatarios)) {
        registrar_envio_lote(
            'campanha',
            $ano,
            "Campanha $ano (cron)",
            '',
            $log_destinatarios
        );
    }
} catch (Exception $e) {
    registrar_log('erro_registro_lote', null, "Cron campanha $ano enviou $total_enviados emails mas falhou ao registrar lote: " . $e->getMessage());
}

// Verifica se campanha terminou (nenhum destinatario restante)
$tem_mais = false;
foreach ($grupos as $grupo) {
    $resultado = db_fetch_all($grupo['query'], $grupo['params']);
    $resultado = array_filter($resultado, fn($d) => !empty($d['email']));
    if (!empty($resultado)) {
        $tem_mais = true;
        break;
    }
}

echo json_encode([
    'status' => 'ok',
    'enviados' => $total_enviados,
    'erros' => $total_erros,
    'grupo' => $grupo_atual,
    'enviados_hoje' => $enviados_hoje_utc + $total_enviados,
    'campanha_completa' => !$tem_mais
]);
