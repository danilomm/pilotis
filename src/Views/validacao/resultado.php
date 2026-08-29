<?php
/**
 * Resultado da validacao de um documento.
 *
 * Tres desfechos, deliberadamente distintos a olho nu:
 *   - documento valido            (verde)
 *   - registro existe, sem efeito (ambar) — ex.: inscricao sem pagamento
 *   - codigo nao confere          (cinza) — formato errado ou assinatura falsa
 *
 * @var array|null $doc
 * @var string $codigo_exibicao
 */
$cor = !$doc ? '#6c757d' : ($doc['valido'] ? '#2E7D32' : '#B36B00');
?>

<article style="border-left: 6px solid <?= $cor ?>; padding-left: 1.2rem;">

    <?php if (!$doc): ?>

        <h2 style="color: <?= $cor ?>; margin-bottom: .3rem;">Documento não localizado</h2>
        <p>
            O código <strong><?= e($codigo_exibicao) ?></strong> não corresponde a
            nenhum documento emitido por este sistema.
        </p>
        <p>
            Confira se ele foi digitado por inteiro — o mais simples é ler o QR do
            próprio documento. Persistindo, escreva para
            <a href="mailto:<?= e(ORG_EMAIL_CONTATO) ?>"><?= e(ORG_EMAIL_CONTATO) ?></a>.
        </p>

    <?php else: ?>

        <h2 style="color: <?= $cor ?>; margin-bottom: .3rem;">
            <?= $doc['valido'] ? 'Documento válido' : 'Documento sem efeito' ?>
        </h2>
        <p style="margin-top: 0;"><strong><?= e($doc['situacao']) ?></strong></p>

        <table>
            <tbody>
                <tr>
                    <th scope="row" style="width: 30%;">Documento</th>
                    <td><?= e($doc['tipo']) ?></td>
                </tr>
                <tr>
                    <th scope="row">Nome</th>
                    <td><?= e($doc['nome']) ?></td>
                </tr>
                <?php if ($doc['cpf']): ?>
                <tr>
                    <th scope="row">CPF</th>
                    <td><?= e($doc['cpf']) ?></td>
                </tr>
                <?php endif; ?>
                <?php foreach ($doc['linhas'] as $rotulo => $valor): ?>
                <tr>
                    <th scope="row"><?= e($rotulo) ?></th>
                    <td><?= e($valor) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

    <footer style="margin-top: 1.5rem;">
        <small>
            Código <?= e($codigo_exibicao) ?> — consulta feita em
            <?= date('d/m/Y \à\s H:i') ?>.
            <br>
            A situação acima é lida no momento da consulta: um documento impresso
            continua com os dados do dia da emissão, esta página não.
        </small>
    </footer>

</article>
