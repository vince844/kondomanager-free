<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use App\Services\CalcoloQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * BUCO B — il riparto non sa nulla di quanto un condòmino ha GIÀ VERSATO.
 *
 * Caso reale (segnalazione forum, luglio 2026): nel 2025 l'assemblea delibera una
 * ristrutturazione e i condòmini versano l'accantonamento per millesimi. Nel 2026 i
 * lavori subiscono una variante e la fattura finale supera l'accantonato.
 *
 * Numeri (semplificati a due unità da 500 millesimi ciascuna):
 *
 *   Fattura lavori           €1.100   →  €550 a testa
 *   Già versato nel 2025     €1.000   →  €500 a testa
 *   Residuo DOVUTO oggi        €100   →   €50 a testa   ← quello che il riparto DEVE chiedere
 *
 * Oggi `CalcoloQuoteService` ripartisce l'importo LORDO del conto sui millesimi senza
 * sottrarre nulla di già versato: non esiste proprio il concetto di "già versato per
 * voce di spesa". Il risultato è che chiede €550 a testa invece di €50.
 *
 * Nella pratica l'amministratore aggira il problema impostando a mano il budget della
 * voce pari al deliberato, così che a giornale finisca solo lo sforo — ma è un trucco
 * manuale: i €1.000 restano senza alcuna traccia per-condòmino, quindi il rendiconto
 * non sa dire CHI ha pagato l'opera, e la stessa cifra non è verificabile.
 *
 * Questo test fissa il comportamento ATTUALE. Quando il primitivo "riscosso-non-speso"
 * sarà in piedi (beta.26), la quota attesa passerà da €550 a €50 a testa.
 *
 * @see docs/fondo_accantonato_e_quadratura_sp.md §4 (netting del riparto)
 */

/** Scenario: gestione straordinaria con una voce lavori e una tabella millesimale. */
function bbScenarioLavori(int $importoFatturaCents): object
{
    $condominio = Condominio::factory()->create();

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome'          => '2026',
        'data_inizio'   => '2026-01-01',
        'data_fine'     => '2026-12-31',
        'stato'         => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id,
        'nome'          => 'Straordinaria Ristrutturazione',
        'data_inizio'   => '2026-01-01',
        'tipo'          => 'straordinaria',
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $pianoConto = PianoConto::create([
        'condominio_id' => $condominio->id,
        'gestione_id'   => $gestione->id,
        'nome'          => 'Piano Lavori',
    ]);

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id,
        'nome'          => 'Millesimi Proprietà',
        'tipo'          => 'standard',
        'quota'         => 'millesimi',
        'attiva'        => true,
    ]);

    // La voce porta l'importo pieno della fattura: è ciò che il riparto distribuisce.
    $conto = Conto::forceCreate([
        'piano_conto_id' => $pianoConto->id,
        'nome'           => 'Lavori ristrutturazione',
        'importo'        => $importoFatturaCents,
        'tipo'           => 'spesa',
        'attivo'         => true,
    ]);

    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id'     => $conto->id,
        'tabella_id'   => $tabella->id,
        'coefficiente' => 100,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);

    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId,
        'soggetto'                     => 'proprietario',
        'percentuale'                  => 100,
        'created_at'                   => now(),
        'updated_at'                   => now(),
    ]);

    return (object) compact('condominio', 'esercizio', 'gestione', 'tabella', 'conto');
}

/** Un immobile con il suo proprietario e i suoi millesimi. */
function bbImmobileConProprietario(Tabella $tabella, int $millesimi): object
{
    static $seq = 0;
    $seq++;

    $immobile = Immobile::forceCreate([
        'condominio_id' => $tabella->condominio_id,
        'nome'          => 'Interno '.$seq,
        'descrizione'   => 'Appartamento',
        'interno'       => (string) $seq,
    ]);

    $anagrafica = Anagrafica::forceCreate([
        'nome'           => 'Condomino '.$seq,
        'email'          => 'bb'.$seq.'@example.com',
        'indirizzo'      => 'Via Roma 1',
        'codice_fiscale' => 'BBTEST'.str_pad((string) $seq, 10, '0', STR_PAD_LEFT),
    ]);

    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $anagrafica->id,
        'immobile_id'   => $immobile->id,
        'tipologia'     => 'proprietario',
        'quota'         => 100.0,
        'attivo'        => true,
        'data_inizio'   => now()->format('Y-m-d'),
    ]);

    DB::table('quote_tabella')->insert([
        'tabella_id'  => $tabella->id,
        'immobile_id' => $immobile->id,
        'valore'      => $millesimi,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    return (object) ['immobile' => $immobile, 'anagrafica' => $anagrafica];
}

test('RETROCOMPATIBILITÀ: senza coperture registrate il riparto non cambia di un centesimo', function () {
    // Fattura €1.100 su due unità da 500 millesimi, nessun contributo registrato.
    $sc = bbScenarioLavori(110_000);
    $a  = bbImmobileConProprietario($sc->tabella, 500);
    $b  = bbImmobileConProprietario($sc->tabella, 500);

    $quote = (new CalcoloQuoteService())->calcolaPerGestione($sc->gestione);

    // €550 a testa, esattamente come prima della beta.26: il netting entra in gioco
    // SOLO se esiste un contributo registrato per quell'unità. Tutte le installazioni
    // esistenti, che non ne hanno, continuano a ripartire identicamente.
    expect($quote[$a->anagrafica->id][$a->immobile->id])->toBe(55_000);
    expect($quote[$b->anagrafica->id][$b->immobile->id])->toBe(55_000);
})->group('buco-b', 'riparto', 'retrocompatibilita');

/** Registra quanto un'unità ha già versato verso una voce di spesa. */
function bbGiaVersato(object $sc, object $unita, int $importoCents, string $natura = 'fondo_vincolato'): void
{
    \App\Models\Gestionale\ContributoVersato::create([
        'condominio_id' => $sc->condominio->id,
        'target_type'   => Conto::class,
        'target_id'     => $sc->conto->id,
        'immobile_id'   => $unita->immobile->id,
        'anagrafica_id' => $unita->anagrafica->id,
        'importo_cents' => $importoCents,
        'natura'        => $natura,
        'origine'       => 'migrazione',
        'descrizione'   => 'Accantonamento 2025',
    ]);
}

test('IL CASO DEL FORUM: con il già versato, il riparto chiede solo il residuo', function () {
    // Fattura €1.100, ciascuna delle due unità ha già versato €500 nel 2025.
    $sc = bbScenarioLavori(110_000);
    $a  = bbImmobileConProprietario($sc->tabella, 500);
    $b  = bbImmobileConProprietario($sc->tabella, 500);

    bbGiaVersato($sc, $a, 50_000);
    bbGiaVersato($sc, $b, 50_000);

    $service = new CalcoloQuoteService();
    $quote = $service->calcolaPerGestione($sc->gestione);

    // €550 dovuti − €500 già versati = €50 a testa. Non più €550.
    expect($quote[$a->anagrafica->id][$a->immobile->id])->toBe(5_000);
    expect($quote[$b->anagrafica->id][$b->immobile->id])->toBe(5_000);

    // Totale richiesto ai condòmini = €100, esattamente la variante.
    $totale = collect($quote)->flatMap(fn ($i) => array_values($i))->sum();
    expect($totale)->toBe(10_000);

    expect($service->getEccedenzeCopertura())->toBeEmpty();
})->group('buco-b', 'riparto');

test('copertura parziale: solo una delle due unità ha versato', function () {
    $sc = bbScenarioLavori(110_000);
    $a  = bbImmobileConProprietario($sc->tabella, 500);
    $b  = bbImmobileConProprietario($sc->tabella, 500);

    bbGiaVersato($sc, $a, 50_000); // solo A ha versato

    $quote = (new CalcoloQuoteService())->calcolaPerGestione($sc->gestione);

    expect($quote[$a->anagrafica->id][$a->immobile->id])->toBe(5_000);   // €550 − €500
    expect($quote[$b->anagrafica->id][$b->immobile->id])->toBe(55_000);  // nulla versato
})->group('buco-b', 'riparto');

test('copertura totale: se il versato copre tutto, non si chiede nulla', function () {
    $sc = bbScenarioLavori(100_000); // fattura €1.000, pari all'accantonato
    $a  = bbImmobileConProprietario($sc->tabella, 500);
    $b  = bbImmobileConProprietario($sc->tabella, 500);

    bbGiaVersato($sc, $a, 50_000);
    bbGiaVersato($sc, $b, 50_000);

    $service = new CalcoloQuoteService();
    $quote = $service->calcolaPerGestione($sc->gestione);

    $totale = collect($quote)->flatMap(fn ($i) => array_values($i))->sum();
    expect($totale)->toBe(0);
    expect($service->getEccedenzeCopertura())->toBeEmpty();
})->group('buco-b', 'riparto');

test('eccedenza: chi ha versato più del dovuto non va sotto zero, ma viene segnalato', function () {
    $sc = bbScenarioLavori(60_000); // fattura €600 → €300 a testa
    $a  = bbImmobileConProprietario($sc->tabella, 500);
    $b  = bbImmobileConProprietario($sc->tabella, 500);

    bbGiaVersato($sc, $a, 50_000); // ha versato €500 ma ne doveva €300
    bbGiaVersato($sc, $b, 30_000);

    $service = new CalcoloQuoteService();
    $quote = $service->calcolaPerGestione($sc->gestione);

    // La quota non diventa negativa...
    expect($quote[$a->anagrafica->id][$a->immobile->id])->toBe(0);
    expect($quote[$b->anagrafica->id][$b->immobile->id])->toBe(0);

    // ...ma i €200 di troppo non spariscono in silenzio: sono denaro dei condòmini.
    $ecc = $service->getEccedenzeCopertura();
    expect($ecc)->toHaveCount(1);
    expect($ecc[0]['immobile_id'])->toBe($a->immobile->id);
    expect($ecc[0]['eccedenza'])->toBe(20_000);
})->group('buco-b', 'riparto');

test('comproprietari: la copertura dell\'unità si divide tra loro senza perdere centesimi', function () {
    $sc = bbScenarioLavori(110_000);

    // Unità con due comproprietari al 50% ciascuno.
    $immobile = Immobile::forceCreate([
        'condominio_id' => $sc->condominio->id,
        'nome' => 'Interno condiviso', 'descrizione' => 'App', 'interno' => '99',
    ]);
    $ids = [];
    foreach ([1, 2] as $n) {
        $an = Anagrafica::forceCreate([
            'nome' => "Comproprietario $n",
            'email' => "compro$n@example.com",
            'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => 'CMPR'.str_pad((string) $n, 12, '0', STR_PAD_LEFT),
        ]);
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $an->id, 'immobile_id' => $immobile->id,
            'tipologia' => 'proprietario', 'quota' => 50.0, 'attivo' => true,
            'data_inizio' => now()->format('Y-m-d'),
        ]);
        $ids[] = $an->id;
    }
    DB::table('quote_tabella')->insert([
        'tabella_id' => $sc->tabella->id, 'immobile_id' => $immobile->id,
        'valore' => 1000, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // L'unità ha già versato €1.000 su €1.100 dovuti (è l'unica unità).
    \App\Models\Gestionale\ContributoVersato::create([
        'condominio_id' => $sc->condominio->id,
        'target_type'   => Conto::class,
        'target_id'     => $sc->conto->id,
        'immobile_id'   => $immobile->id,
        'importo_cents' => 100_000,
        'natura'        => 'fondo_vincolato',
    ]);

    $quote = (new CalcoloQuoteService())->calcolaPerGestione($sc->gestione);

    // €100 residui divisi tra i due comproprietari: €50 ciascuno, somma esatta.
    $somma = $quote[$ids[0]][$immobile->id] + $quote[$ids[1]][$immobile->id];
    expect($somma)->toBe(10_000);
})->group('buco-b', 'riparto');

/**
 * REGRESSIONE: revisione avversariale beta.26 — la copertura non veniva mai
 * "consumata", quindi ogni chiamata di distribuisciSuTabelle() sullo stesso conto
 * la sottraeva per intero. Riproduce il caso più comune in cui questo accade
 * DAVVERO in produzione: un capitolo rateizzato in due tranche (acconto + saldo),
 * ciascuna un PianoRate SEPARATO con la propria pivot piano_rate_capitoli.importo.
 *
 *   Budget capitolo          €1.000   (500 + 500 su due unità)
 *   Già versato per unità      €300   (600 totali)
 *   Acconto (60% del budget) €600 pivot → lordo per unità €300
 *   Saldo   (40% del budget) €400 pivot → lordo per unità €200
 *
 * Prima della correzione: acconto chiede 300−300=0, saldo chiede 200−300→0
 * (clamp), totale chiesto €0 contro un residuo VERO di €400 (1.000−600 versati).
 * Dopo: la copertura si applica in proporzione a quanto ciascuna chiamata pesa sul
 * budget totale (60%/40%), il totale chiesto torna a coincidere col residuo vero.
 */
test('BUCO B RISOLTO: due piani rate (acconto+saldo) sullo stesso capitolo non duplicano la copertura', function () {
    $sc = bbScenarioLavori(100_000); // capitolo €1.000
    $a  = bbImmobileConProprietario($sc->tabella, 500);
    $b  = bbImmobileConProprietario($sc->tabella, 500);

    bbGiaVersato($sc, $a, 30_000); // €300 già versati a testa (€600 totali)
    bbGiaVersato($sc, $b, 30_000);

    $pianoAcconto = \App\Models\Gestionale\PianoRate::create([
        'gestione_id'   => $sc->gestione->id,
        'condominio_id' => $sc->condominio->id,
        'nome'          => 'Acconto',
        'attivo'        => true,
    ]);
    $pianoAcconto->capitoli()->attach($sc->conto->id, ['importo' => 60_000]);

    $pianoSaldo = \App\Models\Gestionale\PianoRate::create([
        'gestione_id'   => $sc->gestione->id,
        'condominio_id' => $sc->condominio->id,
        'nome'          => 'Saldo',
        'attivo'        => true,
    ]);
    $pianoSaldo->capitoli()->attach($sc->conto->id, ['importo' => 40_000]);

    $quoteAcconto = (new CalcoloQuoteService())->calcolaPerGestione($sc->gestione, $pianoAcconto);
    $quoteSaldo   = (new CalcoloQuoteService())->calcolaPerGestione($sc->gestione, $pianoSaldo);

    $totaleAcconto = collect($quoteAcconto)->flatMap(fn ($i) => array_values($i))->sum();
    $totaleSaldo   = collect($quoteSaldo)->flatMap(fn ($i) => array_values($i))->sum();

    // Il residuo vero è 1.000 − 600 = 400: la somma di quanto chiesto nelle DUE
    // tranche deve tornarci, non azzerarsi come prima della correzione.
    expect($totaleAcconto + $totaleSaldo)->toBe(40_000);

    // 60% dell'acconto sul lordo €300/testa: copertura applicata floor(300*0.6)=180,
    // residuo 120/testa → 240 totali. 40% del saldo: floor(300*0.4)=120,
    // residuo 200-120=80/testa → 160 totali. 240+160=400.
    expect($totaleAcconto)->toBe(24_000);
    expect($totaleSaldo)->toBe(16_000);
})->group('buco-b', 'riparto', 'regressione-avversariale');

test('BUCO B RISOLTO: senza rateizzazione in tranche, un solo piano rate si comporta come prima', function () {
    // Nessuno split: quota=1 sempre, comportamento invariato rispetto al test
    // "IL CASO DEL FORUM" qui sopra, ma passando esplicitamente il PianoRate.
    $sc = bbScenarioLavori(110_000);
    $a  = bbImmobileConProprietario($sc->tabella, 500);
    $b  = bbImmobileConProprietario($sc->tabella, 500);

    bbGiaVersato($sc, $a, 50_000);
    bbGiaVersato($sc, $b, 50_000);

    $piano = \App\Models\Gestionale\PianoRate::create([
        'gestione_id'   => $sc->gestione->id,
        'condominio_id' => $sc->condominio->id,
        'nome'          => 'Rata Unica',
        'attivo'        => true,
    ]);
    $piano->capitoli()->attach($sc->conto->id, ['importo' => 110_000]); // 100% del budget

    $quote = (new CalcoloQuoteService())->calcolaPerGestione($sc->gestione, $piano);

    expect($quote[$a->anagrafica->id][$a->immobile->id])->toBe(5_000);
    expect($quote[$b->anagrafica->id][$b->immobile->id])->toBe(5_000);
})->group('buco-b', 'riparto', 'regressione-avversariale');

test('BUCO B: nessuna struttura dati registra il "già versato" per voce di spesa', function () {
    $sc = bbScenarioLavori(110_000);
    bbImmobileConProprietario($sc->tabella, 500);

    // Le uniche tracce di denaro incassato sono legate alle QUOTE DI UNA RATA
    // (rate_quote.importo_pagato), cioè al piano rate che le ha generate — non
    // alla voce di spesa. Non esiste una tabella che risponda alla domanda
    // "quanto ha versato Rossi per i lavori di ristrutturazione?".
    expect(Schema::hasTable('rate_quota_origine'))->toBeFalse();
    expect(Schema::hasColumn('conti', 'fondo_target_id'))->toBeFalse();

    // È esattamente ciò che serve costruire: il ledger per-condòmino del già-versato,
    // agganciato alla voce di spesa (o alla gestione, per i fondi generici).
})->group('buco-b', 'riparto');
