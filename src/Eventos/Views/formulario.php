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
    <?php if ($tem_cadastro_previo): ?>
        <div class="alert alert-success" style="padding: 12px; background: #d4edda; color: #155724; border-radius: 6px; margin-bottom: 16px;">
            Alguns campos já vêm preenchidos com o que temos registrado.
            Confira e corrija o que estiver desatualizado.
        </div>
    <?php endif; ?>

    <form method="POST" action="/eventos/<?= e($evento['slug']) ?>/<?= e($token) ?>" enctype="multipart/form-data"><?= campo_csrf() ?>

        <fieldset>
            <legend>Categoria de inscrição *</legend>

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
            ?>

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
                    <summary><small><?= $adimplente
                        ? 'Ver as demais categorias'
                        : 'Ver as categorias de filiado' ?></small></summary>

                    <p style="margin: .6rem 0;"><small style="color: var(--pico-muted-color);">
                        <?php if ($adimplente): ?>
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
                                   disabled
                                   <?= ((int)($inscricao['categoria_id'] ?? 0) === (int)$cat['id']) ? 'checked' : '' ?>>
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
            <input type="text" id="nome" autocomplete="name" name="nome" value="<?= e($pre['nome'] ?? '') ?>" required>

            <label for="nome_cracha">Nome no crachá</label>
            <input type="text" id="nome_cracha" name="nome_cracha" maxlength="40"
                   value="<?= e($pre['nome_cracha'] ?? palpite_nome_cracha($pre['nome'] ?? '')) ?>">
            <small>Como você quer ser chamado(a) no evento. Já vem preenchido — corrija se quiser.</small>

            <label for="email">Email *</label>
            <input type="email" id="email" autocomplete="email" name="email" value="<?= e($pre['email'] ?? '') ?>" required>

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
            <input type="tel" id="telefone" autocomplete="tel" name="telefone" value="<?= e($pre['telefone'] ?? '') ?>" placeholder="(00) 00000-0000">
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

            <label for="instituicao">Instituição</label>
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
            <small style="display: block; margin-bottom: 1rem;">
                PDF, JPG ou PNG, máximo 5MB.
            </small>
            <input type="file" id="comprovante" name="comprovante" accept=".pdf,.jpg,.jpeg,.png">
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

        // Campos obrigatórios de inscrição paga
        var pagos = ['cpf', 'telefone', 'endereco', 'cep', 'cidade', 'estado', 'pais'];
        pagos.forEach(function(id) {
            document.getElementById(id).required = valor > 0;
        });

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
        document.getElementById('cpf-req').style.display = mostra;
        document.querySelectorAll('.req-pago').forEach(function (el) {
            el.style.display = mostra;
        });

        btn.textContent = valor > 0 ? 'Continuar para pagamento' : 'Confirmar inscrição gratuita';
    }
    document.addEventListener('DOMContentLoaded', atualizarForm);

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
