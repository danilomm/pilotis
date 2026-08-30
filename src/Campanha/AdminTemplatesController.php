<?php
/**
 * Pilotis — Templates de email, editaveis pelo tesoureiro.
 *
 * Extraido do AdminController em 29/08/2026. Estende-o para herdar
 * exigirLogin(); as rotas em public/index.php apontam para esta classe.
 */

// A base define exigirLogin(). Exigida AQUI, e nao so no index.php: assim o
// arquivo funciona em qualquer ordem de carregamento — inclusive nos testes.
require_once __DIR__ . '/../Controllers/AdminController.php';

class AdminTemplatesController extends AdminController {

    /**
     * Lista templates de email para edição
     */
    public static function templates(): void {
        self::exigirLogin();

        $templates = db_fetch_all("SELECT * FROM email_templates ORDER BY tipo");

        $descricoes = [
            'confirmacao' => 'Confirmação de pagamento',
            'lembrete' => 'Lembrete de pagamento pendente',
            'renovacao' => 'Campanha de renovação',
            'convite' => 'Campanha para novos contatos',
            'seminario' => 'Campanha para participantes do seminário',
            'acesso' => 'Link de acesso ao formulário',
            'declaracao' => 'Texto da declaração PDF',
        ];

        $titulo = "Admin - Templates de Email";

        ob_start();
        require SRC_DIR . '/Campanha/Views/admin/templates.php';
        $content = ob_get_clean();
        require SRC_DIR . '/Views/layout.php';
    }

    /**
     * Salva alterações em um template de email
     */
    public static function salvarTemplate(): void {
        self::exigirLogin();

        $tipo = $_POST['tipo'] ?? '';
        $assunto = trim($_POST['assunto'] ?? '');
        $html = $_POST['html'] ?? '';

        if (!$tipo || !$assunto || !$html) {
            flash('error', 'Preencha todos os campos.');
            redirect('/admin/templates');
            return;
        }

        // Verifica se template existe
        $existente = db_fetch_one("SELECT tipo FROM email_templates WHERE tipo = ?", [$tipo]);
        if (!$existente) {
            flash('error', 'Template não encontrado.');
            redirect('/admin/templates');
            return;
        }

        db_execute(
            "UPDATE email_templates SET assunto = ?, html = ?, updated_at = ? WHERE tipo = ?",
            [$assunto, $html, date('Y-m-d H:i:s'), $tipo]
        );

        flash('success', "Template \"$tipo\" atualizado.");
        redirect('/admin/templates');
    }

    /**
     * Reseta um template para o valor padrão (seed)
     */
    public static function resetarTemplate(): void {
        self::exigirLogin();

        $tipo = $_POST['tipo'] ?? '';
        if (!$tipo) {
            redirect('/admin/templates');
            return;
        }

        // Remove e re-seeds o template específico
        db_execute("DELETE FROM email_templates WHERE tipo = ?", [$tipo]);

        // Re-seed todos (INSERT OR IGNORE só insere os que faltam)
        seed_email_templates(get_db());

        flash('success', "Template \"$tipo\" restaurado ao padrão.");
        redirect('/admin/templates');
    }

}