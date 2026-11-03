<?php

use App\Helpers\MoneyHelper;

/**
 * `MoneyHelper::distribuisciPesiNormalizzati()` è nata nella 1.11.0-beta.19 estraendo il
 * `distribuisciImporto()` privato che viveva, identico byte per byte, in `CalcoloQuoteService`
 * e in `RipartoTabelleService` — dove il secondo si dichiarava «COPIA 1:1 … DEVE rimanere
 * allineata». L'estrazione è servita perché il ciclo passivo aveva bisogno della stessa
 * aritmetica e la terza copia sarebbe nata lì.
 *
 * Il rischio dell'estrazione non è il ciclo passivo: è il **motore di riparto**, che da quella
 * funzione fa uscire le quote che i condòmini pagano. Per questo qui non si prova che «i conti
 * tornano»: si prova che la funzione estratta è **la stessa funzione**, confrontandola con una
 * copia congelata dell'originale su casi generati. Se un domani qualcuno la ottimizza e cambia
 * anche un solo arrotondamento, questo file diventa rosso prima che se ne accorga un condòmino.
 */

/**
 * Copia congelata di `CalcoloQuoteService::distribuisciImporto()` come era al commit 512bd40f
 * (1.11.0-beta.18), prima dell'estrazione. **Non va aggiornata**: è il termine di paragone.
 */
function distribuisciImportoCongelato(array $weights, int $importoTotale): array
{
    $result = [];
    if ($importoTotale === 0) {
        foreach ($weights as $key => $_) { $result[$key] = 0; }
        return $result;
    }

    $sign    = $importoTotale < 0 ? -1 : 1;
    $totAbs  = abs($importoTotale);
    $bases      = [];
    $remainders = [];
    $sumBase    = 0;

    foreach ($weights as $key => $w) {
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

it('è la stessa funzione della copia congelata, su duemila casi generati', function () {
    mt_srand(20260906);   // seme fisso: un rosso si riproduce

    $diversi = [];
    for ($i = 0; $i < 2000; $i++) {
        $quanti = mt_rand(1, 8);
        $grezzi = [];
        for ($k = 0; $k < $quanti; $k++) {
            $dado = mt_rand(0, 100);
            // pesi realistici: millesimi, imponibili di riga, qualche zero, qualche negativo
            $grezzi["r$k"] = $dado < 10 ? 0 : ($dado < 25 ? -mt_rand(1, 5000) : mt_rand(1, 100000));
        }
        $somma = array_sum($grezzi);
        if ($somma === 0) {
            continue;   // pesi non normalizzabili: fuori dal contratto della funzione
        }

        $pesi = array_map(fn ($w) => $w / $somma, $grezzi);
        $totale = mt_rand(-500000, 500000);

        $atteso = distribuisciImportoCongelato($pesi, $totale);
        $ottenuto = MoneyHelper::distribuisciPesiNormalizzati($pesi, $totale);

        if ($atteso !== $ottenuto) {
            $diversi[] = compact('grezzi', 'totale', 'atteso', 'ottenuto');
        }
    }

    expect($diversi)->toBe([], 'la funzione estratta si è discostata dall’originale congelato');
});

it('restituisce esattamente il totale, centesimo per centesimo', function () {
    mt_srand(20260906);

    for ($i = 0; $i < 2000; $i++) {
        $quanti = mt_rand(1, 8);
        $grezzi = [];
        for ($k = 0; $k < $quanti; $k++) {
            $dado = mt_rand(0, 100);
            $grezzi["r$k"] = $dado < 10 ? 0 : ($dado < 25 ? -mt_rand(1, 5000) : mt_rand(1, 100000));
        }
        $somma = array_sum($grezzi);
        if ($somma === 0) {
            continue;
        }

        $pesi = array_map(fn ($w) => $w / $somma, $grezzi);
        $totale = mt_rand(-500000, 500000);

        expect(array_sum(MoneyHelper::distribuisciPesiNormalizzati($pesi, $totale)))
            ->toBe($totale, "il totale non torna sui pesi ".json_encode($grezzi));
    }
});

it('resta proporzionale anche quando i pesi sommano a un valore negativo', function () {
    // È la differenza dichiarata con ripartisciPerQuote(), che lì ripiega su parti uguali.
    // Sulle righe di una fattura un gruppo a somma negativa è legittimo — la nota di credito,
    // o il documento in cui gli storni di riga superano gli addebiti — e la proporzione va tenuta.
    $grezzi = ['grande' => -3000, 'piccola' => 1000];
    $somma = array_sum($grezzi);            // -2000
    $pesi = array_map(fn ($w) => $w / $somma, $grezzi);

    $nostra = MoneyHelper::distribuisciPesiNormalizzati($pesi, -1000);
    $altra  = MoneyHelper::ripartisciPerQuote(-1000, $grezzi);

    expect(array_sum($nostra))->toBe(-1000)
        ->and(array_sum($altra))->toBe(-1000)
        ->and($nostra)->not->toBe($altra);   // le due strategie divergono, ed è voluto
});

/**
 * **Che cosa questo file cattura davvero, misurato il 06/09/2026.** Le mutazioni sono state
 * passate sugli stessi 1.964 casi che il test genera, contando quante volte cambiano il
 * risultato:
 *
 *   `arsort` → `asort` (chi prende il centesimo)   1.672 casi
 *   `floor` → `round`                                280 casi, e la somma non torna più
 *   `$diff > 0` → `$diff > 1`                        478 casi, e la somma non torna più
 *   arrotondamento 8 → 2 decimali                     17 casi
 *   arrotondamento 8 → 4 decimali                      1 caso
 *   arrotondamento 8 → 6 decimali                  **0 casi**
 *
 * L'ultima riga non è un buco della prova: è una **proprietà dell'algoritmo**. Se un
 * arrotondamento più grossolano fa scendere una base di un'unità, quel peso si ritrova un resto
 * vicino a 1 — cioè il più grande — e il passo di compensazione gli restituisce il centesimo.
 * I resti maggiori assorbono le differenze sotto il centesimo, e il `round(…, 8)` è difensivo:
 * comincia a spostare un risultato solo dai 4 decimali in giù. Il test qui sotto lo fissa, così
 * se un domani quella proprietà smette di valere non lo si scopre da un riparto sbagliato.
 */
it('i resti maggiori assorbono le differenze di arrotondamento sotto il centesimo', function () {
    // Un peso costruito per cadere a un decimilionesimo sotto un intero: con 8 decimali la
    // base scende a 4 e il resto 0,9999999 vince la compensazione; con 6 la base è già 5.
    $totale = 10_000_000;
    $primo = 4.9999999 / $totale;
    $pesi = ['sul_filo' => $primo, 'resto' => 1 - $primo];

    $con8 = MoneyHelper::distribuisciPesiNormalizzati($pesi, $totale);

    expect($con8['sul_filo'])->toBe(5)
        ->and(array_sum($con8))->toBe($totale);
});

it('con totale zero non inventa centesimi', function () {
    expect(MoneyHelper::distribuisciPesiNormalizzati(['a' => 0.5, 'b' => 0.5], 0))
        ->toBe(['a' => 0, 'b' => 0]);
});

it('sul gruppo IVA della bolletta di collaudo distribuisce il centesimo mancante', function () {
    // File 06 dei file di collaudo: tre righe al 22 % — trasporto, materia, storno oneri.
    // Riga per riga l'IVA fa 10,05; il DatiRiepilogo del documento ne dichiara 10,06.
    $righe = ['trasporto' => 4061, 'materia' => 693, 'oneri' => -180];
    $somma = array_sum($righe);                       // 4574, l'imponibile dichiarato dal gruppo
    $pesi = array_map(fn ($w) => $w / $somma, $righe);

    $iva = MoneyHelper::distribuisciPesiNormalizzati($pesi, 1006);

    expect(array_sum($iva))->toBe(1006)
        ->and($iva['trasporto'])->toBe(893)
        ->and($iva['materia'])->toBe(153)      // il centesimo va qui: resto 0,42, il più grande
        ->and($iva['oneri'])->toBe(-40);       // la riga negativa resta intatta
});
