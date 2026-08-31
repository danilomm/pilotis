<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2><?= e($evento['nome']) ?></h2>
        <div>
            <a href="/admin/eventos/<?= (int)$evento['id'] ?>/inscritos" role="button">Ver inscritos</a>
            <a href="/admin/eventos" role="button" class="outline">Voltar</a>
        </div>
    </div>

    <?php
        $cores = ['rascunho' => '#856404', 'publicado' => '#155724', 'encerrado' => '#6c757d'];
        $fundos = ['rascunho' => '#fff3cd', 'publicado' => '#d4edda', 'encerrado' => '#e9ecef'];
    ?>
    <p>
        Status:
        <span style="background: <?= $fundos[$evento['status']] ?? '#eee' ?>; color: <?= $cores[$evento['status']] ?? '#333' ?>; padding: 2px 10px; border-radius: 4px;">
            <strong><?= e($evento['status']) ?></strong>
        </span>
        <?php if ($evento['status'] === 'publicado'): ?>
            — página pública: <a href="<?= e(BASE_URL) ?>/eventos/<?= e($evento['slug']) ?>" target="_blank"><?= e(BASE_URL) ?>/eventos/<?= e($evento['slug']) ?></a>
        <?php endif; ?>
    </p>

    <?php if ($tem_pagos): ?>
        <div class="alert alert-warning" style="padding: 10px; background: #fff3cd; color: #856404; border-radius: 6px; margin-bottom: 15px;">
            Este evento já tem inscrições pagas: os <strong>valores</strong> das categorias existentes estão travados.
            Você ainda pode editar descrição/prazos e adicionar categorias novas.
        </div>
    <?php endif; ?>

    <!-- Ações de status -->
    <div style="display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap;">
        <?php if ($evento['status'] !== 'publicado'): ?>
            <form method="POST" action="/admin/eventos/<?= (int)$evento['id'] ?>/status" style="margin: 0;"><?= campo_csrf() ?>
                <input type="hidden" name="status" value="publicado">
                <button type="submit">Publicar (abre inscrições)</button>
            </form>
        <?php endif; ?>
        <?php if ($evento['status'] === 'publicado'): ?>
            <form method="POST" action="/admin/eventos/<?= (int)$evento['id'] ?>/status" style="margin: 0;"><?= campo_csrf() ?>
                <input type="hidden" name="status" value="encerrado">
                <button type="submit" class="secondary">Encerrar inscrições</button>
            </form>
        <?php endif; ?>
        <?php if ($evento['status'] === 'encerrado'): ?>
            <form method="POST" action="/admin/eventos/<?= (int)$evento['id'] ?>/status" style="margin: 0;"><?= campo_csrf() ?>
                <input type="hidden" name="status" value="publicado">
                <button type="submit" class="secondary outline">Reabrir inscrições</button>
            </form>
        <?php endif; ?>
        <form method="POST" action="/admin/eventos/<?= (int)$evento['id'] ?>/excluir" style="margin: 0;"
              onsubmit="return confirm('Excluir este evento? Só é possível sem inscrições.');"><?= campo_csrf() ?>
            <button type="submit" class="secondary outline" style="border-color: #b71c1c; color: #b71c1c;">Excluir</button>
        </form>
    </div>

    <hr>

    <!-- Dados do evento -->
    <h3>Dados do evento</h3>
    <form method="POST" action="/admin/eventos/<?= (int)$evento['id'] ?>" enctype="multipart/form-data"><?= campo_csrf() ?>

        <label for="nome">Nome *</label>
        <input type="text" id="nome" name="nome" value="<?= e($evento['nome']) ?>" required>

        <label for="slug">Slug</label>
        <input type="text" id="slug" name="slug" value="<?= e($evento['slug']) ?>"
               <?= $evento['status'] !== 'rascunho' ? 'disabled' : 'pattern="[a-z0-9][a-z0-9-]+"' ?>>
        <?php if ($evento['status'] !== 'rascunho'): ?>
            <small>O slug não muda depois de publicado (links já circulam).</small>
        <?php endif; ?>

        <label for="organizador">Núcleo organizador</label>
        <input type="text" id="organizador" name="organizador" value="<?= e($evento['organizador'] ?? '') ?>">

        <label for="descricao">Chamada</label>
        <textarea id="descricao" name="descricao" rows="3"><?= e($evento['descricao'] ?? '') ?></textarea>
        <small>Uma ou duas frases. Aparece na lista de eventos e é o texto que
        o WhatsApp mostra quando alguém manda o link. O corpo da página vai no
        campo abaixo.</small>

        <label for="local">Local</label>
        <textarea id="local" name="local" rows="3"
                  placeholder="IAB-RJ&#10;Rua do Pinheiro, 10 — Flamengo&#10;Rio de Janeiro, RJ"><?= e($evento['local'] ?? '') ?></textarea>
        <small>Nome e endereço, uma linha cada.</small>

        <?php
        // A modalidade sai IMPRESSA no comprovante, porque as pessoas o usam
        // para pedir dispensa de ponto no trabalho: sem a palavra "presencial"
        // e sem data e local, o papel prova que alguem se inscreveu, e nao que
        // precisou se deslocar.
        $mod_atual = strtolower(trim((string)($evento['modalidade'] ?? '')));
        ?>
        <label for="modalidade">Modalidade</label>
        <select id="modalidade" name="modalidade">
            <?php foreach (['presencial' => 'Presencial', 'online' => 'Online', 'hibrido' => 'Híbrido'] as $v => $rot): ?>
                <option value="<?= e($v) ?>" <?= ($mod_atual ?: 'presencial') === $v ? 'selected' : '' ?>><?= e($rot) ?></option>
            <?php endforeach; ?>
        </select>
        <small>Sai impressa no comprovante de inscrição, junto com a data e o local —
        é o que permite usá-lo para justificar ausência no trabalho.</small>

        <label for="conteudo">Página do evento</label>
        <textarea id="conteudo" name="conteudo" rows="18"
                  style="font-family: var(--pico-font-family-monospace); font-size: .85rem;"
                  placeholder="## Apresentação&#10;&#10;O V Seminário Docomomo Rio de Janeiro reúne...&#10;&#10;## Eixos temáticos&#10;&#10;- Documentação e inventário&#10;- Preservação e restauro"><?= e($evento['conteudo'] ?? '') ?></textarea>
        <small>
            É o corpo da página pública — apresentação, eixos, programação, comissões.
            Marcação aceita:
            <code>## Título</code>, <code>### Subtítulo</code>,
            <code>- item</code>, <code>1. item</code>,
            <code>**negrito**</code>, <code>*itálico*</code>,
            <code>[texto](https://link)</code>, e <code>---</code> para uma linha divisória.
            Qualquer outra coisa sai como texto.
            <br><strong>Linha em branco separa parágrafos.</strong> Linhas seguidas se juntam
            num parágrafo só — então texto colado de documento, que costuma vir quebrado,
            sai certo. Quando a quebra importar (programação, lista de nomes), use
            <code>- </code> no começo de cada linha.
        </small>

        <div class="grid">
            <div>
                <label for="data_inicio">Início do evento</label>
                <input type="date" id="data_inicio" name="data_inicio" value="<?= e($evento['data_inicio'] ?? '') ?>">
            </div>
            <div>
                <label for="data_fim">Fim do evento</label>
                <input type="date" id="data_fim" name="data_fim" value="<?= e($evento['data_fim'] ?? '') ?>">
            </div>
            <div>
                <label for="prazo_inscricao">Prazo de inscrição</label>
                <input type="date" id="prazo_inscricao" name="prazo_inscricao" value="<?= e($evento['prazo_inscricao'] ?? '') ?>">

                <label for="email_contato">Contato do evento</label>
                <input type="text" id="email_contato" name="email_contato"
                       value="<?= e($evento['email_contato'] ?? '') ?>"
                       placeholder="Comissão organizadora &lt;contato@evento.org&gt;">
                <small>Aparece em destaque no comprovante de inscrição, abaixo das assinaturas.</small>

                <label for="assinantes">Assinantes do comprovante</label>
                <textarea id="assinantes" name="assinantes" rows="3"
                          placeholder="Um nome por linha, com a instituição"><?= e($evento['assinantes'] ?? '') ?></textarea>
                <small>Um por linha. Ex.: <em>Maria Cristina Cabral (UFRJ FAU PROURB)</em>.
                Sem assinantes, o comprovante sai sem assinatura.</small>

                <label for="imagem">Cartaz do evento</label>
                <?php if (!empty($evento['imagem_path'])): ?>
                    <img src="<?= e(EVENTOS_IMG_URL . '/' . $evento['imagem_path']) ?>" alt="Cartaz atual"
                         style="max-width: 160px; height: auto; display: block; margin-bottom: 8px; border-radius: 6px;">
                <?php endif; ?>
                <input type="file" id="imagem" name="imagem" accept="image/jpeg,image/png,image/webp">
                <small>JPG, PNG ou WebP até 10MB. É reduzido para 1400px automaticamente.</small>

                <label for="programa">Programação (PDF)</label>
                <?php if (!empty($evento['programa_path'])): ?>
                    <p style="margin: 0 0 8px;">
                        <a href="<?= e(EVENTOS_DOC_URL . '/' . $evento['programa_path']) ?>" target="_blank">
                            ver a programação publicada
                        </a>
                    </p>
                <?php endif; ?>
                <input type="file" id="programa" name="programa" accept="application/pdf">
                <small>PDF até 10MB. Aparece como link na página do evento, e não substitui o
                calendário do texto. Subir de novo troca o arquivo anterior.</small>

                <label for="apoiadores">Apoio e patrocínio</label>
                <textarea id="apoiadores" name="apoiadores" rows="4"
                          placeholder="Um por linha. Use uma linha só com dois-pontos para separar blocos:&#10;Realização:&#10;Docomomo Rio de Janeiro&#10;Apoio:&#10;IAB-RJ&#10;CAU/RJ"><?= e($evento['apoiadores'] ?? '') ?></textarea>
                <small>Um por linha, na página do evento. Linha terminada em dois-pontos
                (<em>Apoio:</em>, <em>Patrocínio:</em>) vira título do bloco seguinte.</small>

                <label for="imagem_apoiadores">Faixa de logotipos</label>
                <?php if (!empty($evento['imagem_apoiadores'])): ?>
                    <img src="<?= e(EVENTOS_IMG_URL . '/' . $evento['imagem_apoiadores']) ?>" alt="Faixa de apoiadores atual"
                         style="max-width: 220px; height: auto; display: block; margin-bottom: 8px; border-radius: 6px;">
                <?php endif; ?>
                <input type="file" id="imagem_apoiadores" name="imagem_apoiadores" accept="image/jpeg,image/png,image/webp">
                <small>A tira de logotipos que a organização já monta. Vai no rodapé da página do evento.
                Vale manter os nomes acima também: imagem não é texto — não vai para busca nem para leitor de tela.</small>

                <label for="imagem_organizador">Logotipo de quem organiza</label>
                <?php if (!empty($evento['imagem_organizador'])): ?>
                    <img src="<?= e(EVENTOS_IMG_URL . '/' . $evento['imagem_organizador']) ?>" alt="Logotipo do organizador atual"
                         style="max-width: 220px; height: auto; display: block; margin-bottom: 8px;">
                <?php endif; ?>
                <input type="file" id="imagem_organizador" name="imagem_organizador" accept="image/jpeg,image/png,image/webp">
                <small>A marca do núcleo ou entidade que realiza o evento — não a dos apoiadores.
                Sai centralizada sob a palavra <em>Organização</em>, acima da faixa de logotipos.
                <strong>PNG com fundo transparente</strong> é o que fica bem em qualquer fundo.
                Sem arquivo, sai o nome escrito no campo <em>Organizador</em>.</small>

                <label for="emails_organizacao">Acesso da organização ao painel</label>
                <textarea id="emails_organizacao" name="emails_organizacao" rows="3"
                          placeholder="Um email por linha. Quem estiver aqui pede o próprio link de acesso."><?= e($evento['emails_organizacao'] ?? '') ?></textarea>
                <small>Cada pessoa da lista entra em <code>/eventos/<?= e($evento['slug']) ?>/organizacao</code>,
                informa o email e recebe um link de acesso. O painel mostra a lista de inscritos com contato
                e endereço, <strong>sem CPF</strong>, e permite baixar planilha. Todo acesso fica registrado
                no log. Vazio = painel desligado.</small>

                <label for="organizacao_expira_em">Painel válido até</label>
                <input type="date" id="organizacao_expira_em" name="organizacao_expira_em"
                       value="<?= e($evento['organizacao_expira_em'] ?? '') ?>">
                <small>Depois desta data o painel para de abrir. Deixe vazio para não expirar.</small>

                <label for="data_valor_cheio">Início do valor cheio</label>
                <input type="date" id="data_valor_cheio" name="data_valor_cheio" value="<?= e($evento['data_valor_cheio'] ?? '') ?>">
                <small>A partir desta data vale o "Valor cheio" de cada categoria. Deixe vazio para preço único.</small>
            </div>
        </div>

        <button type="submit">Salvar dados</button>
    </form>

    <hr>

    <!-- Categorias -->
    <h3>Categorias de inscrição</h3>

    <?php if (empty($categorias)): ?>
        <p>Nenhuma categoria ainda. Adicione pelo menos uma antes de publicar.</p>
    <?php else: ?>
        <?php foreach ($categorias as $cat): ?>
            <form method="POST" action="/admin/eventos/<?= (int)$evento['id'] ?>/categoria"
                  style="display: flex; gap: 10px; align-items: end; flex-wrap: wrap; border-bottom: 1px solid #eee; padding-bottom: 12px; margin-bottom: 12px;"><?= campo_csrf() ?>
                <input type="hidden" name="categoria_id" value="<?= (int)$cat['id'] ?>">
                <div style="flex: 2; min-width: 180px;">
                    <label>Nome</label>
                    <input type="text" name="nome" value="<?= e($cat['nome']) ?>" required style="margin-bottom: 0;">
                </div>
                <div style="flex: 1; min-width: 100px;">
                    <label>Valor (R$)</label>
                    <input type="text" name="valor" value="<?= number_format($cat['valor'] / 100, 2, ',', '') ?>"
                           <?= $tem_pagos ? 'readonly' : '' ?> style="margin-bottom: 0;">
                </div>
                <div style="flex: 1; min-width: 100px;">
                    <label>Valor cheio</label>
                    <input type="text" name="valor_cheio" value="<?= !empty($cat['valor_cheio']) ? number_format($cat['valor_cheio'] / 100, 2, ',', '') : '' ?>"
                           placeholder="—" <?= $tem_pagos ? 'readonly' : '' ?> style="margin-bottom: 0;">
                </div>
                <div style="flex: 0; min-width: 70px;">
                    <label>Ordem</label>
                    <input type="number" name="ordem" value="<?= (int)$cat['ordem'] ?>" style="margin-bottom: 0; width: 70px;">
                </div>
                <div style="min-width: 160px;">
                    <label style="font-size: 0.85em;">
                        <input type="checkbox" name="verifica_adimplencia" <?= $cat['verifica_adimplencia'] ? 'checked' : '' ?>>
                        Verifica adimplência
                    </label>
                    <label style="font-size: 0.85em;">
                        <input type="checkbox" name="requer_comprovante" <?= $cat['requer_comprovante'] ? 'checked' : '' ?>>
                        Exige comprovante
                    </label>
                    <label style="font-size: 0.85em;">
                        <input type="checkbox" name="independe_filiacao" <?= !empty($cat['independe_filiacao']) ? 'checked' : '' ?>>
                        Independe de filiação
                    </label>
                </div>
                <?php
                // "Independe de filiacao" desliga a regra do desconto para esta
                // categoria: ela passa a aparecer para TODO MUNDO, inclusive
                // para quem tem anuidade em dia. Serve a acompanhante, visitante,
                // ouvinte — categoria que vale para os dois.
                //
                // Marcada por engano numa categoria de PRECO CHEIO, o filiado
                // adimplente volta a ver a opcao cara ao lado da com desconto.
                // O nome do campo nao avisa isso, entao a dica avisa.
                ?>
                <small style="display: block; margin: -.3rem 0 .6rem; color: var(--muted-color);">
                    <em>Só filiado adimplente</em>: categoria com desconto — quem não está em dia não a vê.
                    <em>Independe de filiação</em>: vale para os dois (acompanhante, visitante).
                    Deixe as duas desmarcadas nas categorias de preço cheio, senão o filiado
                    continua vendo a opção mais cara.
                </small>

                <?php if ((int)$cat['valor'] === 0 && trim((string)($cat['cpfs_liberados'] ?? '')) === ''): ?>
                    <div style="flex: 1 0 100%; font-size: 0.85em; color: var(--muted-color);">
                        Gratuita e aberta: qualquer visitante pode se inscrever por ela.
                        Para reservá-la a convidados, preencha os CPFs abaixo.
                    </div>
                <?php endif; ?>
                <div style="flex: 1 0 100%; min-width: 220px;">
                    <label style="font-size: 0.85em;">Lista de liberados — CPF ou email (vazio = categoria aberta a todos)</label>
                    <textarea name="cpfs_liberados" rows="2" placeholder="Um convidado por linha, com CPF e email quando tiver os dois:&#10;111.444.777-35 convidada@universidade.edu&#10;palestrante@exemplo.org&#10;529.982.247-25"
                              style="margin-bottom: 0; font-family: monospace; font-size: 0.85em;"><?= e($cat['cpfs_liberados'] ?? '') ?></textarea>
                </div>
                <?php if (trim((string)($cat['cpfs_liberados'] ?? '')) !== ''): ?>
                    <div style="flex: 1 0 100%; font-size: 0.85em; color: var(--muted-color);">
                        Categoria restrita. Em "Convites" você vê a lista já resolvida — quem é quem, quem
                        é cadastro novo, quem já respondeu — antes de qualquer email sair. Salve as
                        alterações da lista antes de ir para lá.
                    </div>
                <?php endif; ?>
                <div style="display: flex; gap: 6px;">
                    <button type="submit" style="margin-bottom: 0;">Salvar</button>
                    <?php if (trim((string)($cat['cpfs_liberados'] ?? '')) !== ''): ?>
                        <a href="/admin/eventos/<?= (int)$evento['id'] ?>/categoria/<?= (int)$cat['id'] ?>/convites"
                           role="button" class="secondary" style="margin-bottom: 0;">Convites</a>
                    <?php endif; ?>
                    <?php if ((int)$cat['usos'] === 0): ?>
                        <button type="submit" style="margin-bottom: 0; background: #b71c1c; border-color: #b71c1c;"
                                formaction="/admin/eventos/<?= (int)$evento['id'] ?>/categoria/<?= (int)$cat['id'] ?>/excluir"
                                onclick="return confirm('Excluir esta categoria?');">×</button>
                    <?php else: ?>
                        <small style="align-self: center;"><?= (int)$cat['usos'] ?> inscr.</small>
                    <?php endif; ?>
                </div>
            </form>
        <?php endforeach; ?>
    <?php endif; ?>

    <h4>Adicionar categoria</h4>
    <form method="POST" action="/admin/eventos/<?= (int)$evento['id'] ?>/categoria"
          style="display: flex; gap: 10px; align-items: end; flex-wrap: wrap;"><?= campo_csrf() ?>
        <div style="flex: 2; min-width: 180px;">
            <label>Nome</label>
            <input type="text" name="nome" required placeholder="Ex.: Filiado, Estudante, Geral" style="margin-bottom: 0;">
        </div>
        <div style="flex: 1; min-width: 100px;">
            <label>Valor (R$)</label>
            <input type="text" name="valor" required placeholder="0 = gratuita" style="margin-bottom: 0;">
        </div>
        <div style="flex: 0; min-width: 70px;">
            <label>Ordem</label>
            <input type="number" name="ordem" value="0" style="margin-bottom: 0; width: 70px;">
        </div>
        <div style="min-width: 160px;">
            <label style="font-size: 0.85em;">
                <input type="checkbox" name="verifica_adimplencia"> Verifica adimplência
            </label>
            <label style="font-size: 0.85em;">
                <input type="checkbox" name="requer_comprovante"> Exige comprovante
            </label>
            <label style="font-size: 0.85em;">
                <input type="checkbox" name="independe_filiacao"> Independe de filiação
            </label>
        </div>
            <?php
            // "Independe de filiacao" desliga a regra do desconto para esta
            // categoria: ela passa a aparecer para TODO MUNDO, inclusive
            // para quem tem anuidade em dia. Serve a acompanhante, visitante,
            // ouvinte — categoria que vale para os dois.
            //
            // Marcada por engano numa categoria de PRECO CHEIO, o filiado
            // adimplente volta a ver a opcao cara ao lado da com desconto.
            // O nome do campo nao avisa isso, entao a dica avisa.
            ?>
            <small style="display: block; margin: -.3rem 0 .6rem; color: var(--muted-color);">
                <em>Só filiado adimplente</em>: categoria com desconto — quem não está em dia não a vê.
                <em>Independe de filiação</em>: vale para os dois (acompanhante, visitante).
                Deixe as duas desmarcadas nas categorias de preço cheio, senão o filiado
                continua vendo a opção mais cara.
            </small>

        <div style="flex: 1 0 100%; min-width: 220px;">
            <label style="font-size: 0.85em;">Lista de liberados — CPF ou email (vazio = categoria aberta a todos)</label>
            <textarea name="cpfs_liberados" rows="2" placeholder="Um convidado por linha, com CPF e email quando tiver os dois:&#10;111.444.777-35 convidada@universidade.edu&#10;palestrante@exemplo.org&#10;529.982.247-25"
                      style="margin-bottom: 0; font-family: monospace; font-size: 0.85em;"></textarea>
        </div>
        <button type="submit" style="margin-bottom: 0;">Adicionar</button>
    </form>

    <small style="display: block; margin-top: 10px; color: var(--muted-color);">
        <strong>Verifica adimplência</strong>: no submit, o sistema confere se o CPF/email pertence a filiado
        com anuidade paga; se não, pede outra categoria.
        <strong>Exige comprovante</strong>: upload de comprovante de matrícula (PDF/JPG/PNG, máx. 5MB).
        CPF é pedido automaticamente em toda categoria paga (exigência PagBank).
        <strong>Independe de filiação</strong>: a categoria aparece para todos, filiados ou não
        (acompanhante, visitante). Sem essa marca, uma categoria que não verifica adimplência é
        entendida como o preço cheio equivalente e fica indisponível para quem tem anuidade em dia.
        <strong>Lista de liberados</strong>: preenchida, a categoria vira restrita — só aparece, e só é
        aceita, para quem está na lista. É como se cadastram isentos, convidados e palestrantes. Aceita
        CPF (para quem já está na base) e email (para convidado de fora), misturados. A conferência é feita
        contra o cadastro da pessoa, não contra o que ela digita no formulário. Vazia, a categoria é aberta
        a todos, que é o que se quer num evento de inscrição gratuita.
    </small>
</article>
