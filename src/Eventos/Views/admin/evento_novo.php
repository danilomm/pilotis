<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Novo Evento</h2>
        <a href="/admin/eventos" role="button" class="outline">Voltar</a>
    </div>

    <p>O evento é criado como <strong>rascunho</strong>. Depois de adicionar as categorias, você publica.</p>

    <form method="POST" action="/admin/eventos/novo"><?= campo_csrf() ?>

        <label for="nome">Nome do evento *</label>
        <input type="text" id="nome" name="nome" required placeholder="Ex.: 9º Seminário Docomomo SP">

        <label for="slug">Slug (endereço do evento) *</label>
        <input type="text" id="slug" name="slug" required pattern="[a-z0-9][a-z0-9-]+"
               placeholder="Ex.: seminario-sp-2027">
        <small>Só letras minúsculas, números e hífens. Convenção: incluir o ano. O link público será
        <code>pilotis.docomomobrasil.com/eventos/&lt;slug&gt;</code>.</small>

        <label for="organizador">Núcleo organizador</label>
        <input type="text" id="organizador" name="organizador" placeholder="Ex.: Núcleo Docomomo SP">

        <label for="descricao">Descrição</label>
        <textarea id="descricao" name="descricao" rows="5" placeholder="Texto exibido na página pública do evento"></textarea>

        <div class="grid">
            <div>
                <label for="data_inicio">Data de início do evento</label>
                <input type="date" id="data_inicio" name="data_inicio">
                <small class="data-extenso" data-para="data_inicio"></small>
            </div>
            <div>
                <label for="data_fim">Data de fim do evento</label>
                <input type="date" id="data_fim" name="data_fim">
                <small class="data-extenso" data-para="data_fim"></small>
            </div>
            <div>
                <label for="prazo_inscricao">Prazo de inscrição *</label>
                <input type="date" id="prazo_inscricao" name="prazo_inscricao">
                <small class="data-extenso" data-para="prazo_inscricao"></small>
                <small>Último dia para se inscrever.</small>
            </div>
        </div>

        <button type="submit">Criar Rascunho</button>
    </form>
</article>

<?php require SRC_DIR . '/Views/_data_extenso.php'; ?>
