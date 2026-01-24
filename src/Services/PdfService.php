<?php
/**
 * Pilotis - Geracao de PDF para declaracao de filiacao
 *
 * Usa TCPDF para gerar o PDF.
 * Instalar: composer require tecnickcom/tcpdf
 *
 * Se TCPDF nao estiver disponivel, usa geracao simples com HTML.
 */

class PdfService {

    /**
     * Retorna a cor primária da organização como array RGB
     */
    private static function corPrimaria(): array {
        $hex = ltrim(ORG_COR_PRIMARIA, '#');
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Gera PDF da declaracao de filiacao
     *
     * Retorna bytes do PDF
     */
    public static function gerarDeclaracao(
        string $nome,
        string $email,
        string $categoria,
        int $ano,
        int $valor_centavos
    ): string {
        $categoria_nome = CATEGORIAS_DISPLAY[$categoria] ?? $categoria;
        $valor_formatado = formatar_valor($valor_centavos);

        // Carrega template do banco (coordenadora/gestão estão no texto do template)
        $tpl = carregar_template('declaracao', [
            'nome' => $nome,
            'ano' => $ano,
            'categoria' => $categoria_nome,
            'valor' => $valor_formatado,
        ]);

        $html_corpo = $tpl ? $tpl['html'] : self::textoDeclaracaoPadrao($nome, $categoria_nome, $valor_formatado, $ano);

        // Tenta usar TCPDF se disponivel
        $tcpdf_path = BASE_DIR . '/vendor/tecnickcom/tcpdf/tcpdf.php';
        if (file_exists($tcpdf_path)) {
            require_once $tcpdf_path;
            return self::gerarComTcpdf($nome, $email, $ano, $html_corpo);
        }

        // Fallback: PDF simples com texto
        return self::gerarPdfSimples($nome, $email, $html_corpo);
    }

    /**
     * Texto padrão da declaração (fallback se template não existir)
     */
    private static function textoDeclaracaoPadrao(string $nome, string $categoria, string $valor, int $ano): string {
        return "<p>Declaramos para os devidos fins que <strong>$nome</strong> " .
            "é filiado(a) ao <strong>" . ORG_NOME . "</strong> na categoria <strong>$categoria</strong>, " .
            "com anuidade de <strong>$valor</strong> referente ao ano de <strong>$ano</strong>, " .
            "devidamente quitada.</p>" .
            "<p style='margin-top: 60px; text-align: center;'>" .
            "<strong>Marta Peixoto</strong><br>" .
            "Coordenadora do Docomomo Brasil<br>" .
            "Gestão 2026-2027</p>";
    }

    /**
     * Gera PDF usando TCPDF
     */
    private static function gerarComTcpdf(
        string $nome,
        string $email,
        int $ano,
        string $html_corpo
    ): string {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Configuracoes
        $pdf->SetCreator('Pilotis - ' . ORG_NOME);
        $pdf->SetAuthor(ORG_NOME);
        $pdf->SetTitle("Declaracao de Filiacao - $nome");
        $pdf->SetMargins(25, 25, 25);
        $pdf->SetAutoPageBreak(true, 25);

        // Remove cabecalho e rodape padrao
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $pdf->AddPage();

        // Logo
        $logo_path = PUBLIC_DIR . '/assets/img/' . ORG_LOGO;
        if (file_exists($logo_path)) {
            $ext = strtoupper(pathinfo($logo_path, PATHINFO_EXTENSION));
            $pdf->Image($logo_path, 65, 20, 80, 0, $ext, '', '', false, 300);
        }

        // Titulo
        $pdf->SetY(55);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'DECLARAÇÃO', 0, 1, 'C');

        // Corpo da declaração (do template)
        $pdf->SetY(75);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->writeHTML($html_corpo, true, false, true, false, 'J');

        // Dados do filiado no rodape
        $pdf->SetY(190);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, $nome, 0, 1, 'L');
        $pdf->Cell(0, 5, $email, 0, 1, 'L');

        return $pdf->Output('', 'S');
    }

    /**
     * Gera PDF simples (fallback sem TCPDF)
     * Usa biblioteca nativa do PHP para gerar PDF basico
     */
    private static function gerarPdfSimples(
        string $nome,
        string $email,
        string $html_corpo
    ): string {
        // Converte HTML para texto simples
        $content = strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $html_corpo));
        $content = html_entity_decode($content, ENT_QUOTES, 'UTF-8');
        $content = preg_replace('/\n{3,}/', "\n\n", trim($content));
        $content .= "\n\n$nome\n$email";

        // PDF simples com texto
        $pdf = "%PDF-1.4\n";
        $pdf .= "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>\nendobj\n";

        // Stream de conteudo
        $stream = "BT\n/F1 12 Tf\n50 750 Td\n";

        // Quebra texto em linhas
        $lines = explode("\n", wordwrap($content, 70, "\n", true));
        foreach ($lines as $line) {
            $line = str_replace(['(', ')', '\\'], ['\\(', '\\)', '\\\\'], $line);
            $stream .= "($line) Tj\n0 -15 Td\n";
        }

        $stream .= "ET";

        $pdf .= "4 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream\nendobj\n";
        $pdf .= "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";

        $xref_offset = strlen($pdf);
        $pdf .= "xref\n0 6\n";
        $pdf .= "0000000000 65535 f \n";
        $pdf .= "0000000009 00000 n \n";
        $pdf .= "0000000058 00000 n \n";
        $pdf .= "0000000115 00000 n \n";
        $pdf .= sprintf("%010d 00000 n \n", 250);
        $pdf .= sprintf("%010d 00000 n \n", 250 + strlen($stream) + 50);

        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\n";
        $pdf .= "startxref\n$xref_offset\n";
        $pdf .= "%%EOF";

        return $pdf;
    }

    /**
     * Salva declaracao em arquivo
     */
    public static function salvarDeclaracao(
        string $caminho,
        string $nome,
        string $email,
        string $categoria,
        int $ano,
        int $valor_centavos
    ): string {
        $pdf_bytes = self::gerarDeclaracao($nome, $email, $categoria, $ano, $valor_centavos);
        file_put_contents($caminho, $pdf_bytes);
        return $caminho;
    }
}
