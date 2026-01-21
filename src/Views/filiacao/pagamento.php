<?php
$extra_head = '<script src="https://assets.pagseguro.com.br/checkout-sdk-js/rc/dist/browser/pagseguro.min.js"></script>';
?>
<article>
    <h2>Pagamento - Filiação <?= e($ano) ?></h2>

    <p><strong>Nome:</strong> <?= e($cadastrado['nome']) ?></p>
    <p><strong>Valor:</strong> <?= e($valor_formatado) ?></p>

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
        .tabs input:checked + label { background: #4a8c4a; color: white; }
        .tab-content { display: none; padding: 20px; border: 1px solid #ddd; margin-top: -1px; }
        #tab-pix:checked ~ #content-pix,
        #tab-boleto:checked ~ #content-boleto,
        #tab-cartao:checked ~ #content-cartao { display: block; }
    </style>

    <!-- Conteúdo PIX -->
    <div id="content-pix" class="tab-content" style="display: block;">
        <?php if ($pix_data && !empty($pix_data['qr_code'])): ?>
            <div class="text-center">
                <h3>Escaneie o QR Code</h3>
                <?php if (!empty($pix_data['qr_code_link'])): ?>
                    <img src="<?= e($pix_data['qr_code_link']) ?>" alt="QR Code PIX" style="max-width: 300px;">
                <?php endif; ?>

                <p><strong>Ou copie o código PIX:</strong></p>
                <textarea readonly style="width: 100%; height: 100px; font-family: monospace; font-size: 12px;" onclick="this.select()"><?= e($pix_data['qr_code']) ?></textarea>

                <p><small>Válido até: <?= e($pix_data['expiration_date']) ?></small></p>
            </div>
        <?php else: ?>
            <p>Gere um novo código PIX para pagar:</p>
            <form method="POST" action="/filiacao/<?= e($ano) ?>/<?= e($token) ?>/gerar-pix">
                <button type="submit">Gerar PIX</button>
            </form>
        <?php endif; ?>
    </div>

    <!-- Conteúdo Boleto -->
    <div id="content-boleto" class="tab-content">
        <?php if ($boleto_data && !empty($boleto_data['boleto_link'])): ?>
            <div class="text-center">
                <h3>Boleto Gerado</h3>
                <p><a href="<?= e($boleto_data['boleto_link']) ?>" target="_blank" class="btn-primary" role="button">Abrir Boleto PDF</a></p>

                <?php if (!empty($boleto_data['barcode'])): ?>
                    <p><strong>Código de barras:</strong></p>
                    <textarea readonly style="width: 100%; height: 50px; font-family: monospace;" onclick="this.select()"><?= e($boleto_data['barcode']) ?></textarea>
                <?php endif; ?>

                <p><small>Vencimento: <?= e($boleto_data['due_date']) ?></small></p>
            </div>

            <form method="POST" action="/filiacao/<?= e($ano) ?>/<?= e($token) ?>/gerar-boleto" style="margin-top: 20px;">
                <button type="submit" class="secondary">Gerar Novo Boleto</button>
            </form>
        <?php else: ?>
            <p>Gere um boleto para pagar:</p>
            <form method="POST" action="/filiacao/<?= e($ano) ?>/<?= e($token) ?>/gerar-boleto">
                <button type="submit">Gerar Boleto</button>
            </form>
            <p><small>Necessário informar endereço completo no formulário anterior.</small></p>
        <?php endif; ?>
    </div>

    <!-- Conteúdo Cartão -->
    <div id="content-cartao" class="tab-content">
        <h3>Pagamento com Cartão de Crédito</h3>

        <form id="form-cartao" method="POST" action="/filiacao/<?= e($ano) ?>/<?= e($token) ?>/pagar-cartao">
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
                    alert('Erro nos dados do cartão: ' + JSON.stringify(card.errors));
                    btn.disabled = false;
                    btn.textContent = 'Pagar <?= e($valor_formatado) ?>';
                    return;
                }

                document.getElementById('card_encrypted').value = card.encryptedCard;
                this.submit();
            } catch (err) {
                alert('Erro ao processar cartão: ' + err.message);
                btn.disabled = false;
                btn.textContent = 'Pagar <?= e($valor_formatado) ?>';
            }
        });

        // Formatação do número do cartão
        document.getElementById('card_number').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(\d{4})(?=\d)/g, '$1 ');
            e.target.value = value;
        });

        // Formatação da validade
        document.getElementById('card_expiry').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length >= 2) {
                value = value.substring(0, 2) + '/' + value.substring(2, 4);
            }
            e.target.value = value;
        });
        </script>
    </div>

    <hr>
    <p class="text-center">
        <a href="/filiacao/<?= e($ano) ?>/<?= e($token) ?>">Voltar ao formulário</a>
    </p>
</article>

<script>
// Ativa aba correta baseado no método existente
<?php if ($boleto_data): ?>
document.getElementById('tab-boleto').checked = true;
document.getElementById('content-pix').style.display = 'none';
document.getElementById('content-boleto').style.display = 'block';
<?php endif; ?>

// Controle das abas
document.querySelectorAll('.tabs input[type="radio"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        document.querySelectorAll('.tab-content').forEach(function(content) {
            content.style.display = 'none';
        });
        document.getElementById('content-' + this.id.replace('tab-', '')).style.display = 'block';
    });
});
</script>
