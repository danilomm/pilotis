<article>
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
        <h2>Campanhas</h2>
        <div>
            <a href="/admin" role="button" class="outline">Painel</a>
            <a href="/admin/templates" role="button" class="outline">Templates</a>
            <a href="/admin/contatos" role="button" class="outline">Contatos</a>
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

    <!-- Card Nova Campanha -->
    <?php if (!empty($anos_disponiveis)): ?>
    <div style="border: 2px dashed #28a745; border-radius: 8px; padding: 20px; margin-bottom: 20px; background: #f8fff8;">
        <h3 style="margin-top: 0; color: #28a745;">+ Nova Campanha</h3>
        <form method="POST" action="/admin/campanha/criar" style="display: flex; gap: 15px; align-items: center; flex-wrap: wrap;">
            <div>
                <label for="ano_novo" style="margin-bottom: 5px;">Ano:</label>
                <select name="ano" id="ano_novo" style="width: auto;">
                    <?php foreach ($anos_disponiveis as $a): ?>
                        <option value="<?= $a ?>"><?= $a ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" style="background: #28a745; color: white; border: none;">
                Criar Campanha
            </button>
        </form>
        <p style="margin-bottom: 0; margin-top: 15px;"><small>Valores iniciais: Estudante <?= formatar_valor($valores['estudante']) ?> | Nacional <?= formatar_valor($valores['profissional_nacional']) ?> | Internacional <?= formatar_valor($valores['profissional_internacional']) ?> (editaveis apos criar)</small></p>
    </div>
    <?php endif; ?>

    <!-- Cards de Campanhas -->
    <?php foreach ($campanhas as $c): ?>
        <?php
        $ano = $c['ano'];
        $status = $c['status'];
        $stats = $c['stats'];
        $is_aberta = in_array($status, ['aberta', 'enviando', 'pausada']);
        $is_enviando = $status === 'enviando';
        $is_pausada = $status === 'pausada';
        $status_colors = [
            'aberta' => '#17a2b8',
            'enviando' => '#28a745',
            'pausada' => '#ffc107',
            'fechada' => '#6c757d',
        ];
        $status_labels = [
            'aberta' => 'Aberta',
            'enviando' => 'Enviando',
            'pausada' => 'Pausada',
            'fechada' => 'Fechada',
        ];
        $border_color = $status_colors[$status] ?? '#6c757d';
        $status_bg = $border_color;
        $status_label = $status_labels[$status] ?? ucfirst($status);
        $status_text_color = $status === 'pausada' ? '#000' : 'white';
        ?>
        <div style="border: 2px solid <?= $border_color ?>; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
            <!-- Cabeçalho -->
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 style="margin: 0;">Campanha <?= e($ano) ?></h3>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <mark style="background: <?= $status_bg ?>; color: <?= $status_text_color ?>; padding: 4px 12px; border-radius: 4px;"><?= $status_label ?></mark>
                    <?php if ($is_aberta && (int)($stats['total'] ?? 0) === 0): ?>
                        <form method="POST" action="/admin/campanha/excluir" style="margin: 0;">
                            <input type="hidden" name="ano" value="<?= $ano ?>">
                            <button type="submit" style="background: transparent; color: #dc3545; border: 1px solid #dc3545; padding: 4px 8px; font-size: 12px; border-radius: 4px; cursor: pointer;"
                                    onclick="return confirm('Excluir campanha <?= $ano ?>?')">Excluir</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Estatísticas -->
            <?php if ($is_aberta): ?>
                <!-- Valores editáveis -->
                <?php $cv = $c['valores']; ?>
                <details style="margin-bottom: 15px; background: #f8f9fa; padding: 12px 15px; border-radius: 8px; border: 1px solid #dee2e6;">
                    <summary style="cursor: pointer; font-size: 0.95em; list-style: none;">
                        <strong>Valores:</strong>
                        Estudante <?= formatar_valor($cv['valor_estudante']) ?> |
                        Nacional <?= formatar_valor($cv['valor_profissional']) ?> |
                        Internacional <?= formatar_valor($cv['valor_internacional']) ?>
                        <span style="float: right; color: #17a2b8; font-size: 13px;">&#9998; Editar</span>
                    </summary>
                    <form method="POST" action="/admin/campanha/valores" style="margin-top: 12px; display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end;">
                        <input type="hidden" name="ano" value="<?= $ano ?>">
                        <div>
                            <label style="font-size: 12px;">Estudante (R$)</label>
                            <input type="text" name="valor_estudante" value="<?= number_format($cv['valor_estudante'] / 100, 2, ',', '') ?>" style="width: 100px; padding: 4px 8px;">
                        </div>
                        <div>
                            <label style="font-size: 12px;">Nacional (R$)</label>
                            <input type="text" name="valor_profissional" value="<?= number_format($cv['valor_profissional'] / 100, 2, ',', '') ?>" style="width: 100px; padding: 4px 8px;">
                        </div>
                        <div>
                            <label style="font-size: 12px;">Internacional (R$)</label>
                            <input type="text" name="valor_internacional" value="<?= number_format($cv['valor_internacional'] / 100, 2, ',', '') ?>" style="width: 100px; padding: 4px 8px;">
                        </div>
                        <button type="submit" style="background: #17a2b8; color: white; border: none; padding: 6px 12px; font-size: 13px;">Salvar</button>
                    </form>
                </details>

                <!-- Data de término -->
                <form method="POST" action="/admin/campanha/data-fim" style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <input type="hidden" name="ano" value="<?= $ano ?>">
                    <label style="margin: 0; font-size: 0.9em;"><strong>Término da campanha:</strong></label>
                    <input type="date" name="data_fim" value="<?= e($c['data_fim'] ?? '') ?>" style="width: auto; padding: 4px 8px;">
                    <button type="submit" style="background: #17a2b8; color: white; border: none; padding: 6px 12px; font-size: 13px;">Salvar</button>
                    <?php if (!empty($c['data_fim'])): ?>
                        <?php
                        $dias_restantes = (int)((strtotime($c['data_fim']) - strtotime('today')) / 86400);
                        $cor_dias = $dias_restantes <= 3 ? '#dc3545' : ($dias_restantes <= 7 ? '#ffc107' : '#28a745');
                        ?>
                        <small style="color: <?= $cor_dias ?>;">
                            <?php if ($dias_restantes > 0): ?>
                                (<?= $dias_restantes ?> dia<?= $dias_restantes > 1 ? 's' : '' ?> restante<?= $dias_restantes > 1 ? 's' : '' ?>)
                            <?php elseif ($dias_restantes === 0): ?>
                                (encerra hoje)
                            <?php else: ?>
                                (encerrada há <?= abs($dias_restantes) ?> dia<?= abs($dias_restantes) > 1 ? 's' : '' ?>)
                            <?php endif; ?>
                        </small>
                    <?php endif; ?>
                </form>

                <!-- Funil -->
                <div class="grid" style="margin-bottom: 15px;">
                    <div style="background: #17a2b8; padding: 10px; border-radius: 8px; text-align: center;">
                        <strong style="color: white; font-size: 1.3em;"><?= (int)($stats['enviados'] ?? 0) ?></strong>
                        <br><small style="color: white;">Enviados</small>
                    </div>
                    <div style="background: #6c757d; padding: 10px; border-radius: 8px; text-align: center;">
                        <strong style="color: white; font-size: 1.3em;"><?= (int)($stats['acessos'] ?? 0) ?></strong>
                        <br><small style="color: white;">Acessaram</small>
                    </div>
                    <div style="background: #ffc107; padding: 10px; border-radius: 8px; text-align: center;">
                        <strong style="color: #000; font-size: 1.3em;"><?= (int)($stats['pendentes'] ?? 0) ?></strong>
                        <br><small>Pendentes</small>
                    </div>
                    <div style="background: #28a745; padding: 10px; border-radius: 8px; text-align: center;">
                        <strong style="color: white; font-size: 1.3em;"><?= (int)($stats['pagos'] ?? 0) ?></strong>
                        <br><small style="color: white;">Pagos</small>
                    </div>
                    <div style="background: #d4edda; padding: 10px; border-radius: 8px; text-align: center;">
                        <strong style="color: #155724; font-size: 1.3em;"><?= formatar_valor((int)($stats['arrecadado'] ?? 0)) ?></strong>
                        <br><small style="color: #155724;">Arrecadado</small>
                    </div>
                </div>

                <!-- Grupo de teste -->
                <details style="margin-bottom: 15px; background: #fff8e1; padding: 12px 15px; border-radius: 8px; border: 1px solid #ffe082;">
                    <summary style="cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center;">
                        <strong>Grupo de teste</strong>
                        <span style="color: #f57c00; font-size: 13px;">Editar</span>
                    </summary>
                    <form method="POST" action="/admin/campanha/grupo-teste" style="margin-top: 10px;">
                        <textarea name="grupo_teste" rows="3" style="font-size: 13px; font-family: monospace;" placeholder="Um email por linha"><?= e(str_replace(',', "\n", $grupo_teste)) ?></textarea>
                        <div style="display: flex; gap: 10px; align-items: center;">
                            <button type="submit" style="background: #f57c00; color: white; border: none; padding: 6px 12px; font-size: 13px;">Salvar lista</button>
                            <small style="color: #666;"><?= count(array_filter(explode(',', $grupo_teste))) ?> emails</small>
                        </div>
                    </form>
                    <form method="POST" action="/admin/campanha/enviar-teste" style="margin-top: 10px;">
                        <input type="hidden" name="ano" value="<?= $ano ?>">
                        <button type="submit" style="background: #28a745; color: white; border: none; padding: 8px 16px; font-size: 14px;"
                                onclick="return confirm('Enviar emails para o grupo de teste (<?= count(array_filter(explode(',', $grupo_teste))) ?> pessoas)?')">
                            Enviar para grupo de teste
                        </button>
                    </form>
                </details>

                <!-- Envio Manual de Emails -->
                <details open style="margin-bottom: 15px; background: #f0fff0; padding: 12px 15px; border-radius: 8px; border: 1px solid #b2dfdb;" id="envio-manual-<?= $ano ?>">
                    <summary style="cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center;">
                        <strong style="color: #2e7d32;">Envio de Emails</strong>
                        <span style="color: #2e7d32; font-size: 13px;">Envio manual por lotes</span>
                    </summary>
                    <div style="margin-top: 12px;">
                        <div id="grupos-<?= $ano ?>" style="margin-bottom: 12px;">
                            <small style="color: #888;">Carregando grupos...</small>
                        </div>
                        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 8px;">
                            <div style="background: #e8f5e9; padding: 6px 12px; border-radius: 4px;">
                                Enviados hoje: <strong id="enviados-hoje-<?= $ano ?>">...</strong> / 290
                            </div>
                            <button type="button" id="btn-enviar-<?= $ano ?>"
                                    onclick="enviarLote(<?= $ano ?>)"
                                    style="background: #2e7d32; color: white; border: none; padding: 10px 20px; font-size: 14px; border-radius: 5px; cursor: pointer;">
                                Enviar proximo lote (50)
                            </button>
                        </div>
                        <div id="log-envio-<?= $ano ?>" style="font-family: monospace; font-size: 12px; color: #555; max-height: 150px; overflow-y: auto;"></div>
                    </div>
                </details>

                <!-- Lembretes -->
                <details style="margin-bottom: 15px; background: #fff3e0; padding: 12px 15px; border-radius: 8px; border: 1px solid #ffe0b2;" id="lembretes-<?= $ano ?>">
                    <summary style="cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center;">
                        <strong style="color: #e65100;">Lembretes</strong>
                        <span style="color: #e65100; font-size: 13px;" id="lembretes-badge-<?= $ano ?>"></span>
                    </summary>
                    <div style="margin-top: 12px;">
                        <div id="lembretes-info-<?= $ano ?>">
                            <small style="color: #888;">Carregando...</small>
                        </div>
                        <div style="margin-top: 10px;">
                            <button type="button" id="btn-lembretes-<?= $ano ?>"
                                    onclick="processarLembretes(<?= $ano ?>)"
                                    style="background: #e65100; color: white; border: none; padding: 8px 16px; font-size: 13px; border-radius: 5px; cursor: pointer;">
                                Processar lembretes de hoje
                            </button>
                            <span id="lembretes-resultado-<?= $ano ?>" style="margin-left: 10px; font-size: 13px;"></span>
                        </div>
                    </div>
                </details>

                <!-- Ações -->
                <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                    <form method="POST" action="/admin/campanha/fechar" style="margin: 0;">
                        <input type="hidden" name="ano" value="<?= $ano ?>">
                        <button type="submit" style="background: #dc3545; color: white; border: none; padding: 8px 16px; font-size: 14px;"
                                onclick="return confirm('Fechar campanha <?= $ano ?>? Não pagos serão marcados.') && confirm('Tem certeza?')">
                            Fechar Campanha
                        </button>
                    </form>
                </div>

            <?php else: ?>
                <!-- Campanha fechada: mostra resumo final com métricas detalhadas -->
                <?php
                    $m = $c['metricas'] ?? null;
                    $emails_env = $m ? $m['emails_enviados'] : 0;
                    $nao_renov = $m ? $m['nao_renovaram']['total'] : 0;
                    $renovaram = $m ? $m['renovaram']['total'] : 0;
                    // Base = filiados do ano anterior (renovaram + não renovaram)
                    $base_ano_anterior = $renovaram + $nao_renov;
                    $pct_nao_renov = $base_ano_anterior > 0 ? round(100 * $nao_renov / $base_ano_anterior) : 0;
                ?>

                <!-- Resumo principal -->
                <div class="grid" style="margin-bottom: 15px;">
                    <div style="background: #17a2b8; padding: 10px; border-radius: 8px; text-align: center;">
                        <strong style="color: white; font-size: 1.3em;"><?= $emails_env ?></strong>
                        <br><small style="color: white;">Emails Enviados</small>
                    </div>
                    <div style="background: #28a745; padding: 10px; border-radius: 8px; text-align: center;">
                        <strong style="color: white; font-size: 1.3em;"><?= (int)($stats['pagos'] ?? 0) ?></strong>
                        <br><small style="color: white;">Filiados</small>
                    </div>
                    <div style="background: #dc3545; padding: 10px; border-radius: 8px; text-align: center;">
                        <strong style="color: white; font-size: 1.3em;"><?= $nao_renov ?></strong>
                        <span style="color: white; font-size: 0.85em;">(<?= $pct_nao_renov ?>% de <?= $ano - 1 ?>)</span>
                        <br><small style="color: white;">Não Renovaram</small>
                    </div>
                    <div style="background: #d4edda; padding: 10px; border-radius: 8px; text-align: center;">
                        <strong style="color: #155724; font-size: 1.3em;"><?= formatar_valor((int)($stats['arrecadado'] ?? 0)) ?></strong>
                        <br><small style="color: #155724;">Arrecadado</small>
                    </div>
                </div>

                <?php if ($m): ?>
                <?php
                    // Calcula percentuais (em relação ao total de filiados do ano)
                    $total_filiados = (int)($stats['pagos'] ?? 0);
                    $pct_novos = $total_filiados > 0 ? round(100 * $m['novos']['total'] / $total_filiados) : 0;
                    $pct_retornaram = $total_filiados > 0 ? round(100 * $m['retornaram']['total'] / $total_filiados) : 0;
                    $pct_renovaram = $total_filiados > 0 ? round(100 * $m['renovaram']['total'] / $total_filiados) : 0;
                ?>
                <!-- Composição dos filiados -->
                <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 15px;">
                    <div style="flex: 1; min-width: 120px; background: #e3f2fd; padding: 10px; border-radius: 8px; text-align: center;">
                        <strong style="color: #1565c0;"><?= $m['novos']['total'] ?></strong>
                        <span style="color: #1565c0; font-size: 0.85em;">(<?= $pct_novos ?>%)</span>
                        <br><small style="color: #1565c0;">🆕 Novos</small>
                    </div>
                    <div style="flex: 1; min-width: 120px; background: #fff3e0; padding: 10px; border-radius: 8px; text-align: center;">
                        <strong style="color: #e65100;"><?= $m['retornaram']['total'] ?></strong>
                        <span style="color: #e65100; font-size: 0.85em;">(<?= $pct_retornaram ?>%)</span>
                        <br><small style="color: #e65100;">🔄 Retornaram</small>
                    </div>
                    <div style="flex: 1; min-width: 120px; background: #e8f5e9; padding: 10px; border-radius: 8px; text-align: center;">
                        <strong style="color: #2e7d32;"><?= $m['renovaram']['total'] ?></strong>
                        <span style="color: #2e7d32; font-size: 0.85em;">(<?= $pct_renovaram ?>%)</span>
                        <br><small style="color: #2e7d32;">✅ Renovaram</small>
                    </div>
                </div>

                <!-- Detalhes expandíveis -->
                <details style="margin-top: 10px;">
                    <summary style="cursor: pointer;">Detalhes por categoria</summary>

                    <table style="margin-top: 10px; font-size: 0.85em; width: 100%;">
                        <thead>
                            <tr style="background: #f8f9fa;">
                                <th></th>
                                <th style="text-align: center;">Estudante</th>
                                <th style="text-align: center;">Nacional</th>
                                <th style="text-align: center;">Internacional</th>
                                <th style="text-align: right;">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><strong>Filiados</strong></td>
                                <?php foreach (['estudante', 'profissional_nacional', 'profissional_internacional'] as $cat_key): ?>
                                    <td style="text-align: center;"><?= $c['categorias'][$cat_key]['qtd'] ?? 0 ?></td>
                                <?php endforeach; ?>
                                <td style="text-align: right;"><strong><?= (int)($stats['pagos'] ?? 0) ?></strong></td>
                            </tr>
                            <tr style="color: #1565c0;">
                                <td>&nbsp;&nbsp;🆕 Novos</td>
                                <?php foreach (['estudante', 'profissional_nacional', 'profissional_internacional'] as $cat_key): ?>
                                    <td style="text-align: center;"><?= $m['novos']['por_categoria'][$cat_key]['qtd'] ?? 0 ?></td>
                                <?php endforeach; ?>
                                <td style="text-align: right;"><?= $m['novos']['total'] ?></td>
                            </tr>
                            <tr style="color: #e65100;">
                                <td>&nbsp;&nbsp;🔄 Retornaram</td>
                                <?php foreach (['estudante', 'profissional_nacional', 'profissional_internacional'] as $cat_key): ?>
                                    <td style="text-align: center;"><?= $m['retornaram']['por_categoria'][$cat_key]['qtd'] ?? 0 ?></td>
                                <?php endforeach; ?>
                                <td style="text-align: right;"><?= $m['retornaram']['total'] ?></td>
                            </tr>
                            <tr style="color: #2e7d32;">
                                <td>&nbsp;&nbsp;✅ Renovaram</td>
                                <?php foreach (['estudante', 'profissional_nacional', 'profissional_internacional'] as $cat_key): ?>
                                    <td style="text-align: center;"><?= $m['renovaram']['por_categoria'][$cat_key]['qtd'] ?? 0 ?></td>
                                <?php endforeach; ?>
                                <td style="text-align: right;"><?= $m['renovaram']['total'] ?></td>
                            </tr>
                            <tr style="color: #c62828; border-top: 1px solid #ddd;">
                                <td><strong>Não Renovaram</strong></td>
                                <?php foreach (['estudante', 'profissional_nacional', 'profissional_internacional'] as $cat_key): ?>
                                    <td style="text-align: center;"><?= $m['nao_renovaram']['por_categoria'][$cat_key]['qtd'] ?? 0 ?></td>
                                <?php endforeach; ?>
                                <td style="text-align: right;"><strong><?= $m['nao_renovaram']['total'] ?></strong></td>
                            </tr>
                            <tr style="border-top: 2px solid #333;">
                                <td><strong>Arrecadado</strong></td>
                                <?php foreach (['estudante', 'profissional_nacional', 'profissional_internacional'] as $cat_key): ?>
                                    <td style="text-align: center;"><?= formatar_valor($c['categorias'][$cat_key]['total'] ?? 0) ?></td>
                                <?php endforeach; ?>
                                <td style="text-align: right;"><strong><?= formatar_valor((int)($stats['arrecadado'] ?? 0)) ?></strong></td>
                            </tr>
                        </tbody>
                    </table>
                </details>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Histórico de envios -->
            <?php if (!empty($c['envios'])): ?>
            <details style="margin-top: 15px; background: #e8f4f8; padding: 10px 15px; border-radius: 8px; border: 1px solid #bee5eb;">
                <summary style="cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center;">
                    <strong style="color: #17a2b8;">Envios (<?= count($c['envios']) ?>)</strong>
                    <span style="color: #17a2b8; font-size: 13px;">Ver historico</span>
                </summary>
                <div style="margin-top: 10px;">
                    <?php foreach ($c['envios'] as $envio): ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid #d1ecf1; flex-wrap: wrap; gap: 5px;">
                        <div>
                            <strong><?= e(ucfirst($envio['tipo'])) ?></strong>
                            <span style="color: #666; margin-left: 8px;"><?= $envio['total_sucesso'] ?> enviados<?php if ($envio['total_falha'] > 0): ?>, <span style="color: #dc3545;"><?= $envio['total_falha'] ?> falhas</span><?php endif; ?></span>
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <small style="color: #888;"><?= date('d/m/Y H:i', strtotime($envio['created_at'])) ?></small>
                            <a href="/admin/envio/<?= $envio['id'] ?>" style="font-size: 0.8em; padding: 3px 8px; background: #17a2b8; color: white; border-radius: 4px; text-decoration: none;">Ver</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </details>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if (empty($campanhas)): ?>
        <p>Nenhuma campanha encontrada. Crie a primeira acima.</p>
    <?php endif; ?>
</article>

<script>
// Carrega preview dos grupos ao abrir a pagina
document.addEventListener('DOMContentLoaded', function() {
    <?php foreach ($campanhas as $c): ?>
    <?php if (in_array($c['status'], ['aberta', 'enviando', 'pausada'])): ?>
    carregarPreview(<?= $c['ano'] ?>);
    <?php endif; ?>
    <?php endforeach; ?>
});

async function carregarPreview(ano) {
    try {
        const resp = await fetch('/admin/campanha/preview-lote', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'ano=' + ano
        });
        const data = await resp.json();
        atualizarGrupos(ano, data.grupos);
        document.getElementById('enviados-hoje-' + ano).textContent = data.enviados_hoje;

        if (data.enviados_hoje >= data.limite_diario) {
            const btn = document.getElementById('btn-enviar-' + ano);
            btn.disabled = true;
            btn.textContent = 'Limite diario atingido';
            btn.style.background = '#999';
        }
    } catch (e) {
        document.getElementById('grupos-' + ano).innerHTML = '<small style="color: red;">Erro ao carregar grupos</small>';
    }
}

function atualizarGrupos(ano, grupos) {
    let total = 0;
    let html = '<table style="width: 100%; font-size: 0.9em; margin-bottom: 5px;">';
    grupos.forEach(function(g, i) {
        total += g.total;
        const cor = g.total > 0 ? '#333' : '#aaa';
        html += '<tr style="color: ' + cor + ';">'
            + '<td style="padding: 3px 0;">Grupo ' + (i+1) + ': <strong>' + g.nome + '</strong></td>'
            + '<td style="text-align: right; padding: 3px 0;">' + g.total + ' destinatarios</td>'
            + '<td style="text-align: right; padding: 3px 0; color: #888; font-size: 0.85em;">' + g.template + '</td>'
            + '</tr>';
    });
    html += '<tr style="border-top: 1px solid #ccc; font-weight: bold;">'
        + '<td style="padding: 5px 0;">Total</td>'
        + '<td style="text-align: right; padding: 5px 0;">' + total + ' destinatarios</td>'
        + '<td></td></tr>';
    html += '</table>';
    document.getElementById('grupos-' + ano).innerHTML = html;
}

async function enviarLote(ano) {
    const btn = document.getElementById('btn-enviar-' + ano);
    const log = document.getElementById('log-envio-' + ano);

    if (!confirm('Enviar proximo lote de ate 50 emails?')) return;

    btn.disabled = true;
    btn.textContent = 'Enviando...';
    btn.style.background = '#888';

    try {
        const resp = await fetch('/admin/campanha/enviar-lote', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'ano=' + ano
        });
        const data = await resp.json();

        if (data.erro) {
            log.innerHTML += '<div style="color: red;">' + data.erro + '</div>';
            btn.disabled = true;
            btn.textContent = 'Limite atingido';
            btn.style.background = '#999';
            return;
        }

        // Atualiza contadores
        atualizarGrupos(ano, data.grupos);
        document.getElementById('enviados-hoje-' + ano).textContent = data.enviados_hoje;

        // Mostra resultado
        const agora = new Date().toLocaleTimeString('pt-BR');
        log.innerHTML += '<div>' + agora + ' - Lote: ' + data.enviados + ' enviados'
            + (data.erros > 0 ? ', ' + data.erros + ' erros' : '')
            + (data.grupo_atual ? ' (' + data.grupo_atual + ')' : '')
            + '</div>';
        log.scrollTop = log.scrollHeight;

        if (data.enviados_hoje >= data.limite_diario) {
            btn.disabled = true;
            btn.textContent = 'Limite diario atingido';
            btn.style.background = '#999';
        } else if (data.enviados === 0) {
            btn.disabled = true;
            btn.textContent = 'Todos enviados';
            btn.style.background = '#999';
        } else {
            btn.disabled = false;
            btn.textContent = 'Enviar proximo lote (50)';
            btn.style.background = '#2e7d32';
        }
    } catch (e) {
        log.innerHTML += '<div style="color: red;">Erro de rede: ' + e.message + '</div>';
        btn.disabled = false;
        btn.textContent = 'Enviar proximo lote (50)';
        btn.style.background = '#2e7d32';
    }
}

async function processarLembretes(ano) {
    const btn = document.getElementById('btn-lembretes-' + ano);
    const resultado = document.getElementById('lembretes-resultado-' + ano);

    btn.disabled = true;
    btn.textContent = 'Processando...';

    try {
        const resp = await fetch('/admin/lembretes/processar', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'ano=' + ano
        });
        const data = await resp.json();

        resultado.innerHTML = '<span style="color: #2e7d32;">'
            + data.enviados + ' enviados, '
            + data.erros + ' erros, '
            + data.pulados + ' pulados'
            + '</span>';

        btn.disabled = false;
        btn.textContent = 'Processar lembretes de hoje';
    } catch (e) {
        resultado.innerHTML = '<span style="color: red;">Erro: ' + e.message + '</span>';
        btn.disabled = false;
        btn.textContent = 'Processar lembretes de hoje';
    }
}
</script>
