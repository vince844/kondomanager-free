<?php

/**
 * Il condominio «Via Roma», esercizio 2026, una banca e la sola gestione ordinaria; apertura della banca € 5.000,00 a giornale. Voci: Pulizie (spesa,
 * preventivo € 3.000,00), Manutenzioni (spesa, € 1.200,00), Spese bancarie (spesa, € 150,00), Affitto antenna (entrata, € 240,00). Il piano chiede
 * € 4.110,00 (3.000 + 1.200 + 150 − 240) in 12 rate, tutte emesse il 31/01. Ugo e Bice, 500 millesimi ciascuno: € 2.055,00 a testa, € 171,25 a rata.
 *
 * - FT-1 Pulizie € 2.900,00, pagata € 2.400,00 il 15/12 con una commissione di € 2,50 sulla voce «Spese bancarie».
 * - FT-2 Manutenzioni, imponibile € 1.000,00 + IVA 22 % = € 1.220,00, ritenuta 4 % = € 40,00; pagata al fornitore € 1.180,00 il 10/11 con una
 *   commissione di € 2,50 scritta come prima della voce (sulle «Spese bancarie» di sistema, senza voce); l'F24 della ritenuta versato il 16/12.
 * - FT-3 Manutenzioni € 150,00, contestata, non pagata.
 * - Spese bancarie con una regolazione immediata: € 96,00 il 30/06, con la voce.
 * - Affitto antenna: € 240,00 incassati il 15/02.
 * - Quote pagate al 31/12: Ugo tutte (€ 2.055,00, il 31/03); Bice € 1.645,00 il 30/11 (rate 1–9 e € 103,75 della 10): non pagate € 410,00, tutte scadute.
 *
 * Le cifre del progetto, fatte a mano: banca al 31/12 = 5.000 + 3.700 + 240 − (2.402,50 + 1.182,50 + 40 + 96) = € 5.219,00; costi del giornale € 4.131,00;
 * risultato = 4.110 − 4.131 = € −21,00.
 */

use App\Actions\Gestionale\Movimenti\ConfermaVersamentoF24Action;
use App\Actions\Gestionale\Movimenti\GeneraDelegheF24Action;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PagamentoFornitore;
use App\Services\Gestionale\CalendarioFiscaleService;
use App\Services\Gestionale\PlafondRitenuteService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

/** Il fornitore soggetto alla ritenuta del 4 % sull'imponibile intero, persona fisica: l'F24 si genera. */
function vrmFornitoreConRitenuta(): \App\Models\Fornitore
{
    $id = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Idraulica Bianchi', 'soggetto_ritenuta' => true, 'ritenuta_decisa_il' => now(), 'perc_imponibile_ritenuta' => 100, 'perc_ritenuta' => 4,
        'natura_percipiente' => 'persona_fisica_irpef', 'giorni_scadenza' => 30, 'modalita_pagamento_default' => 'bonifico', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return \App\Models\Fornitore::find($id);
}

/** Il giorno dell'amministratore, alle 10 di Roma. */
function vrmGiorno($test, string $giorno): void
{
    $test->travelTo(Carbon::parse($giorno.' 10:00', 'Europe/Rome'));
}

/** La base: condominio, banca aperta a € 5.000,00, le quattro voci, il piano da € 4.110,00 approvato ed emesso il 31/01. */
function vrmBase($test): array
{
    vrmGiorno($test, '2026-01-02');
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 500.0);
    be52Unita($s, 'C', 'bice', $s['tabella'], 500.0);
    $s['cassa']->update(['saldo_iniziale' => 500000]);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $s['pc'] = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'nome' => 'Piano dei conti']);
    $s['pulizie'] = be52Voce($test, $s, $s['pc'], 'Pulizie', 'spesa', '3.000,00');
    $s['manutenzioni'] = be52Voce($test, $s, $s['pc'], 'Manutenzioni', 'spesa', '1.200,00');
    $s['bancarie'] = be52Voce($test, $s, $s['pc'], 'Spese bancarie', 'spesa', '150,00');
    $s['antenna'] = be52Voce($test, $s, $s['pc'], 'Affitto antenna', 'entrata', '240,00');
    $s['piano'] = be52PianoDallaRotta($test, $s);
    $test->actingAs($test->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => true, 'data_delibera_assemblea' => '2026-01-02'])->assertSessionHasNoErrors();
    vrmGiorno($test, '2026-01-31');
    $ids = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->orderBy('numero_rata')->pluck('id')->all();
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), ['rate_ids' => $ids, 'data_emissione' => '2026-01-31', 'invia_notifiche' => false])->assertSessionHasNoErrors();
    $s['fornitore'] = be52Fornitore();
    $s['idraulico'] = vrmFornitoreConRitenuta();
    $s['sistema'] = (int) ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'spese_bancarie')->value('id');

    return $s;
}

/** Le quote di una persona, per numero di rata: [numero => id della quota]. */
function vrmQuote(array $s, string $chi): array
{
    return DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $s['piano']->id)
        ->where('rate_quote.anagrafica_id', $s['persone'][$chi]->id)->orderBy('rate.numero_rata')->pluck('rate_quote.id', 'rate.numero_rata')->all();
}

/** L'incasso dalla rotta della pagina: [id quota => centesimi]; la parte in più resta sulla rata (credito sulla rata). */
function vrmIncassa($test, array $s, string $chi, array $righe, string $data, int $parteInPiu = 0): int
{
    $totale = array_sum($righe) + $parteInPiu;
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone'][$chi]->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $data, 'importo_totale' => number_format($totale / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => number_format($parteInPiu / 100, 2, '.', ''), 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => array_map(fn ($id, $cent) => ['rata_id' => (int) $id, 'importo' => number_format($cent / 100, 2, '.', '')], array_keys($righe), array_values($righe)),
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
}

/** Una fattura dalla rotta: righe [[id voce, imponibile in euro, aliquota]], con la ritenuta se il fornitore la applica. */
function vrmFattura($test, array $s, \App\Models\Fornitore $f, string $numero, array $righe, string $data, array $extra = []): FatturaPassiva
{
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), array_merge(be52PayloadFattura($s, $f, $righe[0][0], 0, [
        'data_documento' => $data, 'data_scadenza' => $data, ...($f->soggetto_ritenuta ? ['applica_ritenuta' => true] : []),
        'righe' => array_map(fn ($r) => ['descrizione' => 'Servizio '.$numero, 'importo_imponibile' => $r[1], 'aliquota_iva' => $r[2] ?? 0, 'conto_id' => $r[0], 'is_sopravvenienza' => false], $righe),
    ], $numero), $extra))->assertSessionHasNoErrors();

    return FatturaPassiva::where('numero_documento', $numero)->where('condominio_id', $s['c']->id)->firstOrFail();
}

/** Un pagamento dalla rotta: allocazioni [[fattura, tipo, centesimi]], la commissione in centesimi con la sua voce. */
function vrmPaga($test, array $s, \App\Models\Fornitore $f, array $allocazioni, string $data, int $commissione = 0, ?int $voceCommissione = null): PagamentoFornitore
{
    $test->actingAs($test->user)->post(route('admin.gestionale.pagamenti-fornitori.store', [$s['c']]), [
        'fornitore_id' => $f->id, 'esercizio_id' => $s['e']->id, 'conto_corrente_id' => $s['cassa']->conto_contabile_id, 'data_pagamento' => $data,
        'metodo_pagamento' => 'bonifico', 'iban_beneficiario' => 'IT60X0542811101000000123456', 'importo_commissioni_cents' => $commissione, 'bonifico_parlante' => false,
        'voce_commissione_id' => $voceCommissione, 'allow_overdraft' => true, 'iban_confermato_manualmente' => true, 'conferma_duplicato_verificato' => true,
        'allocazioni' => array_map(fn ($a) => ['fattura_id' => $a[0]->id, 'tipo' => $a[1], 'importo_allocato_cents' => $a[2]], $allocazioni),
    ])->assertSessionHasNoErrors();

    return PagamentoFornitore::where('condominio_id', $s['c']->id)->whereNull('pagamento_padre_id')->latest('id')->firstOrFail();
}

/** La commissione di un pagamento riportata come la scriveva la versione di prima: «Spese bancarie» di sistema, senza voce. */
function vrmCommissioneVecchia(array $s, PagamentoFornitore $p): void
{
    DB::table('righe_scritture')->where('scrittura_id', $p->scrittura_contabile_id)->where('voce_spesa_id', $s['bancarie']->id)
        ->update(['conto_contabile_id' => $s['sistema'], 'voce_spesa_id' => null]);
}

/**
 * Fino al 30/11: le quote di Ugo e di Bice, l'antenna, le tre fatture, la regolazione, il pagamento della FT-2. Con `$ugoInPiuSullaTerza` Ugo paga
 * la rata 3 il 30/11, con quella parte in più (resta sulla rata: un credito sulla rata), e le altre il 31/03.
 */
function vrmFinoANovembre($test, int $ugoInPiuSullaTerza = 0): array
{
    $s = vrmBase($test);
    vrmGiorno($test, '2026-02-15');
    $test->actingAs($test->user)->post(route('admin.gestionale.incassi-da-terzi.store', [$s['c']]), [
        'esercizio_id' => $s['e']->id, 'conto_id' => $s['antenna']->id, 'cassa_id' => $s['cassa']->id, 'data_operazione' => '2026-02-15',
        'importo' => 240.00, 'anagrafica_id' => null, 'causale' => 'Affitto antenna 2026',
    ])->assertSessionHasNoErrors();
    vrmGiorno($test, '2026-03-31');
    $ugo = vrmQuote($s, 'ugo');
    $s['incasso_ugo'] = vrmIncassa($test, $s, 'ugo', array_fill_keys($ugoInPiuSullaTerza > 0 ? array_values(array_diff_key($ugo, [3 => true])) : $ugo, 17125), '2026-03-31');
    vrmGiorno($test, '2026-04-10');
    $s['ft1'] = vrmFattura($test, $s, $s['fornitore'], 'FT-1', [[$s['pulizie']->id, 2900.00]], '2026-04-10');
    $s['ft2'] = vrmFattura($test, $s, $s['idraulico'], 'FT-2', [[$s['manutenzioni']->id, 1000.00, 22]], '2026-04-10');
    $s['ft3'] = vrmFattura($test, $s, $s['fornitore'], 'FT-3', [[$s['manutenzioni']->id, 150.00]], '2026-04-10', ['stato_approvazione' => 'contestata']);
    vrmGiorno($test, '2026-06-30');
    $test->actingAs($test->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), [
        'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $s['bancarie']->id, 'cassa_id' => $s['cassa']->id, 'fornitore_id' => null,
        'data_operazione' => '2026-06-30', 'causale' => 'Canone e bolli', 'importo' => 96.00,
    ])->assertSessionHasNoErrors();
    vrmGiorno($test, '2026-11-10');
    $s['pag_ft2'] = vrmPaga($test, $s, $s['idraulico'], [[$s['ft2'], 'pagamento', 118000]], '2026-11-10', 250, $s['bancarie']->id);
    vrmCommissioneVecchia($s, $s['pag_ft2']);
    vrmGiorno($test, '2026-11-30');
    $bice = vrmQuote($s, 'bice');
    $righe = [];
    foreach ($bice as $n => $id) {
        if ($n <= 9) {
            $righe[$id] = 17125;
        }
    }
    $righe[$bice[10]] = 10375;
    $s['incasso_bice'] = vrmIncassa($test, $s, 'bice', $righe, '2026-11-30');
    if ($ugoInPiuSullaTerza > 0) {
        vrmIncassa($test, $s, 'ugo', [$ugo[3] => 17125], '2026-11-30', $ugoInPiuSullaTerza);
    }

    return $s;
}

/** Il caso intero: dopo novembre, il pagamento della FT-1 con la commissione sulla voce (15/12) e l'F24 della ritenuta della FT-2 (16/12). */
function vrmScenario($test, int $ugoInPiuSullaTerza = 0): array
{
    $s = vrmFinoANovembre($test, $ugoInPiuSullaTerza);
    vrmGiorno($test, '2026-12-15');
    $s['pag_ft1'] = vrmPaga($test, $s, $s['fornitore'], [[$s['ft1'], 'pagamento', 240000]], '2026-12-15', 250, $s['bancarie']->id);
    vrmGiorno($test, '2026-12-16');
    vrmVersaF24($s, '2026-12-16');

    return $s;
}

/** L'F24 delle ritenute dei pagamenti fino a quel giorno, generato e versato dalla banca dello scenario. */
function vrmVersaF24(array $s, string $data): void
{
    $delega = (new GeneraDelegheF24Action(new PlafondRitenuteService(new CalendarioFiscaleService)))->esegui($s['c'], $s['e']->id)->first();
    expect($delega)->not->toBeNull();
    // Come la pagina della delega: il conto e la sua cassa (senza la cassa la riga non sarebbe nel registro di contabilità).
    (new ConfermaVersamentoF24Action)->esegui($delega, $data, $s['cassa']->conto_contabile_id, $s['cassa']->id);
}

/**
 * Il fascicolo dell'esercizio e, per ogni parte, la sua vista com'è stata scritta: `html` e `testo` (senza i tag, gli spazi in fila uno solo), per
 * nome (`frontespizio`, `riepilogo`, `situazione`, `cassa`, `controlli`, `posizioni`, `crediti`, `registro`), e `dati`, i dati che la vista ha ricevuto.
 */
function vrmTestiDelFascicolo(array $s, \App\Models\Esercizio $e, array $parti = [], bool $dettaglio = true): array
{
    $viste = [];
    \Illuminate\Support\Facades\View::composer(['pdf.gestionale.rendiconto_frontespizio', 'pdf.gestionale.rendiconto_riepilogo', 'pdf.gestionale.stato_patrimoniale',
        'pdf.gestionale.rendiconto_posizioni', 'pdf.gestionale.rendiconto_crediti', 'pdf.gestionale.registro_contabilita'], function ($v) use (&$viste) {
            $nome = str_replace(['pdf.gestionale.rendiconto_', 'pdf.gestionale.registro_contabilita'], ['', 'registro'], $v->getName());
            $viste[$nome === 'pdf.gestionale.stato_patrimoniale' ? $v->getData()['parte'] : $nome] = [$v->getName(), $v->getData()];
        });
    $f = app(\App\Services\Gestionale\FascicoloDelRendiconto::class)->genera($s['c'], $e, $parti, $dettaglio);
    $parti = ['fascicolo' => $f];
    foreach ($viste as $nome => [$vista, $dati]) {
        $html = view($vista, $dati)->render();
        $parti[$nome] = ['html' => $html, 'testo' => vrmTesto($html), 'dati' => $dati];
    }

    return $parti;
}

/** Il testo di una stampa: senza i tag (una cella, un paragrafo o un a capo diventano uno spazio), con le entità sciolte e gli spazi in fila uno solo. */
function vrmTesto(string $html): string
{
    $html = preg_replace('#</?(td|th|tr|p|div|br|h1|h2|table)\b[^>]*>#i', ' ', $html);

    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}
