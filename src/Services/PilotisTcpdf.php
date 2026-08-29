<?php
/**
 * Pilotis - Subclasse do TCPDF
 *
 * Existe por um motivo so: o TCPDF desenha um link "Powered by TCPDF" na
 * ultima pagina de todo documento. Ele nao aparece na renderizacao (fica na
 * borda inferior), mas sai em "selecionar tudo" e em qualquer extracao de
 * texto — indesejavel num comprovante que vai para prestacao de contas.
 *
 * A flag `$tcpdflink` e `protected` e nao tem setter publico; so da para
 * desliga-la de dentro de uma subclasse. O `__construct` do TCPDF a religa,
 * entao desligamos DEPOIS do parent::__construct().
 *
 * Serve tambem para o rodape de identificacao da entidade (nome, CNPJ,
 * tesouraria), que no comprovante de inscricao precisa ficar no PE DA PAGINA,
 * e nao logo abaixo do texto: o `Footer()` do TCPDF e o unico jeito de fixar
 * a posicao independentemente do tamanho do corpo.
 *
 * Este arquivo so pode ser carregado depois do tcpdf.php.
 */

if (!class_exists('TCPDF')) {
    throw new RuntimeException('PilotisTcpdf requer que o TCPDF ja esteja carregado.');
}

class PilotisTcpdf extends TCPDF {

    /** @var string[] Linhas do rodape de identificacao. Vazio = sem rodape. */
    private $rodape_pilotis = [];

    public function __construct(
        $orientation = 'P',
        $unit = 'mm',
        $format = 'A4',
        $unicode = true,
        $encoding = 'UTF-8',
        $diskcache = false,
        $pdfa = false
    ) {
        parent::__construct($orientation, $unit, $format, $unicode, $encoding, $diskcache, $pdfa);
        $this->tcpdflink = false;
    }

    /**
     * Define as linhas do rodape de identificacao e liga a impressao dele.
     * Lista vazia mantem o documento sem rodape (caso da declaracao de
     * filiacao, que ja traz a identificacao no corpo).
     */
    public function setRodapePilotis(array $linhas): void {
        $this->rodape_pilotis = array_values(array_filter(
            array_map('trim', $linhas),
            static function ($l) { return $l !== ''; }
        ));
        $this->setPrintFooter(!empty($this->rodape_pilotis));
    }

    /**
     * Uma linha so, miuda, com os trechos separados por ponto — mais timbre
     * que rodape. O corpo em tres linhas competia com o texto do documento.
     *
     * O tamanho da fonte se ajusta ate a linha caber na largura util (8pt
     * para baixo, piso de 5pt): os dados vem do .env e variam de entidade
     * para entidade (razao social longa, CNPJ, email), entao fixar o corpo
     * arriscaria estourar a margem. Com os dados do Docomomo a linha fecha
     * em 6,25pt — repeticao de nome aqui custa corpo de fonte.
     */
    public function Footer() {
        if (empty($this->rodape_pilotis)) {
            return;
        }
        $linha = implode('  ·  ', $this->rodape_pilotis);
        $util = $this->getPageWidth() - $this->lMargin - $this->rMargin;

        $corpo = 8;
        $this->SetFont('helvetica', '', $corpo);
        while ($corpo > 5 && $this->GetStringWidth($linha) > $util) {
            $corpo -= 0.25;
            $this->SetFont('helvetica', '', $corpo);
        }

        $this->SetY(-15);
        $this->SetTextColor(130, 130, 130);
        $this->Cell(0, 4, $linha, 0, 1, 'C');
        $this->SetTextColor(0, 0, 0);
    }
}
