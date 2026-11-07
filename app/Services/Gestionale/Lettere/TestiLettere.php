<?php

namespace App\Services\Gestionale\Lettere;

/**
 * Il testo delle lettere: i testi del programma, uno per tipo, da cui parte la finestra quando non si sceglie un modello dello studio
 * ({@see ModelliLettere}), e i campi che si riempiono per ogni persona. Il testo si cambia a ogni invio. I testi del programma non si cambiano e
 * non stanno nel database: si duplicano in un modello.
 *
 * Per diffida e messa in mora il programma ha un testo di partenza neutro, come per promemoria e sollecito: la persona, il condominio, le rate e il
 * totale, la richiesta di pagare entro il termine, una frase sul recupero del credito, e niente altro: niente articoli di legge, interessi, spese,
 * servizi sospesi. La differenza fra i due sta nel tono: la diffida chiede il versamento con fermezza; la messa in mora è la richiesta formale di
 * pagamento, con questa lettera. Il recupero del credito il condominio «potrà» farlo: non è una promessa (una dispensa dei sei mesi, o la scelta
 * dell'amministratore di aspettare); chi vuole «procederà» lo scrive nel suo modello. Si cambiano a ogni invio e si duplicano in un modello; la
 * finestra dice di controllarli prima di mandarli. Lo spazio della firma lo mette la lettera.
 *
 * **Il termine** per il versamento ha tre forme: «N giorni dal ricevimento di questa lettera», «entro il [data]», nessun termine. Il campo
 * `[termine]` del testo diventa «15 giorni dal ricevimento di questa lettera» o «il 30/04/2026» (dopo «entro»); la lettera porta la riga a parte
 * «Termine per il versamento» solo se il testo non usa il campo.
 *
 * **Il nome del condominio** si scrive una volta: se comincia già con «Condominio» o «Supercondominio» (senza badare alle maiuscole), il titolo
 * della lettera non gli mette davanti la parola, e nel testo «condominio [condominio]» diventa il nome e basta ({@see condominioConNome()},
 * {@see riempi()}). Il nome resta com'è scritto: «del Condominio Le Rose».
 */
final class TestiLettere
{
    /** I campi che si riempiono per ogni persona. */
    public const CAMPI = [
        '[nome]'        => 'il nome della persona',
        '[totale]'      => 'il totale della lettera',
        '[termine]'     => 'il termine per il versamento',
        '[condominio]'  => 'il nome del condominio',
    ];

    public const LUNGHEZZA_MASSIMA = 5000;

    /**
     * I testi del programma, da cui si parte quando non si sceglie un modello dello studio. Non parlano delle coordinate del bonifico: le porta
     * la lettera, nel riquadro «Per il bonifico», solo quando il condominio ha un conto con l'IBAN; un testo che le promettesse sarebbe falso
     * nelle lettere senza.
     */
    public const DI_SERIE = [
        'promemoria' => "Gentile [nome],\nle ricordiamo le sue rate del condominio [condominio] che scadono nei prossimi giorni, indicate qui sotto, "
            . "per un totale di [totale].\nSe ha già versato, non tenga conto di questo promemoria.",
        'sollecito' => "Gentile [nome],\ndalla contabilità del condominio [condominio] risultano da versare le rate indicate qui sotto, per un totale "
            . "di [totale]. Le chiediamo di versare entro [termine].\nSe ha già versato, "
            . 'non tenga conto di questa lettera e ci mandi, se può, la ricevuta del bonifico.',
        'diffida' => "Gentile [nome],\ndalla contabilità del condominio [condominio] risultano ancora da versare le rate indicate qui sotto, per un "
            . "totale di [totale].\nLe chiediamo con fermezza di versare il totale entro [termine].\nSe il versamento non arriva "
            . "entro quel termine, il condominio potrà procedere al recupero del credito.\nSe ha già versato, ci mandi la ricevuta del bonifico.",
        'messa_in_mora' => "Gentile [nome],\ncon questa lettera il condominio [condominio] le chiede formalmente il pagamento delle rate "
            . "indicate qui sotto, per un totale di [totale], entro [termine].\nSe il pagamento non arriva entro quel termine, il condominio potrà "
            . "procedere al recupero del credito.\nSe ha già versato, ci mandi la ricevuta del bonifico.",
    ];

    /** Le forme del termine per il versamento. */
    public const FORME_DEL_TERMINE = ['giorni', 'data', 'nessuno'];

    /**
     * Il testo di una persona: i campi riempiti con i suoi dati. Il termine è il testo del campo ({@see termineNelTesto()}); senza un termine il
     * campo resta vuoto (il server non lo permette: un testo con `[termine]` vuole un termine). Se il nome del condominio comincia già con
     * «Condominio», «condominio [condominio]» diventa il nome e basta: «del condominio [condominio]» con «Condominio Le Rose» è «del Condominio
     * Le Rose». Il campo da solo è sempre il nome com'è.
     */
    public static function riempi(string $modello, string $nome, int $totale, ?string $termine, string $condominio): string
    {
        if (self::cominciaConCondominio($condominio)) {
            $modello = preg_replace('/(?<![\p{L}\p{N}])condominio\s+\[condominio\]/iu', '[condominio]', $modello) ?? $modello;
        }

        return strtr($modello, [
            '[nome]'       => $nome,
            '[totale]'     => self::euro($totale),
            '[termine]'    => (string) $termine,
            '[condominio]' => $condominio,
        ]);
    }

    /** Il nome comincia già con la parola «Condominio» o «Supercondominio», senza badare alle maiuscole? «Condominiale» è un'altra parola. */
    public static function cominciaConCondominio(string $nome): bool
    {
        return preg_match('/^\s*(super)?condominio(?![\p{L}\p{N}])/iu', $nome) === 1;
    }

    /**
     * Il nome del condominio con la parola davanti, una volta sola: «Le Rose» diventa «Condominio Le Rose», «Condominio Le Rose» resta com'è.
     * La parola si sceglie per la frase: «Condominio» in un titolo, «condominio» dentro una frase.
     */
    public static function condominioConNome(string $nome, string $parola = 'Condominio'): string
    {
        return self::cominciaConCondominio($nome) ? trim($nome) : $parola . ' ' . trim($nome);
    }

    /** «15 giorni dal ricevimento di questa lettera». */
    public static function termine(int $giorni): string
    {
        return ($giorni === 1 ? '1 giorno' : $giorni . ' giorni') . ' dal ricevimento di questa lettera';
    }

    /** La forma del termine scritta in una lettera: quella salvata o, per una riga che ha solo i giorni, «giorni». */
    public static function formaDelTermine(array $dati): ?string
    {
        $forma = $dati['termine_forma'] ?? null;

        return in_array($forma, self::FORME_DEL_TERMINE, true) ? $forma : (isset($dati['termine']) ? 'giorni' : null);
    }

    /** Il campo `[termine]` di una lettera, dopo «entro»: «15 giorni dal ricevimento di questa lettera», «il 30/04/2026»; null senza termine. */
    public static function termineNelTesto(array $dati): ?string
    {
        return match (self::formaDelTermine($dati)) {
            'giorni' => isset($dati['termine']) ? self::termine((int) $dati['termine']) : null,
            'data'   => isset($dati['termine_data']) ? 'il ' . \Carbon\CarbonImmutable::parse($dati['termine_data'])->format('d/m/Y') : null,
            default  => null,
        };
    }

    /** La riga a parte «Termine per il versamento: …», se il testo non usa già `[termine]`. */
    public static function rigaDelTermine(array $dati): ?string
    {
        if (str_contains((string) ($dati['modello'] ?? $dati['testo'] ?? ''), '[termine]')) {
            return null;
        }

        return match (self::formaDelTermine($dati)) {
            'giorni' => self::termineNelTesto($dati),
            'data'   => 'entro ' . self::termineNelTesto($dati),
            default  => null,
        };
    }

    public static function euro(int $centesimi): string
    {
        return '€ ' . number_format($centesimi / 100, 2, ',', '.');
    }
}
