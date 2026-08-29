<?php
/**
 * Pilotis - Gerador de planilha .xlsx
 *
 * POR QUE existe, em vez de mandar CSV: quem recebe a lista de inscritos é a
 * organização do evento, não gente de informática. CSV abre torto no Excel em
 * português (separador, acento), e a pessoa acha que o sistema está errado.
 *
 * POR QUE é escrito à mão, em vez de uma biblioteca: um .xlsx é um arquivo ZIP
 * com alguns XML dentro. Escrever os XML é trivial, e o ZIP, quando gravado
 * SEM compressão, também — os leitores aceitam. Assim não entra dependência
 * nova de 10MB no projeto, e não depende da extensão `zip` do PHP estar
 * instalada no servidor, que é hospedagem compartilhada e não se controla.
 *
 * Faz o que a lista precisa e nada mais: uma aba, cabeçalho em negrito e
 * congelado, larguras de coluna, tudo como texto. Não faz fórmula, número
 * formatado, cor nem gráfico.
 */

class XlsxService {

    /**
     * Monta a planilha e devolve os bytes.
     *
     * @param string   $aba     Nome da aba
     * @param string[] $cabecalho
     * @param array[]  $linhas  Lista de linhas, cada uma lista de strings
     */
    public static function gerar(string $aba, array $cabecalho, array $linhas): string {
        $larguras = self::larguras($cabecalho, $linhas);

        $arquivos = [
            '[Content_Types].xml' => self::contentTypes(),
            '_rels/.rels' => self::rels(),
            'xl/workbook.xml' => self::workbook($aba),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
            'xl/styles.xml' => self::styles(),
            'xl/worksheets/sheet1.xml' => self::sheet($cabecalho, $linhas, $larguras),
        ];

        return self::zip($arquivos);
    }

    /**
     * Larguras aproximadas: o maior conteúdo de cada coluna, com teto, para a
     * planilha abrir legível sem a pessoa ter que arrastar cada divisória.
     */
    private static function larguras(array $cabecalho, array $linhas): array {
        $larguras = [];
        foreach ($cabecalho as $i => $texto) {
            $larguras[$i] = mb_strlen((string)$texto);
        }
        foreach ($linhas as $linha) {
            foreach (array_values($linha) as $i => $valor) {
                $larguras[$i] = max($larguras[$i] ?? 8, min(mb_strlen((string)$valor), 60));
            }
        }
        foreach ($larguras as $i => $l) {
            $larguras[$i] = max(10, min($l + 3, 60));
        }
        return $larguras;
    }

    private static function coluna(int $indice): string {
        $nome = '';
        $indice++;
        while ($indice > 0) {
            $resto = ($indice - 1) % 26;
            $nome = chr(65 + $resto) . $nome;
            $indice = intdiv($indice - 1, 26);
        }
        return $nome;
    }

    /**
     * Tira o que o XML 1.0 não aceita.
     *
     * Um único caractere de controle — vindo de texto colado de editor, por
     * exemplo — deixa o arquivo inteiro ilegível, e não há como a pessoa
     * adivinhar de onde veio. Some com ele em silêncio.
     */
    private static function limpar(string $valor): string {
        $limpo = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $valor);
        // preg_replace devolve null em UTF-8 inválido; aí vale limpar por byte.
        return $limpo ?? preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $valor);
    }

    private static function celula(int $col, int $linha, string $valor, int $estilo = 0): string {
        // Tudo como texto (inlineStr): a lista tem CPF, telefone e CEP, que o
        // Excel estragaria se adivinhasse número — zero à esquerda some e
        // valor grande vira notação científica.
        $ref = self::coluna($col) . $linha;
        // Teto do formato: celula acima de 32.767 caracteres deixa o arquivo
        // INTEIRO ilegivel — o Excel abre com o dialogo de reparo e a
        // organizacao fica sem a lista, sem pista de qual linha causou. Os
        // campos vem de formulario publico sem limite de servidor.
        $valor = mb_substr($valor, 0, 32767);
        $texto = htmlspecialchars(self::limpar($valor), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $s = $estilo ? " s=\"$estilo\"" : '';
        return "<c r=\"$ref\"$s t=\"inlineStr\"><is><t xml:space=\"preserve\">$texto</t></is></c>";
    }

    private static function sheet(array $cabecalho, array $linhas, array $larguras): string {
        $cols = '';
        foreach ($larguras as $i => $l) {
            $n = $i + 1;
            $cols .= "<col min=\"$n\" max=\"$n\" width=\"$l\" customWidth=\"1\"/>";
        }

        $xml = '<row r="1">';
        foreach (array_values($cabecalho) as $i => $texto) {
            $xml .= self::celula($i, 1, (string)$texto, 1);
        }
        $xml .= '</row>';

        $n = 1;
        foreach ($linhas as $linha) {
            $n++;
            $xml .= "<row r=\"$n\">";
            foreach (array_values($linha) as $i => $valor) {
                $xml .= self::celula($i, $n, (string)$valor);
            }
            $xml .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0" tabSelected="1">'
            // Congela o cabeçalho: lista longa sem isso é ilegível ao rolar.
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>'
            . "<cols>$cols</cols>"
            . "<sheetData>$xml</sheetData>"
            . '</worksheet>';
    }

    private static function styles(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF2E7D32"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    private static function workbook(string $aba): string {
        // O Excel recusa nome de aba com : \ / ? * [ ] ou mais de 31 caracteres.
        $nome = preg_replace('/[:\\\\\\/?*\\[\\]]/', '-', $aba);
        $nome = htmlspecialchars(self::limpar(mb_substr(trim($nome) ?: 'Planilha', 0, 31)), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . "<sheets><sheet name=\"$nome\" sheetId=\"1\" r:id=\"rId1\"/></sheets>"
            . '</workbook>';
    }

    private static function contentTypes(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function rels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbookRels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /**
     * ZIP mínimo, entradas SEM compressão (método 0).
     *
     * Sem compressão porque comprimir exigiria a extensão zlib com raw deflate
     * e um cabeçalho a mais para acertar; guardar cru é aceito por Excel,
     * LibreOffice e Google Sheets, e uma lista de inscritos não chega a
     * tamanho que justifique.
     */
    private static function zip(array $arquivos): string {
        $locais = '';
        $central = '';
        $offset = 0;

        // Data fixa (1980-01-01): sem isso a planilha muda a cada geração,
        // e não há por que o carimbo de hora do ZIP importar aqui.
        $data = 0x0021;
        $hora = 0x0000;

        foreach ($arquivos as $nome => $conteudo) {
            $crc = crc32($conteudo);
            $tam = strlen($conteudo);

            $local = "PK\x03\x04"
                . pack('v', 20)          // versão necessária
                . pack('v', 0)           // flags
                . pack('v', 0)           // método: sem compressão
                . pack('v', $hora)
                . pack('v', $data)
                . pack('V', $crc)
                . pack('V', $tam)        // tamanho comprimido
                . pack('V', $tam)        // tamanho original
                . pack('v', strlen($nome))
                . pack('v', 0)
                . $nome;

            $locais .= $local . $conteudo;

            $central .= "PK\x01\x02"
                . pack('v', 20) . pack('v', 20)
                . pack('v', 0) . pack('v', 0)
                . pack('v', $hora) . pack('v', $data)
                . pack('V', $crc)
                . pack('V', $tam) . pack('V', $tam)
                . pack('v', strlen($nome))
                . pack('v', 0) . pack('v', 0)
                . pack('v', 0) . pack('v', 0)
                . pack('V', 0)
                . pack('V', $offset)
                . $nome;

            $offset += strlen($local) + $tam;
        }

        $fim = "PK\x05\x06"
            . pack('v', 0) . pack('v', 0)
            . pack('v', count($arquivos)) . pack('v', count($arquivos))
            . pack('V', strlen($central))
            . pack('V', $offset)
            . pack('v', 0);

        return $locais . $central . $fim;
    }
}
