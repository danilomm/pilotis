<article>
    <h2>Filiação <?= e($ano) ?></h2>

    <?php if ($pagamento_existente && $pagamento_existente['status'] === 'pago'): ?>
        <div class="alert alert-success">
            Sua filiação para <?= e($ano) ?> já está confirmada!
        </div>
        <p>Para alterações em seus dados, entre em contato com a tesouraria.</p>
    </article>
    <?php return; ?>
    <?php endif; ?>

    <?php if ($pagamento_existente && $pagamento_existente['status'] === 'pendente'): ?>
        <div class="alert alert-warning">
            Você tem um pagamento pendente. <a href="/filiacao/<?= e($ano) ?>/<?= e($token) ?>/pagamento">Clique aqui para pagar</a>.
        </div>
    <?php endif; ?>

    <form method="POST" action="/filiacao/<?= e($ano) ?>/<?= e($token) ?>" enctype="multipart/form-data"><?= campo_csrf() ?>

        <p><small>* Campos obrigatórios</small></p>

        <fieldset>
            <legend>Dados Pessoais</legend>

            <label for="nome">Nome Completo *</label>
            <input type="text" id="nome" autocomplete="name" name="nome" value="<?= e($cadastrado['nome'] ?? '') ?>" required>

            <label for="email">Email *</label>
            <input type="email" id="email" autocomplete="email" name="email" value="<?= e($cadastrado['email'] ?? '') ?>" required>

            <label for="cpf">CPF *</label>
            <input type="text" id="cpf" autocomplete="off" name="cpf" value="<?= e($cadastrado['cpf'] ?? '') ?>" placeholder="000.000.000-00" required>

            <label for="telefone">Telefone *</label>
            <input type="tel" id="telefone" autocomplete="tel" name="telefone" value="<?= e($cadastrado['telefone'] ?? '') ?>" placeholder="(00) 00000-0000" required>
        </fieldset>

        <fieldset>
            <legend>Endereço para Correspondência *</legend>
            <small style="display: block; margin-bottom: 1rem; color: var(--muted-color);">
                Informe o endereço onde você deseja receber revistas, livros e outras publicações da <?= e(ORG_SIGLA) ?>.
                Se você mora em local sem portaria ou com horário restrito, considere informar um endereço alternativo.
            </small>

            <label for="endereco">Endereço (rua, número, complemento) *</label>
            <input type="text" id="endereco" autocomplete="street-address" name="endereco" value="<?= e($cadastrado['endereco'] ?? '') ?>" required>

            <div class="grid">
                <div>
                    <label for="cep">CEP *</label>
                    <input type="text" id="cep" name="cep" value="<?= e($cadastrado['cep'] ?? '') ?>" placeholder="00000-000" required>
                </div>
                <div>
                    <label for="cidade">Cidade *</label>
                    <input type="text" id="cidade" autocomplete="address-level2" name="cidade" value="<?= e($cadastrado['cidade'] ?? '') ?>" list="cidades-list" required>
                </div>
            </div>

            <div class="grid">
                <div>
                    <label for="estado">Estado (UF) *</label>
                    <input type="text" id="estado" autocomplete="address-level1" name="estado" value="<?= e($cadastrado['estado'] ?? '') ?>" list="estados-list" maxlength="2" placeholder="XX" required>
                </div>
                <div>
                    <label for="pais">País *</label>
                    <input type="text" id="pais" autocomplete="country-name" name="pais" value="<?= e($cadastrado['pais'] ?? 'Brasil') ?>" required>
                </div>
            </div>
        </fieldset>

        <fieldset>
            <legend>Dados Profissionais</legend>

            <label for="profissao">Profissão *</label>
            <input type="text" id="profissao" name="profissao" value="<?= e($cadastrado['profissao'] ?? '') ?>" list="profissoes-list" required>

            <label for="formacao">Formação</label>
            <select id="formacao" name="formacao">
                <option value="">Selecione...</option>
                <?php foreach (FORMACOES as $f): ?>
                    <option value="<?= e($f) ?>" <?= ($cadastrado['formacao'] ?? '') === $f ? 'selected' : '' ?>><?= e($f) ?></option>
                <?php endforeach; ?>
            </select>

            <label for="instituicao">Instituição</label>
            <input type="text" id="instituicao" autocomplete="organization" name="instituicao" value="<?= e($cadastrado['instituicao'] ?? '') ?>" list="instituicoes-list" placeholder="Se mais de uma, separe por vírgula">
        </fieldset>

        <fieldset>
            <legend>Categoria de Filiação *</legend>

            <?php foreach ($categorias as $cat): ?>
                <label>
                    <input type="radio" name="categoria" value="<?= e($cat['valor']) ?>"
                           <?= $cat['selecionada'] ? 'checked' : '' ?> required
                           onchange="toggleComprovante()">
                    <?= e($cat['label']) ?>
                </label>
            <?php endforeach; ?>
        </fieldset>

        <fieldset id="fieldset-comprovante" style="display: none;">
            <legend>Comprovante de Matrícula *</legend>
            <small style="display: block; margin-bottom: 1rem; color: var(--muted-color);">
                Envie um comprovante de matrícula em instituição de ensino (graduação ou pós-graduação).
                Formatos aceitos: PDF, JPG, PNG. Tamanho máximo: 5MB.
            </small>
            <input type="file" id="comprovante" name="comprovante" accept=".pdf,.jpg,.jpeg,.png">
            <?php if (!empty($comprovante_existente)): ?>
                <small style="color: green;">✓ Comprovante já enviado anteriormente.</small>
            <?php endif; ?>
        </fieldset>

        <script>
        function toggleComprovante() {
            var categoria = document.querySelector('input[name="categoria"]:checked');
            var fieldset = document.getElementById('fieldset-comprovante');
            var input = document.getElementById('comprovante');
            if (categoria && categoria.value === 'estudante') {
                fieldset.style.display = 'block';
                <?php if (empty($comprovante_existente)): ?>
                input.required = true;
                <?php endif; ?>
            } else {
                fieldset.style.display = 'none';
                input.required = false;
            }
        }
        // Roda ao carregar a página
        document.addEventListener('DOMContentLoaded', toggleComprovante);
        </script>

        <?php
        // Aviso de CPF invalido NO CAMPO, enquanto a pessoa ainda esta olhando
        // para ele. O servidor tambem confere (FiliacaoController) — isto aqui
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


        <fieldset>
            <legend>Observações</legend>
            <label for="observacoes_filiado">Algo que queira nos informar?</label>
            <textarea id="observacoes_filiado" name="observacoes_filiado" rows="3"><?= e($cadastrado['observacoes_filiado'] ?? '') ?></textarea>
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


        <button type="submit">Continuar para Pagamento</button>

    </form>

    <!-- Datalists para autocomplete -->
    <datalist id="instituicoes-list">
        <?php foreach ($autocomplete['instituicoes'] ?? [] as $inst): ?>
            <option value="<?= e($inst) ?>">
        <?php endforeach; ?>
    </datalist>

    <datalist id="cidades-list">
        <?php foreach ($autocomplete['cidades'] ?? [] as $cidade): ?>
            <option value="<?= e($cidade) ?>">
        <?php endforeach; ?>
    </datalist>

    <datalist id="estados-list">
        <?php foreach ($autocomplete['estados'] ?? [] as $estado): ?>
            <option value="<?= e($estado) ?>">
        <?php endforeach; ?>
    </datalist>

    <datalist id="profissoes-list">
        <?php foreach ($autocomplete['profissoes'] ?? [] as $profissao): ?>
            <option value="<?= e($profissao) ?>">
        <?php endforeach; ?>
    </datalist>
</article>
