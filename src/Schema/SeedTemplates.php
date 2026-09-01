<?php
/**
 * Pilotis — Conteudo inicial dos templates de email.
 *
 * E semeadura de dados, nao estrutura — mas roda junto da migracao e so ali,
 * entao mora ao lado dela.
 *
 * Extraido de src/db.php em 29/08/2026. O db.php continua existindo e
 * incluindo este arquivo, entao todo require antigo segue valendo.
 */

/**
 * Seed de templates padrão
 */
function seed_email_templates(PDO $db): void {
    $header = "<div style='background-color: " . ORG_COR_PRIMARIA . "; padding: 20px; text-align: center;'><h1 style='color: white; margin: 0;'>{{titulo}}</h1></div>";
    $footer_links = '';
    if (ORG_SITE_URL) {
        $site_display = preg_replace('#^https?://(www\.)?#', '', ORG_SITE_URL);
        $footer_links .= "<a href='" . ORG_SITE_URL . "' style='color: white;'>$site_display</a>";
    }
    if (ORG_INSTAGRAM) {
        if ($footer_links) $footer_links .= ' · ';
        $footer_links .= "<a href='https://www.instagram.com/" . ORG_INSTAGRAM . "' style='color: white;'>@" . ORG_INSTAGRAM . "</a>";
    }
    $footer_content = ORG_NOME . ($footer_links ? "<br>$footer_links" : '');
    $footer = "<div style='padding: 15px; background-color: " . ORG_COR_PRIMARIA . "; color: white; text-align: center; font-size: 12px;'>$footer_content</div>";
    $wrap = fn($titulo, $body) => "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>" . str_replace('{{titulo}}', $titulo, $header) . "<div style='padding: 20px; background-color: #f9f9f9;'>$body</div>$footer</div>";
    $btn = fn($texto, $var) => "<p style='text-align: center; margin: 30px 0;'><a href='{{" . $var . "}}' style='background-color: " . ORG_COR_PRIMARIA . "; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px;'>$texto</a></p>";

    $templates = [
        [
            'tipo' => 'confirmacao',
            'assunto' => 'Filiação ' . ORG_NOME . ' {{ano}} - Confirmada!',
            'descricao' => 'Enviado após confirmação de pagamento',
            'variaveis' => 'nome, ano, categoria, valor',
            'html' => $wrap('Filiação Confirmada!',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>Sua filiação ao <strong>" . ORG_NOME . "</strong> para o ano de <strong>{{ano}}</strong> está confirmada!</p>" .
                "<table style='width: 100%; border-collapse: collapse; margin: 20px 0;'><tr><td style='padding: 10px; border-bottom: 1px solid #ddd;'><strong>Categoria:</strong></td><td style='padding: 10px; border-bottom: 1px solid #ddd;'>{{categoria}}</td></tr><tr><td style='padding: 10px; border-bottom: 1px solid #ddd;'><strong>Valor:</strong></td><td style='padding: 10px; border-bottom: 1px solid #ddd;'>{{valor}}</td></tr></table>" .
                "<p>Em anexo, enviamos sua declaração de filiação.</p>" .
                "<p>Obrigado por fazer parte do " . ORG_NOME . "!</p>"
            ),
        ],
        [
            'tipo' => 'lembrete',
            'assunto' => '{{urgencia}}Filiação ' . ORG_NOME . ' {{ano}} - Pagamento Pendente',
            'descricao' => 'Lembrete de pagamento pendente',
            'variaveis' => 'nome, ano, valor, link, urgencia, dias_info',
            'html' => $wrap('{{urgencia}}Lembrete de Pagamento',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>Identificamos que sua filiação ao " . ORG_NOME . " para {{ano}} ainda está pendente de pagamento.</p>" .
                "<p><strong>Valor:</strong> {{valor}}</p>" .
                "<p>{{dias_info}}</p>" .
                $btn('Realizar Pagamento', 'link') .
                "<p><small>Se já realizou o pagamento, por favor desconsidere este email.</small></p>"
            ),
        ],
        [
            'tipo' => 'lembrete_vencido',
            'assunto' => 'Filiação ' . ORG_NOME . ' {{ano}} - Pagamento Expirado',
            'descricao' => 'Aviso de que boleto/PIX expirou e precisa gerar novo',
            'variaveis' => 'nome, ano, valor, link',
            'html' => $wrap('Pagamento Expirado',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>O prazo do seu pagamento (boleto ou PIX) para a filiação ao <strong>" . ORG_NOME . "</strong> {{ano}} expirou.</p>" .
                "<p>Não se preocupe — basta acessar o link abaixo e gerar um novo pagamento:</p>" .
                "<p><strong>Valor:</strong> {{valor}}</p>" .
                $btn('Gerar Novo Pagamento', 'link') .
                "<p><small>Se já realizou o pagamento por outro meio, por favor desconsidere este email.</small></p>"
            ),
        ],
        [
            'tipo' => 'ultima_chance',
            'assunto' => 'Última chance! Filiação ' . ORG_NOME . ' {{ano}} encerra em {{dias}} dias',
            'descricao' => 'Lembrete final antes do encerramento da campanha',
            'variaveis' => 'nome, ano, dias, data_fim, link',
            'html' => $wrap('Última Chance!',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>A campanha de filiação ao <strong>" . ORG_NOME . "</strong> para {{ano}} encerra em <strong>{{dias}} dias</strong> ({{data_fim}}).</p>" .
                "<p>Não perca a oportunidade de fazer parte da nossa rede!</p>" .
                $btn('Filiar-se Agora', 'link') .
                "<p><small>Se já realizou sua filiação, por favor desconsidere este email.</small></p>"
            ),
        ],
        [
            'tipo' => 'ultima_chance_internacional',
            'assunto' => 'Prazo para filiação internacional encerra em {{dias}} dias — ' . ORG_NOME . ' {{ano}}',
            'descricao' => 'Lembrete de prazo internacional (enviado a todos não pagos)',
            'variaveis' => 'nome, ano, dias, data_fim, link',
            'html' => $wrap('Prazo Internacional',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>O prazo para a categoria <strong>Internacional</strong> da filiação ao <strong>" . ORG_NOME . "</strong> {{ano}} encerra em <strong>{{dias}} dias</strong> ({{data_fim}}).</p>" .
                "<p>Após essa data, estarão disponíveis apenas as categorias <strong>Nacional</strong> e <strong>Estudante</strong>.</p>" .
                "<p>Se você deseja se filiar na categoria Internacional, aproveite para concluir agora!</p>" .
                $btn('Filiar-se Agora', 'link') .
                "<p><small>Se já realizou sua filiação, por favor desconsidere este email.</small></p>"
            ),
        ],
        [
            'tipo' => 'lembrete_acesso',
            'assunto' => 'Complete sua filiação - ' . ORG_NOME . ' {{ano}}',
            'descricao' => 'Lembrete para quem acessou o formulário mas não concluiu',
            'variaveis' => 'nome, ano, link',
            'html' => $wrap('Complete sua Filiação',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>Notamos que você iniciou sua filiação ao <strong>" . ORG_NOME . "</strong> para {{ano}}, mas ainda não concluiu o processo.</p>" .
                "<p>Leva apenas alguns minutos para preencher o formulário e escolher a forma de pagamento.</p>" .
                $btn('Continuar Filiação', 'link') .
                "<p><small>Se já concluiu sua filiação, por favor desconsidere este email.</small></p>"
            ),
        ],
        [
            'tipo' => 'renovacao',
            'assunto' => 'Renove sua Filiação - ' . ORG_NOME . ' {{ano}}',
            'descricao' => 'Campanha para filiados de anos anteriores',
            'variaveis' => 'nome, ano, link, prazo',
            'html' => $wrap('Renove sua Filiação',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>É hora de renovar sua filiação ao " . ORG_NOME . "!</p>" .
                "<p><strong>Benefícios da filiação:</strong></p>" .
                "<ul><li>Descontos em eventos do " . ORG_NOME . " e núcleos regionais</li><li>Acesso à rede de profissionais e pesquisadores</li><li>Para internacional: " . ORG_SIGLA . " Journal, Member Card, descontos em museus</li></ul>" .
                "{{prazo}}" .
                $btn('Renovar Filiação', 'link')
            ),
        ],
        [
            'tipo' => 'convite',
            'assunto' => 'Convite para Filiação - ' . ORG_NOME . ' {{ano}}',
            'descricao' => 'Campanha para novos contatos',
            'variaveis' => 'nome, ano, link, prazo',
            'html' => $wrap('Convite para Filiação',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>Gostaríamos de convidar você a se filiar ao <strong>" . ORG_NOME . "</strong>!</p>" .
                "<p>O " . ORG_NOME . " é uma organização dedicada à documentação e conservação do patrimônio moderno.</p>" .
                "<p><strong>Benefícios da filiação:</strong></p>" .
                "<ul><li>Descontos em eventos do " . ORG_NOME . " e núcleos regionais</li><li>Acesso à rede de profissionais e pesquisadores</li><li>Participação nas atividades e publicações</li></ul>" .
                "{{prazo}}" .
                $btn('Filiar-se Agora', 'link')
            ),
        ],
        [
            'tipo' => 'seminario',
            'assunto' => 'Filiação ' . ORG_NOME . ' {{ano}} - Participante do Seminário',
            'descricao' => 'Campanha para participantes do seminário',
            'variaveis' => 'nome, ano, link, prazo',
            'html' => $wrap('Filiação ' . ORG_NOME,
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>Obrigado por sua participação no <strong>seminário do " . ORG_NOME . "</strong>!</p>" .
                "<p>Convidamos você a se filiar ao " . ORG_NOME . " e fortalecer nossa rede de documentação e conservação da arquitetura, urbanismo e paisagismo modernos.</p>" .
                "{{prazo}}" .
                $btn('Filiar-se Agora', 'link')
            ),
        ],
        [
            'tipo' => 'acesso',
            'assunto' => 'Acesso à Filiação ' . ORG_NOME . ' {{ano}}',
            'descricao' => 'Link de acesso ao formulário (segurança)',
            'variaveis' => 'nome, ano, link',
            'html' => $wrap('Acesso à Filiação',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>Você solicitou acesso ao formulário de filiação do <strong>" . ORG_NOME . "</strong> para o ano de <strong>{{ano}}</strong>.</p>" .
                "<p>Clique no botão abaixo para acessar seu formulário:</p>" .
                $btn('Acessar Formulário', 'link') .
                "<p><small>Se você não solicitou este acesso, ignore este email.</small></p>" .
                "<p><small>Este link é pessoal e intransferível.</small></p>"
            ),
        ],
        [
            'tipo' => 'evento_acesso',
            'assunto' => 'Inscrição — {{evento}}',
            'descricao' => 'Link de acesso ao formulário de inscrição em evento',
            'variaveis' => 'nome, evento, link',
            'html' => $wrap('Inscrição em Evento',
                // "Completar Inscricao" prometia o fim quando este e o comeco:
                // depois do clique ainda vem o formulario inteiro, a categoria,
                // as vezes o comprovante de matricula, e o pagamento. Quem le
                // "completar" acha que falta um passo, deixa para depois, e o
                // link e o unico caminho de volta. O texto agora diz o que vem.
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>Você pediu para se inscrever no evento <strong>{{evento}}</strong>.</p>" .
                "<p>No formulário você informa seus dados, escolhe a categoria e " .
                "faz o pagamento. Leva alguns minutos.</p>" .
                $btn('Preencher minha inscrição', 'link') .
                "<p><small>Se você não solicitou esta inscrição, ignore este email.</small></p>" .
                "<p><small>Este link é pessoal e intransferível.</small></p>"
            ),
        ],
        [
            // Convite nominal, disparado pela tesouraria a partir da lista de
            // liberados de uma categoria restrita. Diferente do evento_acesso:
            // ali a pessoa pediu o link; aqui ela esta sendo convidada, e nao
            // pediu nada — o texto precisa dizer de onde isso veio.
            'tipo' => 'evento_convite',
            'assunto' => 'Convite — {{evento}}',
            'descricao' => 'Convite com link pronto para categoria restrita (isentos, palestrantes)',
            'variaveis' => 'nome, evento, categoria, link',
            'html' => $wrap('Convite',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>A organização do <strong>{{evento}}</strong> reservou sua inscrição na categoria " .
                "<strong>{{categoria}}</strong>.</p>" .
                "<p>Para confirmar, é só abrir o formulário pelo botão abaixo e conferir seus dados:</p>" .
                $btn('Confirmar minha inscrição', 'link') .
                "<p><small>Este link é pessoal e já vem com sua categoria reservada — " .
                "não precisa informar CPF nem email para acessá-lo.</small></p>"
            ),
        ],
        [
            'tipo' => 'evento_lembrete_vencimento',
            'assunto' => 'Seu pagamento vence amanhã — {{evento}}',
            'descricao' => 'Lembrete de vencimento de PIX/boleto de inscricao em evento',
            'variaveis' => 'nome, evento, valor, link',
            'html' => $wrap('Pagamento vence amanhã',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>O pagamento da sua inscrição no <strong>{{evento}}</strong>, " .
                "no valor de <strong>{{valor}}</strong>, vence <strong>amanhã</strong>.</p>" .
                $btn('Pagar agora', 'link') .
                "<p><small>Se já pagou, ignore este email — a confirmação pode levar algumas " .
                "horas, e o boleto até dois dias úteis.</small></p>"
            ),
        ],
        [
            'tipo' => 'evento_lembrete_incompleta',
            'assunto' => 'Sua inscrição está incompleta — {{evento}}',
            'descricao' => 'Lembrete de inscricao em evento comecada e nao concluida',
            'variaveis' => 'nome, evento, prazo, link',
            'html' => $wrap('Inscrição incompleta',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>Você começou sua inscrição no <strong>{{evento}}</strong>, mas ela ainda " .
                "não foi concluída.</p>" .
                "<p>As inscrições vão até <strong>{{prazo}}</strong>.</p>" .
                $btn('Continuar minha inscrição', 'link') .
                "<p><small>Se mudou de ideia, não precisa fazer nada.</small></p>"
            ),
        ],
        [
            'tipo' => 'painel_acesso',
            'assunto' => 'Acesso ao acompanhamento — {{evento}}',
            'descricao' => 'Link de acesso ao painel de inscritos (organização do evento)',
            'variaveis' => 'evento, link',
            'html' => $wrap('Acompanhamento do evento',
                "<p>Você pediu acesso ao acompanhamento das inscrições do " .
                "<strong>{{evento}}</strong>.</p>" .
                $btn('Abrir o acompanhamento', 'link') .
                "<p><small>O link vale por 30 minutos e abre uma sessão de 12 horas.</small></p>" .
                "<p><small>A lista traz dados pessoais de quem se inscreveu, confiados a você para a " .
                "organização do evento. Não encaminhe este email nem a lista.</small></p>" .
                "<p><small>Se não foi você que pediu, ignore — o acesso só vale para quem recebeu " .
                "este email.</small></p>"
            ),
        ],
        [
            'tipo' => 'evento_confirmacao',
            'assunto' => 'Inscrição confirmada — {{evento}}',
            'descricao' => 'Confirmação de inscrição em evento (paga ou gratuita)',
            'variaveis' => 'nome, evento, categoria, valor',
            'html' => $wrap('Inscrição Confirmada',
                "<p>Olá <strong>{{nome}}</strong>,</p>" .
                "<p>Sua inscrição no evento <strong>{{evento}}</strong> está confirmada!</p>" .
                "<p>Categoria: <strong>{{categoria}}</strong><br>" .
                "Valor: <strong>{{valor}}</strong></p>" .
                "<p>Guarde este email como comprovante da sua inscrição.</p>"
            ),
        ],
    ];

    // Comprovante de inscricao em evento (PDF). As assinaturas NAO ficam aqui:
    // vem do campo "assinantes" do proprio evento, porque mudam a cada evento.
    $templates[] = [
        'tipo' => 'evento_comprovante',
        'assunto' => 'Comprovante de inscrição — {{evento}}',
        'descricao' => 'Texto do comprovante de inscrição em PDF (assinaturas vêm do evento)',
        // {{documento}} JA VEM COM A VIRGULA na frente (", CPF 000.000.000-00"
        // ou ", Passaporte XX0000000") e fica VAZIO quando nao ha documento.
        // E o unico jeito de a frase sair certa nos dois casos, porque template
        // nao tem condicional: com "CPF {{cpf}}" fixo no texto, filiado
        // estrangeiro saia "CPF ," e, depois que o documento passou a existir,
        // "CPF Passaporte XX0000000".
        // {{cpf}} continua valendo — so o numero — para template ja editado.
        // {{quando_onde}} traz a frase pronta, com <strong>: "O evento é
        // presencial, e acontece em 12 e 13 de novembro de 2026, no IAB-RJ,
        // Rua ...". Vai no comprovante porque as pessoas o usam para pedir
        // DISPENSA DE PONTO no trabalho — sem data, local e modalidade o papel
        // prova que alguem se inscreveu, e nao que precisou se deslocar.
        'variaveis' => 'nome, documento (já traz a vírgula; vazio se não houver), cpf, evento, categoria, quando_onde (frase pronta com data, local e modalidade), valor, data_pagamento, metodo',
        'html' => "<p>Declaramos para os devidos fins que <strong>{{nome}}</strong>{{documento}}" .
            " está inscrito(a) no evento <strong>{{evento}}</strong>, " .
            "na categoria <strong>{{categoria}}</strong>.</p>" .
            "{{quando_onde}}" .
            "<p>Inscrição no valor de <strong>{{valor}}</strong>, com pagamento " .
            "confirmado em <strong>{{data_pagamento}}</strong> por <strong>{{metodo}}</strong>.</p>",
    ];

    // Template da declaração PDF
    $templates[] = [
        'tipo' => 'declaracao',
        'assunto' => 'Declaração de Filiação {{ano}}',
        'descricao' => 'Texto da declaração PDF enviada ao filiado',
        'variaveis' => 'nome, ano, categoria, valor',
        'html' => "<p>Declaramos para os devidos fins que <strong>{{nome}}</strong> " .
            "é filiado(a) ao <strong>" . ORG_NOME . "</strong> na categoria <strong>{{categoria}}</strong>, " .
            "com anuidade de <strong>{{valor}}</strong> referente ao ano de <strong>{{ano}}</strong>, " .
            "devidamente quitada.</p>" .
            "<p>O " . ORG_NOME . " é uma organização dedicada à documentação e conservação do patrimônio moderno.</p>" .
            "<p style='margin-top: 60px; text-align: center;'>" .
            "<strong>Marta Peixoto</strong><br>" .
            "Coordenadora do " . ORG_NOME . "<br>" .
            "Gestão 2026-2027</p>",
    ];

    $stmt = $db->prepare("INSERT OR IGNORE INTO email_templates (tipo, assunto, html, descricao, variaveis) VALUES (?, ?, ?, ?, ?)");
    foreach ($templates as $t) {
        $stmt->execute([$t['tipo'], $t['assunto'], $t['html'], $t['descricao'], $t['variaveis']]);
    }

    // `descricao` e `variaveis` sao ATUALIZADAS sempre, e o `html` nunca.
    //
    // Sao coisas de natureza diferente, e o INSERT OR IGNORE acima tratava as
    // tres igual. O `html` e do tesoureiro: editavel pelo /admin, e sobrescrever
    // o que ele escreveu seria pior do que qualquer defeito. As outras duas sao
    // DOCUMENTACAO do codigo — a lista que a tela mostra de quais {{variaveis}}
    // aquele template aceita. Nao ha como edita-las pelo /admin (ver
    // Campanha/Views/admin/templates.php, que so as exibe), e em banco ja
    // semeado elas ficavam congeladas na versao do dia em que a linha nasceu:
    // {{documento}} e {{quando_onde}} passaram a funcionar no comprovante e a
    // tela continuava sem menciona-los. Documentacao que envelhece em silencio
    // e pior do que documentacao nenhuma — quem le confia.
    $meta = $db->prepare("UPDATE email_templates SET descricao = ?, variaveis = ? WHERE tipo = ?");
    foreach ($templates as $t) {
        $meta->execute([$t['descricao'], $t['variaveis'], $t['tipo']]);
    }
}
