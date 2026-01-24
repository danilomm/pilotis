<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/jodit@4/es2021/jodit.min.css">
<script src="https://cdn.jsdelivr.net/npm/jodit@4/es2021/jodit.min.js"></script>

<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Templates de Email</h2>
        <div>
            <a href="/admin" role="button" class="outline">Painel</a>
            <a href="/admin/campanha" role="button" class="outline">Campanhas</a>
            <a href="/admin/logout" role="button" class="secondary outline">Sair</a>
        </div>
    </div>

    <?php if ($msg = get_flash('success')): ?>
        <div style="background: #d4edda; padding: 15px; border-radius: 8px; margin-bottom: 20px; color: #155724;">
            <?= e($msg) ?>
        </div>
    <?php endif; ?>

    <?php if ($msg = get_flash('error')): ?>
        <div style="background: #f8d7da; padding: 15px; border-radius: 8px; margin-bottom: 20px; color: #721c24;">
            <?= e($msg) ?>
        </div>
    <?php endif; ?>

    <p style="color: #666; margin-bottom: 25px;">
        Edite o assunto e o corpo dos emails enviados pelo sistema.
        Use <code>{{variavel}}</code> para inserir dados dinamicos.
        O editor visual permite formatar sem conhecer HTML — use o botao <strong>&lt;/&gt;</strong> para ver/editar o codigo fonte.
    </p>

    <?php foreach ($templates as $tpl): ?>
    <details style="margin-bottom: 15px; background: #f8f9fa; padding: 12px 15px; border-radius: 8px; border: 1px solid #dee2e6;" data-tipo="<?= e($tpl['tipo']) ?>">
        <summary style="cursor: pointer; font-size: 0.95em; list-style: none; display: flex; justify-content: space-between; align-items: center;">
            <div>
                <strong><?= e($tpl['tipo']) ?></strong>
                <span style="color: #666; margin-left: 10px;"><?= e($descricoes[$tpl['tipo']] ?? $tpl['descricao'] ?? '') ?></span>
            </div>
            <span style="color: #17a2b8; font-size: 13px; background: #e8f4f8; padding: 3px 10px; border-radius: 4px; border: 1px solid #bee5eb;">&#9998; Editar</span>
        </summary>

        <form method="POST" action="/admin/templates" style="margin-top: 15px;" onsubmit="syncEditor('<?= e($tpl['tipo']) ?>')">
            <input type="hidden" name="tipo" value="<?= e($tpl['tipo']) ?>">

            <?php if ($tpl['variaveis']): ?>
            <div style="background: #fff3cd; padding: 8px 12px; border-radius: 5px; margin-bottom: 12px; font-size: 0.85em; color: #856404;">
                <strong>Variaveis disponiveis:</strong>
                <?php foreach (explode(', ', $tpl['variaveis']) as $v): ?>
                    <code style="background: #ffeaa7; padding: 1px 5px; border-radius: 3px; margin-left: 3px;">{{<?= e(trim($v)) ?>}}</code>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <label for="assunto_<?= e($tpl['tipo']) ?>"><strong>Assunto:</strong></label>
            <input type="text" id="assunto_<?= e($tpl['tipo']) ?>" name="assunto" value="<?= e($tpl['assunto']) ?>" required style="margin-bottom: 10px;">

            <label><strong>Corpo do email:</strong></label>
            <textarea id="html_<?= e($tpl['tipo']) ?>" name="html" style="display:none;"><?= e($tpl['html']) ?></textarea>
            <div id="editor_<?= e($tpl['tipo']) ?>" class="email-editor"></div>

            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 10px;">
                <button type="submit" style="background: <?= ORG_COR_PRIMARIA ?>; color: white; border: none;">Salvar</button>
                <button type="button" onclick="previewTemplate('<?= e($tpl['tipo']) ?>')" class="outline" style="font-size: 0.85em;">Preview</button>
            </div>

            <?php if ($tpl['updated_at']): ?>
            <small style="color: #999; margin-top: 8px; display: block;">Ultima edicao: <?= e($tpl['updated_at']) ?></small>
            <?php endif; ?>
        </form>

        <form method="POST" action="/admin/templates/resetar" style="margin-top: 10px; border-top: 1px solid #dee2e6; padding-top: 10px;">
            <input type="hidden" name="tipo" value="<?= e($tpl['tipo']) ?>">
            <button type="submit" class="secondary outline" style="font-size: 0.8em;"
                    onclick="return confirm('Restaurar template &quot;<?= e($tpl['tipo']) ?>&quot; ao padrao? As alteracoes serao perdidas.')">
                Restaurar Padrao
            </button>
        </form>
    </details>
    <?php endforeach; ?>
</article>

<!-- Modal de preview -->
<dialog id="previewDialog" style="max-width: 650px; width: 90%; padding: 0; border-radius: 8px; border: 1px solid #ccc;">
    <div style="padding: 15px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee;">
        <strong>Preview do Email</strong>
        <button onclick="document.getElementById('previewDialog').close()" class="outline" style="padding: 5px 12px; font-size: 0.8em;">Fechar</button>
    </div>
    <div id="previewContent" style="padding: 15px; max-height: 70vh; overflow-y: auto;"></div>
</dialog>

<script>
// Armazena instancias dos editores
var editors = {};

// Inicializa editor quando details é aberto
document.querySelectorAll('details[data-tipo]').forEach(function(det) {
    det.addEventListener('toggle', function() {
        if (!det.open) return;
        var tipo = det.dataset.tipo;
        if (editors[tipo]) return; // Já inicializado

        var textarea = document.getElementById('html_' + tipo);
        var editorDiv = document.getElementById('editor_' + tipo);

        editors[tipo] = Jodit.make(editorDiv, {
            height: 350,
            language: 'pt_br',
            toolbarButtonSize: 'small',
            showCharsCounter: false,
            showWordsCounter: false,
            showXPathInStatusbar: false,
            askBeforePasteHTML: false,
            askBeforePasteFromWord: false,
            buttons: 'bold,italic,underline,|,ul,ol,|,font,fontsize,brush,|,link,|,align,|,hr,table,|,source',
            buttonsMD: 'bold,italic,underline,|,ul,ol,|,link,|,source',
            buttonsSM: 'bold,italic,|,link,|,source',
            iframe: false,
            enter: 'p',
            defaultMode: 1, // WYSIWYG
            sourceEditorNativeOptions: {
                theme: 'ace/theme/chrome',
            },
        });

        // Carrega conteúdo do textarea no editor
        editors[tipo].value = textarea.value;
    });
});

// Sincroniza editor com textarea antes de submit
function syncEditor(tipo) {
    if (editors[tipo]) {
        document.getElementById('html_' + tipo).value = editors[tipo].value;
    }
}

// Preview com substituição de variáveis
function previewTemplate(tipo) {
    var html = editors[tipo] ? editors[tipo].value : document.getElementById('html_' + tipo).value;
    var exemplos = {
        'nome': 'Maria Silva',
        'ano': '2026',
        'categoria': 'Filiado Pleno Brasil',
        'valor': 'R$ 230,00',
        'link': '#',
        'urgencia': '',
        'dias_info': 'Restam 5 dias para o vencimento.'
    };
    for (var key in exemplos) {
        html = html.split('{{' + key + '}}').join(exemplos[key]);
    }
    document.getElementById('previewContent').innerHTML = html;
    document.getElementById('previewDialog').showModal();
}
</script>

<style>
.jodit-container {
    border-radius: 6px !important;
    border-color: #dee2e6 !important;
}
.email-editor {
    min-height: 200px;
}
</style>
