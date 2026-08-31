<?php
/**
 * Pilotis — Exibicao parcial de dado pessoal.
 *
 * Mostra o bastante para a pessoa reconhecer o proprio dado, sem revela-lo a
 * quem chegou ali com so uma pista.
 *
 * Extraido de src/db.php em 29/08/2026. O db.php continua existindo e
 * incluindo este arquivo, entao todo require antigo segue valendo.
 */

function mascarar_email(string $email): string {
    $email = trim($email);
    if (!$email || strpos($email, '@') === false) return $email;
    [$user, $dominio] = explode('@', $email, 2);
    // Mostra o bastante para a pessoa RECONHECER a propria caixa, sem revelar
    // o endereco a quem chegou ate aqui so com o CPF. Ex.:
    //   fulano@instituicao.br  ->  ful****@i**********.br
    $visiveis = min(3, max(1, mb_strlen($user) - 1));
    $user_mask = mb_substr($user, 0, $visiveis) . str_repeat('*', max(3, mb_strlen($user) - $visiveis));

    $partes = explode('.', $dominio);
    $ultimo = count($partes) - 1;
    foreach ($partes as $i => &$parte) {
        // Mantem o TLD (e o penultimo, tipo .arq.br / .com.br) legivel;
        // mascara so o nome do dominio.
        $eh_sufixo = ($i >= $ultimo - 1 && mb_strlen($parte) <= 3) || $i === $ultimo;
        if (!$eh_sufixo && mb_strlen($parte) > 2) {
            $parte = mb_substr($parte, 0, 1) . str_repeat('*', mb_strlen($parte) - 1);
        }
    }
    unset($parte);

    return $user_mask . '@' . implode('.', $partes);
}

/**
 * Mascara CPF para exibicao publica: 123.456.789-09 -> ***.456.789-**
 *
 * Usado na pagina de validacao de documento, que e publica por natureza:
 * mostra o bastante para quem tem o documento na mao conferir que e da
 * mesma pessoa, sem entregar o numero a quem so tem o link.
 */
/**
 * O documento da pessoa, mascarado, para a pagina publica de validacao.
 *
 * Quem abre `/validar/{codigo}` e um TERCEIRO — setor de reembolso, secretaria
 * de programa. Ele precisa casar o papel com o registro, nao ficar com o
 * numero: por isso o meio aparece e as pontas nao.
 *
 * Existe desde 30/08/2026, quando `pessoas.documento` passou a guardar o
 * documento de quem nao tem CPF. Sem isto, a validacao do comprovante de um
 * filiado estrangeiro nao mostra documento NENHUM, e quem confere fica so com
 * o nome.
 */
function mascarar_documento(?string $cpf, ?string $documento = null, ?string $tipo = null): string {
    $m = mascarar_cpf($cpf);
    if ($m !== '') return $m;

    $doc = trim((string)$documento);
    if ($doc === '') return '';

    // Mesma ideia do CPF: mostra o miolo, esconde as pontas. Documento curto
    // (4 caracteres ou menos) sai inteiro mascarado — nao ha miolo a mostrar
    // sem entregar quase tudo.
    $rotulo = trim((string)$tipo) !== '' ? ucfirst(trim((string)$tipo)) . ' ' : '';
    $n = mb_strlen($doc);
    if ($n <= 4) return $rotulo . str_repeat('*', $n);

    $mostra = max(1, (int)floor($n / 3));
    $ini = (int)floor(($n - $mostra) / 2);
    return $rotulo . str_repeat('*', $ini) . mb_substr($doc, $ini, $mostra) . str_repeat('*', $n - $ini - $mostra);
}

function mascarar_cpf(?string $cpf): string {
    $d = preg_replace('/\D/', '', (string)$cpf);
    if (strlen($d) !== 11) return '';
    return '***.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-**';
}
