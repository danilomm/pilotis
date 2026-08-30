<?php
/**
 * Pilotis - Acesso ao painel de leitura da organizacao do evento
 *
 * O painel mostra o cadastro dos inscritos (tudo menos CPF), porque a
 * organizacao precisa entrar em contato e mandar correspondencia. Isso e dado
 * pessoal de terceiro, entao o acesso nao pode ser um link solto nem uma senha
 * compartilhada — as duas coisas circulam e nao dizem quem entrou.
 *
 * Aqui o acesso e por EMAIL AUTORIZADO: a pessoa informa o email, e se ele
 * estiver na lista do evento recebe um link valido por 30 minutos
 * tempo. Ao abrir, ganha uma sessao. Efeitos:
 *
 *   - o acesso fica preso a uma caixa postal, nao a um segredo repassavel;
 *   - o log registra QUEM abriu, e quando;
 *   - tirar alguem e apagar uma linha da lista do evento.
 *
 * O link nao fica no banco: e assinado com a SECRET_KEY e carrega a propria
 * validade. Sem tabela nova, e sem nada para limpar depois.
 */

class PainelOrganizacaoService {

    /** Quanto tempo o link do email continua servindo. */
    // NAO e de uso unico, e isso e deliberado: lerToken() e HMAC sem estado,
    // nada marca o link como gasto. Ele funciona quantas vezes se quiser dentro
    // dos 30 minutos, e e encaminhavel.
    //
    // POR QUE assim: scanner corporativo de link (Safe Links e afins) abre a URL
    // sozinho antes de a pessoa clicar, e queimaria um link de uso unico. E o
    // risco de encaminhar e pequeno — o email vai DENTRO do token e e reconferido
    // contra a lista a cada pagina, entao quem recebe o link encaminhado entra
    // como aquela pessoa autorizada, e sai no instante em que ela for removida.
    private const MINUTOS_LINK = 30;

    /** Quanto tempo a sessao dura depois de aberta. */
    private const HORAS_SESSAO = 12;

    public static function gerarLink(array $evento, string $email): string {
        $expira = time() + self::MINUTOS_LINK * 60;
        $dados = (int)$evento['id'] . '|' . strtolower(trim($email)) . '|' . $expira;
        $token = rtrim(strtr(base64_encode($dados), '+/', '-_'), '=') . '.' . self::assinatura($dados);

        return rtrim(BASE_URL, '/') . '/eventos/' . $evento['slug'] . '/organizacao/acesso/' . $token;
    }

    /**
     * Confere o token do email. Devolve o email autorizado, ou null.
     */
    public static function lerToken(array $evento, string $token): ?string {
        if (strpos($token, '.') === false) return null;
        [$payload, $assinatura] = explode('.', $token, 2);

        $dados = base64_decode(strtr($payload, '-_', '+/'), true);
        if ($dados === false) return null;
        if (!hash_equals(self::assinatura($dados), $assinatura)) return null;

        $partes = explode('|', $dados);
        if (count($partes) !== 3) return null;
        [$evento_id, $email, $expira] = $partes;

        if ((int)$evento_id !== (int)$evento['id']) return null;
        if ((int)$expira < time()) return null;
        if (!email_autorizado_no_painel($evento, $email)) return null;

        return $email;
    }

    /**
     * Abre a sessao do painel para este evento.
     */
    public static function abrirSessao(array $evento, string $email): void {
        start_session();
        $_SESSION['painel_organizacao'][(int)$evento['id']] = [
            'email' => $email,
            'expira' => time() + self::HORAS_SESSAO * 3600,
        ];
    }

    /**
     * Quem esta vendo o painel agora, ou null se ninguem.
     * Reconfere a autorizacao a cada acesso: tirar o email da lista do evento
     * derruba a sessao aberta, sem precisar esperar ela expirar.
     */
    public static function sessaoAtiva(array $evento): ?string {
        start_session();
        $s = $_SESSION['painel_organizacao'][(int)$evento['id']] ?? null;
        if (!$s) return null;
        if (($s['expira'] ?? 0) < time()) return null;
        if (!email_autorizado_no_painel($evento, $s['email'] ?? '')) return null;
        if (!painel_organizacao_ativo($evento)) return null;

        return $s['email'];
    }

    /**
     * Registra no log uma acao feita pelo painel, sempre com o email de quem fez.
     *
     * Existe para que envio, acesso, download — e, na etapa 2, a EDICAO da
     * programacao — passem todos pelo mesmo lugar e tenham a mesma forma. Log
     * de acao com autor e o que distingue "a organizacao publicou" de "alguem
     * publicou": a credencial e um link por email, entao o email e a unica
     * identidade que existe aqui.
     */
    public static function registrarAcao(array $evento, string $email, string $acao, string $detalhe = ''): void {
        registrar_log('painel_organizacao_' . $acao, null,
            "Painel de {$evento['slug']}: $acao por [$email]" . ($detalhe !== '' ? " — $detalhe" : ''));
    }

    /**
     * O painel pode ESCREVER neste evento?
     *
     * Hoje: nao, sempre. E a resposta certa enquanto a etapa 1 esta no ar, e
     * esta funcao existe para que a etapa 2 tenha um lugar unico onde mudar
     * isso — em vez de a permissao aparecer implicitamente na primeira tela de
     * edicao que alguem escrever.
     *
     * A DECISAO A TOMAR ANTES DE ABRIR (restricao 3 do ROADMAP): a credencial
     * atual foi desenhada como janela — link valido por 30 min, sessao de 12 h,
     * sem senha compartilhada — e o argumento de seguranca que a justificou foi
     * explicitamente "eles so leem". Por isso o painel nao mostra CPF nem
     * valores. Quando a organizacao publicar a programacao por aqui, essa mesma
     * sessao de 12 horas obtida por email vira permissao de publicar na pagina
     * publica do evento.
     *
     * O que ja esta decidido: toda alteracao passa por registrarAcao(), com o
     * email. O que falta decidir: se a edicao exige uma confirmacao a mais que
     * a leitura — e a sessao de 12 horas, herdada de um desenho de leitura, e
     * longa demais para escrita.
     *
     * A assinatura recebe $email desde 30/08/2026, ainda sem uso: a permissao de
     * escrita quase certamente sera POR PESSOA (quem da organizacao pode
     * publicar), e uma assinatura que nao expressa isso convida a decidir errado
     * depois. Trocar agora custa nada; trocar com chamadores custa.
     *
     * ATENCAO: esta guarda **ainda nao tem chamador**. Uma guarda que ninguem
     * chama nao impede nada — a tela de edicao da etapa 2 pode ser escrita sem
     * nunca passar por aqui, que e exatamente o que a restricao queria evitar.
     * Quem escrever a primeira tela de escrita: **chamar isto antes de gravar**.
     */
    public static function podeEscrever(array $evento, ?string $email = null): bool {
        return false;
    }

    public static function encerrarSessao(array $evento): void {
        start_session();
        unset($_SESSION['painel_organizacao'][(int)$evento['id']]);
    }

    private static function assinatura(string $dados): string {
        return substr(hash_hmac('sha256', $dados, SECRET_KEY), 0, 32);
    }
}
