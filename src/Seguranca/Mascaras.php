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
function mascarar_cpf(?string $cpf): string {
    $d = preg_replace('/\D/', '', (string)$cpf);
    if (strlen($d) !== 11) return '';
    return '***.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-**';
}
