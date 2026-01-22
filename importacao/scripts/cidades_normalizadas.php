<?php
/**
 * Mapeamento de normalização de cidades
 * Chave = valor original (lowercase, trimmed)
 * Valor = nome normalizado
 *
 * Regras:
 * - Remover estado/UF do nome da cidade
 * - Acentuar corretamente
 * - Capitalizar corretamente (preposições minúsculas)
 * - Valores vazios para entradas inválidas (estado como cidade, etc)
 */
return [
    // Aracaju
    'aracaju' => 'Aracaju',
    'aracaju - se' => 'Aracaju',

    // Belém
    'belem' => 'Belém',
    'belém' => 'Belém',
    'belém/pará' => 'Belém',

    // Belo Horizonte
    'belo horizonte' => 'Belo Horizonte',

    // Brasília
    'brasilia' => 'Brasília',
    'brasília' => 'Brasília',
    'distrito federal' => 'Brasília',

    // Camaragibe
    'camaragibe' => 'Camaragibe',

    // Campina Grande
    'campina grande' => 'Campina Grande',
    'campina grande/paraíba' => 'Campina Grande',

    // Campinas
    'campinas' => 'Campinas',

    // Carapicuíba
    'carapicuiba' => 'Carapicuíba',
    'carapicuíba' => 'Carapicuíba',

    // Florianópolis
    'florianopolis' => 'Florianópolis',
    'florianópolis' => 'Florianópolis',

    // Fortaleza
    'fortaleza' => 'Fortaleza',
    'fortaleza-ce' => 'Fortaleza',
    'fortaleza/ceará' => 'Fortaleza',

    // Goiânia
    'goiânia' => 'Goiânia',
    'goiânia/goiás' => 'Goiânia',

    // Limeira
    'limeira' => 'Limeira',

    // Maceió
    'maceió' => 'Maceió',
    'maceió, alagoas' => 'Maceió',

    // Recife
    'recife' => 'Recife',
    'recife/pernambuco' => 'Recife',

    // Salvador
    'salvador' => 'Salvador',
    'salvador/bahia' => 'Salvador',

    // São Carlos
    'são carlos' => 'São Carlos',
    'são carlos sp' => 'São Carlos',

    // São Luís
    'são luís' => 'São Luís',
    'são luis maranhão' => 'São Luís',
    'sao lus' => 'São Luís',

    // São Paulo
    'são paulo' => 'São Paulo',
    'sao paulo' => 'São Paulo',
    'são paulo sp' => 'São Paulo',
    'sao paulo sp' => 'São Paulo',
    'são paulo / são paulo' => 'São Paulo',
    'são paulo/são paulo' => 'São Paulo',

    // Teresina
    'teresina' => 'Teresina',
    'teresina/ piauí' => 'Teresina',

    // Uberlândia
    'uberlândia' => 'Uberlândia',
    'uberlândia, minas gerais' => 'Uberlândia',

    // Estados como cidade (inválidos - deveria ser vazio ou cidade correta)
    'rio grande do sul' => '', // Estado, não cidade
    'santa catarina' => '',    // Estado, não cidade
    'rs' => '',                // Sigla de estado
    'pe' => '',                // Sigla de estado

    // Outras cidades (identidade - já corretas)
    'anápolis' => 'Anápolis',
    'bauru' => 'Bauru',
    'blumenau' => 'Blumenau',
    'boa vista' => 'Boa Vista',
    'brusque' => 'Brusque',
    'campo grande' => 'Campo Grande',
    'campo largo' => 'Campo Largo',
    'carlos barbosa' => 'Carlos Barbosa',
    'carpina' => 'Carpina',
    'caruaru' => 'Caruaru',
    'cascavel' => 'Cascavel',
    'caxias do sul' => 'Caxias do Sul',
    'chapecó' => 'Chapecó',
    'cotia' => 'Cotia',
    'cuiabá' => 'Cuiabá',
    'curitiba' => 'Curitiba',
    'escada' => 'Escada',
    'feira de santana' => 'Feira de Santana',
    'guaíba' => 'Guaíba',
    'ijuí' => 'Ijuí',
    'itu' => 'Itu',
    'jaboatão' => 'Jaboatão',
    'jacareí' => 'Jacareí',
    'joinville' => 'Joinville',
    'joão pessoa' => 'João Pessoa',
    'juazeiro do norte' => 'Juazeiro do Norte',
    'juiz de fora' => 'Juiz de Fora',
    'lagoa seca' => 'Lagoa Seca',
    'lauro de freitas' => 'Lauro de Freitas',
    'londrina' => 'Londrina',
    'macapá' => 'Macapá',
    'manaus' => 'Manaus',
    'maringá' => 'Maringá',
    'matão' => 'Matão',
    'milano' => 'Milano',
    'mossoró' => 'Mossoró',
    'natal' => 'Natal',
    'niterói' => 'Niterói',
    'nova iorque' => 'Nova Iorque',
    'penha' => 'Penha',
    'porto alegre' => 'Porto Alegre',
    'porto velho' => 'Porto Velho',
    'pouso alegre' => 'Pouso Alegre',
    'presidente prudente' => 'Presidente Prudente',
    'rio de janeiro' => 'Rio de Janeiro',
    'rio negro' => 'Rio Negro',
    'santa maria' => 'Santa Maria',
    'são josé do rio pardo' => 'São José do Rio Pardo',
    'sorocaba' => 'Sorocaba',
    'vitória' => 'Vitória',
    'viçosa' => 'Viçosa',
];
