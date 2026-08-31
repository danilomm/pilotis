<?php
/**
 * Pilotis - Controller do fluxo público de inscrição em eventos
 *
 * Fluxo: página do evento → email → link com token → formulário → pagamento.
 * Independe de campanhas.status (decisão 7 do plano de eventos).
 */

class EventosController {

    /**
     * Lista pública de eventos com inscrições abertas
     */
    public static function listar(): void {
        $eventos = db_fetch_all("
            SELECT * FROM eventos
            WHERE status = 'publicado'
            AND (prazo_inscricao IS NULL OR prazo_inscricao >= DATE('now','localtime'))
            ORDER BY COALESCE(data_inicio, prazo_inscricao)
        ");

        $titulo = "Eventos - " . ORG_NOME;
        ob_start();
        require SRC_DIR . '/Eventos/Views/lista.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Página do evento.
     *
     * Nao e mais so a porta da inscricao: o Docomomo-RJ nao fez site do V
     * Seminario, entao esta pagina E a pagina do evento. Apresenta o conteudo,
     * as datas, o local e os valores; a entrada por CPF fica em /inscricao.
     */
    public static function pagina(string $slug): void {
        $evento = buscar_evento_por_slug($slug);
        if (!$evento) {
            error_404();
            return;
        }

        $categorias = db_fetch_all(
            "SELECT * FROM evento_categorias WHERE evento_id = ? ORDER BY ordem, id",
            [(int)$evento['id']]
        );

        // Categoria restrita (convidados, isentos) nao entra na tabela publica
        // de precos: ninguem esta identificado aqui, e anunciar que existe uma
        // categoria gratuita so convida a tentativa.
        $categorias = array_values(array_filter($categorias, fn(array $cat) => !categoria_restrita($cat)));
        $abertas = evento_inscricoes_abertas($evento);

        $titulo = $evento['nome'];
        $meta = self::metaDoEvento($evento);
        ob_start();
        require SRC_DIR . '/Eventos/Views/pagina.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Entrada da inscrição: só o CPF (ou o email, para quem não tem CPF).
     *
     * Separada da pagina do evento porque as duas coisas passaram a ser
     * diferentes. Quem chega pelo cartaz quer ler o que e o evento; quem ja
     * decidiu quer o campo de CPF sem rolar a pagina inteira. Junto, o botao
     * "Inscrever-se" nao tinha para onde levar.
     */
    public static function inscricao(string $slug): void {
        $evento = buscar_evento_por_slug($slug);
        if (!$evento) {
            error_404();
            return;
        }

        // Inscricoes fechadas: a pagina do evento diz isso melhor, com as datas
        // e o conteudo. Aqui so haveria um formulario que nao aceita nada.
        if (!evento_inscricoes_abertas($evento)) {
            redirect("/eventos/$slug");
            return;
        }

        $titulo = "Inscrição — " . $evento['nome'];
        $meta = self::metaDoEvento($evento);
        ob_start();
        require SRC_DIR . '/Eventos/Views/inscricao.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Meta tags para o link circular.
     *
     * O cartaz do evento vai impresso com esta URL e o link vai para grupos de
     * WhatsApp. A previa que o aplicativo monta na primeira vez FICA EM CACHE —
     * do lado dele, fora do nosso alcance. Entao isto se acerta antes de o link
     * sair, nao depois de alguem reclamar que aparece so o endereco cru.
     */
    private static function metaDoEvento(array $evento): array {
        $descricao = trim((string)($evento['descricao'] ?? ''));
        if ($descricao === '') {
            // Sem chamada escrita, monta uma com o que o evento tem: e melhor
            // que a previa vazia, e diz as duas coisas que importam.
            $partes = array_filter([
                data_por_extenso($evento['data_inicio'] ?? null, $evento['data_fim'] ?? null),
                trim(strtok((string)($evento['local'] ?? ''), "\n")),
            ]);
            $descricao = implode(' · ', $partes);
        }
        // Uma linha so: o WhatsApp corta perto de 160 caracteres e o corte no
        // meio de uma palavra fica pior que a frase curta.
        $descricao = preg_replace('/\s+/', ' ', $descricao);
        if (mb_strlen($descricao) > 180) {
            $descricao = mb_substr($descricao, 0, 177);
            $ultimo_espaco = mb_strrpos($descricao, ' ');
            if ($ultimo_espaco !== false && $ultimo_espaco > 120) {
                $descricao = mb_substr($descricao, 0, $ultimo_espaco);
            }
            $descricao .= '…';
        }

        // Imagem ABSOLUTA: caminho relativo nao e resolvido por quem monta a
        // previa, que le o HTML de fora do site.
        $imagem = !empty($evento['imagem_path'])
            ? rtrim(BASE_URL, '/') . EVENTOS_IMG_URL . '/' . $evento['imagem_path']
            : null;

        return [
            'titulo' => $evento['nome'],
            'descricao' => $descricao,
            'imagem' => $imagem,
            'url' => rtrim(BASE_URL, '/') . '/eventos/' . $evento['slug'],
        ];
    }

    /**
     * Recebe email e envia link de acesso ao formulário
     */
    public static function inscrever(string $slug): void {
        require_once SRC_DIR . '/Services/BrevoService.php';

        $evento = buscar_evento_por_slug($slug);
        if (!$evento) {
            error_404();
            return;
        }
        if (!evento_inscricoes_abertas($evento)) {
            flash('error', 'As inscrições para este evento estão encerradas.');
            redirect("/eventos/$slug");
            return;
        }

        // Limite por IP: esta tela manda email para o endereco do CADASTRO,
        // que quem preenche nao precisa controlar. Sem trava, uma lista de CPFs
        // enche a caixa de quem esta na base e esgota a cota do Brevo. A
        // resposta nao muda, para nao denunciar o bloqueio.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
        if (excedeu_limite_pedidos('evento_link_pedido', $ip, 10)) {
            registrar_log('evento_link_limite', null, "Limite de pedidos de link no evento {$evento['slug']} atingido [$ip]");
            flash('success', 'Se este documento estiver no cadastro, o link foi enviado para o email registrado.');
            redirect("/eventos/$slug/inscricao");
            return;
        }

        // Conta a TENTATIVA, e nao o envio. Contando 'evento_link_enviado' — que
        // so e gravado quando o email sai — o CPF que nao esta na base nao
        // contava nada, e varrer uma lista era ilimitado. E as duas respostas se
        // distinguem a olho (tela de email enviado x tela pedindo email), entao
        // a varredura dizia quem esta cadastrado. Registrado ANTES do trabalho,
        // e depois da checagem, para nao contar a requisicao da vez.
        registrar_log('evento_link_pedido', null, "Pedido de link no evento {$evento['slug']} [$ip]");

        // Dois caminhos de entrada, cada um com seu campo:
        //   cpf   -> filiado (recadastrou o CPF este ano)
        //   email -> quem ainda nao e filiado
        // Pelo CPF achamos a pessoa e mandamos o link para o email que ELA ja tem
        // no cadastro: resolve o "nao lembro com qual email me inscrevi" sem que o
        // link chegue a quem nao controla a caixa postal.
        $cpf_informado = trim($_POST['cpf'] ?? '');
        $so_digitos = preg_replace('/\D/', '', $cpf_informado);
        $entrou_por_cpf = $cpf_informado !== '';

        if ($entrou_por_cpf && strlen($so_digitos) !== 11) {
            flash('error', 'CPF inválido. Digite os 11 números.');
            redirect("/eventos/$slug/inscricao");
            return;
        }

        $pessoa = null;
        $email = '';

        if ($entrou_por_cpf) {
            $pessoa = buscar_pessoa_por_cpf($so_digitos);

            if (!$pessoa) {
                // CPF nao consta: segundo passo pede o email. O CPF digitado
                // segue junto para nao precisar redigitar.
                registrar_log('evento_cpf_nao_encontrado', null, "CPF sem cadastro no evento {$evento['slug']}");
                $cpf_pendente = $so_digitos;
                $titulo = "Inscrição — " . $evento['nome'];
                ob_start();
                require SRC_DIR . '/Eventos/Views/pedir_email.php';
                $content = ob_get_clean();
                require SRC_DIR . '/Views/layout.php';
                return;
            }
            if (empty($pessoa['email'])) {
                // Sem email nao ha como entregar o link com seguranca
                registrar_log('evento_cpf_sem_email', (int)$pessoa['id'], "CPF sem email cadastrado no evento {$evento['slug']}");
                flash('error', 'Seu cadastro não tem email registrado. Informe seu email para continuar, ou escreva para ' . ORG_EMAIL_CONTATO . '.');
                redirect("/eventos/$slug/inscricao");
                return;
            }

            $email = $pessoa['email'];
            $token = $pessoa['token'];
            if (!$token) {
                $token = gerar_token();
                db_execute("UPDATE pessoas SET token = ? WHERE id = ?", [$token, $pessoa['id']]);
            }
            registrar_log('evento_entrada_cpf', (int)$pessoa['id'], "Entrada pelo CPF para evento {$evento['slug']}");

        } else {
            $email = strtolower(trim($_POST['email'] ?? ''));
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                flash('error', 'Informe um email válido.');
                redirect("/eventos/$slug/inscricao");
                return;
            }

            // Busca ou cria pessoa (mesma base de cadastros da filiação)
            $pessoa = buscar_pessoa_por_email($email);
            if ($pessoa) {
                $token = $pessoa['token'];
                if (!$token) {
                    $token = gerar_token();
                    db_execute("UPDATE pessoas SET token = ? WHERE id = ?", [$token, $pessoa['id']]);
                }
                registrar_log('evento_entrada_email', $pessoa['id'], "Entrada pelo email para evento {$evento['slug']}");
            } else {
                $pessoa_id = criar_pessoa($email);
                $pessoa = buscar_pessoa_por_email($email);
                $token = $pessoa['token'];
                registrar_log('evento_novo_cadastro', $pessoa_id, "Novo cadastro via evento {$evento['slug']}");
            }

            // CPF vindo do primeiro passo: grava se a pessoa ainda nao tem e o
            // numero nao pertence a outra (mesma pre-checagem do indice unico).
            $cpf_do_passo1 = preg_replace('/\D/', '', (string)($_POST['cpf_pendente'] ?? ''));
            if (strlen($cpf_do_passo1) === 11 && empty($pessoa['cpf'])
                && !cpf_pertence_a_outra_pessoa($cpf_do_passo1, (int)$pessoa['id'])) {
                db_execute("UPDATE pessoas SET cpf = ? WHERE id = ?", [$cpf_do_passo1, (int)$pessoa['id']]);
                $pessoa['cpf'] = $cpf_do_passo1;
            }
        }

        // Cria registro de inscrição se ainda não existe
        $inscricao = buscar_inscricao((int)$pessoa['id'], (int)$evento['id']);
        if (!$inscricao) {
            criar_inscricao((int)$pessoa['id'], (int)$evento['id'], 'enviado', 'publico');
        }

        // Envia link por email
        $erro_envio = null;
        try {
            $enviado = BrevoService::enviarLinkInscricao($email, $pessoa['nome'] ?? '', $evento['nome'], $slug, $token);
            if ($enviado) {
                registrar_log('evento_link_enviado', $pessoa['id'], "Link de inscrição enviado para {$evento['slug']} [$ip]");
            } else {
                registrar_log('evento_erro_link', $pessoa['id'], "Falha ao enviar link de inscrição para {$evento['slug']}");
                $erro_envio = "Não foi possível enviar o email. Tente novamente.";
            }
        } catch (Exception $e) {
            registrar_log('evento_erro_link', $pessoa['id'], "Exceção ao enviar link: " . $e->getMessage());
            $erro_envio = "Erro ao enviar email: " . $e->getMessage();
        }

        // Entrou por CPF: a pessoa nao digitou o email, entao mostramos mascarado
        // (confirma qual caixa checar sem expor o endereco a quem tem so o CPF).
        $email_exibicao = $entrou_por_cpf ? mascarar_email($email) : $email;

        $titulo = "Verifique seu Email";
        ob_start();
        require SRC_DIR . '/Eventos/Views/email_enviado.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    // ==================== PAINEL DA ORGANIZACAO ====================

    /**
     * Entrada do painel: pede o email autorizado.
     */
    public static function organizacaoEntrada(string $slug): void {
        require_once SRC_DIR . '/Eventos/PainelOrganizacaoService.php';

        $evento = buscar_evento_por_slug($slug);
        if (!$evento || !painel_organizacao_ativo($evento)) {
            error_404();
            return;
        }

        if (PainelOrganizacaoService::sessaoAtiva($evento)) {
            redirect("/eventos/$slug/organizacao/inscritos");
            return;
        }

        $titulo = 'Acompanhamento — ' . $evento['nome'];
        ob_start();
        require SRC_DIR . '/Eventos/Views/organizacao_entrada.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Recebe o email e manda o link de acesso.
     *
     * Responde igual para email autorizado e nao autorizado: quem tenta nao
     * descobre quem faz parte da organizacao.
     */
    public static function organizacaoEnviarLink(string $slug): void {
        require_once SRC_DIR . '/Eventos/PainelOrganizacaoService.php';
        require_once SRC_DIR . '/Services/BrevoService.php';

        $evento = buscar_evento_por_slug($slug);
        if (!$evento || !painel_organizacao_ativo($evento)) {
            error_404();
            return;
        }

        $email = strtolower(trim($_POST['email'] ?? ''));
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';

        // Duas travas, porque sao dois abusos diferentes.
        //
        // Por EMAIL (3/h): impede encher a caixa de uma pessoa autorizada.
        // Por IP (5/h): impede que a mesma origem percorra uma LISTA de
        // endereços — a trava por email sozinha permite 3 pedidos para cada
        // nome que o atacante conheca, e o custo cai na cota do Brevo.
        //
        // A resposta e identica nos dois casos e no de email nao autorizado:
        // quem tenta nao descobre se acertou o endereco nem se foi barrado.
        // Conta TENTATIVA, nao envio bem-sucedido.
        //
        // Ate 30/08/2026 as duas travas liam 'painel_organizacao_link', que so e
        // gravado quando o email SAI para um endereco autorizado. A trava por
        // email funcionava; a trava por IP — cuja razao declarada e impedir que
        // a mesma origem percorra uma LISTA de enderecos — **nunca disparava**,
        // porque percorrer a lista nao gravava nada contavel. Com seis pessoas
        // na organizacao, uma origem mandava 3x6 = 18 emails/hora contra um teto
        // de 5.
        //
        // E o mesmo defeito consertado em 29/08 em evento_link_pedido e
        // filiacao_link_pedido; o painel ficou de fora daquele conserto.
        $excedeu = excedeu_limite_pedidos('painel_organizacao_pedido', $email, 3)
                || excedeu_limite_pedidos('painel_organizacao_pedido', $ip, 5);

        if ($excedeu) {
            registrar_log('painel_organizacao_limite', null,
                "Limite de pedidos de link no painel de {$evento['slug']} atingido [$ip]");
        } else {
            // Registrado ANTES do trabalho e DEPOIS da checagem, com as duas
            // chaves entre colchetes — e assim que excedeu_limite_pedidos() conta.
            registrar_log('painel_organizacao_pedido', null,
                "Pedido de link no painel de {$evento['slug']} [$email] [$ip]");
        }

        if (!$excedeu && email_autorizado_no_painel($evento, $email)) {
            $link = PainelOrganizacaoService::gerarLink($evento, $email);
            try {
                BrevoService::enviarAcessoPainel($email, $evento['nome'], $link);
                // As chaves vao entre colchetes: e assim que
                // excedeu_limite_pedidos() as conta. Sem isso o contador fica
                // em zero para sempre e a trava nunca dispara.
                registrar_log('painel_organizacao_link', null, "Link de acesso ao painel de {$evento['slug']} enviado para [$email] [$ip]");
            } catch (Exception $e) {
                registrar_log('painel_organizacao_erro', null, 'Erro ao enviar link do painel: ' . $e->getMessage());
            }
        } elseif (!$excedeu) {
            registrar_log('painel_organizacao_negado', null, "Tentativa de acesso ao painel de {$evento['slug']} com email nao autorizado");
        }

        $titulo = 'Acompanhamento — ' . $evento['nome'];
        $enviado = true;
        ob_start();
        require SRC_DIR . '/Eventos/Views/organizacao_entrada.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Abre a sessao a partir do link recebido por email.
     */
    public static function organizacaoAcesso(string $slug, string $token): void {
        require_once SRC_DIR . '/Eventos/PainelOrganizacaoService.php';

        $evento = buscar_evento_por_slug($slug);
        if (!$evento || !painel_organizacao_ativo($evento)) {
            error_404();
            return;
        }

        $email = PainelOrganizacaoService::lerToken($evento, $token);
        if (!$email) {
            registrar_log('painel_organizacao_negado', null, "Link invalido ou expirado no painel de {$evento['slug']}");
            flash('error', 'Este link não vale mais. Informe seu email para receber outro.');
            redirect("/eventos/$slug/organizacao");
            return;
        }

        PainelOrganizacaoService::abrirSessao($evento, $email);
        PainelOrganizacaoService::registrarAcao($evento, $email, 'acesso');
        redirect("/eventos/$slug/organizacao/inscritos");
    }

    /**
     * O painel em si. Somente leitura.
     */
    public static function organizacaoInscritos(string $slug): void {
        require_once SRC_DIR . '/Eventos/PainelOrganizacaoService.php';

        $evento = buscar_evento_por_slug($slug);
        if (!$evento) { error_404(); return; }

        $email_sessao = PainelOrganizacaoService::sessaoAtiva($evento);
        if (!$email_sessao) {
            redirect("/eventos/$slug/organizacao");
            return;
        }

        $filtro = (string)($_GET['status'] ?? '');
        $busca = trim((string)($_GET['q'] ?? ''));
        $inscritos = inscritos_do_evento((int)$evento['id'], $filtro, $busca);

        $totais = db_fetch_one("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN status IN ('pago','gratuita_confirmada') THEN 1 ELSE 0 END) AS confirmadas,
                   SUM(CASE WHEN status = 'pago' THEN 1 ELSE 0 END) AS pagas,
                   SUM(CASE WHEN status = 'gratuita_confirmada' THEN 1 ELSE 0 END) AS isentas,
                   SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) AS pendentes,
                   SUM(CASE WHEN status IN ('enviado','acesso') THEN 1 ELSE 0 END) AS sem_resposta
            FROM inscricoes WHERE evento_id = ?
        ", [(int)$evento['id']]);

        $titulo = 'Inscritos — ' . $evento['nome'];
        ob_start();
        require SRC_DIR . '/Eventos/Views/organizacao_inscritos.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    public static function organizacaoXlsx(string $slug): void { self::exportarPainel($slug, 'xlsx'); }
    public static function organizacaoCsv(string $slug): void { self::exportarPainel($slug, 'csv'); }

    /**
     * Planilha do painel. Mesmo conteudo da tela, SEM CPF.
     *
     * O CPF fica fora por nao servir ao trabalho da organizacao: ela precisa
     * falar com as pessoas e mandar correspondencia, nao identifica-las na
     * Receita. Quem lida com pagamento e a tesouraria, e la o CPF esta.
     */
    private static function exportarPainel(string $slug, string $formato): void {
        require_once SRC_DIR . '/Eventos/PainelOrganizacaoService.php';

        $evento = buscar_evento_por_slug($slug);
        if (!$evento) { error_404(); return; }

        $email_sessao = PainelOrganizacaoService::sessaoAtiva($evento);
        if (!$email_sessao) {
            redirect("/eventos/$slug/organizacao");
            return;
        }

        $rows = inscritos_do_evento((int)$evento['id'], (string)($_GET['status'] ?? ''), trim((string)($_GET['q'] ?? '')));
        PainelOrganizacaoService::registrarAcao($evento, $email_sessao, 'download',
            "lista em $formato, " . count($rows) . ' linhas');

        $situacoes = [
            'pago' => 'Pago', 'gratuita_confirmada' => 'Isento confirmado',
            'pendente' => 'Aguardando pagamento', 'acesso' => 'Abriu o formulário',
            'enviado' => 'Link enviado',
        ];

        $cabecalho = [
            'Nome', 'Email', 'Telefone', 'Categoria', 'Situação', 'Data do pagamento',
            'Instituição', 'Profissão', 'Endereço', 'CEP', 'Cidade', 'Estado', 'País',
            'Comprovante de matrícula',
        ];

        $linhas = [];
        foreach ($rows as $r) {
            $exige = !empty($r['requer_comprovante']);
            $linhas[] = [
                $r['nome'],
                $r['email'] ?? '',
                $r['telefone'] ?? '',
                $r['categoria_nome'] ?? '',
                $situacoes[$r['status']] ?? $r['status'],
                $r['data_pagamento'] ? date('d/m/Y', strtotime($r['data_pagamento'])) : '',
                $r['instituicao'] ?? '',
                $r['profissao'] ?? '',
                $r['endereco'] ?? '',
                $r['cep'] ?? '',
                $r['cidade'] ?? '',
                $r['estado'] ?? '',
                $r['pais'] ?? '',
                $exige ? (tem_comprovante_evento((int)$evento['id'], (int)$r['pessoa_id']) ? 'enviado' : 'falta') : 'não exigido',
            ];
        }

        exportar_planilha($formato, 'inscritos_' . $evento['slug'], 'Inscritos', $cabecalho, $linhas);
    }

    public static function organizacaoSair(string $slug): void {
        require_once SRC_DIR . '/Eventos/PainelOrganizacaoService.php';
        $evento = buscar_evento_por_slug($slug);
        if ($evento) PainelOrganizacaoService::encerrarSessao($evento);
        redirect("/eventos/$slug/organizacao");
    }

    /**
     * Formulário de inscrição (via token)
     */
    public static function formulario(string $slug, string $token): void {
        $evento = buscar_evento_por_slug($slug);
        if (!$evento) {
            error_404();
            return;
        }

        $cadastrado = buscar_pessoa_por_token($token);
        if (!$cadastrado) {
            flash('error', 'Link inválido ou expirado.');
            redirect("/eventos/$slug/inscricao");
            return;
        }

        $inscricao = buscar_inscricao((int)$cadastrado['id'], (int)$evento['id']);

        // Inscrição já confirmada → recibo (funciona mesmo com prazo encerrado)
        if ($inscricao && in_array($inscricao['status'], ['pago', 'gratuita_confirmada'])) {
            self::renderConfirmada($evento, $cadastrado, $inscricao);
            return;
        }

        if (!evento_inscricoes_abertas($evento)) {
            $titulo = $evento['nome'];
            ob_start();
            require SRC_DIR . '/Eventos/Views/encerrado.php';
            $content = ob_get_clean();
            require SRC_DIR . '/Views/layout.php';
            return;
        }

        // Garante registro de inscrição e marca acesso.
        // $primeiro_acesso: só a TRANSIÇÃO para 'acesso', nunca o estado. É o
        // que decide o agendamento do lembrete, mais abaixo.
        $primeiro_acesso = false;
        if (!$inscricao) {
            $inscricao = criar_inscricao((int)$cadastrado['id'], (int)$evento['id'], 'acesso', 'publico');
            $primeiro_acesso = true;
        } elseif ($inscricao['status'] === 'enviado') {
            db_execute(
                "UPDATE inscricoes SET status = 'acesso', status_at = datetime('now','localtime') WHERE id = ?",
                [(int)$inscricao['id']]
            );
            $primeiro_acesso = true;
        }

        registrar_log('evento_acesso_formulario', $cadastrado['id'], "Acesso ao formulário do evento {$evento['slug']}");

        // Quem abriu o formulário e não concluiu recebe lembrete. Agendar aqui,
        // e não no envio, alcança também quem desistiu no meio do caminho.
        //
        // Preso à TRANSIÇÃO, como na filiação. Guardar só por status era
        // verdadeiro em 'enviado', 'acesso' E 'pendente', então rodava em todo
        // GET — e agendarInscricaoIncompleta() calcula hoje+7 e hoje+14, com a
        // dedup feita pela tupla que inclui a data. Datas diferentes, linhas
        // novas: cinco visitas em cinco dias viravam dez lembretes, um email
        // por dia. A idempotência que o comentário antigo afirmava não existia.
        if ($primeiro_acesso
            && !in_array($inscricao['status'] ?? '', ['pago', 'gratuita_confirmada'], true)) {
            require_once SRC_DIR . '/Services/LembreteService.php';
            LembreteService::agendarInscricaoIncompleta((int)$inscricao['id'], $evento['prazo_inscricao'] ?: null);
        }

        $instituicoes = instituicoes_conhecidas();

        // Pré-preenchimento: dados da inscrição (se já preencheu) > cadastro (última filiação com dados)
        $pre_consentimento = (string)($inscricao['consentimento_versao'] ?? '');

        $pre = $cadastrado;
        if ($inscricao && !empty($inscricao['endereco'])) {
            foreach (['telefone','endereco','cep','cidade','estado','pais','profissao','instituicao'] as $c) {
                if (!empty($inscricao[$c])) $pre[$c] = $inscricao[$c];
            }
        }
        $tem_cadastro_previo = !empty(trim($cadastrado['nome'] ?? '')) || !empty($cadastrado['endereco']);

        $categorias = db_fetch_all(
            "SELECT * FROM evento_categorias WHERE evento_id = ? ORDER BY ordem, id",
            [(int)$evento['id']]
        );
        // Categoria restrita so aparece para quem esta na lista de CPFs.
        // Esconder e cortesia; a checagem que vale e a do salvar, porque o CPF
        // pode ser trocado no proprio formulario.
        $categorias = array_values(array_filter($categorias, function (array $cat) use ($cadastrado) {
            return pessoa_liberada_na_categoria($cat, $cadastrado);
        }));

        // Adimplencia conferida JA NA MONTAGEM da tela, e nao so no envio.
        // A pessoa chegou aqui identificada: o sistema sabe se ela tem direito
        // ao desconto, e esconder isso produzia duas incoerencias — filiado
        // vendo a categoria mais cara do mesmo trilho, e nao-filiado escolhendo
        // uma categoria que so seria recusada depois de preencher tudo.
        $adimplente = verificar_adimplencia_evento(
            $cadastrado['cpf'] ?? null,
            $cadastrado['email'] ?? null,
            $evento
        ) !== null;

        $comprovante_existente = tem_comprovante_evento((int)$evento['id'], (int)$cadastrado['id']);

        $titulo = "Inscrição - " . $evento['nome'];
        ob_start();
        require SRC_DIR . '/Eventos/Views/formulario.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Submete a inscrição
     */
    public static function salvar(string $slug, string $token): void {
        require_once SRC_DIR . '/Services/BrevoService.php';

        $evento = buscar_evento_por_slug($slug);
        if (!$evento) {
            error_404();
            return;
        }
        if (!evento_inscricoes_abertas($evento)) {
            flash('error', 'As inscrições para este evento estão encerradas.');
            redirect("/eventos/$slug");
            return;
        }

        $cadastrado = buscar_pessoa_por_token($token);
        if (!$cadastrado) {
            flash('error', 'Link inválido.');
            redirect("/eventos/$slug/inscricao");
            return;
        }

        $inscricao = buscar_inscricao((int)$cadastrado['id'], (int)$evento['id']);
        if ($inscricao && in_array($inscricao['status'], ['pago', 'gratuita_confirmada'])) {
            self::renderConfirmada($evento, $cadastrado, $inscricao);
            return;
        }
        if (!$inscricao) {
            $inscricao = criar_inscricao((int)$cadastrado['id'], (int)$evento['id'], 'acesso', 'publico');
        }

        // Categoria
        $categoria_id = (int)($_POST['categoria_id'] ?? 0);
        $categoria = db_fetch_one(
            "SELECT * FROM evento_categorias WHERE id = ? AND evento_id = ?",
            [$categoria_id, (int)$evento['id']]
        );
        if (!$categoria) {
            flash('error', 'Escolha uma categoria de inscrição.');
            redirect("/eventos/$slug/$token");
            return;
        }
        $valor = valor_vigente_categoria($categoria, $evento);

        // Campos
        $nome = trim($_POST['nome'] ?? '');
        // Vazio cai no palpite: crachá sem nome é pior que crachá com o nome
        // provável. mb_substr porque o campo alimenta impressão.
        $nome_cracha = mb_substr(trim($_POST['nome_cracha'] ?? ''), 0, 40);
        if ($nome_cracha === '') {
            $nome_cracha = palpite_nome_cracha($nome);
        }
        $email_form = strtolower(trim($_POST['email'] ?? ''));
        $cpf = trim($_POST['cpf'] ?? '');

        // Documento de quem nao tem CPF. Sem tipo, o numero nao diz nada:
        // 'passaporte' e o padrao, e a pessoa corrige se for outro.
        $documento = trim($_POST['documento'] ?? '');
        $documento_tipo = trim($_POST['documento_tipo'] ?? '');
        if ($documento !== '' && $documento_tipo === '') $documento_tipo = 'passaporte';
        if ($documento === '') $documento_tipo = '';
        $telefone = trim($_POST['telefone'] ?? '');
        $endereco = trim($_POST['endereco'] ?? '');
        $cep = trim($_POST['cep'] ?? '');
        $cidade = trim($_POST['cidade'] ?? '');
        $estado = strtoupper(trim($_POST['estado'] ?? ''));
        $pais = trim($_POST['pais'] ?? 'Brasil');
        $profissao = trim($_POST['profissao'] ?? '');
        // Instituicao vem da lista fechada; so cai no texto livre quando a
        // pessoa escolhe "Outra". Teto de 30 caracteres e a mesma regra da
        // tela: sigla no formato UNIDADE-UNIVERSIDADE, para nao voltarem as
        // 140 grafias avulsas que a base acumulou.
        $escolha = trim($_POST['instituicao_escolha'] ?? '');
        $instituicao = ($escolha !== '' && $escolha !== '__outra')
            ? $escolha
            : mb_substr(trim($_POST['instituicao'] ?? ''), 0, 30);

        if (empty($nome)) {
            flash('error', 'Nome é obrigatório.');
            redirect("/eventos/$slug/$token");
            return;
        }

        // Consentimento conferido NO SERVIDOR. O `required` do HTML e conforto
        // de quem preenche, nao garantia: qualquer POST direto o ignora, e e
        // justamente o registro de consentimento que nao pode depender do
        // navegador ter colaborado.
        //
        // Aceita quando a caixa vem marcada AGORA ou quando ja havia
        // consentimento nesta MESMA versao — a caixa ja vem marcada nesse caso,
        // e reenviar o formulario nao pode exigir reler o que nao mudou.
        $consentiu = !empty($_POST['consentimento'])
            || (string)($inscricao['consentimento_versao'] ?? '') === POLITICA_PRIVACIDADE_VERSAO;
        if (!$consentiu) {
            flash('error', 'É preciso concordar com o aviso de privacidade para continuar.');
            redirect("/eventos/$slug/$token");
            return;
        }

        // CPF: confere o DIGITO VERIFICADOR, e nao so a presenca. Ver a nota
        // extensa no FiliacaoController — o mesmo defeito, e foi ele que
        // produziu as 30 recusas do PagBank em producao. Aqui vale sempre que
        // houver CPF preenchido: categoria gratuita nao o exige, mas numero
        // errado gravado hoje vira recusa na proxima inscricao paga.
        if ($cpf !== '' && !cpf_valido($cpf)) {
            flash('error', 'CPF inválido: confira os números digitados.');
            redirect("/eventos/$slug/$token");
            return;
        }

        // Categoria paga: identificacao e dados de contato/endereco (boleto).
        //
        // A identificacao aceita CPF **ou** outro documento. O CPF e o unico que
        // o PagBank aceita; quem entra com passaporte tem a inscricao registrada
        // como PENDENTE e a tesouraria combina o pagamento por fora — e nao
        // chega a tela de pagamento, para nao tentar o cartao e falhar. Ate
        // 30/08/2026 aqui exigia CPF e ponto: estrangeiro preenchia o
        // formulario inteiro para levar "CPF é obrigatório para inscrição paga".
        if ($valor > 0) {
            $obrigatorios = [
                'telefone' => 'Telefone', 'endereco' => 'Endereço',
                'cep' => 'CEP', 'cidade' => 'Cidade', 'estado' => 'Estado', 'pais' => 'País',
            ];
            foreach ($obrigatorios as $campo => $label) {
                if (empty($$campo)) {
                    flash('error', "$label é obrigatório para inscrição paga.");
                    redirect("/eventos/$slug/$token");
                    return;
                }
            }
            if ($cpf === '' && $documento === '') {
                flash('error', 'Informe o CPF. Se você não tem CPF brasileiro, '
                    . 'abra "Não tenho CPF brasileiro" e informe o passaporte.');
                redirect("/eventos/$slug/$token");
                return;
            }
            if ($cpf !== '' && strlen(preg_replace('/\D/', '', $cpf)) !== 11) {
                flash('error', 'CPF inválido.');
                redirect("/eventos/$slug/$token");
                return;
            }
        }

        // Categoria restrita: quem nao esta na lista nao entra, mesmo sabendo o
        // id da categoria. E aqui que a restricao vale de fato.
        //
        // Confere contra o CADASTRO, nao contra os campos preenchidos agora:
        // o formulario e editavel, e o email do cadastro e o unico dado ja
        // provado (foi para ele que o link de acesso foi enviado).
        if (!pessoa_liberada_na_categoria($categoria, $cadastrado)) {
            registrar_log('evento_categoria_restrita_negada', $cadastrado['id'],
                "Categoria '{$categoria['nome']}' negada no evento {$evento['slug']} (CPF fora da lista)");
            flash('error', "A categoria {$categoria['nome']} é restrita a uma lista de participantes. " .
                "Escolha outra categoria — ou, se acredita que é um engano, escreva para " . ORG_EMAIL_CONTATO . ".");
            redirect("/eventos/$slug/$token");
            return;
        }

        // Adimplencia, conferida uma vez e usada nos dois sentidos abaixo.
        // SEMPRE contra o cadastro, nunca contra o CPF digitado: o campo e
        // editavel, e a lista publica /filiados/{ano} diz quem esta em dia —
        // com o CPF de qualquer um deles, quem digitasse aqui levava o preco de
        // filiado. Mesma regra da montagem do formulario e de
        // pessoa_liberada_na_categoria().
        $adimplente = verificar_adimplencia_evento(
            $cadastrado['cpf'] ?? null,
            $cadastrado['email'] ?? null,
            $evento
        );

        // Filiado em dia nao paga preco cheio. Uma categoria que nao verifica
        // adimplencia e, por convencao, o preco de quem nao e filiado — a nao
        // ser que esteja marcada como independente de filiacao (acompanhante,
        // visitante), que vale para os dois, ou que seja restrita por CPF.
        if ($adimplente
            && empty($categoria['verifica_adimplencia'])
            && empty($categoria['independe_filiacao'])
            && !categoria_restrita($categoria)) {
            registrar_log('evento_categoria_cheia_negada', $cadastrado['id'],
                "Categoria '{$categoria['nome']}' negada no evento {$evento['slug']} (filiado em dia, categoria de preco cheio)");
            flash('error', "Sua anuidade está em dia: escolha a categoria de filiado correspondente, que custa menos.");
            redirect("/eventos/$slug/$token");
            return;
        }

        // Verificação de adimplência (decisões A/B)
        if (!empty($categoria['verifica_adimplencia'])) {
            if (!$adimplente) {
                registrar_log('evento_adimplencia_negada', $cadastrado['id'],
                    "Categoria '{$categoria['nome']}' negada no evento {$evento['slug']} (CPF/email sem filiação ativa)");
                flash('error', "Não encontramos filiação ativa para este CPF. Escolha outra categoria — ou, se acredita que é um engano, escreva para " . ORG_EMAIL_CONTATO . ".");
                redirect("/eventos/$slug/$token");
                return;
            }
            registrar_log('evento_adimplencia_ok', $cadastrado['id'],
                "Adimplência confirmada (pessoa {$adimplente['pessoa_id']}, ano {$adimplente['ano']}) para {$evento['slug']}");
        }

        // Comprovante de matrícula (se a categoria exige)
        $comprovante_path = null;
        if (!empty($categoria['requer_comprovante'])) {
            $ja_tem = tem_comprovante_evento((int)$evento['id'], (int)$cadastrado['id']);
            if (!$ja_tem && (empty($_FILES['comprovante']) || $_FILES['comprovante']['error'] === UPLOAD_ERR_NO_FILE)) {
                flash('error', "A categoria {$categoria['nome']} exige comprovante de matrícula.");
                redirect("/eventos/$slug/$token");
                return;
            }
            $erro_upload = $_FILES['comprovante']['error'] ?? UPLOAD_ERR_NO_FILE;

            if ($erro_upload === UPLOAD_ERR_OK) {
                $comprovante_path = salvar_comprovante_evento($_FILES['comprovante'], (int)$evento['id'], (int)$cadastrado['id']);
                if ($comprovante_path === null) {
                    flash('error', 'Erro no comprovante. Use PDF, JPG ou PNG de até 5MB.');
                    redirect("/eventos/$slug/$token");
                    return;
                }
            } elseif ($erro_upload !== UPLOAD_ERR_NO_FILE) {
                // Todo erro que NAO seja "nao mandou arquivo" caia neste vao ate
                // 30/08/2026: o codigo seguia, $comprovante_path ficava null, a
                // inscricao virava pendente e a pessoa nao era avisada de nada.
                // O caso comum e UPLOAD_ERR_INI_SIZE — o upload_max_filesize
                // padrao do PHP e 2 MB, e salvar_comprovante_evento() aceita 5;
                // foto de carteirinha tirada no celular passa de 2 MB sem
                // esforco. Resultado: estudante paga, some da fila de "exigem
                // comprovante" e ninguem sabe por que. Fere o invariante de
                // erro visivel.
                $motivos = [
                    UPLOAD_ERR_INI_SIZE   => 'O arquivo é maior do que o servidor aceita. Envie até 2 MB.',
                    UPLOAD_ERR_FORM_SIZE  => 'O arquivo é maior do que o formulário aceita.',
                    UPLOAD_ERR_PARTIAL    => 'O envio foi interrompido. Tente de novo.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Falha no servidor ao receber o arquivo. Avise a organização.',
                    UPLOAD_ERR_CANT_WRITE => 'Falha no servidor ao gravar o arquivo. Avise a organização.',
                    UPLOAD_ERR_EXTENSION  => 'O envio foi bloqueado pelo servidor. Avise a organização.',
                ];
                registrar_log('erro_upload_comprovante', $cadastrado['id'],
                    "Upload de comprovante falhou no evento {$evento['slug']}: codigo $erro_upload");
                flash('error', ($motivos[$erro_upload] ?? 'Não foi possível receber o arquivo.')
                    . ' Se o problema continuar, escreva para ' . ORG_EMAIL_CONTATO . '.');
                redirect("/eventos/$slug/$token");
                return;
            }
        }

        // Atualiza pessoa (nome; CPF só se não pertencer a outra — pré-checagem do índice único)
        $cpf_gravar = $cpf ? preg_replace('/\D/', '', $cpf) : null;
        if ($cpf_gravar && cpf_pertence_a_outra_pessoa($cpf_gravar, (int)$cadastrado['id'])) {
            $cpf_gravar = null; // vinculação será oferecida adiante
        }
        // Documento estrangeiro: grava so quando veio preenchido, para nao
        // apagar o que a tesouraria tenha lancado pelo /admin. Quem passou a ter
        // CPF nao perde o passaporte — sao dados diferentes, e o CPF tem
        // precedencia na hora de identificar num documento.
        $doc_gravar = $documento !== '' ? $documento : null;
        $doc_tipo_gravar = $documento !== '' ? $documento_tipo : null;

        db_execute(
            "UPDATE pessoas SET nome = ?, cpf = COALESCE(?, cpf),
                    documento = COALESCE(?, documento),
                    documento_tipo = COALESCE(?, documento_tipo),
                    updated_at = datetime('now','localtime')
             WHERE id = ?",
            [$nome, $cpf_gravar, $doc_gravar, $doc_tipo_gravar, (int)$cadastrado['id']]
        );

        // Atualiza inscrição.
        // Mesma armadilha da filiação: trocar de categoria muda o valor, e a
        // cobrança já criada continua valendo o antigo — a tela imprimiria o
        // valor novo com o QR do velho, porque gerarPix só cria cobrança quando
        // o order_id está vazio. Invalida sempre que o valor muda.
        $limpar_cobranca = '';
        if ((int)($inscricao['valor'] ?? 0) !== (int)$valor) {
            $limpar_cobranca = ', pagbank_order_id = NULL, pagbank_charge_id = NULL,'
                . ' pagbank_boleto_link = NULL, pagbank_boleto_barcode = NULL,'
                . ' data_vencimento = NULL, metodo = NULL';
            registrar_log('cobranca_invalidada', $cadastrado['id'],
                "Valor da inscricao {$inscricao['id']} ({$evento['slug']}) mudou de "
                . (int)($inscricao['valor'] ?? 0) . " para $valor: cobranca anterior descartada");
        }

        $novo_status = $valor === 0 ? 'gratuita_confirmada' : 'pendente';
        db_execute("
            UPDATE inscricoes SET
                categoria_id = ?, valor = ?, nome_cracha = ?,
                telefone = ?, endereco = ?, cep = ?, cidade = ?, estado = ?, pais = ?,
                profissao = ?, instituicao = ?,
                comprovante_path = COALESCE(?, comprovante_path),
                consentimento_versao = ?, consentimento_em = COALESCE(consentimento_em, datetime('now','localtime')),
                status = ?, status_at = datetime('now','localtime')
                $limpar_cobranca
            WHERE id = ? AND status NOT IN ('pago', 'gratuita_confirmada')
        ", [
            (int)$categoria['id'], $valor, $nome_cracha,
            $telefone ?: null, $endereco ?: null, $cep ?: null, $cidade ?: null,
            $estado ?: null, $pais ?: 'Brasil', $profissao ?: null, $instituicao ?: null,
            $comprovante_path,
            POLITICA_PRIVACIDADE_VERSAO,
            $novo_status,
            (int)$inscricao['id'],
        ]);

        registrar_log('evento_inscricao_salva', $cadastrado['id'],
            "Inscrição no evento {$evento['slug']}: {$categoria['nome']} " . formatar_valor($valor));

        // Gratuita: confirma direto, envia email, mostra recibo
        if ($valor === 0) {
            // Inscrição resolvida: nada de lembrete de "incompleta" depois.
            require_once SRC_DIR . '/Services/LembreteService.php';
            LembreteService::cancelarInscricao((int)$inscricao['id']);

            // Mesmo comprovante da inscricao paga, com o texto de isencao. Quem
            // e isento tambem presta contas -- e e o unico papel que atesta que
            // esteve inscrito. Se a geracao falhar, o email sai sem anexo.
            $pdf_bytes = null;
            try {
                require_once SRC_DIR . '/Services/PdfService.php';
                $pdf_bytes = PdfService::gerarComprovanteInscricao([
                    'inscricao_id' => (int)$inscricao['id'],
                    'nome' => $nome,
                    'email' => $cadastrado['email'] ?? '',
                    'cpf' => $cpf ?: ($cadastrado['cpf'] ?? ''),
                    'documento' => $cadastrado['documento'] ?? null,
                    'documento_tipo' => $cadastrado['documento_tipo'] ?? null,
                    'evento' => $evento['nome'],
                    'categoria' => $categoria['nome'],
                    'valor' => 0,
                    'data_pagamento' => date('Y-m-d H:i:s'),
                    'metodo' => '',
                    'assinantes' => $evento['assinantes'] ?? '',
                    'email_contato' => $evento['email_contato'] ?? '',
                    'imagem_path' => $evento['imagem_path'] ?? '',
                ]);
            } catch (Throwable $e) {
                registrar_log('erro_comprovante_inscricao', $cadastrado['id'],
                    "Falha ao gerar PDF da inscricao isenta {$inscricao['id']}: " . $e->getMessage());
            }

            try {
                BrevoService::enviarConfirmacaoInscricao(
                    $cadastrado['email'] ?? '', $nome, $evento['nome'], $categoria['nome'], 0, $pdf_bytes
                );
                registrar_log('evento_confirmacao_enviada', $cadastrado['id'], "Confirmação de inscrição gratuita: {$evento['slug']}");
            } catch (Exception $e) {
                registrar_log('evento_erro_confirmacao', $cadastrado['id'], "Erro no email de confirmação: " . $e->getMessage());
            }
            $inscricao = buscar_inscricao((int)$cadastrado['id'], (int)$evento['id']);
            self::renderConfirmada($evento, $cadastrado, $inscricao);
            return;
        }

        // Oferta de vinculação a cadastro antigo (decisão B2) antes do pagamento
        $match = buscar_match_consolidacao((int)$cadastrado['id'], $email_form ?: null, $cpf ?: null, $nome, (int)date('Y'));
        if ($match) {
            $motivo = urlencode($match['motivo']);
            $mid = (int)$match['pessoa_id'];
            registrar_log('evento_match_oferecido', $cadastrado['id'], "Match candidato pessoa_id=$mid motivo={$match['motivo']} (evento {$evento['slug']})");
            $sig = assinar_match((int)$cadastrado['id'], $mid);
            redirect("/eventos/$slug/$token/vincular?match=$mid&motivo=$motivo&sig=$sig");
            return;
        }

        // Sem CPF, o pagamento online nao existe: o PagBank exige CPF ou CNPJ e
        // recusaria o cartao. Mandar a pessoa para a tela de pagamento seria
        // deixa-la tentar e falhar, o que e desgastante e nao leva a nada.
        // A inscricao fica registrada e pendente, e a tesouraria combina.
        if ($valor > 0 && $cpf === '') {
            registrar_log('inscricao_sem_cpf', (int)$cadastrado['id'],
                "Inscricao {$inscricao['id']} no evento {$evento['slug']} registrada sem CPF"
                . ($documento !== '' ? " ($documento_tipo)" : '') . '; pagamento a combinar com a tesouraria');

            // Avisa a TESOURARIA, que e quem tem de agir: mandar a ordem de
            // pagamento (PayPal, transferencia) e depois lancar como paga.
            // O log sozinho nao serve: ele so e lido por quem abre o /admin/log
            // de proposito, e a pessoa que preencheu tudo ficaria esperando.
            //
            // Falhar aqui NAO desfaz a inscricao nem muda o que a pessoa ve: o
            // registro dela esta feito, e a pendencia continua no log. Perder a
            // inscricao por causa do aviso seria trocar um problema por um pior.
            try {
                $link_admin = rtrim(BASE_URL, '/') . BASE_PATH . '/admin/eventos/'
                    . (int)$evento['id'] . '/inscritos?status=pendente';
                BrevoService::avisarTesourariaInscricaoSemCpf(
                    $nome,
                    $cadastrado['email'] ?? '',
                    documento_identificacao('', $documento ?: null, $documento_tipo ?: null),
                    $evento['nome'],
                    $categoria['nome'] ?? '',
                    (int)$valor,
                    $pais,
                    $link_admin
                );
            } catch (Throwable $e) {
                registrar_log('erro_aviso_sem_cpf', (int)$cadastrado['id'],
                    "Inscricao {$inscricao['id']} sem CPF registrada, mas o aviso a tesouraria falhou: "
                    . $e->getMessage());
            }

            redirect("/eventos/$slug/$token/aguardando");
            return;
        }

        redirect("/eventos/$slug/$token/pagamento");
    }

    /**
     * Tela: oferece vinculação a cadastro antigo (decisão B2)
     */
    public static function vincular(string $slug, string $token): void {
        $evento = buscar_evento_por_slug($slug);
        if (!$evento) { error_404(); return; }

        $cadastrado = buscar_pessoa_por_token($token);
        if (!$cadastrado) {
            flash('error', 'Link inválido.');
            redirect("/eventos/$slug/inscricao");
            return;
        }

        $match_id = (int)($_GET['match'] ?? 0);
        $motivo = preg_replace('/[^a-z]/', '', strtolower($_GET['motivo'] ?? ''));
        $sig = (string)($_GET['sig'] ?? '');
        if (!$match_id || !in_array($motivo, ['email', 'cpf', 'nome'])) {
            redirect("/eventos/$slug/$token/pagamento");
            return;
        }
        // So o match que o servidor ofereceu a esta pessoa. Ver assinar_match().
        if (!match_assinado((int)$cadastrado['id'], $match_id, $sig)) {
            registrar_log('match_assinatura_invalida', $cadastrado['id'], "GET vincular com match=$match_id nao assinado (evento {$evento['slug']})");
            redirect("/eventos/$slug/$token/pagamento");
            return;
        }

        $antigo = db_fetch_one("
            SELECT p.id, p.nome, (SELECT email FROM emails WHERE pessoa_id=p.id AND principal=1 LIMIT 1) AS email
            FROM pessoas p WHERE p.id = ? AND p.ativo = 1
        ", [$match_id]);
        if (!$antigo) {
            redirect("/eventos/$slug/$token/pagamento");
            return;
        }
        $ultima_paga = db_fetch_one("SELECT MAX(ano) as ano FROM filiacoes WHERE pessoa_id=? AND status='pago'", [$match_id]);

        $email_mascarado = $antigo['email'] ? mascarar_email($antigo['email']) : '';
        $primeiro_nome = explode(' ', trim($antigo['nome']))[0] ?? '';
        $titulo = "Vincular cadastro";
        ob_start();
        require SRC_DIR . '/Eventos/Views/vincular.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Processa a decisão de vinculação
     */
    public static function processarVinculacao(string $slug, string $token): void {
        $evento = buscar_evento_por_slug($slug);
        if (!$evento) { error_404(); return; }

        $cadastrado = buscar_pessoa_por_token($token);
        if (!$cadastrado) {
            flash('error', 'Link inválido.');
            redirect("/eventos/$slug/inscricao");
            return;
        }

        $match_id = (int)($_POST['match'] ?? 0);
        $decisao = $_POST['decisao'] ?? '';

        // Mesma conferencia do GET: e aqui que consolidar_pessoas() roda.
        if ($match_id && !match_assinado((int)$cadastrado['id'], $match_id, (string)($_POST['sig'] ?? ''))) {
            registrar_log('match_assinatura_invalida', $cadastrado['id'], "POST vincular com match=$match_id nao assinado (evento {$evento['slug']})");
            redirect("/eventos/$slug/$token/pagamento");
            return;
        }

        if ($decisao === 'sim' && $match_id) {
            // NAO consolida aqui: o "sim" so PEDE a fusao, e ela acontece quando
            // o link enviado ao email do cadastro antigo for aberto. Aqui isso
            // pesa mais que na filiacao, porque o fluxo de evento nao tem a
            // trava de campanha fechada — e a pagina do evento e publica.
            require_once SRC_DIR . '/Services/BrevoService.php';

            $antigo = db_fetch_one("SELECT id, nome FROM pessoas WHERE id = ?", [$match_id]);
            $email_antigo = db_fetch_one(
                "SELECT email FROM emails WHERE pessoa_id = ? AND principal = 1 LIMIT 1", [$match_id]
            );

            if (!$antigo || empty($email_antigo['email'])) {
                registrar_log('evento_erro_consolidacao', $cadastrado['id'],
                    "Match $match_id sem email para confirmar (evento {$evento['slug']})");
                flash('error', 'Não foi possível confirmar esse cadastro. Seguindo com cadastro separado.');
            } elseif (self::excedeuPedidoDeFusao((string)$email_antigo['email'], $cadastrado)) {
                // Resposta identica a do caminho normal, para nao denunciar a
                // trava nem revelar se o endereco existe.
                flash('success', 'Enviamos um email para ' . mascarar_email($email_antigo['email'])
                    . ' com um link para confirmar a unificação. Sua inscrição segue normalmente.');
            } else {
                $expira = time() + 86400;
                $sig = assinar_consolidacao((int)$cadastrado['id'], $match_id, $expira);
                $link = BASE_URL . "/eventos/" . rawurlencode($slug) . "/" . rawurlencode($token)
                      . "/confirmar-vinculo?match=$match_id&exp=$expira&sig=$sig";
                try {
                    BrevoService::enviarConfirmacaoVinculo(
                        $email_antigo['email'], (string)$antigo['nome'], $link,
                        'inscrição em ' . $evento['nome']
                    );
                    registrar_log('evento_consolidacao_confirmacao_enviada', $cadastrado['id'],
                        "Pedido de fusao em $match_id (evento {$evento['slug']}): confirmacao enviada ao cadastro antigo");
                    flash('success', 'Enviamos um email para ' . mascarar_email($email_antigo['email'])
                        . ' com um link para confirmar a unificação. Sua inscrição segue normalmente.');
                } catch (Throwable $e) {
                    registrar_log('evento_erro_consolidacao', $cadastrado['id'],
                        "Falha ao enviar confirmacao de fusao em $match_id: " . $e->getMessage());
                    flash('error', 'Não foi possível enviar o email de confirmação. Seguindo com cadastro separado.');
                }
            }
        } else {
            registrar_log('evento_consolidacao_recusada', $cadastrado['id'],
                "Match $match_id recusado, cadastro segue separado (evento {$evento['slug']})");
        }

        redirect("/eventos/$slug/$token/pagamento");
    }

    /**
     * Contexto comum das rotas de pagamento.
     * Retorna [evento, cadastrado, inscricao, categoria] ou null (ja redirecionou).
     */
    private static function contextoPagamento(string $slug, string $token): ?array {
        $evento = buscar_evento_por_slug($slug);
        if (!$evento) { error_404(); return null; }

        $cadastrado = buscar_pessoa_por_token($token);
        if (!$cadastrado) {
            flash('error', 'Link inválido.');
            redirect("/eventos/$slug/inscricao");
            return null;
        }

        $inscricao = buscar_inscricao((int)$cadastrado['id'], (int)$evento['id']);
        if (!$inscricao || empty($inscricao['categoria_id'])) {
            redirect("/eventos/$slug/$token");
            return null;
        }

        $categoria = db_fetch_one("SELECT * FROM evento_categorias WHERE id = ?", [(int)$inscricao['categoria_id']]);

        return [$evento, $cadastrado, $inscricao, $categoria];
    }

    /**
     * Dias de validade da cobranca: 3 dias, mas nunca alem do prazo de inscricao.
     * Piso de 1 dia para nunca gerar uma cobranca ja vencida no PagBank.
     */
    private static function diasCobranca(array $evento): int {
        $dias = 3;
        if (!empty($evento['prazo_inscricao'])) {
            $ate_prazo = (int)floor((strtotime($evento['prazo_inscricao']) - strtotime(date('Y-m-d'))) / 86400);
            $dias = min($dias, $ate_prazo);
        }
        return max(1, $dias);
    }

    /**
     * Descricao da cobranca (aparece na fatura/boleto). Sem acentos, como na filiacao.
     */
    private static function descricaoCobranca(array $evento): string {
        return 'Inscricao ' . $evento['nome'];
    }

    /**
     * Endereco para boleto: prioriza o que foi informado na inscricao,
     * cai para os dados herdados do cadastro da pessoa.
     */
    private static function enderecoCobranca(array $inscricao, array $cadastrado): array {
        $get = function (string $campo) use ($inscricao, $cadastrado) {
            return !empty($inscricao[$campo]) ? $inscricao[$campo] : ($cadastrado[$campo] ?? '');
        };
        $cidade = $get('cidade') ?: 'Nao informado';
        return [
            'street' => $get('endereco') ?: 'Nao informado',
            'number' => 'S/N',
            'locality' => $cidade,
            'city' => $cidade,
            'region_code' => $get('estado') ?: 'DF',
            'postal_code' => str_replace('-', '', $get('cep') ?: '70000000'),
        ];
    }

    /**
     * Registra o pedido PagBank e atualiza a inscricao (nunca sobrescreve paga)
     */
    private static function registrarPedido(int $inscricao_id, string $order_id, string $metodo, array $campos): void {
        // Whitelist: nomes de coluna entram na SQL por interpolacao, entao
        // nunca podem vir de fora deste arquivo.
        $permitidas = [
            'pagbank_order_id', 'pagbank_charge_id', 'pagbank_boleto_link',
            'pagbank_boleto_barcode', 'data_vencimento', 'metodo',
        ];

        $sets = [];
        $vals = [];
        foreach ($campos as $col => $val) {
            if (!in_array($col, $permitidas, true)) {
                throw new InvalidArgumentException("Coluna nao permitida em registrarPedido: $col");
            }
            $sets[] = "$col = ?";
            $vals[] = $val;
        }
        $sets[] = "status = 'pendente'";
        $sets[] = "status_at = ?";
        $vals[] = date('Y-m-d H:i:s');
        $vals[] = $inscricao_id;

        db_execute("
            UPDATE inscricoes SET " . implode(', ', $sets) . "
            WHERE id = ? AND status NOT IN ('pago', 'gratuita_confirmada')
        ", $vals);

        db_insert("
            INSERT INTO pagbank_pedidos (inscricao_id, order_id, metodo)
            VALUES (?, ?, ?)
        ", [$inscricao_id, $order_id, $metodo]);

        // Lembrete de vencimento, um dia antes. Cartao nao tem vencimento:
        // ou aprova na hora, ou nao ha o que lembrar.
        if (!empty($campos['data_vencimento'])) {
            require_once SRC_DIR . '/Services/LembreteService.php';
            LembreteService::agendarVencimentoInscricao($inscricao_id, (string)$campos['data_vencimento']);
        }
    }

    /**
     * A pessoa ja pediu fusao demais, contra este endereco ou desta origem?
     *
     * POR QUE: o "sim" da tela de fusao dispara um email para o endereco do
     * cadastro ANTIGO — que quem clica escolhe, porque o criterio de match
     * inclui NOME EXATO, e o site institucional publica o nome completo dos
     * dirigentes. Sem trava, obtido um token pelo fluxo normal, o POST podia ser
     * repetido a vontade: assedio a terceiro com email legitimo da associacao, e
     * estouro da cota de 300/dia do Brevo — que derruba em silencio os links de
     * acesso, os lembretes e as confirmacoes do dia.
     *
     * Na filiacao o mesmo caminho existe, mas esta atras de campanha aberta. No
     * fluxo de evento nao ha essa trava, e e o que vai ao ar.
     *
     * A prova de posse continua sendo o que impede a fusao indevida; isto
     * limita o PEDIDO.
     */
    private static function excedeuPedidoDeFusao(string $email_destino, array $cadastrado): bool
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '?';

        $excedeu = excedeu_limite_pedidos('consolidacao_pedido', $email_destino, 2)
                || excedeu_limite_pedidos('consolidacao_pedido', $ip, 5);

        if ($excedeu) {
            registrar_log('consolidacao_limite', $cadastrado['id'] ?? null,
                "Limite de pedidos de fusao atingido [$ip]");
            return true;
        }

        registrar_log('consolidacao_pedido', $cadastrado['id'] ?? null,
            "Pedido de fusao para [$email_destino] [$ip]");
        return false;
    }

    /**
     * Abre o link mandado ao email do cadastro ANTIGO e consolida.
     * Espelha FiliacaoController::confirmarVinculo.
     */
    public static function confirmarVinculo(string $slug, string $token): void {
        $evento = buscar_evento_por_slug($slug);
        if (!$evento) { error_404(); return; }

        $cadastrado = buscar_pessoa_por_token($token);
        if (!$cadastrado) {
            flash('error', 'Link inválido.');
            redirect("/eventos/$slug/inscricao");
            return;
        }

        $match_id = (int)($_GET['match'] ?? 0);
        $expira   = (int)($_GET['exp'] ?? 0);
        $sig      = (string)($_GET['sig'] ?? '');

        if (!consolidacao_assinada((int)$cadastrado['id'], $match_id, $expira, $sig)) {
            registrar_log('evento_consolidacao_link_invalido', $cadastrado['id'],
                "Link de confirmacao invalido ou vencido para match=$match_id (evento {$evento['slug']})");
            flash('error', 'Este link de confirmação é inválido ou já venceu. Refaça o pedido no formulário.');
            redirect("/eventos/$slug/$token/pagamento");
            return;
        }

        try {
            consolidar_pessoas($match_id, (int)$cadastrado['id'], (int)date('Y'));
            registrar_log('evento_consolidacao_aceita', $match_id,
                "Cadastro {$cadastrado['id']} consolidado em $match_id (confirmado pelo email do cadastro antigo)");
            flash('success', 'Cadastros unificados. Seu histórico de filiações foi preservado.');
        } catch (Throwable $e) {
            registrar_log('evento_erro_consolidacao', $cadastrado['id'],
                "Falha ao consolidar em $match_id: " . $e->getMessage());
            flash('error', 'Não foi possível unificar os cadastros. Seguindo com cadastro separado.');
        }
        redirect("/eventos/$slug/$token/pagamento");
    }

    /**
     * Recalcula o valor da inscricao pela faixa vigente HOJE.
     *
     * Chamada em todo ponto que cria cobranca NOVA — a tela de pagamento e os
     * tres geradores. Nao e so na tela: ate 30/08/2026 so a tela reavaliava, e
     * ela cria um PIX na primeira visita. A partir dali `pagbank_order_id`
     * existia e a reavaliacao nunca mais rodava, entao **abrir a tela uma vez
     * antes da virada de preco e voltar depois para gerar boleto ou pagar com
     * cartao garantia o valor reduzido, indefinidamente**.
     *
     * O criterio: cobranca que a pessoa JA TEM EM MAOS vale o que foi emitido —
     * mexer nela so criaria divergencia entre o QR e a tela. Cobranca NOVA,
     * emitida agora, vale o preco de agora. A data de virada e do EVENTO, nao
     * da pessoa.
     */
    private static function reavaliarValor(array &$inscricao, array $evento, int $pessoa_id): void {
        if (empty($inscricao['categoria_id'])) return;

        $cat = db_fetch_one("SELECT * FROM evento_categorias WHERE id = ?", [(int)$inscricao['categoria_id']]);
        if (!$cat) return;

        $vigente = valor_vigente_categoria($cat, $evento);
        if ($vigente === (int)$inscricao['valor']) return;

        db_execute(
            "UPDATE inscricoes SET valor = ? WHERE id = ? AND status NOT IN ('pago','gratuita_confirmada')",
            [$vigente, (int)$inscricao['id']]
        );
        registrar_log('valor_reavaliado', $pessoa_id,
            "Inscricao {$inscricao['id']} ({$evento['slug']}): valor passou de {$inscricao['valor']} para $vigente"
            . " (faixa vigente na data de emissao da cobranca)");
        $inscricao['valor'] = $vigente;
    }

    /**
     * Tela de pagamento da inscricao (PIX / Boleto / Cartao)
     */
    /**
     * Inscricao registrada, sem pagamento online: a tesouraria vai combinar.
     *
     * Existe porque o PagBank exige CPF ou CNPJ (conferido em 30/08/2026 na
     * documentacao e nas recusas reais do nosso log). Quem se inscreve com
     * passaporte nao tem como pagar por aqui.
     *
     * A tela **diz o motivo**. Mandar a pessoa para o pagamento e deixa-la
     * tentar o cartao seria desgastante e nao levaria a nada; mas desviar em
     * silencio seria pior — ela acharia que o sistema quebrou, e voltaria a
     * tentar. O que ela precisa entender e que nao chegou ali PORQUE nao tem
     * CPF, e que isso ja esta previsto.
     */
    public static function aguardandoTesouraria(string $slug, string $token): void {
        $ctx = self::contextoPagamento($slug, $token);
        if (!$ctx) return;
        [$evento, $cadastrado, $inscricao, $categoria] = $ctx;

        // Ja pago (a tesouraria lancou): mostra a confirmacao normal.
        if (in_array($inscricao['status'], ['pago', 'gratuita_confirmada'], true)) {
            self::renderConfirmada($evento, $cadastrado, $inscricao);
            return;
        }

        // Passou a ter CPF: o caminho normal voltou a existir.
        if (trim((string)($cadastrado['cpf'] ?? '')) !== '') {
            redirect("/eventos/$slug/$token/pagamento");
            return;
        }

        $identificacao = documento_identificacao(
            $cadastrado['cpf'] ?? '', $cadastrado['documento'] ?? null, $cadastrado['documento_tipo'] ?? null
        );

        $titulo = "Inscrição registrada — " . $evento['nome'];
        ob_start();
        require SRC_DIR . '/Eventos/Views/aguardando_tesouraria.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    public static function pagamento(string $slug, string $token): void {
        require_once SRC_DIR . '/Services/PagBankService.php';

        $ctx = self::contextoPagamento($slug, $token);
        if (!$ctx) return;
        [$evento, $cadastrado, $inscricao, $categoria] = $ctx;

        if (in_array($inscricao['status'], ['pago', 'gratuita_confirmada'])) {
            self::renderConfirmada($evento, $cadastrado, $inscricao);
            return;
        }

        // Sem CPF nao ha o que oferecer aqui: o PagBank recusaria os tres meios.
        // Quem chega por URL guardada ou pelo botao de voltar vai para a tela
        // que EXPLICA o motivo, em vez de ver PIX, boleto e cartao que nao
        // funcionam para ela.
        if ((int)$inscricao['valor'] > 0 && trim((string)($cadastrado['cpf'] ?? '')) === '') {
            redirect("/eventos/$slug/$token/aguardando");
            return;
        }

        // Prazo encerrado: so deixa ver cobranca que ja existe (a pessoa se inscreveu a tempo)
        $abertas = evento_inscricoes_abertas($evento);
        if (!$abertas && empty($inscricao['pagbank_order_id'])) {
            $titulo = "Inscrições encerradas - " . $evento['nome'];
            ob_start();
            require SRC_DIR . '/Eventos/Views/encerrado.php';
            $content = ob_get_clean();
            require SRC_DIR . '/Views/layout.php';
            return;
        }

        // Preco em duas faixas: valor_vigente_categoria() decide pela DATA DE
        // HOJE, mas o valor so era calculado no envio do formulario e ficava
        // congelado em inscricoes.valor. Quem enviasse o formulario na vespera
        // da virada e voltasse para pagar semanas depois pagava o reduzido, sem
        // prazo para caducar — a data e do EVENTO, nao da pessoa.
        //
        // Reavalia aqui, enquanto nao ha cobranca criada. Depois de gerada, o
        // valor esta no QR e mudar so criaria divergencia; nesse caso vale o
        // que a pessoa ja tem em maos.
        if (empty($inscricao['pagbank_order_id'])) {
            self::reavaliarValor($inscricao, $evento, (int)$cadastrado['id']);
        }

        $valor_centavos = (int)$inscricao['valor'];
        $pix_data = null;
        $boleto_data = null;
        $erro_pagbank = null;

        if (empty($inscricao['pagbank_order_id'])) {
            // Primeira visita: ja gera o PIX (mesmo comportamento da filiacao)
            try {
                $pix_data = PagBankService::criarCobrancaPix(
                    PagBankService::referenciaInscricao((int)$inscricao['id']),
                    self::descricaoCobranca($evento),
                    $cadastrado['nome'],
                    $cadastrado['email'],
                    $cadastrado['cpf'] ?? null,
                    $valor_centavos,
                    self::diasCobranca($evento)
                );

                self::registrarPedido((int)$inscricao['id'], $pix_data['order_id'], 'pix', [
                    'pagbank_order_id' => $pix_data['order_id'],
                    'data_vencimento' => $pix_data['expiration_date'],
                    'metodo' => 'pix',
                ]);

                registrar_log('inscricao_pix_gerado', $cadastrado['id'],
                    "PIX da inscricao {$inscricao['id']} ({$evento['slug']}): " . $pix_data['order_id']);

            } catch (Exception $e) {
                // A tela recebe a frase em portugues; o LOG recebe o detalhe
                // tecnico do PagBank, com o campo recusado. Sao duas leituras
                // diferentes, e a mesma variavel nas duas perderia a que serve
                // para diagnosticar — o log e a unica saida do servidor.
                $erro_pagbank = PagBankService::mensagemParaPessoa($e);
                registrar_log('erro_pagbank', $cadastrado['id'],
                    "Erro ao criar PIX da inscricao {$inscricao['id']}: " . $e->getMessage());
            }
        } else {
            // Ja existe pedido: busca o QR Code atual
            try {
                $order_data = PagBankService::consultarPedido($inscricao['pagbank_order_id']);
                $qr_codes = $order_data['qr_codes'] ?? [];
                if (!empty($qr_codes)) {
                    $qr = $qr_codes[0];
                    $pix_data = [
                        'order_id' => $inscricao['pagbank_order_id'],
                        'qr_code' => $qr['text'] ?? '',
                        'qr_code_link' => !empty($qr['links']) ? $qr['links'][0]['href'] : '',
                        'expiration_date' => $inscricao['data_vencimento'],
                    ];
                }
            } catch (Exception $e) {
                // Este ramo consulta pedido que JA existe. Ate 30/08/2026 nao
                // registrava nada: a pessoa via o erro na tela e o servidor
                // ficava sem lembranca nenhuma de que a consulta falhou.
                $erro_pagbank = PagBankService::mensagemParaPessoa($e);
                registrar_log('erro_pagbank', $cadastrado['id'],
                    "Erro ao consultar PIX ja gerado da inscricao {$inscricao['id']}: " . $e->getMessage());
            }
        }

        if (!empty($inscricao['pagbank_boleto_link'])) {
            $boleto_data = [
                'boleto_link' => $inscricao['pagbank_boleto_link'],
                'barcode' => $inscricao['pagbank_boleto_barcode'] ?? '',
                'due_date' => $inscricao['data_vencimento'] ?? '',
            ];
        }

        $pagbank_public_key = PagBankService::obterChavePublica();
        $valor_formatado = formatar_valor($valor_centavos);
        $inscricoes_abertas = $abertas;

        $titulo = "Pagamento - " . $evento['nome'];
        ob_start();
        require SRC_DIR . '/Eventos/Views/pagamento.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Gera nova cobranca PIX para a inscricao
     */
    public static function gerarPix(string $slug, string $token): void {
        require_once SRC_DIR . '/Services/PagBankService.php';

        $ctx = self::contextoPagamento($slug, $token);
        if (!$ctx) return;
        [$evento, $cadastrado, $inscricao] = $ctx;

        if (in_array($inscricao['status'], ['pago', 'gratuita_confirmada']) || !evento_inscricoes_abertas($evento)) {
            redirect("/eventos/$slug/$token/pagamento");
            return;
        }

        // Cobranca NOVA vale o preco de hoje. Sem isto, abrir a tela de
        // pagamento antes da virada e voltar depois congelava o valor reduzido.
        self::reavaliarValor($inscricao, $evento, (int)$cadastrado['id']);

        try {
            $pix_data = PagBankService::criarCobrancaPix(
                PagBankService::referenciaInscricao((int)$inscricao['id']),
                self::descricaoCobranca($evento),
                $cadastrado['nome'],
                $cadastrado['email'],
                $cadastrado['cpf'] ?? null,
                (int)$inscricao['valor'],
                self::diasCobranca($evento)
            );

            self::registrarPedido((int)$inscricao['id'], $pix_data['order_id'], 'pix', [
                'pagbank_order_id' => $pix_data['order_id'],
                'data_vencimento' => $pix_data['expiration_date'],
                'metodo' => 'pix',
                'pagbank_boleto_link' => null,
                'pagbank_boleto_barcode' => null,
            ]);

            registrar_log('inscricao_pix_gerado', $cadastrado['id'],
                "PIX da inscricao {$inscricao['id']} ({$evento['slug']}): " . $pix_data['order_id']);

        } catch (Exception $e) {
            registrar_log('erro_pagbank', $cadastrado['id'],
                "Erro ao criar PIX da inscricao {$inscricao['id']}: " . $e->getMessage());
            flash('error', 'Não foi possível gerar o PIX. Tente novamente.');
        }

        redirect("/eventos/$slug/$token/pagamento");
    }

    /**
     * Gera boleto para a inscricao
     */
    public static function gerarBoleto(string $slug, string $token): void {
        require_once SRC_DIR . '/Services/PagBankService.php';

        $ctx = self::contextoPagamento($slug, $token);
        if (!$ctx) return;
        [$evento, $cadastrado, $inscricao] = $ctx;

        if (in_array($inscricao['status'], ['pago', 'gratuita_confirmada']) || !evento_inscricoes_abertas($evento)) {
            redirect("/eventos/$slug/$token/pagamento");
            return;
        }

        // Cobranca NOVA vale o preco de hoje. Sem isto, abrir a tela de
        // pagamento antes da virada e voltar depois congelava o valor reduzido.
        self::reavaliarValor($inscricao, $evento, (int)$cadastrado['id']);

        try {
            $boleto_data = PagBankService::criarCobrancaBoleto(
                PagBankService::referenciaInscricao((int)$inscricao['id']),
                self::descricaoCobranca($evento),
                $cadastrado['nome'],
                $cadastrado['email'],
                $cadastrado['cpf'] ?? null,
                (int)$inscricao['valor'],
                self::enderecoCobranca($inscricao, $cadastrado),
                self::diasCobranca($evento)
            );

            self::registrarPedido((int)$inscricao['id'], $boleto_data['order_id'], 'boleto', [
                'pagbank_order_id' => $boleto_data['order_id'],
                'pagbank_charge_id' => $boleto_data['charge_id'],
                'pagbank_boleto_link' => $boleto_data['boleto_link'],
                'pagbank_boleto_barcode' => $boleto_data['barcode'],
                'data_vencimento' => $boleto_data['due_date'],
                'metodo' => 'boleto',
            ]);

            registrar_log('inscricao_boleto_gerado', $cadastrado['id'],
                "Boleto da inscricao {$inscricao['id']} ({$evento['slug']}): " . $boleto_data['order_id']);

        } catch (Exception $e) {
            registrar_log('erro_pagbank', $cadastrado['id'],
                "Erro ao criar boleto da inscricao {$inscricao['id']}: " . $e->getMessage());
            flash('error', 'Não foi possível gerar o boleto. Confira se o endereço está completo.');
        }

        redirect("/eventos/$slug/$token/pagamento");
    }

    /**
     * Paga a inscricao com cartao de credito
     */
    public static function pagarCartao(string $slug, string $token): void {
        require_once SRC_DIR . '/Services/PagBankService.php';

        $ctx = self::contextoPagamento($slug, $token);
        if (!$ctx) return;
        [$evento, $cadastrado, $inscricao] = $ctx;

        if (in_array($inscricao['status'], ['pago', 'gratuita_confirmada']) || !evento_inscricoes_abertas($evento)) {
            redirect("/eventos/$slug/$token/pagamento");
            return;
        }

        $card_encrypted = $_POST['card_encrypted'] ?? '';
        $holder_name = $_POST['holder_name'] ?? '';

        if (empty($card_encrypted) || empty($holder_name)) {
            flash('error', 'Dados do cartão incompletos.');
            redirect("/eventos/$slug/$token/pagamento");
            return;
        }

        // Cobranca NOVA vale o preco de hoje — ver reavaliarValor().
        self::reavaliarValor($inscricao, $evento, (int)$cadastrado['id']);

        try {
            $cartao_data = PagBankService::criarCobrancaCartao(
                PagBankService::referenciaInscricao((int)$inscricao['id']),
                self::descricaoCobranca($evento),
                $cadastrado['nome'],
                $cadastrado['email'],
                $cadastrado['cpf'] ?? null,
                (int)$inscricao['valor'],
                $card_encrypted,
                $holder_name
            );

            self::registrarPedido((int)$inscricao['id'], $cartao_data['order_id'], 'cartao', [
                'pagbank_order_id' => $cartao_data['order_id'],
                'pagbank_charge_id' => $cartao_data['charge_id'],
                'metodo' => 'cartao',
            ]);

            if ($cartao_data['status'] === 'PAID') {
                $agora = date('Y-m-d H:i:s');
                $rows = db_execute("
                    UPDATE inscricoes SET status = 'pago', data_pagamento = ?, status_at = ?
                    WHERE id = ? AND status NOT IN ('pago', 'gratuita_confirmada')
                ", [$agora, $agora, (int)$inscricao['id']]);

                if ($rows > 0) {
                    registrar_log('inscricao_paga', $cadastrado['id'],
                        "Inscricao {$inscricao['id']} ({$evento['slug']}) paga com cartao: " . $cartao_data['order_id']);

                    require_once SRC_DIR . '/Services/LembreteService.php';
                    LembreteService::cancelarInscricao((int)$inscricao['id']);

                    require_once SRC_DIR . '/Controllers/WebhookController.php';
                    WebhookController::processarInscricaoConfirmada((int)$inscricao['id']);
                }

                $inscricao = buscar_inscricao((int)$cadastrado['id'], (int)$evento['id']);
                self::renderConfirmada($evento, $cadastrado, $inscricao);
                return;
            }

            registrar_log('inscricao_cartao_pendente', $cadastrado['id'],
                "Cartao pendente/recusado na inscricao {$inscricao['id']}: " . $cartao_data['status']);
            flash('error', 'Pagamento com cartão recusado. Verifique os dados ou use PIX/boleto.');

        } catch (Exception $e) {
            registrar_log('erro_pagbank', $cadastrado['id'],
                "Erro ao processar cartao da inscricao {$inscricao['id']}: " . $e->getMessage());
            flash('error', 'Erro ao processar pagamento. Tente novamente.');
        }

        redirect("/eventos/$slug/$token/pagamento");
    }

    /**
     * Renderiza o recibo/confirmação de inscrição
     */
    private static function renderConfirmada(array $evento, array $cadastrado, array $inscricao): void {
        $categoria = $inscricao['categoria_id']
            ? db_fetch_one("SELECT nome FROM evento_categorias WHERE id = ?", [(int)$inscricao['categoria_id']])
            : null;
        $titulo = "Inscrição Confirmada - " . $evento['nome'];
        ob_start();
        require SRC_DIR . '/Eventos/Views/confirmada.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }
}
