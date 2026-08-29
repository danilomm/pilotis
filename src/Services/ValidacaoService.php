<?php
/**
 * Pilotis - Validacao publica de documentos emitidos pelo sistema
 *
 * O comprovante de inscricao (e, quando quisermos, a declaracao de
 * adimplencia) leva um QR que abre /validar/{codigo}. Quem recebe o papel --
 * setor de reembolso, secretaria de programa, coordenacao -- confere na hora
 * se o documento e verdadeiro, sem telefonar para a tesouraria.
 *
 * O codigo NAO fica no banco: sai de HMAC sobre a SECRET_KEY, no formato
 *
 *     EVT-{inscricao_id}-{10 hex}     inscricao em evento
 *     FIL-{filiacao_id}-{10 hex}      filiacao (declaracao de adimplencia)
 *
 * O id deixa a consulta direta; a assinatura impede chegar ao documento de
 * outra pessoa trocando o numero. Sem coluna nova, sem migracao, e nada a
 * ressincronizar se um PDF for reemitido.
 *
 * A pagina de validacao le a situacao ATUAL no banco. E a diferenca em
 * relacao ao papel: inscricao cancelada ou pagamento estornado aparece como
 * invalida mesmo com o PDF antigo na mao.
 */

class ValidacaoService {

    /** Tamanho da assinatura em hex. 10 = 40 bits, suficiente contra
     *  tentativa e erro numa pagina publica sem valor de ataque. */
    private const TAM_ASSINATURA = 10;

    private const TIPOS = ['EVT', 'FIL'];

    /**
     * Codigo publico do documento. Ex.: EVT-9-3F2A18C4D0
     */
    public static function codigo(string $tipo, int $id): string {
        $tipo = strtoupper($tipo);
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new InvalidArgumentException("Tipo de documento desconhecido: $tipo");
        }
        return $tipo . '-' . $id . '-' . self::assinatura($tipo, $id);
    }

    /**
     * URL que vai dentro do QR.
     */
    public static function url(string $codigo): string {
        return rtrim(BASE_URL, '/') . '/validar/' . $codigo;
    }

    /**
     * Confere o codigo e devolve ['tipo' => 'EVT'|'FIL', 'id' => int].
     * Devolve null para formato invalido ou assinatura que nao confere --
     * a pagina trata os dois casos do mesmo jeito, sem dizer qual foi.
     */
    public static function resolver(string $codigo): ?array {
        if (!preg_match('/^(EVT|FIL)-(\d+)-([0-9A-Fa-f]{' . self::TAM_ASSINATURA . '})$/', trim($codigo), $m)) {
            return null;
        }
        $tipo = strtoupper($m[1]);
        $id = (int)$m[2];

        if (!hash_equals(self::assinatura($tipo, $id), strtoupper($m[3]))) {
            return null;
        }
        return ['tipo' => $tipo, 'id' => $id];
    }

    private static function assinatura(string $tipo, int $id): string {
        return strtoupper(substr(hash_hmac('sha256', "$tipo:$id", SECRET_KEY), 0, self::TAM_ASSINATURA));
    }
}
