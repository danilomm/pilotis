<?php
/**
 * Mapeamento de typos conhecidos em emails
 * Chave = email com typo (lowercase)
 * Valor = email correto
 *
 * Regras:
 * - gmal.com → gmail.com
 * - gmial.com → gmail.com
 * - hotmal.com → hotmail.com
 * - yaho.com → yahoo.com
 * - outloook.com → outlook.com
 */
return [
    // Domínios com typos comuns
    'dominios' => [
        'gmal.com' => 'gmail.com',
        'gmial.com' => 'gmail.com',
        'gmail.co' => 'gmail.com',
        'gmail.con' => 'gmail.com',
        'gmail.om' => 'gmail.com',
        'gamil.com' => 'gmail.com',
        'gmaill.com' => 'gmail.com',
        'gnail.com' => 'gmail.com',
        'g]mail.com' => 'gmail.com',
        'hotmal.com' => 'hotmail.com',
        'hotmial.com' => 'hotmail.com',
        'hotmail.co' => 'hotmail.com',
        'hotmail.con' => 'hotmail.com',
        'hotmaill.com' => 'hotmail.com',
        'hotmil.com' => 'hotmail.com',
        'hotmai.com' => 'hotmail.com',
        'yaho.com' => 'yahoo.com',
        'yahho.com' => 'yahoo.com',
        'yahoo.co' => 'yahoo.com',
        'yahooo.com' => 'yahoo.com',
        'yahoo.com.b' => 'yahoo.com.br',
        'outloook.com' => 'outlook.com',
        'outlok.com' => 'outlook.com',
        'outlook.con' => 'outlook.com',
        'outllook.com' => 'outlook.com',
        'uol.com' => 'uol.com.br',
        'uol.co' => 'uol.com.br',
        'bol.co' => 'bol.com.br',
        'terra.co' => 'terra.com.br',
        'ig.co' => 'ig.com.br',
    ],

    // Emails específicos com typos conhecidos (descobertos durante importações)
    'emails' => [
        // Adicionar aqui emails específicos com typos conhecidos
        // 'emailcomtypo@gmail.com' => 'emailcorreto@gmail.com',
    ],
];
