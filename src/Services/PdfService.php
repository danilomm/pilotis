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
        // Como a pessoa se identifica: CPF quando ha, senao o documento
        // estrangeiro. Ate 30/08/2026 era so o CPF, e o comprovante de um
        // filiado estrangeiro saia identificando-o apenas pelo nome — num papel
        // que vai para setor de reembolso e secretaria de programa.
        $identificacao = documento_identificacao(
            $dados['cpf'] ?? '', $dados['documento'] ?? null, $dados['documento_tipo'] ?? null
        );

        $tpl = (int)($dados['valor'] ?? 0) === 0 ? null : carregar_template('evento_comprovante', [
            'nome' => $dados['nome'] ?? '',
            // {{documento}} carrega a propria virgula e some quando nao ha
            // documento; {{cpf}} continua sendo so o numero, para template que
            // o tesoureiro ja tenha editado. Ver a nota no SeedTemplates.
            'documento' => $identificacao !== '' ? ', ' . $identificacao . ',' : '',
            'cpf' => self::formatarCpf($dados['cpf'] ?? ''),
            'evento' => $dados['evento'] ?? '',
            'categoria' => $dados['categoria'] ?? '',
            'valor' => $valor_formatado,
            'data_pagamento' => $data_pag,
            'metodo' => $metodo,
        ], [
            // Vai como HTML porque a frase leva <strong> no que o RH procura:
            // a modalidade, as datas e o local.
            'quando_onde' => self::frasePresenca($dados),
        ]);

        $html_corpo = $tpl ? $tpl['html'] : self::textoComprovantePadrao(
            $dados['nome'] ?? '', $identificacao,
            $dados['evento'] ?? '', $dados['categoria'] ?? '',
            $valor_formatado, $data_pag, $metodo,
            self::frasePresenca($dados)
        );

        $html_corpo .= self::blocoAssinaturas($dados['assinantes'] ?? '');
        $html_corpo .= self::blocoContatoEvento($dados['email_contato'] ?? '');
        // A nota de emissao eletronica NAO entra no corpo: ela e desenhada no
        // pe da pagina pelo PilotisTcpdf, acima do timbre. Ver setNotaEmissao().

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
    /**
     * Escapa valor que vai para o HTML lido pelo TCPDF.
     *
     * O TCPDF **interpreta** o HTML que recebe, inclusive `<img>`, e carrega o
     * arquivo apontado — por caminho absoluto no disco. Como `pessoas.nome` vem
     * de `$_POST` com `trim()` e mais nada, um nome com uma tag de imagem fazia
     * o comprovante sair com o comprovante de MATRICULA de outra pessoa
     * embutido, e o sistema envia esse PDF por email a quem se inscreveu.
     *
     * Nao e o mesmo `e()` das views por precaucao de nome: aqui o destino nao e
     * navegador, e convem que a razao esteja escrita ao lado do uso.
     */
    private static function pdfEscape(?string $valor): string {
        return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Onde e quando o evento acontece, e em que modalidade.
     *
     * Vai no comprovante porque as pessoas o usam para pedir DISPENSA DE PONTO
     * no trabalho: sem data, local e a palavra "presencial", o papel prova que
     * alguem se inscreveu, e nao que precisou se deslocar em tais dias.
     *
     * O `local` e multilinha no cadastro ("IAB-RJ / Rua tal / Cidade, UF");
     * aqui vira uma linha so, separada por virgula, porque e uma frase.
     */
    private static function frasePresenca(array $dados): string {
        $modalidade = modalidade_evento($dados['modalidade'] ?? null);

        $quando = '';
        if (!empty($dados['data_inicio'])) {
            $quando = data_por_extenso($dados['data_inicio'], $dados['data_fim'] ?? null);
        }

        $onde = trim((string)($dados['local'] ?? ''));
        if ($onde !== '') {
            $partes = array_values(array_filter(array_map('trim', preg_split('/\R/', $onde))));
            $onde = implode(', ', $partes);
        }

        if ($quando === '' && $onde === '') {
            return "<p>O evento é <strong>" . self::pdfEscape($modalidade) . "</strong>.</p>";
        }

        $frase = "<p>O evento é <strong>" . self::pdfEscape($modalidade) . "</strong>";
        if ($quando !== '') $frase .= ", e acontece em <strong>" . self::pdfEscape($quando) . "</strong>";
        if ($onde !== '')   $frase .= ($quando !== '' ? ', ' : ', ') . "no <strong>" . self::pdfEscape($onde) . "</strong>";
        return $frase . ".</p>";
    }

    private static function textoComprovantePadrao(
        string $nome, string $cpf, string $evento, string $categoria,
        string $valor, string $data, string $metodo, string $quando_onde = ''
    ): string {
        $nome      = self::pdfEscape($nome);
        $cpf       = self::pdfEscape($cpf);
        $evento    = self::pdfEscape($evento);
        $categoria = self::pdfEscape($categoria);
        $valor     = self::pdfEscape($valor);
        $data      = self::pdfEscape($data);
        $metodo    = self::pdfEscape($metodo);

        // $cpf ja chega pronto de documento_identificacao(): pode ser
        // "CPF 000.000.000-00" ou "Passaporte XX0000000". Vazio, some a
        // virgula toda — melhor sem identificacao do que com rotulo oco.
        $abertura = "<p>Declaramos para os devidos fins que <strong>$nome</strong>" .
            ($cpf ? ", $cpf," : "") .
            " está inscrito(a) no evento <strong>$evento</strong>, " .
            "na categoria <strong>$categoria</strong>.</p>";

        // Inscricao isenta nao teve pagamento: dizer "pagamento confirmado por
        // R$ 0,00" seria falso, e e justamente este documento que vai para a
        // prestacao de contas.
        if ($valor === '' || $valor === 'Gratuita' || preg_match('/^R\$ ?0[,.]00$/', $valor)) {
            return $abertura . $quando_onde . "<p>Inscrição <strong>isenta de taxa</strong>, " .
                "confirmada em <strong>$data</strong>.</p>";
        }

        return $abertura . $quando_onde .
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
        // TEXTO puro, e nao HTML: quem desenha e o Cell() do rodape, no pe da
        // pagina. Ate 31/08/2026 isto voltava um <p> que ia no fluxo do corpo,
        // encostado nas assinaturas — disputando a atencao com o que o
        // documento afirma, e com a posicao variando conforme o tamanho do texto.
        // "sistema do", e nao "sistema de inscrições": a mesma nota vale para a
        // declaracao de filiacao, que nao e inscricao nenhuma. Ela passou a
        // aparecer nos dois documentos em 31/08/2026, ao ir para o rodape.
        return 'Documento emitido eletronicamente em ' . date('d/m/Y')
             . ' pelo sistema do ' . ORG_NOME . ', dispensando assinatura.';
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
     * O TCPDF consegue desenhar esta imagem no ambiente em que estamos rodando?
     *
     * PNG com CANAL ALFA exige a extensao GD ou Imagick — sem uma delas o TCPDF
     * nao devolve erro: ele chama Error(), que com
     * K_TCPDF_THROW_EXCEPTION_ERROR vira excecao e **derruba a geracao inteira
     * do documento**. Nao e o logotipo que se perde, e a declaracao ou o
     * comprovante que a pessoa acabou de pagar.
     *
     * Isso ficou perigoso agora, em 30/08/2026, por um encadeamento:
     *
     * 1. o `.env` de producao traz `ORG_LOGO=logo-docomomo.png`, que e RGBA;
     * 2. o `PUBLIC_DIR` resolvia errado no servidor, entao o `file_exists`
     *    devolvia false e o bloco da imagem era PULADO — e por isso as
     *    declaracoes saiam sem logotipo, defeito ja registrado no CLAUDE.md;
     * 3. o `PUBLIC_DIR` foi consertado. **No proximo deploy o arquivo passa a
     *    ser encontrado pela primeira vez.** Se o PHP do servidor nao tiver GD,
     *    todo PDF passa a estourar — e o sintoma anterior, "sai sem logotipo",
     *    vira "nao sai".
     *
     * O PHP local nao tem GD nem Imagick; o do servidor **nao foi verificado**.
     * Por isso a defesa nao pergunta pelo servidor: ela se vira com o que ha.
     *
     * Havendo o mesmo arquivo em JPG ao lado — e ha: o projeto distribui
     * `logo-docomomo.png` E `logo-docomomo.jpg` —, usa o JPG, que nao tem alfa
     * e nao precisa de extensao nenhuma. Sem alternativa, devolve null e o
     * documento sai sem cabecalho, que e o mal menor.
     */
    private static function imagemUsavelPeloTcpdf(?string $caminho): ?string {
        if ($caminho === null || !file_exists($caminho)) return $caminho;
        if (extension_loaded('gd') || extension_loaded('imagick')) return $caminho;

        if (strtolower(pathinfo($caminho, PATHINFO_EXTENSION)) !== 'png') return $caminho;

        // PNG sem alfa passa direto pelo TCPDF; so o alfa exige extensao.
        $info = @getimagesize($caminho);
        $tem_alfa = false;
        if ($info && isset($info['channels']) && $info['channels'] === 4) {
            $tem_alfa = true;
        } else {
            // getimagesize nem sempre traz 'channels' para PNG. O byte 25 do
            // cabecalho IHDR e o color type: 4 (cinza+alfa) e 6 (RGBA) tem alfa.
            $fh = @fopen($caminho, 'rb');
            if ($fh) {
                $cab = fread($fh, 26);
                fclose($fh);
                if (strlen($cab) === 26) {
                    $tipo = ord($cab[25]);
                    $tem_alfa = ($tipo === 4 || $tipo === 6);
                }
            }
        }
        if (!$tem_alfa) return $caminho;

        $jpg = preg_replace('/\.png$/i', '.jpg', $caminho);
        if (is_file($jpg)) return $jpg;

        error_log("Pilotis: $caminho e PNG com alfa e falta GD/Imagick; PDF sai sem cabecalho");
        return null;
    }

    /**
     * Desenha a imagem, e segue sem ela se nao der.
     *
     * Rede de seguranca depois da checagem acima: imagem corrompida, formato
     * que o TCPDF nao reconhece, arquivo cortado no meio de um upload por FTP.
     * O documento importa mais do que o cabecalho dele — e o comprovante vai
     * anexado ao email de quem acabou de pagar.
     */
    private static function imagemOuNada(TCPDF $pdf, string $arquivo, float $x, float $y, float $largura, string $ext): void {
        try {
            $pdf->Image($arquivo, $x, $y, $largura, 0, $ext, '', '', false, 300);
        } catch (Throwable $e) {
            error_log("Pilotis: cabecalho do PDF nao pode ser desenhado ($arquivo): " . $e->getMessage());
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
        $nome      = self::pdfEscape($nome);
        $categoria = self::pdfEscape($categoria);
        $valor     = self::pdfEscape($valor);

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
        $pdf->setNotaEmissao(self::notaEmissaoEletronica());

        $pdf->AddPage();

        // Cabecalho: faixa do proprio evento quando houver; senao o logo da
        // entidade. Faixa larga ocupa a largura util; logo fica centralizado.
        $topo = 55;
        $cabecalho = $imagem_cabecalho && file_exists($imagem_cabecalho)
            ? $imagem_cabecalho
            : PUBLIC_DIR . '/assets/img/' . ORG_LOGO;

        $cabecalho = self::imagemUsavelPeloTcpdf($cabecalho);

        if ($cabecalho !== null && file_exists($cabecalho)) {
            $ext = strtoupper(pathinfo($cabecalho, PATHINFO_EXTENSION));
            $info = @getimagesize($cabecalho);
            $proporcao = ($info && $info[1] > 0) ? $info[0] / $info[1] : 1;

            if ($proporcao >= 2) {
                $largura = 160;                       // largura util (A4 - margens de 25mm)
                $altura = $largura / $proporcao;
                self::imagemOuNada($pdf, $cabecalho, 25, 18, $largura, $ext);
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
                self::imagemOuNada($pdf, $cabecalho, $x, 20, $largura, $ext);
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
