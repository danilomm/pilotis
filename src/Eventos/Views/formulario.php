<article>
    <h2>Inscrição — <?= e($evento['nome']) ?></h2>

    <?php
    // A frase e sobre POR QUE OS CAMPOS VEM PREENCHIDOS, e nao sobre quem a
    // pessoa e.
    //
    // Ate 31/08/2026 dizia "Encontramos seu cadastro no Docomomo Brasil", e
    // isso estava errado de duas maneiras. Primeira: boa parte de quem tem
    // cadastro nunca se filiou nem se cadastrou — 258 pessoas vieram da lista
    // de um seminario e 222 de contatos importados. Elas nao reconhecem esse
    // vinculo, e a frase afirma um. Segunda: `nome` passa a existir assim que a
    // pessoa preenche o formulario UMA vez, entao na segunda visita alguem
    // totalmente novo lia "encontramos seu cadastro" sobre dados que ela mesma
    // acabara de digitar.
    ?>
    <?php
    // Sai de cena quando o formulario voltou por recusa: ali os campos vem do
    // que a PESSOA acabou de digitar, e nao do cadastro. Dizer "com o que temos
    // registrado" logo abaixo do erro mandaria conferir a fonte errada.
    ?>
    <?php if ($tem_cadastro_previo && empty($repovoado)): ?>
        <div class="alert alert-success" style="padding: 12px; background: #d4edda; color: #155724; border-radius: 6px; margin-bottom: 16px;">
            Alguns campos já vêm preenchidos com o que temos registrado.
            Confira e corrija o que estiver desatualizado.
        </div>
    <?php endif; ?>

    <form method="POST" action="/eventos/<?= e($evento['slug']) ?>/<?= e($token) ?>" enctype="multipart/form-data"><?= campo_csrf() ?>

        <fieldset>
            <legend>Categoria de inscrição *</legend>

            <?php
            // O prazo aparece AQUI, e nao so na tela de entrada: quem chega
            // pelo link do email nunca passou por ela, e e nesta tela que a
            // pessoa decide. Mesma frase, mesma funcao — prazo escrito em dois
            // lugares diverge no dia em que um dos dois muda.
            $prazo_frase = prazo_inscricao_frase($evento);
            ?>
            <?php if ($prazo_frase !== ''): ?>
                <p style="margin: 0 0 .8rem;"><small style="color: var(--pico-muted-color);">
                    <?= e($prazo_frase) ?>
                </small></p>
            <?php endif; ?>

            <?php
            // Com a adimplencia ja conferida, a lista se divide: o que vale
            // para esta pessoa fica a vista; o resto vai para um <details>.
            // Nada e removido — quem quiser conferir o que existe, abre.
            $principais = [];
            $secundarias = [];
            foreach ($categorias as $cat) {
                // Categoria restrita so chegou aqui porque o CPF desta pessoa
                // esta na lista: ela e para ela, e fica sempre a vista.
                if (categoria_restrita($cat)) {
                    $principais[] = $cat;
                    continue;
                }
                // Marcada como independente de filiacao, vale para os dois.
                if (!empty($cat['independe_filiacao'])) {
                    $principais[] = $cat;
                    continue;
                }
                $so_filiado = !empty($cat['verifica_adimplencia']);
                if ($adimplente ? !$so_filiado : $so_filiado) {
                    $secundarias[] = $cat;
                } else {
                    $principais[] = $cat;
                }
            }
            // Sem categoria aplicavel (evento so para filiados, pessoa nao
            // filiada, por exemplo), mostra tudo em vez de tela vazia.
            if (empty($principais)) {
                $principais = $categorias;
                $secundarias = [];
            }

            // ISENCAO MANDA EM TUDO: quem tem uma categoria restrita e GRATUITA
            // ve so ela a vista, e o resto vai para o <details>.
            //
            // Sem isto, um isento que tambem e filiado adimplente via TRES
            // opcoes escolhiveis lado a lado — "Professor/Pesquisador filiado
            // R$ 200", "Estudante de Pos filiado R$ 100" e "Isento". Aconteceu
            // com o tesoureiro na propria inscricao dele, em 04/09/2026.
            //
            // Oferecer cobranca a quem a organizacao decidiu isentar nao e
            // neutralidade: e convite a errar, e quem erra PAGA. O sistema ja
            // sabe qual e a categoria dela; a escolha nao deveria estar em pe
            // de igualdade com as outras.
            //
            // As demais nao somem — descem para o <details>, como toda categoria
            // indisponivel nesta tela, e vao DESABILITADAS junto com ele. Ou
            // seja: quem e isento nao consegue escolher categoria paga por
            // aqui. E deliberado, e nao um efeito colateral: a isencao e
            // decisao da organizacao, e o formulario a cumpre. Isento que
            // queira pagar assim mesmo fala com a tesouraria — caso raro que
            // nao justifica deixar a armadilha na tela de todo mundo.
            //
            // O servidor ACEITARIA uma categoria paga dele, e essa folga entre
            // tela e servidor e conhecida: aqui ela fecha para o lado seguro,
            // que e nao cobrar quem nao deve pagar.
            $isencao = array_values(array_filter(
                $principais,
                fn(array $c) => categoria_restrita($c) && (int)valor_vigente_categoria($c, $evento) === 0
            ));
            if ($isencao) {
                $resto = array_values(array_filter(
                    $principais,
                    fn(array $c) => !(categoria_restrita($c) && (int)valor_vigente_categoria($c, $evento) === 0)
                ));
                $secundarias = array_merge($resto, $secundarias);
                $principais = $isencao;
            }
            ?>

            <?php if (!empty($isencao)): ?>
                <p style="margin: 0 0 .8rem;"><small style="color: var(--pico-muted-color);">
                    Sua inscrição é isenta — a organização do evento já a indicou.
                    Não há nada a pagar.
                </small></p>
            <?php endif; ?>

            <?php if ($adimplente && $principais !== $categorias): ?>
                <p style="margin: 0 0 .8rem;"><small style="color: var(--pico-muted-color);">
                    Sua anuidade está em dia — abaixo estão as categorias com desconto de filiado.
                </small></p>
            <?php endif; ?>

            <?php foreach ($principais as $cat): ?>
                <?php $v = valor_vigente_categoria($cat, $evento); ?>
                <!-- flex + <span>: o <small> dentro do <label> do Pico virava
                     bloco e montava em cima da linha de cima. -->
                <label style="display: flex; align-items: baseline; gap: .5rem; margin-bottom: .7rem;">
                    <input type="radio" name="categoria_id" value="<?= (int)$cat['id'] ?>" required
                           data-valor="<?= $v ?>"
                           data-comprovante="<?= (int)$cat['requer_comprovante'] ?>"
                           onchange="atualizarForm()"
                           style="margin: 0; flex: 0 0 auto;"
                           <?= ((int)($inscricao['categoria_id'] ?? 0) === (int)$cat['id']) ? 'checked' : '' ?>>
                    <span>
                        <?= e($cat['nome']) ?> — <?= $v > 0 ? formatar_valor($v) : 'Gratuita' ?>
                        <?php if ($cat['verifica_adimplencia'] && !$adimplente): ?>
                            <?php // Para quem ja e adimplente isso repete a linha acima. ?>
                            <br><small style="color: var(--pico-muted-color);">para filiados com anuidade em dia — conferido pelo CPF</small>
                        <?php endif; ?>
                        <?php if ($cat['requer_comprovante']): ?>
                            <br><small style="color: var(--pico-muted-color);">exige comprovante de matrícula</small>
                        <?php endif; ?>
                    </span>
                </label>
            <?php endforeach; ?>

            <?php if (!empty($secundarias)): ?>
                <details style="margin-top: .4rem;">
                    <summary><small><?php
                        if (!empty($isencao))      echo 'Ver as categorias pagas';
                        elseif ($adimplente)       echo 'Ver as demais categorias';
                        else                       echo 'Ver as categorias de filiado';
                    ?></small></summary>

                    <p style="margin: .6rem 0;"><small style="color: var(--pico-muted-color);">
                        <?php if (!empty($isencao)): ?>
                            Estas são as categorias pagas do evento. Você não precisa de nenhuma
                            delas: sua inscrição é isenta. Ficam aqui só para constar.
                        <?php elseif ($adimplente): ?>
                            Estas são as categorias de preço cheio, para quem não é filiado. Como sua anuidade
                            está em dia, seu preço é o de filiado — por isso elas ficam indisponíveis.
                        <?php else: ?>
                            Estas exigem anuidade <?= (int)evento_ano_referencia($evento) ?> em dia, conferida pelo CPF.
                            Não encontramos filiação paga para o seu cadastro — se achar que houve engano, escreva para
                            <a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a>.
                        <?php endif; ?>
                    </small></p>

                    <?php foreach ($secundarias as $cat): ?>
                        <?php $v = valor_vigente_categoria($cat, $evento); ?>
                        <label style="display: flex; align-items: baseline; gap: .5rem; margin-bottom: .7rem; opacity: .55;">
                            <input type="radio" name="categoria_id" value="<?= (int)$cat['id'] ?>"
                                   data-valor="<?= $v ?>"
                                   data-comprovante="<?= (int)$cat['requer_comprovante'] ?>"
                                   onchange="atualizarForm()"
                                   style="margin: 0; flex: 0 0 auto;"
                                   disabled>
                            <?php
                            // Sem `checked`. Ate 31/08/2026 a categoria
                            // indisponivel saia `disabled` E `checked` quando ja
                            // estava gravada na inscricao: o `:checked` fazia o
                            // JS trata-la como escolhida e a validacao do grupo
                            // passar, mas controle desabilitado NAO e enviado —
                            // o formulario ia sem `categoria_id` e voltava
                            // "Escolha uma categoria", sem dizer qual, com a
                            // marcada escondida dentro do <details> fechado.
                            ?>
                            <span>
                                <?= e($cat['nome']) ?> — <?= $v > 0 ? formatar_valor($v) : 'Gratuita' ?>
                                <?php if ($cat['requer_comprovante']): ?>
                                    <br><small style="color: var(--pico-muted-color);">exige comprovante de matrícula</small>
                                <?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </details>
            <?php endif; ?>
        </fieldset>

        <fieldset>
            <legend>Dados pessoais</legend>

            <label for="nome">Nome completo *</label>
            <input type="text" id="nome" autocomplete="name" name="nome" value="<?= e($pre['nome'] ?? '') ?>" required
                   minlength="5" maxlength="60"
                   title="Nome completo, entre 5 e 60 caracteres.">
            <?php
            // 5 e 60 sao do PagBank, nao nossos: `customer.name` fora
            // dessa faixa faz a cobranca voltar 400 ("size must be
            // between 5 and 60"), e o erro so apareceria na tela de
            // pagamento, depois de o formulario inteiro ter sido
            // aceito. Aqui a pessoa ve no campo. O maior nome da base
            // tem 50 caracteres, entao o teto nao corta ninguem.
            ?>

            <label for="nome_cracha">Nome no crachá</label>
            <input type="text" id="nome_cracha" name="nome_cracha" maxlength="40"
                   value="<?= e($pre['nome_cracha'] ?? palpite_nome_cracha($pre['nome'] ?? '')) ?>">
            <small>Como você quer ser chamado(a) no evento. Já vem preenchido — corrija se quiser.</small>

            <?php
            // SOMENTE LEITURA, e a dica diz por que.
            //
            // Ate 31/08/2026 o campo era editavel e obrigatorio — e o que a
            // pessoa escrevesse era DESCARTADO: `$email_form` e lido no
            // controller e usado num lugar so, para procurar cadastro antigo.
            // Nao ha coluna `email` em `inscricoes`, o UPDATE de `pessoas` nao
            // toca em email, e nada escreve na tabela `emails`.
            //
            // Quem corrigisse um endereco desatualizado saia convencida de que
            // corrigiu, e a confirmacao, o comprovante e os lembretes
            // continuavam indo para o endereco velho — que e tambem o que vai
            // para o PagBank.
            //
            // Trocar de email nao e coisa de formulario de inscricao: e
            // identidade, o link de acesso foi enviado PARA ele, e
            // `emails.email` e UNIQUE. Passa pela tesouraria.
            ?>
            <label for="email">Email</label>
            <input type="email" id="email" autocomplete="email" name="email"
                   value="<?= e($pre['email'] ?? '') ?>" readonly>
            <small>É para este endereço que vão a confirmação e o comprovante — é o mesmo
            que recebeu o link desta página. Para trocar, escreva para
            <a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a>.</small>

            <label for="cpf">CPF <span id="cpf-req">*</span></label>
            <input type="text" id="cpf" autocomplete="off" name="cpf" value="<?= e(formatar_cpf($pre['cpf'] ?? '')) ?>"
                   placeholder="000.000.000-00" inputmode="numeric">
            <small>Obrigatório para inscrições pagas (exigência do meio de pagamento).</small>

            <?php
            // Quem nao tem CPF usa outro documento. Bloco FECHADO por padrao: e
            // a excecao, e quem precisa dele clica.
            //
            // Ate 31/08/2026 ele abria tambem quando o campo de CPF estava
            // vazio, e isso confundia CAMPO VAZIO com NAO TER CPF. Campo vazio
            // e o estado normal de toda pessoa nova — brasileira, com CPF, que
            // so ainda nao digitou. Ela abria o formulario com o campo de
            // passaporte escancarado, sugerindo um caminho que nao e o dela.
            //
            // So abre sozinho quando JA HA documento gravado: ai a pessoa (ou a
            // tesouraria) ja usou esse caminho, e esconder o dado seria pior.
            //
            // Isto NAO substitui o CPF para pagar: o PagBank exige CPF ou CNPJ.
            // Quem se inscrever assim tem a inscricao registrada como pendente e
            // a tesouraria combina o pagamento por fora. O aviso esta na tela,
            // para ninguem descobrir isso depois de preencher tudo.
            $tem_doc = trim((string)($pre['documento'] ?? '')) !== '';
            ?>
            <details id="bloco-documento" <?= $tem_doc ? 'open' : '' ?> style="margin: .2rem 0 1rem;">
                <summary style="cursor: pointer;"><small>Não tenho CPF brasileiro</small></summary>

                <div class="grid" style="margin-top: .6rem;">
                    <div>
                        <label for="documento">Documento</label>
                        <input type="text" id="documento" name="documento"
                               value="<?= e($pre['documento'] ?? '') ?>" placeholder="número do passaporte">
                    </div>
                    <div>
                        <label for="documento_tipo">Tipo</label>
                        <input type="text" id="documento_tipo" name="documento_tipo" list="tipos-documento"
                               value="<?= e($pre['documento_tipo'] ?? '') ?>" placeholder="passaporte">
                        <datalist id="tipos-documento">
                            <option value="passaporte">
                            <option value="RNM">
                            <option value="DNI">
                            <option value="NIE">
                        </datalist>
                    </div>
                </div>
                <small id="aviso-sem-cpf">Preenchendo aqui, sua inscrição fica registrada e
                pendente de pagamento: o pagamento online só aceita CPF, então a
                tesouraria entra em contato com você para combinar. Você recebe a confirmação
                por email quando o pagamento for lançado.</small>
            </details>

            <label for="telefone">Telefone <span class="req-pago">*</span></label>
            <?php
            // `type="tel"` NAO garante teclado numerico — em parte dos
            // navegadores ele so muda o autofill. O `inputmode` e o que troca o
            // teclado do celular, e a maioria se inscreve pelo celular. O CPF e
            // o CEP ja tinham; o telefone passou batido.
            ?>
            <input type="tel" id="telefone" autocomplete="tel" name="telefone" value="<?= e($pre['telefone'] ?? '') ?>"
                   placeholder="(00) 00000-0000" inputmode="tel">
        </fieldset>

        <fieldset id="fs-endereco">
            <legend>Endereço <span class="req-pago">*</span></legend>

            <label for="endereco">Endereço (rua, número, complemento)</label>
            <input type="text" id="endereco" autocomplete="street-address" name="endereco" value="<?= e($pre['endereco'] ?? '') ?>">

            <div class="grid">
                <div>
                    <label for="cep">CEP</label>
                    <input type="text" id="cep" name="cep" value="<?= e($pre['cep'] ?? '') ?>"
                           placeholder="00000-000" inputmode="numeric" autocomplete="postal-code">
                </div>
                <div>
                    <label for="cidade">Cidade</label>
                    <input type="text" id="cidade" autocomplete="address-level2" name="cidade" value="<?= e($pre['cidade'] ?? '') ?>">
                </div>
            </div>
            <div class="grid">
                <div>
                    <label for="estado">Estado (UF)</label>
                    <input type="text" id="estado" autocomplete="address-level1" name="estado" value="<?= e($pre['estado'] ?? '') ?>" maxlength="2" placeholder="XX">
                </div>
                <div>
                    <label for="pais">País</label>
                    <input type="text" id="pais" autocomplete="country-name" name="pais" value="<?= e($pre['pais'] ?? 'Brasil') ?>">
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Dados profissionais</legend>

            <label for="profissao">Profissão</label>
            <input type="text" id="profissao" name="profissao" value="<?= e($pre['profissao'] ?? '') ?>">

            <?php
            // O label aponta para o SELECT, que e o campo visivel. Ate
            // 31/08/2026 apontava para o `instituicao`, que fica escondido ate
            // a pessoa escolher "Outra": o leitor de tela anunciava
            // "Instituição" para um campo oculto e chegava ao select sem nome
            // nenhum. E havia dois labels para o mesmo id, o de baixo tambem.
            ?>
            <label for="instituicao_escolha">Instituição</label>
            <?php
            $inst_atual = $pre['instituicao'] ?? '';
            $inst_na_lista = $inst_atual !== '' && in_array($inst_atual, $instituicoes ?? [], true);
            ?>
            <select id="instituicao_escolha" name="instituicao_escolha">
                <option value="">— selecione —</option>
                <?php foreach (($instituicoes ?? []) as $i): ?>
                    <option value="<?= e($i) ?>" <?= $inst_atual === $i ? 'selected' : '' ?>><?= e($i) ?></option>
                <?php endforeach; ?>
                <option value="__outra" <?= ($inst_atual !== '' && !$inst_na_lista) ? 'selected' : '' ?>>
                    Outra — não está na lista
                </option>
            </select>

            <div id="instituicao_outra_bloco" style="<?= ($inst_atual !== '' && !$inst_na_lista) ? '' : 'display:none;' ?>">
                <label for="instituicao">Qual?</label>
                <input type="text" id="instituicao" autocomplete="organization" name="instituicao"
                       maxlength="30" placeholder="FAU-USP, PROPAR-UFRGS, UFBA"
                       value="<?= $inst_na_lista ? '' : e($inst_atual) ?>">
                <small>Use a sigla, no formato <strong>UNIDADE-UNIVERSIDADE</strong> — como
                FAU-USP, IAU-USP, PROPAR-UFRGS. Só a sigla da universidade também serve.</small>
            </div>

            <script>
            (function () {
                var sel = document.getElementById('instituicao_escolha');
                var bloco = document.getElementById('instituicao_outra_bloco');
                sel.addEventListener('change', function () {
                    bloco.style.display = (sel.value === '__outra') ? '' : 'none';
                });
            })();
            </script>
        </fieldset>

        <fieldset id="fs-comprovante" style="display: none;">
            <legend>Comprovante de matrícula *</legend>
            <?php
            // O teto REAL e o menor entre o nosso (5 MB, em
            // salvar_comprovante_evento) e o `upload_max_filesize` do PHP, que
            // costuma ser 2 MB e corta ANTES de o codigo rodar. Afirmar 5 MB
            // fazia a pessoa descobrir o limite verdadeiro depois de subir a
            // foto, provavelmente no celular e no 4G.
            $teto_php = (int)ini_get('upload_max_filesize');
            $teto = $teto_php > 0 ? min(5, $teto_php) : 5;
            ?>
            <small style="display: block; margin-bottom: 1rem;">
                PDF, JPG ou PNG, máximo <?= (int)$teto ?>MB.
            </small>
            <?php
            // O `<legend>` da o contexto do bloco, mas nao nomeia o campo: sem
            // este label o leitor de tela chega a um "escolher arquivo" sem
            // dizer arquivo de que.
            ?>
            <label for="comprovante">Arquivo do comprovante</label>
            <input type="file" id="comprovante" name="comprovante" accept=".pdf,.jpg,.jpeg,.png">
            <?php if (!empty($repovoado) && empty($comprovante_existente)): ?>
                <?php
                // O texto voltou da tentativa anterior; o arquivo nao volta —
                // navegador nenhum repovoa <input type=file>. Dizer isso evita
                // a pessoa enviar de novo achando que o anexo continua ali.
                ?>
                <small style="color: var(--pico-del-color);">Escolha o arquivo de novo: o
                anexo não é guardado entre as tentativas.</small>
            <?php endif; ?>
            <?php if (!empty($comprovante_existente)): ?>
                <small style="color: green;">✓ Comprovante já enviado anteriormente.</small>
            <?php endif; ?>
        </fieldset>
        <?php
        // Consentimento: o que fica GRAVADO e a versao do texto e a data
        // (POLITICA_PRIVACIDADE_VERSAO). Sem isso, a pergunta "essas pessoas
        // concordaram com o que?" nao tem resposta — e ela ja foi feita neste
        // projeto: quem preencheu os formularios de 2015 a 2024 nunca soube que
        // a lista viraria pagina publica, e ela existiu sete meses.
        //
        // Marcado quando a pessoa JA consentiu nesta mesma versao: refazer o
        // aceite a cada visita nao acrescenta nada e vira ruido. Versao nova
        // reabre a caixa, que e o ponto de versionar.
        $ja_consentiu = ($pre_consentimento ?? '') === POLITICA_PRIVACIDADE_VERSAO;
        ?>
        <fieldset style="margin-top: 1.4rem;">
            <label for="consentimento" style="font-weight: normal;">
                <input type="checkbox" id="consentimento" name="consentimento" value="1"
                       <?= $ja_consentiu ? 'checked' : '' ?> required>
                Li e concordo com o <a href="/privacidade" target="_blank" rel="noopener">aviso
                de privacidade</a>: quais dados são coletados, para que servem e quem os vê.
            </label>
        </fieldset>


        <button type="submit" id="btn-continuar">Continuar</button>
    </form>

    <script>
    function atualizarForm() {
        var sel = document.querySelector('input[name="categoria_id"]:checked');
        var fsComprovante = document.getElementById('fs-comprovante');
        var inputComp = document.getElementById('comprovante');
        var btn = document.getElementById('btn-continuar');
        if (!sel) return;

        var valor = parseInt(sel.getAttribute('data-valor'));
        var precisaComprovante = sel.getAttribute('data-comprovante') === '1';

        // Comprovante
        fsComprovante.style.display = precisaComprovante ? 'block' : 'none';
        inputComp.required = precisaComprovante && <?= !empty($comprovante_existente) ? 'false' : 'true' ?>;

        // Campos obrigatórios de inscrição paga.
        //
        // O CPF fica de FORA desta lista: ele e o unico que tem substituto. O
        // servidor aceita CPF *ou* documento estrangeiro (EventosController, na
        // checagem de categoria paga), e ate 31/08/2026 o navegador nao sabia
        // disso: marcava `#cpf` como required e nada o desmarcava quando a
        // pessoa preenchia o passaporte.
        //
        // O efeito era o pior possivel — a tela /aguardando, o aviso a
        // tesouraria e o botao "lancar pago" existiam, funcionavam, e NINGUEM
        // chegava neles: o balao nativo do navegador apontava para o campo que a
        // pessoa nao tem como preencher, sem mensagem nossa e sem log.
        //
        // Passou despercebido porque o caminho foi testado por POST direto, que
        // fala com o servidor. Quem recusava era o cliente.
        var pagos = ['telefone', 'endereco', 'cep', 'cidade', 'estado', 'pais'];
        pagos.forEach(function(id) {
            document.getElementById(id).required = valor > 0;
        });

        atualizarIdentificacao(valor > 0);

        // E o ASTERISCO acompanha. As classes `req-pago` e `cpf-req` existiam no
        // HTML desde sempre para isto e nada as tocava: quem escolhia categoria
        // gratuita continuava vendo "CPF *", "Telefone *", "Endereço *" em
        // campos que nao eram exigidos dele. O `required` estava certo; o
        // asterisco e que mentia.
        //
        // Antes de escolher categoria nada e mexido (o `return` acima), entao a
        // marcacao conservadora do HTML e o que aparece — e ela se corrige no
        // primeiro clique, que e o primeiro campo do formulario.
        var mostra = valor > 0 ? '' : 'none';
        document.querySelectorAll('.req-pago').forEach(function (el) {
            el.style.display = mostra;
        });

        btn.textContent = valor > 0 ? 'Continuar para pagamento' : 'Confirmar inscrição gratuita';
    }

    /**
     * CPF e documento se substituem: um preenchido dispensa o outro.
     *
     * Espelha a regra do servidor, que exige identificacao — CPF OU documento —
     * so em categoria paga. O asterisco do CPF acompanha: com documento
     * preenchido ele deixa de ser obrigatorio, e dizer o contrario seria a
     * mesma mentira que o `required` era.
     */
    function atualizarIdentificacao(categoriaPaga) {
        var cpf = document.getElementById('cpf');
        var doc = document.getElementById('documento');
        var req = document.getElementById('cpf-req');
        if (!cpf) return;

        var temDoc = doc && doc.value.trim() !== '';
        cpf.required = categoriaPaga && !temDoc;
        if (req) req.style.display = cpf.required ? '' : 'none';
    }

    document.addEventListener('DOMContentLoaded', function () {
        atualizarForm();
        var doc = document.getElementById('documento');
        // Digitar o documento tem de liberar o CPF na hora, e nao so na proxima
        // troca de categoria: quem abre o bloco ja escolheu a categoria antes.
        if (doc) doc.addEventListener('input', function () {
            var sel = document.querySelector('input[name="categoria_id"]:checked');
            atualizarIdentificacao(sel ? parseInt(sel.getAttribute('data-valor')) > 0 : false);
        });
    });

    // Mesma mascara da tela de entrada: a pessoa digita so numeros e o campo
    // pontua sozinho.
    (function () {
        var cpf = document.getElementById('cpf');
        if (!cpf) return;
        cpf.addEventListener('input', function () {
            var d = cpf.value.replace(/\D/g, '').slice(0, 11);
            var out = d;
            if (d.length > 9)      out = d.slice(0,3) + '.' + d.slice(3,6) + '.' + d.slice(6,9) + '-' + d.slice(9);
            else if (d.length > 6) out = d.slice(0,3) + '.' + d.slice(3,6) + '.' + d.slice(6);
            else if (d.length > 3) out = d.slice(0,3) + '.' + d.slice(3);
            cpf.value = out;
        });
    })();
    </script>

        <?php
        // Aviso de CPF invalido NO CAMPO, enquanto a pessoa ainda esta olhando
        // para ele. O servidor tambem confere (EventosController) — isto aqui
        // e para a pessoa nao chegar ao pagamento com o numero errado, que foi
        // o que produziu 30 recusas do PagBank em producao, uma delas com 10
        // tentativas em um minuto. O erro vinha em ingles, na tela seguinte,
        // sem dizer que era o CPF.
        ?>
        <script>
        (function () {
            var cpf = document.getElementById('cpf');
            if (!cpf) return;

            var aviso = document.createElement('small');
            aviso.style.color = 'var(--pico-del-color, #b00)';
            aviso.style.display = 'none';
            aviso.textContent = 'Esse CPF não confere. Verifique os números.';
            cpf.parentNode.insertBefore(aviso, cpf.nextSibling);

            function valido(d) {
                if (d.length !== 11 || /^(\d)\1{10}$/.test(d)) return false;
                for (var n = 9; n <= 10; n++) {
                    var soma = 0;
                    for (var i = 0; i < n; i++) soma += parseInt(d[i], 10) * ((n + 1) - i);
                    var dv = (soma * 10) % 11;
                    if (dv === 10) dv = 0;
                    if (dv !== parseInt(d[n], 10)) return false;
                }
                return true;
            }

            function conferir() {
                var d = cpf.value.replace(/\D/g, '');
                // Enquanto digita, so avisa quando ja ha 11 numeros: acusar aos
                // 3 digitos seria ruido, e o campo ficaria vermelho o tempo todo.
                var ruim = d.length === 11 && !valido(d);
                aviso.style.display = ruim ? 'block' : 'none';
                cpf.setAttribute('aria-invalid', ruim ? 'true' : 'false');
                if (!ruim && d.length !== 11) cpf.removeAttribute('aria-invalid');
            }

            cpf.addEventListener('input', conferir);
            cpf.addEventListener('blur', conferir);
            conferir();
        })();
        </script>
</article>
