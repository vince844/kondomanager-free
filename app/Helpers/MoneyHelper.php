<?php

namespace App\Helpers;

class MoneyHelper
{
    public static function toCents($input): int
    {
        if (is_null($input) || $input === '') {
            return 0;
        }

        // CASO 1: Arriva un numero puro (es. 100 o 120.50)
        // Succede se cambi libreria JS o se passi dati da API
        if (is_numeric($input)) {
            return (int) round((float) $input * 100);
        }

        // CASO 2: Arriva la stringa mascherata (es. "1.200,50" o "-100,00")
        // Questa è la logica che volevi tu
        
        // 1. Rimuoviamo i punti delle migliaia
        $clean = str_replace('.', '', $input);
        
        // 2. Sostituiamo la virgola col punto per renderlo "capibile" da PHP
        $clean = str_replace(',', '.', $clean);

        // 3. Ora abbiamo "1200.50", moltiplichiamo per 100
        return (int) round((float) $clean * 100);
    }

    public static function format(int $cents, bool $withSymbol = true): string
    {
        if ($cents === 0) {
            return $withSymbol ? '€ 0,00' : '0,00';
        }
        
        $formatted = number_format($cents / 100, 2, ',', '.');
        return $withSymbol ? "€ {$formatted}" : $formatted;
    }
    
    // Utile per il frontend (edit form)
    public static function fromCents(int $cents): float
    {
        return round($cents / 100, 2);
    }

    public static function formatField($model, string $fieldName, bool $withSymbol = true): string
    {
        $cents = $model->{$fieldName} ?? 0;
        return self::format($cents, $withSymbol);
    }

    /**
     * Spezza un importo in centesimi fra più soggetti in proporzione ai loro pesi, senza
     * perdere né creare un centesimo.
     *
     * **Il problema.** Un `round()` indipendente per ciascuno non somma al totale: cento euro
     * e un centesimo fra tre quote uguali danno `round(3333,67) = 3334` a testa, cioè 10002
     * centesimi distribuiti su 10001 disponibili. Un centesimo nato dal nulla, che su un
     * piano rate diventa una quadratura che non torna — e nel verso opposto un centesimo che
     * sparisce. Il difetto era in `GenerateSaldiAction` fin dall'inizio ed è la ragione per
     * cui questa funzione nasce nella beta.43.
     *
     * **Il metodo è quello dei resti maggiori.** Si assegna a ognuno la parte intera della
     * sua quota esatta, poi i centesimi avanzati vanno, uno ciascuno, a chi ha il resto più
     * grande. È preferito all'alternativa che `CalcoloQuoteService::addebitaDiretto` usava
     * fino alla beta.43 — «l'ultimo assorbe lo scarto» — per una ragione che si spiega a un
     * amministratore: lì chi paga l'arrotondamento lo decideva l'ordine con cui il database
     * restituisce le righe, qui lo decide il calcolo. La differenza è di un centesimo e di
     * una domanda a cui saper rispondere. Da quella beta i due percorsi chiamano questa.
     *
     * **Il segno viaggia a parte.** I saldi possono essere a credito, cioè negativi (la
     * convenzione del progetto è positivo = debito). Ripartire direttamente un negativo
     * manderebbe `floor()` dalla parte sbagliata, quindi si lavora sul valore assoluto e il
     * segno si riapplica alla fine.
     *
     * **A pari resto il centesimo va a chi viene prima nell'array** (Coda 301, decisione
     * 183.3). Perché la regola valga davvero, i resti si confrontano in aritmetica intera: fino
     * alla 1.11.0 erano differenze in virgola mobile, e con quote 10/30/60 su 6 centesimi i
     * resti di x e di z — tutti e due 0,6 a mano — uscivano 0,5999… e 0,6000…: il centesimo
     * andava a z qualunque fosse l'ordine (x 0, y 2, z 4 invece di x 1, y 2, z 3). Quando i
     * pesi sono decimali (millesimi, quote, giorni, centesimi: `pesiInteri()`) il conto è
     * esatto; altrimenti le parti si arrotondano all'ottava cifra e il resto si confronta come
     * intero di quell'ordine (`restiInteriDaVirgola()`). L'ordine dell'array lo dà il chiamante:
     * per i titolari di un'unità è quello di `Immobile::anagrafiche()`, chi è titolare da prima
     * e poi chi è registrato prima.
     *
     * @param  array<int|string, float>  $pesi  [chiave => peso]. Pesi tutti a zero o assenti
     *                                          → riparto in parti uguali.
     * @return array<int|string, int>           [chiave => centesimi]. La somma è esattamente
     *                                          `$totaleCents`.
     */
    public static function ripartisciPerQuote(int $totaleCents, array $pesi): array
    {
        if ($pesi === []) {
            return [];
        }

        $sommaPesi = array_sum($pesi);

        // Senza millesimi non c'è proporzione da rispettare: si divide per teste. È il
        // comportamento che l'ADR-001 dei saldi iniziali dichiarava e che non era mai stato
        // scritto — al suo posto un `?: 100` metteva 100 al denominatore, ogni quota usciva
        // zero e l'importo spariva per intero.
        if ($sommaPesi <= 0) {
            $pesi = array_fill_keys(array_keys($pesi), 1.0);
            $sommaPesi = (float) count($pesi);
        }

        $segno = $totaleCents < 0 ? -1 : 1;
        $assoluto = abs($totaleCents);

        [$interi] = self::pesiInteri($pesi, $assoluto) ?? [null];
        $denominatore = $interi === null ? 0 : array_sum($interi);

        if ($interi !== null && $denominatore > 0) {
            [$quote, $resti] = self::partiEResti($assoluto, $interi, $denominatore);
        } else {
            [$quote, $resti] = self::restiInteriDaVirgola(
                array_map(fn ($peso): float => $assoluto * ((float) $peso) / $sommaPesi, $pesi),
            );
        }

        $quote = self::assegnaAvanzo($quote, $resti, $assoluto - array_sum($quote));

        return $segno === 1 ? $quote : array_map(fn (int $c): int => -$c, $quote);
    }

    /**
     * I pesi come interi esatti, quando lo sono: ognuno moltiplicato per 10^k, con il k più
     * piccolo (da 0 a 8) che li rende tutti interi. Millesimi a quattro decimali, quote a due,
     * giorni e centesimi passano tutti di qui; una frazione come 1/3 o una quota per giorni
     * normalizzata no, e la funzione dà `null`.
     *
     * Un peso «è intero» se dista dall'intero meno di un millesimo di miliardesimo del suo
     * valore, e comunque meno di un milionesimo: assorbe l'errore di rappresentazione di un
     * decimale in virgola mobile (0,1 non è esatto in binario), ed è così stretto che lo scarto
     * sulle parti resta sotto il millesimo di centesimo anche su un importo di dieci milioni di
     * euro. Il tetto del milionesimo serve ai pesi grandi: in scala 10^8 un peso da 5.000 in su
     * supera 5 × 10^11, e lì la tolleranza relativa da sola supera mezza unità e prende per
     * intero qualunque numero; un peso come 8.833,33… (26.500 / 3) diventerebbe 883333333333,
     * e un pari resto esatto si romperebbe per l'arrotondamento invece di andare al primo.
     *
     * Dà `null` anche quando i numeri non stanno negli interi a 64 bit: oltre 2^53 un peso non
     * è più esatto in virgola mobile, e `$assoluto` per il peso più grande non deve superare
     * `PHP_INT_MAX`.
     *
     * Il secondo elemento è la scala, 10^k: `distribuisciPesiNormalizzati()` divide per lei,
     * perché i suoi pesi sono già frazioni dell'importo.
     *
     * @param  array<int|string, mixed>  $pesi
     * @return array{0: array<int|string, int>, 1: int}|null
     */
    private static function pesiInteri(array $pesi, int $assoluto): ?array
    {
        $scala = 1.0;
        for ($k = 0, $unita = 1; $k <= 8; $k++, $scala *= 10.0, $unita *= 10) {
            $interi = [];
            foreach ($pesi as $chiave => $peso) {
                $x = ((float) $peso) * $scala;
                if (! is_finite($x) || abs($x) > 9007199254740992.0) {
                    return null;
                }
                $n = round($x);
                if (abs($x - $n) > min(1e-6, 1e-12 * max(1.0, abs($x)))) {
                    continue 2;
                }
                $interi[$chiave] = (int) $n;
            }

            $massimo = $interi === [] ? 0 : max(array_map('abs', $interi));
            if ($massimo > 0 && $assoluto > intdiv(PHP_INT_MAX, $massimo)) {
                return null;
            }

            return [$interi, $unita];
        }

        return null;
    }

    /**
     * Parte intera e resto di ognuno, in interi: `$assoluto × p = parte × $denominatore + resto`,
     * con il resto in [0, $denominatore) anche per un peso negativo (la parte è il `floor`,
     * come nella via in virgola mobile).
     *
     * @param  array<int|string, int>  $pesi
     * @return array{0: array<int|string, int>, 1: array<int|string, int>}
     */
    private static function partiEResti(int $assoluto, array $pesi, int $denominatore): array
    {
        $parti = [];
        $resti = [];
        foreach ($pesi as $chiave => $p) {
            $prodotto = $assoluto * $p;
            $parte = intdiv($prodotto, $denominatore);
            $resto = $prodotto % $denominatore;
            if ($resto < 0) {
                $parte--;
                $resto += $denominatore;
            }
            $parti[$chiave] = $parte;
            $resti[$chiave] = $resto;
        }

        return [$parti, $resti];
    }

    /**
     * La via per i pesi che non sono decimali: le parti esatte in virgola mobile, arrotondate
     * all'ottava cifra, e il resto come intero in centomilionesimi di centesimo. Due resti che a
     * mano sono uguali e che l'aritmetica binaria scrive 0,5999… e 0,6000… diventano lo stesso
     * intero, e decide l'ordine.
     *
     * @param  array<int|string, float>  $esatti
     * @return array{0: array<int|string, int>, 1: array<int|string, int>}
     */
    private static function restiInteriDaVirgola(array $esatti): array
    {
        $parti = [];
        $resti = [];
        foreach ($esatti as $chiave => $esatto) {
            $esatto = round($esatto, 8);
            $parte = (int) floor($esatto);
            $parti[$chiave] = $parte;
            $resti[$chiave] = (int) round(($esatto - $parte) * 100_000_000);
        }

        return [$parti, $resti];
    }

    /**
     * Un centesimo ciascuno ai resti più grandi, finché l'avanzo non finisce. `arsort` su interi
     * è stabile da PHP 8: a pari resto vince chi viene prima nell'array.
     *
     * @param  array<int|string, int>  $parti
     * @param  array<int|string, int>  $resti
     * @return array<int|string, int>
     */
    private static function assegnaAvanzo(array $parti, array $resti, int $avanzo): array
    {
        arsort($resti);
        foreach (array_keys($resti) as $chiave) {
            if ($avanzo <= 0) {
                break;
            }
            $parti[$chiave]++;
            $avanzo--;
        }

        return $parti;
    }


    /**
     * Un importo spezzato come lo vuole il modello F24: euro da una parte, centesimi
     * dall'altra.
     *
     * Sul modello ministeriale la colonna degli importi è **divisa in due caselle** da una
     * virgola prestampata, e le avvertenze dell'Agenzia sono esplicite su due punti che
     * `format()` viola entrambi: i centesimi vanno «sempre indicati con le prime due cifre
     * decimali anche nel caso che tali cifre siano pari a zero» — quindi mai `52,7` né `52` —
     * e la casella degli euro non ospita separatori di migliaia, perché è una griglia di
     * caselle e non un numero scritto.
     *
     * `€ 1.060,00` a schermo diventa quindi `1060` + `00` sul modello. Sono due
     * rappresentazioni dello stesso dato, non due arrotondamenti: la sorgente resta l'intero
     * in centesimi e qui non si arrotonda niente.
     *
     * Il valore assoluto è voluto: le due colonne del modello (debito e credito) portano già
     * il verso, e un meno stampato dentro la casella dell'importo renderebbe la delega
     * irricevibile.
     *
     * @return array{euro: string, centesimi: string}
     */
    public static function perModelloF24(int $cents): array
    {
        $assoluto = abs($cents);

        return [
            'euro' => (string) intdiv($assoluto, 100),
            'centesimi' => str_pad((string) ($assoluto % 100), 2, '0', STR_PAD_LEFT),
        ];
    }
    /**
     * Ripartisce un importo in centesimi su pesi **già normalizzati**, col metodo dei resti
     * maggiori.
     *
     * ⚠️ **Non è `ripartisciPerQuote()`, e la differenza non è cosmetica.** Quella prende pesi
     * grezzi, li normalizza da sé, e — se la loro somma è **≤ 0** — ripiega su un riparto in
     * parti uguali. Questa pretende pesi che sommano già a 1 e resta **proporzionale sempre**,
     * anche quando la somma dei pesi è negativa. Misurato il 06/09/2026 su 19.982 casi casuali:
     * con pesi a somma positiva le due funzioni danno lo **stesso** risultato in 19.769 casi su
     * 19.769; con pesi a somma negativa divergono in **213 casi su 213**. La somma torna esatta
     * al totale in entrambe, in tutti i casi.
     *
     * Quale usare dipende da cosa significano i pesi. Sui **millesimi** un totale di pesi ≤ 0 è
     * un dato malformato e dividere per teste è il ripiego giusto: usa `ripartisciPerQuote()`.
     * Sulle **righe di una fattura** un gruppo che somma a zero o meno è legittimo — è la nota
     * di credito, o il documento in cui gli storni di riga superano gli addebiti — e lì la
     * proporzione va mantenuta: usa questa.
     *
     * Il corpo nasce come copia letterale del `distribuisciImporto()` privato che viveva in
     * `CalcoloQuoteService` e, identico byte per byte, in `RipartoTabelleService`, con la
     * dichiarazione «COPIA 1:1 — DEVE rimanere allineata». Estratto qui nella 1.11.0-beta.19
     * senza cambiare una virgola, perché la terza copia sarebbe nata nel ciclo passivo.
     *
     * Dalla 1.12.0 (Coda 301, decisione 183.3) i resti si confrontano in interi, come in
     * `ripartisciPerQuote()`: a pari resto vince chi viene prima nell'array, anche quando la
     * virgola mobile scrive i due resti 0,5999… e 0,6000…. È il riparto principale delle spese
     * sulle tabelle (`CalcoloQuoteService`): con quote 10/30/60 e 6 centesimi dà x 1, y 2, z 3.
     *
     * @param  array<int|string, float>  $pesiNormalizzati  [chiave => peso]. Devono sommare a 1.
     * @param  int  $importoTotale  centesimi, con segno.
     * @return array<int|string, int>  [chiave => centesimi]. La somma è esattamente `$importoTotale`.
     */
    public static function distribuisciPesiNormalizzati(array $pesiNormalizzati, int $importoTotale): array
    {
        $result = [];
        if ($importoTotale === 0) {
            foreach ($pesiNormalizzati as $key => $_) { $result[$key] = 0; }
            return $result;
        }

        $sign    = $importoTotale < 0 ? -1 : 1;
        $totAbs  = abs($importoTotale);

        // In interi quando i pesi sono decimali (`pesiInteri()`): la parte di ognuno è
        // `$totAbs × p / 10^k`, cioè `$totAbs × $w` senza errore; altrimenti la via di sempre,
        // con il resto confrontato come intero (Coda 301).
        [$interi, $unita] = self::pesiInteri($pesiNormalizzati, $totAbs) ?? [null, 0];
        if ($interi !== null) {
            [$bases, $remainders] = self::partiEResti($totAbs, $interi, $unita);
        } else {
            [$bases, $remainders] = self::restiInteriDaVirgola(
                array_map(fn ($w): float => $totAbs * $w, $pesiNormalizzati),
            );
        }

        $bases = self::assegnaAvanzo($bases, $remainders, $totAbs - array_sum($bases));

        foreach ($bases as $key => $b) {
            $result[$key] = $b * $sign;
        }

        return $result;
    }
}
