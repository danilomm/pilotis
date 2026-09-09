<?php
/**
 * Pilotis - Carteira de filiado (PDF)
 *
 * O Docomomo International emite, por Delft, uma carteira para quem paga a
 * faixa International+Brasil. Esta e a equivalente nacional, e existe por uma
 * diferenca de fundo: o QR da internacional codifica
 * `https://docomomo.com/docomomo-membership-2026-confirmation/` -- a MESMA URL
 * nas 54 carteiras. Ele nao identifica ninguem e nao afirma nada sobre quem
 * porta o cartao; e um link de pagina institucional impresso em forma de
 * quadrado.
 *
 * O QR daqui e por pessoa e aponta para `/validar/FIL-{filiacao_id}-{hmac}`,
 * que o `ValidacaoService` ja assinava desde 29/08/2026 sem nunca ter sido
 * ligado a documento nenhum. A pagina le o banco NA HORA: filiacao que caducar
 * ou pagamento estornado aparecem como sem efeito, com o PDF antigo na mao.
 *
 * E por isso que o cartao NAO afirma validade em texto. Papel nao sabe o que
 * aconteceu depois de impresso; a pagina sabe. O cartao diz de que ano e a
 * filiacao e onde se confere a situacao de hoje.
 *
 * Duas saidas do mesmo dado:
 *
 *   'tela'   756 x 1340 pt, a mesma pagina da carteira internacional, para as
 *            duas ficarem lado a lado no celular. Nao foi pensada para papel.
 *   'cartao' 85,6 x 54 mm, ISO/IEC 7810 ID-1 -- o tamanho do cartao de credito,
 *            para quem quiser imprimir e guardar na carteira.
 *
 * Cores: `ORG_COR_PRIMARIA` e `ORG_COR_SECUNDARIA`, e NAO o verde do manual
 * escrito aqui dentro. O projeto e GPL: outra associacao que instale o Pilotis
 * tem de receber a carteira na cor dela, nao na do Docomomo. Para o Docomomo
 * Brasil as duas chaves valem o que diz o manual de identidade visual --
 * Pantone 370 C (#399400) e o tingimento 70% (#84AE56).
 *
 * Tipografia: Futura, a familia do manual, quando instalada no TCPDF; helvetica
 * quando nao (o `scripts/gerar_carteirinhas.php` instala se faltar).
 */

require_once SRC_DIR . '/Services/ValidacaoService.php';

class Carteirinha {

    /** Marca em negativo, sem extensao: ver self::marca() para as tres formas. */
    private const MARCA = 'logo-docomomo-brasil-negativo';

    /**
     * Gera o PDF e devolve os bytes.
     *
     * $dados: filiacao_id, nome, categoria (chave), ano.
     */
    public static function gerar(array $dados, string $formato = 'tela'): string {
        if (!defined('K_TCPDF_THROW_EXCEPTION_ERROR')) {
            define('K_TCPDF_THROW_EXCEPTION_ERROR', true);
        }
        if (!class_exists('TCPDF')) {
            throw new RuntimeException('A carteira de filiado precisa do TCPDF.');
        }

        $formato = $formato === 'cartao' ? 'cartao' : 'tela';
        $pagina = $formato === 'cartao'
            ? [242.65, 153.07]   // 85,6 x 54 mm em pt
            : [756, 1340];       // a mesma pagina da carteira internacional

        $pdf = new PilotisTcpdf(
            $formato === 'cartao' ? 'L' : 'P', 'pt', $pagina, true, 'UTF-8'
        );
        $pdf->SetCreator('Pilotis');
        $pdf->SetAuthor(ORG_NOME);
        $pdf->SetTitle('Carteira de filiação ' . $dados['ano'] . ' — ' . $dados['nome']);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        $formato === 'cartao'
            ? self::desenharCartao($pdf, $dados)
            : self::desenharTela($pdf, $dados);

        return $pdf->Output('', 'S');
    }

    // ---------------------------------------------------------------- tela

    private static function desenharTela(TCPDF $pdf, array $dados): void {
        [$W, $H] = [756.0, 1340.0];
        $m = 72.0;
        $util = $W - 2 * $m;

        [$vr, $vg, $vb] = self::rgb(ORG_COR_PRIMARIA);
        [$cr, $cg, $cb] = self::rgb(ORG_COR_SECUNDARIA);

        $pdf->SetFillColor($vr, $vg, $vb);
        $pdf->Rect(0, 0, $W, $H, 'F');

        // Marca em negativo na esquerda; o ano na direita, na mesma faixa. Sao
        // as duas coisas que se leem de longe: de quem e a carteira e de que
        // ano ela e.
        $base = self::marca($pdf, $m, 110, 340);
        $pdf->SetTextColor(255, 255, 255);
        self::fonte($pdf, 'B', 58);
        $pdf->SetXY($W - $m - 240, $base - 62);
        $pdf->Cell(240, 62, (string)$dados['ano'], 0, 0, 'R');

        $pdf->SetDrawColor($cr, $cg, $cb);
        $pdf->SetLineWidth(1.5);
        $pdf->Line($m, 262, $W - $m, 262);

        self::fonte($pdf, '', 13);
        $pdf->setFontSpacing(3.4);
        $pdf->SetXY($m, 292);
        $pdf->Cell($util, 18, mb_strtoupper('Carteira de filiação', 'UTF-8'), 0, 0, 'L');
        $pdf->setFontSpacing(0);

        // O nome e o maior elemento do cartao. Na internacional ele sai menor
        // que "Docomomo Brazil", que e a informacao que a marca ja da.
        $y = self::nome($pdf, $dados['nome'], $m, 350, $util, 44, 2);

        self::fonte($pdf, '', 20);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY($m, $y + 18);
        $pdf->Cell($util, 26, self::categoria($dados['categoria']), 0, 0, 'L');

        self::painelValidacao($pdf, $dados, $m, 620, $util, 300, 48, 14, 12.5);

        self::fonte($pdf, '', 12);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY($m, $H - 108);
        $pdf->Cell($util, 16, ORG_NOME . '  ·  ' . self::site(), 0, 0, 'C');
    }

    // -------------------------------------------------------------- cartao

    /**
     * 85,6 x 54 mm, o tamanho ISO do cartao de credito. Tudo aqui e pequeno de
     * proposito e nada e decorativo: o que nao couber em 5 pt legiveis nao
     * entra.
     */
    private static function desenharCartao(TCPDF $pdf, array $dados): void {
        [$W, $H] = [242.65, 153.07];
        $m = 13.0;

        [$vr, $vg, $vb] = self::rgb(ORG_COR_PRIMARIA);
        [$cr, $cg, $cb] = self::rgb(ORG_COR_SECUNDARIA);

        $pdf->SetFillColor($vr, $vg, $vb);
        $pdf->Rect(0, 0, $W, $H, 'F');

        // O QR ganha coluna propria a direita: e o que se aproxima do leitor, e
        // assim nunca divide espaco com o texto.
        $qr = 60.0;
        $pad = 8.0;
        $pw = $qr + 2 * $pad;
        $ph = $pad + $qr + 5 + 7 + $pad;
        $px = $W - $m - $pw;
        $py = ($H - $ph) / 2;

        $texto = $px - $m - 12;

        $base = self::marca($pdf, $m, 17, 94);

        $pdf->SetDrawColor($cr, $cg, $cb);
        $pdf->SetLineWidth(0.6);
        $pdf->Line($m, $base + 9, $m + $texto, $base + 9);

        $pdf->SetTextColor(255, 255, 255);
        self::fonte($pdf, '', 5.6);
        $pdf->setFontSpacing(1.2);
        $pdf->SetXY($m, $base + 14);
        $pdf->Cell($texto, 8, mb_strtoupper('Carteira de filiação ' . $dados['ano'], 'UTF-8'), 0, 0, 'L');
        $pdf->setFontSpacing(0);

        $y = self::nome($pdf, $dados['nome'], $m, $base + 26, $texto, 13.5, 2);

        self::fonte($pdf, '', 7.4);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetXY($m, $y + 3);
        $pdf->Cell($texto, 10, self::categoria($dados['categoria']), 0, 0, 'L');

        self::fonte($pdf, '', 5.4);
        $pdf->SetXY($m, $H - $m - 8);
        $pdf->Cell($texto, 8, self::site(), 0, 0, 'L');

        $codigo = ValidacaoService::codigo('FIL', (int)$dados['filiacao_id']);

        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($px, $py, $pw, $ph, 4, '1111', 'F');
        self::qr($pdf, $codigo, $px + $pad, $py + $pad, $qr);

        $pdf->SetFont('courier', 'B', 5);
        $pdf->SetTextColor(35, 31, 32);
        $pdf->SetXY($px, $py + $pad + $qr + 2);
        $pdf->Cell($pw, 7, $codigo, 0, 0, 'C');
    }

    // ------------------------------------------------------------ pedacos

    /**
     * Marca em negativo. Devolve o Y da base do que foi desenhado, para o
     * resto se pendurar nele.
     *
     * Tres formas, nesta ordem, e a ordem e o argumento:
     *
     * 1. `.pdfpath` -- a marca em VETOR, escrita direto no fluxo do PDF. E a
     *    unica que fica nitida no celular: um PNG de 300 dpi reduzido a 0,5x
     *    sai lavado e a area dele vira um retangulo mais claro que o fundo.
     * 2. `.png` -- o que qualquer instalacao tem. SEM canal alfa, de proposito:
     *    o branco ja vem chapado sobre o verde da marca, porque PNG com alfa
     *    exigiria GD ou Imagick, e a falta delas nao devolve erro no TCPDF --
     *    derruba a geracao inteira.
     * 3. o nome da organizacao em texto, como o `layout.php` ja faz quando
     *    falta o logotipo do site.
     */
    private static function marca(TCPDF $pdf, float $x, float $y, float $largura): float {
        $base = PUBLIC_DIR . '/assets/img/' . self::MARCA;

        if ($pdf instanceof PilotisTcpdf && is_file($base . '.pdfpath')) {
            try {
                return $y + $pdf->caminhoVetorial($base . '.pdfpath', $x, $y, $largura, [255, 255, 255]);
            } catch (Throwable $e) {
                error_log('Pilotis: marca vetorial da carteira falhou: ' . $e->getMessage());
            }
        }

        if (is_file($base . '.png')) {
            $info = @getimagesize($base . '.png');
            if ($info && $info[0] > 0) {
                $altura = $largura * $info[1] / $info[0];
                try {
                    $pdf->Image($base . '.png', $x, $y, $largura, $altura, 'PNG', '', '', false, 300);
                    return $y + $altura;
                } catch (Throwable $e) {
                    error_log('Pilotis: marca da carteira nao pode ser desenhada: ' . $e->getMessage());
                }
            }
        }

        $corpo = $largura / 7.5;
        $pdf->SetTextColor(255, 255, 255);
        self::fonte($pdf, 'B', $corpo);
        $pdf->SetXY($x, $y);
        $pdf->Cell($largura, $corpo * 1.3, ORG_NOME, 0, 0, 'L');
        return $y + $corpo * 1.3;
    }

    /**
     * Nome da pessoa, encolhendo ate caber em $linhas linhas.
     *
     * Encolher e necessario, nao zelo: 332 cadastros tem 4 palavras no nome,
     * 203 tem 5 ou mais, e o maior tem 50 caracteres. Corpo fixo estouraria a
     * margem justamente nos nomes mais longos. Devolve o Y do fim do bloco.
     */
    private static function nome(TCPDF $pdf, string $nome, float $x, float $y,
                                 float $largura, float $corpo, int $linhas): float {
        $pdf->SetTextColor(255, 255, 255);
        $nome = trim(preg_replace('/\s+/u', ' ', $nome));

        while ($corpo > 6) {
            self::fonte($pdf, 'B', $corpo);
            if ($pdf->getNumLines($nome, $largura) <= $linhas) break;
            $corpo -= $corpo > 20 ? 1.5 : 0.4;
        }
        self::fonte($pdf, 'B', $corpo);

        $altura = $corpo * 1.22;
        $pdf->SetXY($x, $y);
        $pdf->MultiCell($largura, $altura, $nome, 0, 'L', false, 1, '', '', true, 0, false, true, 0, 'T');
        return $pdf->GetY();
    }

    /**
     * Painel branco do QR, com o codigo e a instrucao centrados sob ele.
     *
     * O branco nao e enfeite: e a zona de silencio que o leitor precisa em
     * volta do codigo, e num cartao de fundo cheio ela tem de ser desenhada.
     *
     * O codigo por extenso fica ao lado do QR desde o comprovante de
     * inscricao, e pelo mesmo motivo: quem recebe o arquivo por email nao tem
     * uma segunda camera para apontar para a propria tela.
     */
    private static function painelValidacao(TCPDF $pdf, array $dados, float $x, float $y,
                                            float $largura, float $qr, float $pad,
                                            float $corpo_codigo, float $corpo_texto): void {
        $codigo = ValidacaoService::codigo('FIL', (int)$dados['filiacao_id']);

        $lh = $corpo_texto * 1.35;
        $altura = $pad + $qr + $corpo_codigo * 2.2 + $lh * 2 + $pad;

        $pdf->SetFillColor(255, 255, 255);
        $pdf->RoundedRect($x, $y, $largura, $altura, 12, '1111', 'F');

        self::qr($pdf, $codigo, $x + ($largura - $qr) / 2, $y + $pad, $qr);

        $pdf->SetFont('courier', 'B', $corpo_codigo);
        $pdf->SetTextColor(35, 31, 32);
        $pdf->SetXY($x, $y + $pad + $qr + $corpo_codigo * 0.5);
        $pdf->Cell($largura, $corpo_codigo * 1.5, $codigo, 0, 0, 'C');

        // Duas frases, e a segunda e a que separa esta carteira da
        // internacional: o papel nao sabe o que aconteceu depois de impresso.
        self::fonte($pdf, '', $corpo_texto);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->SetXY($x + $pad, $y + $pad + $qr + $corpo_codigo * 2.2);
        $pdf->MultiCell($largura - 2 * $pad, $lh,
            'Leia o QR ou informe este código em ' . self::hostValidacao() . "\n"
            . 'A página mostra a situação de hoje, não a do dia da emissão.',
            0, 'C');
    }

    /**
     * O QR sai em grafite sobre branco, e nao na cor da marca: um cartao de
     * filiado e conferido na porta de um evento, por camera qualquer e luz
     * qualquer. Contraste maximo e o unico criterio que importa aqui.
     *
     * Correcao de erro H (30%) pelo mesmo motivo -- o cartao vai amassar no
     * bolso e ser lido de tela riscada.
     */
    private static function qr(TCPDF $pdf, string $codigo, float $x, float $y, float $lado): void {
        $pdf->write2DBarcode(ValidacaoService::url($codigo), 'QRCODE,H', $x, $y, $lado, $lado, [
            'border' => false, 'vpadding' => 0, 'hpadding' => 0,
            'fgcolor' => [35, 31, 32], 'bgcolor' => false,
            'module_width' => 1, 'module_height' => 1,
        ], 'N');
    }

    private static function fonte(TCPDF $pdf, string $estilo, float $corpo): void {
        static $familia = null;
        if ($familia === null) {
            $dir = defined('K_PATH_FONTS') ? K_PATH_FONTS : '';
            $familia = ($dir && is_file($dir . 'futuram.php') && is_file($dir . 'futurab.php'))
                ? 'futura' : 'helvetica';
        }
        // Futura Medium e Futura Bold sao arquivos distintos, e nao estilos de
        // uma familia so: o TCPDF os registra como 'futuram' e 'futurab'.
        $nome = $familia === 'futura'
            ? ($estilo === 'B' ? 'futurab' : 'futuram')
            : 'helvetica';
        $pdf->SetFont($nome, $familia === 'futura' ? '' : $estilo, $corpo);
    }

    /**
     * O rotulo do `.env` sai como esta, sem prefixo.
     *
     * Ele chegou a sair como "Filiado Pleno Brasil", porque "Pleno Brasil"
     * sozinho nao diz de que se trata. Duas razoes para voltar atras, em
     * 09/09/2026: "filiado" flexiona genero e marca no masculino uma carteira
     * que vai para 172 pessoas, e o contexto que faltava ja esta na linha de
     * cima, que diz CARTEIRA DE FILIAÇÃO. Prefixo que repete o cabecalho nao
     * informa nada e ainda gasta largura no cartao de 85,6 mm.
     */
    private static function categoria(?string $chave): string {
        return CATEGORIAS_DISPLAY[$chave] ?? (string)$chave;
    }

    private static function site(): string {
        return preg_replace('~^https?://~', '', rtrim(env('ORG_SITE_URL', BASE_URL), '/'));
    }

    private static function hostValidacao(): string {
        return preg_replace('~^https?://~', '', BASE_URL) . '/validar';
    }

    private static function rgb(string $hex): array {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
