<?php

namespace App\Services\PDF;

/**
 * La carta di una stampa: lo studio da scrivere in testa, il logo, e da dove comincia la testata. La dà `CartaDiStampa`, la ricevono le viste
 * come `$carta`.
 *
 * C'è lo studio quando c'è il suo nome: senza, la stampa ha la testata di sempre. Il modello F24 non ha lo studio. Il logo si stampa solo con lo
 * studio, e se la carta lo vuole. Il colore è quello dei titoli e delle righe della carta: nullo vuol dire i colori di oggi di ogni vista; vale
 * anche senza lo studio, perché è della carta.
 *
 * `primaPaginaIntera()`: la prima pagina ha il blocco intero dello studio e del condominio, le seguenti una riga. Le parti del fascicolo dopo il
 * frontespizio cominciano con la riga: il blocco intero è nel frontespizio.
 *
 * Con la carta intestata ({@see cartaIntestata()}, solo sulle stampe verticali) lo studio è stampato sulla carta: il programma non lo scrive (né il
 * logo), scrive il condominio in cima alla parte libera, dentro i margini della carta.
 */
final class Carta
{
    /** Il margine sinistro delle stampe senza la carta intestata, in mm (`PdfService`). */
    public const MARGINE_SINISTRO = 12.0;

    /**
     * La colonna del destinatario delle lettere, delle ricevute e degli avvisi comincia qui, in mm dal bordo sinistro del foglio; le sue righe sono
     * allineate a destra, fino al margine destro.
     */
    public const DESTINATARIO = 112.0;

    /** Lo spazio fra la colonna di sinistra (lo studio, il condominio) e quella del destinatario, in mm: la colonna di sinistra va a capo prima. */
    public const SPAZIO_PRIMA_DEL_DESTINATARIO = 8.0;

    /**
     * @param  array{nome: ?string, indirizzo: ?string, email: ?string, pec: ?string, telefono: ?string, comune: ?string, partita_iva: ?string, codice_fiscale: ?string, sito: ?string}  $studio
     */
    public function __construct(
        public readonly GruppoCarta $gruppo,
        private readonly array $studio,
        private readonly ?string $logo,
        private readonly bool $primaPaginaIntera = true,
        public readonly ?string $colore = null,
        private readonly ?CartaIntestata $intestata = null,
    ) {}

    /** La carta intestata sotto le pagine, o null: a campi, o una stampa orizzontale, o una carta intestata che non si legge, o il modello F24. */
    public function cartaIntestata(): ?CartaIntestata
    {
        return $this->intestata;
    }

    /** Il margine sinistro della stampa, in mm: quello della carta intestata, o quello di sempre. */
    public function margineSinistro(): float
    {
        return $this->cartaIntestata()?->sinistro ?? self::MARGINE_SINISTRO;
    }

    /**
     * La larghezza della colonna di sinistra delle lettere, delle ricevute e degli avvisi, per lo stile della cella («100mm» con il margine di
     * sempre), con dentro lo spazio prima del destinatario: la colonna del destinatario comincia a 112 mm dal bordo del foglio con qualunque
     * margine sinistro.
     */
    public static function larghezzaMittente(?self $carta): string
    {
        $mm = self::DESTINATARIO - ($carta?->margineSinistro() ?? self::MARGINE_SINISTRO);

        return rtrim(rtrim(number_format($mm, 2, '.', ''), '0'), '.').'mm';
    }

    /**
     * Il colore dei titoli e delle righe per una vista: quello della carta, o `$oggi`, il colore che la vista scrive da sempre (senza una carta,
     * come quando la vista si stampa da sola).
     */
    public static function coloreDi(?self $carta, string $oggi): string
    {
        return $carta?->colore ?? $oggi;
    }

    /** Il programma scrive lo studio: c'è il suo nome, e la stampa non ha la carta intestata (lì lo studio è stampato sulla carta). */
    public function haStudio(): bool
    {
        return $this->gruppo !== GruppoCarta::NESSUNO && $this->studio['nome'] !== null && $this->intestata === null;
    }

    public function nome(): ?string
    {
        return $this->haStudio() ? $this->studio['nome'] : null;
    }

    /** Il percorso locale del logo, per mPDF; nullo senza lo studio o senza un logo che si legga. */
    public function logo(): ?string
    {
        return $this->haStudio() ? $this->logo : null;
    }

    public function primaPaginaIntera(): bool
    {
        return $this->primaPaginaIntera;
    }

    /**
     * Le righe sotto il nome dello studio: l'indirizzo con il comune, «P.IVA … · C.F. …», «Tel. … · Email … · PEC … · sito». Un campo vuoto non
     * lascia il separatore, una riga vuota non c'è. Gli spazi prima del «·» e dopo il nome di un dato («PEC», «Tel.») non vanno a capo: quando la
     * riga dei recapiti va a capo, il punto resta con la parola di prima, e la riga dopo comincia con un dato intero, il nome con il suo valore.
     *
     * @return list<string>
     */
    public function righe(): array
    {
        if (! $this->haStudio()) {
            return [];
        }
        $s = $this->studio;
        $unisci = fn (array $pezzi) => implode("\u{00A0}· ", array_filter($pezzi, fn ($p) => $p !== null));

        return array_values(array_filter([
            self::indirizzoConIlComune($s['indirizzo'], $s['comune']),
            $unisci([$s['partita_iva'] !== null ? "P.IVA\u{00A0}".$s['partita_iva'] : null, $s['codice_fiscale'] !== null ? "C.F.\u{00A0}".$s['codice_fiscale'] : null]),
            $unisci([
                $s['telefono'] !== null ? "Tel.\u{00A0}".$s['telefono'] : null,
                $s['email'] !== null ? "Email\u{00A0}".$s['email'] : null,
                $s['pec'] !== null ? "PEC\u{00A0}".$s['pec'] : null,
                $s['sito'],
            ]),
        ], fn ($riga) => $riga !== null && $riga !== ''));
    }

    /**
     * «indirizzo, comune»; il comune non si ripete quando l'indirizzo lo dice già dopo l'ultima virgola, dove sta la città («Via Milano 12, 20900»
     * non dice Milano): senza badare alle maiuscole, agli spazi, agli accenti («Forli» è «Forlì») e agli apostrofi (quello tipografico dei programmi
     * di scrittura è quello della tastiera), e come parola intera («Bra» non è in «Via Brambilla»). Un indirizzo senza virgola si legge tutto.
     */
    private static function indirizzoConIlComune(?string $indirizzo, ?string $comune): ?string
    {
        if ($comune === null || $indirizzo === null) {
            return $indirizzo ?? $comune;
        }
        $virgola = mb_strrpos($indirizzo, ',');
        $citta = self::confrontabile($virgola === false ? $indirizzo : mb_substr($indirizzo, $virgola + 1));
        $parole = preg_split('/\s+/u', self::confrontabile($comune), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $cerca = '/(?<![\p{L}\p{N}])'.implode('\s*', array_map(fn ($p) => preg_quote($p, '/'), $parole)).'(?![\p{L}\p{N}])/iu';

        return preg_match($cerca, $citta) === 1 ? $indirizzo : $indirizzo.', '.$comune;
    }

    /** Il testo senza accenti e con gli apostrofi tipografici resi come quello della tastiera, per un confronto. */
    private static function confrontabile(string $testo): string
    {
        return \Illuminate\Support\Str::ascii(str_replace(["\u{2019}", "\u{2018}", "\u{02BC}", '`', "\u{00B4}"], "'", $testo));
    }
}
