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

    // Cor verde do Docomomo
    const VERDE_DOCOMOMO = [74, 140, 74];

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
        int $valor_centavos,
        string $coordenadora = 'Marta Peixoto',
        string $gestao = '2026-2027'
    ): string {
        // Tenta usar TCPDF se disponivel
        $tcpdf_path = BASE_DIR . '/vendor/tecnickcom/tcpdf/tcpdf.php';
        if (file_exists($tcpdf_path)) {
            require_once $tcpdf_path;
            return self::gerarComTcpdf($nome, $email, $categoria, $ano, $valor_centavos, $coordenadora, $gestao);
        }

        // Fallback: gera PDF simples usando HTML-to-PDF nativo do navegador
        // (na pratica, retorna HTML que pode ser convertido pelo sistema de email)
        return self::gerarPdfSimples($nome, $email, $categoria, $ano, $valor_centavos, $coordenadora, $gestao);
    }

    /**
     * Gera PDF usando TCPDF
     */
    private static function gerarComTcpdf(
        string $nome,
        string $email,
        string $categoria,
        int $ano,
        int $valor_centavos,
        string $coordenadora,
        string $gestao
    ): string {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);

        // Configuracoes
        $pdf->SetCreator('Pilotis - Docomomo Brasil');
        $pdf->SetAuthor('Docomomo Brasil');
        $pdf->SetTitle("Declaracao de Filiacao - $nome");
        $pdf->SetMargins(25, 25, 25);
        $pdf->SetAutoPageBreak(true, 25);

        // Remove cabecalho e rodape padrao
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);

        $pdf->AddPage();

        // Logo
        $logo_path = PUBLIC_DIR . '/assets/img/logo-docomomo.jpg';
        if (file_exists($logo_path)) {
            $pdf->Image($logo_path, 65, 20, 80, 0, 'JPG', '', '', false, 300);
        }

        // Titulo
        $pdf->SetY(55);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'DECLARACAO', 0, 1, 'C');

        // Texto da declaracao
        $categoria_nome = CATEGORIAS_DISPLAY[$categoria] ?? $categoria;
        $valor_formatado = formatar_valor($valor_centavos);

        $texto = "Declaramos para os devidos fins que $nome e filiada/o ao " .
                 "Docomomo Brasil na modalidade $categoria_nome [Anuidade: $valor_formatado] " .
                 "para o periodo de janeiro a dezembro de $ano.";

        $pdf->SetY(75);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->MultiCell(0, 8, $texto, 0, 'J', false, 1, '', '', true);

        // Assinatura
        $pdf->SetY(130);
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 6, $coordenadora, 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 11);
        $pdf->Cell(0, 5, 'Coordenadora do Docomomo Brasil', 0, 1, 'L');
        $pdf->Cell(0, 5, 'Associacao de Colaboradores do Docomomo Brasil', 0, 1, 'L');
        $pdf->Cell(0, 5, "Gestao $gestao", 0, 1, 'L');
        $pdf->Cell(0, 5, '@docomomobrasil', 0, 1, 'L');

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
        string $categoria,
        int $ano,
        int $valor_centavos,
        string $coordenadora,
        string $gestao
    ): string {
        $categoria_nome = CATEGORIAS_DISPLAY[$categoria] ?? $categoria;
        $valor_formatado = formatar_valor($valor_centavos);

        // Gera PDF minimo valido usando especificacao PDF 1.4
        $content = "Declaramos para os devidos fins que $nome e filiada/o ao " .
                   "Docomomo Brasil na modalidade $categoria_nome [Anuidade: $valor_formatado] " .
                   "para o periodo de janeiro a dezembro de $ano.\n\n" .
                   "$coordenadora\n" .
                   "Coordenadora do Docomomo Brasil\n" .
                   "Associacao de Colaboradores do Docomomo Brasil\n" .
                   "Gestao $gestao\n" .
                   "@docomomobrasil\n\n" .
                   "$nome\n$email";

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
