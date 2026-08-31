<?php
/**
 * Pilotis - Aviso de privacidade (pagina publica)
 *
 * Controller separado, e nao um metodo pendurado noutro, porque a pagina nao e
 * de modulo nenhum: ela fala do sistema inteiro, e e linkada da entrada da
 * filiacao, da entrada do evento e dos dois formularios.
 *
 * Sem login, sem parametro, sem consulta ao banco. O texto e a versao vivem no
 * codigo (ver POLITICA_PRIVACIDADE_VERSAO no config.php), e nao no banco, de
 * proposito: o consentimento gravado aponta para uma versao, e a versao tem de
 * ser recuperavel no historico do git anos depois. Texto editavel pelo admin
 * seria texto sem historico.
 */

class PrivacidadeController {

    public static function ver(): void {
        $titulo = 'Aviso de privacidade';
        ob_start();
        require SRC_DIR . '/Views/privacidade.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }
}
