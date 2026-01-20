<?php
/**
 * Pilotis - Controller de Filiados (lista publica)
 */

class FiliadosController {

    /**
     * Lista filiados de um ano
     */
    public static function listar(string $ano): void {
        $filiados = listar_filiados((int)$ano);

        // Agrupa por categoria
        $por_categoria = [
            'profissional_internacional' => [],
            'profissional_nacional' => [],
            'estudante' => [],
        ];

        foreach ($filiados as $f) {
            $cat = $f['categoria'] ?? 'profissional_nacional';
            if (!isset($por_categoria[$cat])) {
                $por_categoria['profissional_nacional'][] = $f;
            } else {
                $por_categoria[$cat][] = $f;
            }
        }

        $titulo = "Filiados $ano";
        $total = count($filiados);

        ob_start();
        require SRC_DIR . '/Views/filiados/listar.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }
}
