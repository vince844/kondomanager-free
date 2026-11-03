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

        $quote = [];
        $resti = [];
        $assegnato = 0;

        foreach ($pesi as $chiave => $peso) {
            $esatto = $assoluto * ((float) $peso) / $sommaPesi;
            $intero = (int) floor($esatto);

            $quote[$chiave] = $intero;
            $resti[$chiave] = $esatto - $intero;
            $assegnato += $intero;
        }

        // `arsort` conserva le chiavi ed è stabile da PHP 8: a parità di resto vince chi
        // viene prima, quindi il risultato è deterministico e i test lo possono fissare.
        arsort($resti);

        $avanzo = $assoluto - $assegnato;
        foreach (array_keys($resti) as $chiave) {
            if ($avanzo <= 0) {
                break;
            }

            $quote[$chiave]++;
            $avanzo--;
        }

        return $segno === 1 ? $quote : array_map(fn (int $c): int => -$c, $quote);
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
     * Il corpo è la copia letterale del `distribuisciImporto()` privato che viveva in
     * `CalcoloQuoteService` e, identico byte per byte, in `RipartoTabelleService`, con la
     * dichiarazione «COPIA 1:1 — DEVE rimanere allineata». Estratto qui nella 1.11.0-beta.19
     * senza cambiare una virgola, perché la terza copia sarebbe nata nel ciclo passivo.
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
        $bases      = [];
        $remainders = [];
        $sumBase    = 0;

        foreach ($pesiNormalizzati as $key => $w) {
            $raw   = round($totAbs * $w, 8);
            $base  = (int) floor($raw);
            $bases[$key]      = $base;
            $remainders[$key] = $raw - $base;
            $sumBase += $base;
        }

        $diff = $totAbs - $sumBase;
        if ($diff > 0) {
            arsort($remainders);
            $keys = array_keys($remainders);
            for ($i = 0; $i < $diff && $i < count($keys); $i++) {
                $bases[$keys[$i]]++;
            }
        }

        foreach ($bases as $key => $b) {
            $result[$key] = $b * $sign;
        }

        return $result;
    }
}
