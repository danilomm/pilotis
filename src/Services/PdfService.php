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
            self::prepararTcpdf();
            require_once $tcpdf_path;
            require_once __DIR__ . '/PilotisTcpdf.php';
            return self::gerarComTcpdf($nome, $email, $ano, $html_corpo);
        }

        // Fallback: PDF simples com texto (ver aviso em gerarPdfSimples)
        return self::gerarPdfSimples($nome, $email, $html_corpo);
    }

    /**
     * Gera o COMPROVANTE DE INSCRICAO em evento.
     *
     * Nao e recibo: quem recebe o dinheiro e o Docomomo Brasil (via PagBank),
     * enquanto quem assina aqui e a coordenacao do evento, que atesta a
     * inscricao. O documento traz os dados do pagamento (valor, data, forma)
     * para servir tambem a prestacao de contas na universidade.
     * Os assinantes vem do EVENTO — cada seminario tem a sua coordenacao.
     */
    public static function gerarComprovanteInscricao(array $dados): string {
        $valor_formatado = formatar_valor((int)($dados['valor'] ?? 0));
        $data_pag = !empty($dados['data_pagamento'])
            ? date('d/m/Y', strtotime($dados['data_pagamento']))
            : date('d/m/Y');

        $metodos = ['pix' => 'PIX', 'boleto' => 'Boleto bancário', 'cartao' => 'Cartão de crédito'];
        $metodo = $metodos[$dados['metodo'] ?? ''] ?? ($dados['metodo'] ?? '');

        // Inscricao isenta nao passa pelo template: o texto dele afirma
        // pagamento ("no valor de X, confirmado em D por M"), e nao ha
        // pagamento nenhum. Usa o texto proprio, que declara a isencao.
        $tpl = (int)($dados['valor'] ?? 0) === 0 ? null : carregar_template('evento_comprovante', [
            'nome' => $dados['nome'] ?? '',
            'cpf' => self::formatarCpf($dados['cpf'] ?? ''),
            'evento' => $dados['evento'] ?? '',
            'categoria' => $dados['categoria'] ?? '',
            'valor' => $valor_formatado,
            'data_pagamento' => $data_pag,
            'metodo' => $metodo,
        ]);

        $html_corpo = $tpl ? $tpl['html'] : self::textoComprovantePadrao(
            $dados['nome'] ?? '', self::formatarCpf($dados['cpf'] ?? ''),
            $dados['evento'] ?? '', $dados['categoria'] ?? '',
            $valor_formatado, $data_pag, $metodo
        );

        $html_corpo .= self::blocoAssinaturas($dados['assinantes'] ?? '');
        $html_corpo .= self::blocoContatoEvento($dados['email_contato'] ?? '');
        $html_corpo .= self::notaEmissaoEletronica();

        // Codigo de validacao: so existe quando sabemos de qual inscricao o
        // documento fala. Amostra avulsa sai sem QR, e nao com QR quebrado.
        $codigo = null;
        if (!empty($dados['inscricao_id'])) {
            require_once __DIR__ . '/ValidacaoService.php';
            $codigo = ValidacaoService::codigo('EVT', (int)$dados['inscricao_id']);
        }

        $tcpdf_path = BASE_DIR . '/vendor/tecnickcom/tcpdf/tcpdf.php';
        if (file_exists($tcpdf_path)) {
            self::prepararTcpdf();
            require_once $tcpdf_path;
            require_once __DIR__ . '/PilotisTcpdf.php';
            $faixa = !empty($dados['imagem_path'])
                ? EVENTOS_IMG_DIR . '/' . $dados['imagem_path']
                : null;

            return self::gerarComTcpdf(
                $dados['nome'] ?? '', $dados['email'] ?? '', 0, $html_corpo,
                'COMPROVANTE DE INSCRIÇÃO', $faixa, false, self::rodapeIdentificacao(),
                $codigo
            );
        }
        return self::gerarPdfSimples($dados['nome'] ?? '', $dados['email'] ?? '', $html_corpo);
    }

    /**
     * Texto padrao do comprovante (fallback se o template nao existir)
     */
    private static function textoComprovantePadrao(
        string $nome, string $cpf, string $evento, string $categoria,
        string $valor, string $data, string $metodo
    ): string {
        $abertura = "<p>Declaramos para os devidos fins que <strong>$nome</strong>" .
            ($cpf ? ", CPF $cpf," : ",") .
            " está inscrito(a) no evento <strong>$evento</strong>, " .
            "na categoria <strong>$categoria</strong>.</p>";

        // Inscricao isenta nao teve pagamento: dizer "pagamento confirmado por
        // R$ 0,00" seria falso, e e justamente este documento que vai para a
        // prestacao de contas.
        if ($valor === '' || $valor === 'Gratuita' || preg_match('/^R\$ ?0[,.]00$/', $valor)) {
            return $abertura . "<p>Inscrição <strong>isenta de taxa</strong>, " .
                "confirmada em <strong>$data</strong>.</p>";
        }

        return $abertura .
            "<p>Inscrição no valor de <strong>$valor</strong>, com pagamento " .
            "confirmado em <strong>$data</strong>" .
            ($metodo ? " por <strong>$metodo</strong>" : '') . ".</p>";
    }

    /**
     * Bloco de assinaturas: um nome por linha no campo do evento.
     * Sem assinantes cadastrados, nao imprime nada (melhor um comprovante sem
     * assinatura do que com o nome errado).
     */
    private static function blocoAssinaturas(string $assinantes): string {
        $linhas = array_values(array_filter(array_map('trim', explode("\n", $assinantes))));
        if (empty($linhas)) return '';

        // Documento emitido pelo sistema, sem assinatura de proprio punho: por
        // isso os nomes vao alinhados a esquerda, como identificacao de quem
        // responde pelo evento — nao como bloco de assinatura (que pediria
        // centralizacao e espaco em branco acima). Regua para assinar, nunca:
        // e erro formal.
        $html = "<div style='margin-top: 40px;'>";
        foreach ($linhas as $linha) {
            // "Nome (Instituicao)" -> nome em cima, instituicao embaixo
            $nome = $linha;
            $instituicao = '';
            if (preg_match('/^(.*?)\s*\(([^)]+)\)\s*$/u', $linha, $m)) {
                $nome = trim($m[1]);
                $instituicao = trim($m[2]);
            }

            $html .= "<p style='text-align: left; margin-bottom: 14px;'>" .
                     "<strong>" . htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') . "</strong>";
            if ($instituicao !== '') {
                $html .= "<br><span style='font-size: 10pt;'>" .
                         htmlspecialchars($instituicao, ENT_QUOTES, 'UTF-8') . "</span>";
            }
            $html .= "</p>";
        }
        return $html . "</div>";
    }

    /**
     * Contato do evento, em destaque logo abaixo das assinaturas.
     * Duvidas sobre o evento vao para a organizacao dele; os dados da entidade
     * (CNPJ, tesouraria) ficam como informacao secundaria de rodape.
     */
    private static function blocoContatoEvento(string $contato): string {
        $contato = trim($contato);
        if ($contato === '') return '';

        return "<p style='text-align: left; margin-top: 22px; font-size: 12pt;'>" .
               "<strong>" . htmlspecialchars($contato, ENT_QUOTES, 'UTF-8') . "</strong>" .
               "</p>";
    }

    /**
     * Nota de emissao eletronica.
     *
     * O documento leva os nomes da coordenacao mas ninguem assina de proprio
     * punho. Sem esta linha, uma prestacao de contas mais rigorosa pode
     * devolver o comprovante pedindo assinatura.
     */
    private static function notaEmissaoEletronica(): string {
        return "<p style='margin-top: 34px; font-size: 9pt; color: #555555;'>" .
               "Documento emitido eletronicamente em " . date('d/m/Y') .
               " pelo sistema de inscrições do " . htmlspecialchars(ORG_NOME, ENT_QUOTES, 'UTF-8') .
               ", dispensando assinatura." .
               "</p>";
    }

    /**
     * Rodape de identificacao da entidade, no pe da pagina.
     *
     * Mesma informacao do rodape do site: quem emite, CNPJ e email da
     * tesouraria — e o que uma prestacao de contas procura para identificar
     * o recebedor. Fica no `Footer()` do TCPDF para nao subir junto com o
     * texto quando o corpo for curto.
     */
    private static function rodapeIdentificacao(): array {
        return array_filter([
            'Pilotis — Sistema de Gestão de Filiados',
            ORG_RAZAO_SOCIAL . (ORG_CNPJ ? ' — CNPJ ' . ORG_CNPJ : ''),
            ORG_EMAIL_CONTATO,
        ]);
    }

    /**
     * Liga o modo de excecao do TCPDF ANTES de ele ser carregado.
     *
     * POR QUE: por padrao o TCPDF encerra o processo com die() em qualquer erro
     * — imagem que ele nao sabe ler, cache sem permissao de escrita, PNG com
     * canal alfa sem GD nem Imagick. O comentario dos chamadores diz que "se a
     * geracao falhar, o email sai sem anexo", mas catch(Exception) e
     * catch(Throwable) NAO capturam die: o processo morre no meio, a filiacao
     * ja esta paga, o PagBank recebe 200 com um fragmento HTML, o email nunca
     * sai e nenhuma linha entra no log. Com a constante definida antes do
     * require, o TCPDF lanca excecao e os try/catch passam a valer.
     */
    private static function prepararTcpdf(): void {
        if (!defined('K_TCPDF_THROW_EXCEPTION_ERROR')) {
            define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
        }
    }

    /**
     * CPF so-digitos -> 000.000.000-00
     */
    private static function formatarCpf(string $cpf): string {
        $d = preg_replace('/\D/', '', $cpf);

        // Zero a esquerda perdido em importacao de planilha: ha 41 cadastros
        // com menos de 11 digitos (36 com 10, 4 com 9, 1 com 8). Completar a
        // esquerda so vale se o resultado for um CPF VALIDO — senao estariamos
        // fabricando numero. Medido na base: os 41 ficam validos ao completar,
        // e os 227 com 11 digitos ja sao validos.
        if (strlen($d) > 0 && strlen($d) < 11 && cpf_valido(str_pad($d, 11, '0', STR_PAD_LEFT))) {
            $d = str_pad($d, 11, '0', STR_PAD_LEFT);
        }

        // Nao e CPF valido? Melhor omitir do que imprimir numero invalido ao
        // lado do nome da associacao, num documento que vai para reembolso ou
        // prestacao de contas, onde o CPF e conferido.
        if (!cpf_valido($d)) {
            return '';
        }
        return substr($d,0,3) . '.' . substr($d,3,3) . '.' . substr($d,6,3) . '-' . substr($d,9,2);
    }

    /**
     * Texto padrão da declaração (fallback se template não existir)
     */
    private static function textoDeclaracaoPadrao(string $nome, string $categoria, string $valor, int $ano): string {
        return "<p>Declaramos para os devidos fins que <strong>$nome</strong> " .
            "é filiado(a) ao <strong>" . ORG_NOME . "</strong> na categoria <strong>$categoria</strong>, " .
            "com anuidade de <strong>$valor</strong> referente ao ano de <strong>$ano</strong>, " .
            "devidamente quitada.</p>" .
            (ORG_ASSINANTE ? "<p style='margin-top: 60px; text-align: center;'>" .
            "<strong>" . ORG_ASSINANTE . "</strong><br>" .
            (ORG_CARGO ? ORG_CARGO . " do " . ORG_NOME . "<br>" : '') .
            (ORG_GESTAO ? "Gestão " . ORG_GESTAO : '') .
            "</p>" : '');
    }

    /**
     * Gera PDF usando TCPDF
     */
    private static function gerarComTcpdf(
        string $nome,
        string $email,
        int $ano,
        string $html_corpo,
        string $titulo = 'DECLARAÇÃO',
        ?string $imagem_cabecalho = null,
        bool $rodape_identificacao = true,
        array $rodape_pagina = [],
        ?string $codigo_validacao = null
    ): string {
        $pdf = new PilotisTcpdf('P', 'mm', 'A4', true, 'UTF-8', false);

        // Configuracoes
        $pdf->SetCreator('Pilotis - ' . ORG_NOME);
        $pdf->SetAuthor(ORG_NOME);
        $pdf->SetTitle(($titulo === 'DECLARAÇÃO' ? 'Declaracao de Filiacao' : 'Comprovante de Inscricao') . " - $nome");
        $pdf->SetMargins(25, 25, 25);
        $pdf->SetAutoPageBreak(true, 25);

        // Remove cabecalho e rodape padrao
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setRodapePilotis($rodape_pagina);

        $pdf->AddPage();

        // Cabecalho: faixa do proprio evento quando houver; senao o logo da
        // entidade. Faixa larga ocupa a largura util; logo fica centralizado.
        $topo = 55;
        $cabecalho = $imagem_cabecalho && file_exists($imagem_cabecalho)
            ? $imagem_cabecalho
            : PUBLIC_DIR . '/assets/img/' . ORG_LOGO;

        if (file_exists($cabecalho)) {
            $ext = strtoupper(pathinfo($cabecalho, PATHINFO_EXTENSION));
            $info = @getimagesize($cabecalho);
            $proporcao = ($info && $info[1] > 0) ? $info[0] / $info[1] : 1;

            if ($proporcao >= 2) {
                $largura = 160;                       // largura util (A4 - margens de 25mm)
                $altura = $largura / $proporcao;
                $pdf->Image($cabecalho, 25, 18, $largura, 0, $ext, '', '', false, 300);
                $topo = 18 + $altura + 14;
            } else {
                // Cartaz mais quadrado ou em retrato. $topo TEM de acompanhar a
                // altura real: este ramo so roda com proporcao < 2, entao 80mm
                // de largura dao mais de 40mm de altura, e a imagem termina
                // depois de y=60 — enquanto o titulo era impresso em y=55. Um
                // poster em retrato saia com "COMPROVANTE DE INSCRICAO", nome,
                // CPF e valor POR CIMA da arte, ilegivel se o cartaz for escuro.
                $largura = 80;
                $altura = $largura / $proporcao;
                // Cartaz muito alto comeria a pagina: teto de 90mm, reduzindo a
                // largura junto para nao distorcer.
                if ($altura > 90) {
                    $altura = 90;
                    $largura = $altura * $proporcao;
                }
                $x = (210 - $largura) / 2;            // centralizado em A4
                $pdf->Image($cabecalho, $x, 20, $largura, 0, $ext, '', '', false, 300);
                $topo = 20 + $altura + 14;
            }
        }

        // Titulo
        $pdf->SetY($topo);
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, $titulo, 0, 1, 'C');

        // Corpo, logo abaixo do titulo
        $pdf->Ln(6);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->writeHTML($html_corpo, true, false, true, false, 'J');

        if ($codigo_validacao !== null) {
            self::blocoValidacao($pdf, $codigo_validacao);
        }

        // Identificacao no rodape: util na declaracao de filiacao, redundante
        // no comprovante de inscricao (nome e CPF ja estao no corpo).
        if ($rodape_identificacao) {
            $pdf->SetY(190);
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(0, 5, $nome, 0, 1, 'L');
            $pdf->Cell(0, 5, $email, 0, 1, 'L');
        }

        return $pdf->Output('', 'S');
    }

    /**
     * QR de validacao, ancorado no pe da area de texto.
     *
     * Fica embaixo, junto do codigo em texto: quem tiver o papel na mao le o
     * QR; quem receber o PDF por email copia o codigo. O TCPDF ja traz o
     * gerador (include/barcodes/qrcode.php), entao nao entra dependencia.
     *
     * Se o conteudo tiver descido demais, o bloco vai para uma pagina nova em
     * vez de colidir com o texto.
     */
    private static function blocoValidacao(TCPDF $pdf, string $codigo): void {
        require_once __DIR__ . '/ValidacaoService.php';
        $url = ValidacaoService::url($codigo);

        $y = max($pdf->GetY() + 12, 205);
        if ($y > 235) {
            $pdf->AddPage();
            $y = 40;
        }

        $estilo = [
            'border' => false, 'vpadding' => 0, 'hpadding' => 0,
            'fgcolor' => [0, 0, 0], 'bgcolor' => false,
            'module_width' => 1, 'module_height' => 1,
        ];
        $pdf->write2DBarcode($url, 'QRCODE,M', 25, $y, 24, 24, $estilo, 'N');

        $pdf->SetXY(54, $y + 2);
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(60, 60, 60);
        $pdf->Cell(0, 5, 'Confira este documento', 0, 1, 'L');

        $pdf->SetX(54);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->MultiCell(110, 4,
            'Leia o QR ao lado ou acesse ' . rtrim(BASE_URL, '/') . '/validar' .
            ' e informe o código abaixo. A página mostra a situação atual do registro.',
            0, 'L');

        $pdf->SetX(54);
        $pdf->SetFont('courier', 'B', 10);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->Cell(0, 6, $codigo, 0, 1, 'L');

        $pdf->SetFont('helvetica', '', 12);
        $pdf->SetY($y + 26);
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
        // Este caminho so existe para nao deixar a pessoa sem nada quando o
        // TCPDF nao esta instalado, e o documento que ele produz e ruim:
        // acentuacao degradada (Type1 sem codificacao UTF-8) e xref reconstruido
        // pelo leitor. Como o vendor/ e copiado a mao por FTP no deploy, uma
        // transferencia incompleta faria TODA declaracao e TODO comprovante
        // sairem assim, por email, sem aviso nenhum. O log e a unica chance de
        // alguem perceber antes de um filiado reclamar.
        registrar_log('pdf_fallback', null,
            'TCPDF ausente em ' . BASE_DIR . '/vendor/ — documento gerado no formato simples,'
            . ' com acentuacao degradada. Conferir o upload do vendor/.');

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
            // A ordem importa: escapar a barra DEPOIS de inserir \\( e \\)
            // re-escapava as barras recem-inseridas, e o PDF imprimia
            // literalmente "filiado\\(a\\)" no meio do texto. Barra primeiro.
            $line = str_replace('\\', '\\\\', $line);
            $line = str_replace(['(', ')'], ['\\(', '\\)'], $line);
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
