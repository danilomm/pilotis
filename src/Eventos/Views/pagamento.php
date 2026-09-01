<?php
$extra_head = '<script src="https://assets.pagseguro.com.br/checkout-sdk-js/rc/dist/browser/pagseguro.min.js"></script>';
$base = "/eventos/" . rawurlencode($slug) . "/" . rawurlencode($token);
?>
<article>
    <h2>Pagamento — <?= e($evento['nome']) ?></h2>

    <p><strong>Nome:</strong> <?= e($cadastrado['nome']) ?></p>
    <?php if (!empty($categoria['nome'])): ?>
        <p><strong>Categoria:</strong> <?= e($categoria['nome']) ?></p>
    <?php endif; ?>
    <p><strong>Valor:</strong> <?= e($valor_formatado) ?></p>

    <?php if (empty($inscricoes_abertas)): ?>
        <div class="alert alert-warning" style="padding: 12px; background: #fff3cd; color: #856404; border-radius: 6px;">
            O prazo de inscrição encerrou, mas a cobrança gerada antes do prazo continua válida.
            Não é possível gerar uma nova.
        </div>
    <?php endif; ?>

    <?php if ($erro_pagbank): ?>
        <div class="alert alert-error">
            Erro ao gerar pagamento: <?= e($erro_pagbank) ?>
        </div>
    <?php endif; ?>

    <!-- Abas de método de pagamento -->
    <div class="tabs" style="margin: 20px 0;">
        <input type="radio" name="tab" id="tab-pix" checked>
        <label for="tab-pix" style="cursor: pointer; padding: 10px 20px; border: 1px solid #ddd; margin-right: -1px;">PIX</label>

        <input type="radio" name="tab" id="tab-boleto">
        <label for="tab-boleto" style="cursor: pointer; padding: 10px 20px; border: 1px solid #ddd; margin-right: -1px;">Boleto</label>

        <input type="radio" name="tab" id="tab-cartao">
        <label for="tab-cartao" style="cursor: pointer; padding: 10px 20px; border: 1px solid #ddd;">Cartão</label>
    </div>

    <style>
        .tabs input[type="radio"] { display: none; }
        .tabs label { display: inline-block; background: #f5f5f5; }
        .tabs input:checked + label { background: <?= ORG_COR_PRIMARIA ?>; color: white; }
        .tab-content { display: none; padding: 20px; border: 1px solid #ddd; margin-top: -1px; }
        #tab-pix:checked ~ #content-pix,
        #tab-boleto:checked ~ #content-boleto,
        #tab-cartao:checked ~ #content-cartao { display: block; }
    </style>

    <!-- PIX -->
    <div id="content-pix" class="tab-content" style="display: block;">
        <?php if ($pix_data && !empty($pix_data['qr_code'])): ?>
            <div class="text-center">
                <h3>Escaneie o QR Code</h3>
                <?php if (!empty($pix_data['qr_code_link'])): ?>
                    <img src="<?= e($pix_data['qr_code_link']) ?>" alt="QR Code PIX" style="width: 100%; max-width: 300px; height: auto;">
                <?php endif; ?>

                <p><strong>Ou copie o código PIX:</strong></p>
                <textarea id="codigo-pix" readonly style="width: 100%; height: 100px; font-family: monospace; font-size: 12px;" onclick="this.select()"><?= e($pix_data['qr_code']) ?></textarea>
                <button type="button" class="secondary" style="width: auto;"
                        onclick="copiarTexto('codigo-pix', this)">Copiar código PIX</button>

                <p><small>Válido até: <?= e($pix_data['expiration_date']) ?></small></p>
                <p style="margin-top: 15px; padding: 10px; background: #e8f5e9; border-radius: 6px; color: #2e7d32;">
                    Após o pagamento, você receberá um email de confirmação da inscrição em até 30 minutos.
                </p>
            </div>
        <?php elseif (!empty($inscricoes_abertas)): ?>
            <p>Gere um novo código PIX para pagar:</p>
            <form method="POST" action="<?= e($base) ?>/gerar-pix"><?= campo_csrf() ?>
                <button type="submit">Gerar PIX</button>
            </form>
        <?php else: ?>
            <p>Nenhuma cobrança PIX ativa.</p>
        <?php endif; ?>
    </div>

    <!-- Boleto -->
    <div id="content-boleto" class="tab-content">
        <?php if ($boleto_data && !empty($boleto_data['boleto_link'])): ?>
            <div class="text-center">
                <h3>Boleto Gerado</h3>
                <p><a href="<?= e($boleto_data['boleto_link']) ?>" target="_blank" class="btn-primary" role="button">Abrir Boleto PDF</a></p>

                <?php if (!empty($boleto_data['barcode'])): ?>
                    <p><strong>Código de barras:</strong></p>
                    <textarea id="codigo-boleto" readonly style="width: 100%; height: 50px; font-family: monospace;" onclick="this.select()"><?= e($boleto_data['barcode']) ?></textarea>
                    <button type="button" class="secondary" style="width: auto;"
                            onclick="copiarTexto('codigo-boleto', this)">Copiar código de barras</button>
                <?php endif; ?>

                <p><small>Vencimento: <?= e($boleto_data['due_date']) ?></small></p>
                <p style="margin-top: 15px; padding: 10px; background: #fff3e0; border-radius: 6px; color: #e65100;">
                    O boleto pode levar até 2 dias úteis para compensar. Você receberá o email de confirmação assim que o pagamento for identificado.
                </p>
            </div>

            <?php if (!empty($inscricoes_abertas)): ?>
                <form method="POST" action="<?= e($base) ?>/gerar-boleto" style="margin-top: 20px;"><?= campo_csrf() ?>
                    <button type="submit" class="secondary">Gerar Novo Boleto</button>
                </form>
            <?php endif; ?>
        <?php elseif (!empty($inscricoes_abertas)): ?>
            <p>Gere um boleto para pagar:</p>
            <form method="POST" action="<?= e($base) ?>/gerar-boleto"><?= campo_csrf() ?>
                <button type="submit">Gerar Boleto</button>
            </form>
            <p><small>Necessário ter informado endereço completo no formulário.</small></p>
        <?php else: ?>
            <p>Nenhum boleto ativo.</p>
        <?php endif; ?>
    </div>

    <!-- Cartão -->
    <div id="content-cartao" class="tab-content">
        <h3>Pagamento com Cartão de Crédito</h3>

        <?php if (empty($inscricoes_abertas)): ?>
            <p>O prazo de inscrição encerrou. Use a cobrança já gerada.</p>
        <?php elseif (empty($pagbank_public_key)): ?>
            <p>Pagamento com cartão indisponível no momento. Use PIX ou boleto.</p>
        <?php else: ?>
        <form id="form-cartao" method="POST" action="<?= e($base) ?>/pagar-cartao"><?= campo_csrf() ?>
            <input type="hidden" name="card_encrypted" id="card_encrypted">

            <label for="card_number">Número do Cartão</label>
            <input type="text" id="card_number" placeholder="0000 0000 0000 0000" maxlength="19" required>

            <div class="grid">
                <div>
                    <label for="card_expiry">Validade</label>
                    <input type="text" id="card_expiry" placeholder="MM/AA" maxlength="5" required>
                </div>
                <div>
                    <label for="card_cvv">CVV</label>
                    <input type="text" id="card_cvv" placeholder="000" maxlength="4" required>
                </div>
            </div>

            <label for="holder_name">Nome no Cartão</label>
            <input type="text" id="holder_name" name="holder_name" placeholder="NOME COMO NO CARTÃO" required>

            <button type="submit" id="btn-pagar-cartao">Pagar <?= e($valor_formatado) ?></button>
        </form>

        <script>
        document.getElementById('form-cartao').addEventListener('submit', async function(e) {
            e.preventDefault();

            const btn = document.getElementById('btn-pagar-cartao');
            btn.disabled = true;
            btn.textContent = 'Processando...';

            try {
                const card = PagSeguro.encryptCard({
                    publicKey: '<?= e($pagbank_public_key) ?>',
                    holder: document.getElementById('holder_name').value,
                    number: document.getElementById('card_number').value.replace(/\s/g, ''),
                    expMonth: document.getElementById('card_expiry').value.split('/')[0],
                    expYear: '20' + document.getElementById('card_expiry').value.split('/')[1],
                    securityCode: document.getElementById('card_cvv').value
                });

                if (card.hasErrors) {
                    // A mensagem do SDK do PagBank vem em ingles e por codigo;
                    // despeja-la com JSON.stringify era a unica saida em
                    // ingles/jargao do fluxo publico. O detalhe vai para o
                    // console, para quem for diagnosticar.
                    console.error('PagBank card.errors:', card.errors);
                    alert('Não foi possível ler os dados do cartão. Confira o número, '
                        + 'a validade, o CVV e o nome impresso, e tente de novo.');
                    btn.disabled = false;
                    btn.textContent = 'Pagar <?= e($valor_formatado) ?>';
                    return;
                }

                document.getElementById('card_encrypted').value = card.encryptedCard;
                this.submit();
            } catch (err) {
                console.error('PagBank:', err);
                alert('Não foi possível processar o cartão agora. Tente de novo em alguns '
                    + 'minutos, ou use PIX ou boleto.');
                btn.disabled = false;
                btn.textContent = 'Pagar <?= e($valor_formatado) ?>';
            }
        });

        document.getElementById('card_number').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(\d{4})(?=\d)/g, '$1 ');
            e.target.value = value;
        });

        document.getElementById('card_expiry').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length >= 2) {
                value = value.substring(0, 2) + '/' + value.substring(2, 4);
            }
            e.target.value = value;
        });
        </script>
        <?php endif; ?>
    </div>

    <hr>
    <p class="text-center">
        <a href="<?= e($base) ?>">Voltar ao formulário</a>
    </p>
    <p><small>Dúvidas: <a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a></small></p>
</article>

<script>
<?php if ($boleto_data): ?>
document.getElementById('tab-boleto').checked = true;
document.getElementById('content-pix').style.display = 'none';
document.getElementById('content-boleto').style.display = 'block';
<?php endif; ?>

document.querySelectorAll('.tabs input[type="radio"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.tab-content').forEach(function(content) {
            content.style.display = 'none';
        });
        document.getElementById('content-' + this.id.replace('tab-', '')).style.display = 'block';
    });
});
</script>

<script>
// Botao de copiar. navigator.clipboard so existe em HTTPS (ou localhost);
// o execCommand fica de reserva para o resto.
function copiarTexto(id, botao) {
    var campo = document.getElementById(id);
    if (!campo) return;
    var texto = campo.value;
    var aviso = function () {
        var antes = botao.textContent;
        botao.textContent = 'Copiado ✓';
        botao.disabled = true;
        setTimeout(function () { botao.textContent = antes; botao.disabled = false; }, 2000);
    };
    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(texto).then(aviso, function () { campo.select(); });
    } else {
        campo.select();
        try { document.execCommand('copy'); aviso(); } catch (e) { /* resta o texto selecionado */ }
    }
}
</script>
