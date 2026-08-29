<?php
/**
 * Pilotis — ponto de entrada da camada de dados.
 *
 * Este arquivo tinha 2.016 linhas e fazia quatro trabalhos sem relacao entre
 * si: abrir conexao, migrar schema, consultar, e guardar regra de negocio
 * (consolidacao, criterio de match, assinatura HMAC, mascaramento, limites).
 * Nao era uma camada, era uma gaveta. Em 29/08/2026 foi dividido:
 *
 *   Schema/     como o banco e aberto e como sua estrutura evolui
 *   Dados/      consultas, por assunto. Nenhuma regra de negocio aqui
 *   Dominio/    as decisoes: quem pode se inscrever, por quanto, e se dois
 *               cadastros sao a mesma pessoa
 *   Seguranca/  assinatura, limites e mascaramento
 *
 * O arquivo continua existindo e incluindo os quatro, de proposito: as 158
 * chamadas `require SRC_DIR . '/db.php'` espalhadas pelo sistema seguem
 * valendo, e a divisao nao precisou tocar em nenhuma delas. Quem escrever
 * codigo novo pode incluir so o modulo de que precisa.
 *
 * A ORDEM importa: Consulta antes de tudo que consulta; Conexao antes de
 * Migracao; Dados antes de Dominio, que os usa.
 */

require_once __DIR__ . '/config.php';

// Conexão singleton, usada por Schema/Conexao.php.
$_db = null;

// --- como se fala com o banco -------------------------------------------
require_once __DIR__ . '/Schema/Conexao.php';
require_once __DIR__ . '/Dados/Consulta.php';

// --- que forma o banco tem ----------------------------------------------
require_once __DIR__ . '/Schema/Migracao.php';
require_once __DIR__ . '/Schema/SeedTemplates.php';

// --- ferramentas que as consultas usam ----------------------------------
require_once __DIR__ . '/Seguranca/Assinatura.php';
require_once __DIR__ . '/Seguranca/Limites.php';
require_once __DIR__ . '/Seguranca/Mascaras.php';

// --- consultas, por assunto ---------------------------------------------
require_once __DIR__ . '/Dados/Log.php';
require_once __DIR__ . '/Dados/Pessoas.php';
require_once __DIR__ . '/Dados/Filiacoes.php';
require_once __DIR__ . '/Dados/Eventos.php';
require_once __DIR__ . '/Dados/Autocomplete.php';
require_once __DIR__ . '/Dados/Templates.php';

// --- regras -------------------------------------------------------------
require_once __DIR__ . '/Dominio/Consolidacao.php';
require_once __DIR__ . '/Dominio/Eventos.php';
require_once __DIR__ . '/Dominio/Inscricoes.php';
