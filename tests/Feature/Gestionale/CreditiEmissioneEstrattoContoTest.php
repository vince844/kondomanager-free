<?php

/**
 * 1.11.0-beta.49 — la contabilità dei crediti, parte «emissione ed estratto conto» (decisioni 71, 75 e 76; voci P1, P3, P5
 * e P6 di `docs/piano_esecutivo_beta49.md`).
 *
 * Coperti: la rata di soli crediti, la rata mista e la quota a zero che vanno a giornale con la spesa in DARE e il credito
 * riportato in AVERE (DC2, DC2-b, DC2-c, O10, S5); la rata zero fatta di un debito e di un credito uguali; il credito già
 * usato prima dell'emissione, letto dal giornale (contrasto 1 del critico), anche sui dati della 1.10 e dopo uno storno con
 * l'annullamento dell'emissione (contrasto 2, S2); le rate «emesse» senza righe della 1.9 e della 1.10, che l'amministratore
 * emette dalla pagina del piano (76.1); la rata che non ha niente da portare, che resta in bozza; l'emissione senza il conto
 * «Passate gestioni»; le quote a credito senza il dettaglio del calcolo (piani della 1.7.x), che si fermano da sole, con l'esito
 * che nomina la persona e la rata (76.3, ritoccata l'11/10/2026 sul rilievo LDA-2: le altre quote della rata vanno a giornale);
 * il saldo iniziale dell'estratto conto (DC3) con il saldo dell'unità, i piani senza esercizio e i saldi della 1.7.x e della
 * 1.8.x, che la migrazione del 01/03/2026 ha lasciato con `is_applicato` falso; l'abbinamento riga-quota con due quote
 * gemelle (decisione 25); i totali dell'estratto senza le coppie di storno, a video e nel PDF (75.5); la previsione di cassa
 * netta (`Rata::incassi`), che non conta il credito riportato né un incasso stornato. Al capolinea: lo stato patrimoniale
 * (`StatoPatrimonialePaginaService`) e l'estratto conto in PDF.
 *
 * Cosa NON copre: il conto del prelievo e del rimborso (DC1) e lo storno di una compensazione, se non dove servono a
 * misurare l'emissione (`CreditiStornoTest`); W1 (`StatoDelPianoDopoPassaggioTest`); il comando di riallineamento
 * (`RiallineaCreditiTest`); DC4; i testi della guida e della conferma dell'emissione.
 *
 * Tutte le cifre sono in centesimi. Segno dei saldi: positivo = debito, negativo = credito.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/**
 * Preventivo ordinario da 120000 in `$numeroRate` rate mensili dal 5 gennaio 2026, ripartito a millesimi sulle unità date
 * (['A' => ['ugo', 500], 'B' => ['bice', 500]]); i saldi pregressi [[persona|null, unità, centesimi]]; i conti per emettere.
 */
function ceScenario(array $unita, int $numeroRate, string $metodo, array $saldi = []): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 120000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);

    $banca = ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.10', 'nome' => 'Banca', 'tipo' => 'attivo', 'ruolo' => 'banca', 'categoria' => 'liquidita']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '10.20', 'nome' => 'Crediti verso condomini', 'tipo' => 'attivo', 'ruolo' => 'crediti_condomini', 'categoria' => 'crediti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.10', 'nome' => 'Anticipi', 'tipo' => 'passivo', 'ruolo' => 'anticipi_condomini', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '20.20', 'nome' => 'Gestione rate', 'tipo' => 'passivo', 'ruolo' => 'gestione_rate', 'categoria' => 'debiti']);
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '30.10', 'nome' => 'Passate gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);
    $cassa = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 0]);

    $persone = [];
    $immobili = [];
    foreach ($unita as $nome => [$chi, $millesimi]) {
        $immobili[$nome] = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "CE-{$seq}-{$nome}", 'nome' => "Interno {$nome}", 'interno' => $nome]);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $immobili[$nome]->id, 'valore' => (float) $millesimi, 'created_at' => now(), 'updated_at' => now()]);
        $persone[$chi] ??= tap(Anagrafica::forceCreate(['nome' => ucfirst($chi), 'email' => "ce-{$chi}{$seq}@test.it", 'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => strtoupper(str_pad($chi, 6, 'X')) . str_pad((string) $seq, 10, '0', STR_PAD_LEFT)]), fn ($a) => $a->condomini()->syncWithoutDetaching([$c->id]));
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$chi]->id, 'immobile_id' => $immobili[$nome]->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
            'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ($saldi as [$chi, $nome, $importo]) {
        Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $chi === null ? null : $persone[$chi]->id, 'immobile_id' => $immobili[$nome]->id,
            'gestione_id' => $g->id, 'saldo_iniziale' => $importo, 'origine' => 'manuale', 'is_applicato' => false]);
    }
    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => $numeroRate, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => $metodo, 'applica_saldi' => true,
    ]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $e);

    return compact('c', 'e', 'g', 'immobili', 'persone', 'piano', 'cassa');
}

/**
 * I dati come li lascia un piano generato con la 1.7.x e portato avanti dalle migrazioni: le quote senza `regole_calcolo` (la
 * colonna nasce con la 1.8.0, vuota sulle quote di prima) e i saldi con `is_applicato` falso e senza `piano_rate_id` (la
 * migrazione 2026_03_01_153020_update_saldi_table.php mette `is_applicato = 0` su tutti i saldi di allora; `piano_rate_id`
 * nasce vuoto con la beta.32, e la sua riparazione tocca solo i saldi con `is_applicato` vero).
 */
function ceDati17(array $s): void
{
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->update(['regole_calcolo' => null]);
    DB::table('saldi')->where('condominio_id', $s['c']->id)->update(['is_applicato' => false, 'piano_rate_id' => null]);
}

/** I dati di un piano generato con la 1.8.x: le quote con il dettaglio del calcolo, i saldi come li lascia la stessa migrazione. */
function ceDati18(array $s): void
{
    DB::table('saldi')->where('condominio_id', $s['c']->id)->update(['is_applicato' => false, 'piano_rate_id' => null]);
}

function ceRata(array $s, int $numero): int
{
    return (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', $numero)->value('id');
}

function ceQuota(array $s, string $chi, int $numero): int
{
    return (int) DB::table('rate_quote')->where('rata_id', ceRata($s, $numero))->where('anagrafica_id', $s['persone'][$chi]->id)->value('id');
}

/** L'emissione vera, dalla rotta della pagina del piano. */
function ceEmetti($test, array $s, array $numeri, string $data)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => array_map(fn ($n) => ceRata($s, $n), $numeri), 'data_emissione' => $data, 'invia_notifiche' => false,
    ]);
}

/** Un incasso o una compensazione dal modulo vero: [id della quota => centesimi], negativo per usare un credito. */
function ceIncassa($test, array $s, string $chi, array $righe, string $data): int
{
    $totale = array_sum($righe);
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone'][$chi]->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $data, 'importo_totale' => number_format($totale / 100, 2, '.', ''),
        'descrizione' => 'Prova', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => array_map(fn ($id, $cent) => ['rata_id' => (int) $id, 'importo' => number_format($cent / 100, 2, '.', '')], array_keys($righe), array_values($righe)),
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
}

function ceStorna($test, array $s, int $scritturaId): void
{
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $scritturaId]))->assertSessionHasNoErrors();
}

function ceAnnullaEmissione($test, array $s, int $numero)
{
    return $test->actingAs($test->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', [$s['c'], $s['piano'], ceRata($s, $numero)]));
}

/** Il saldo di un conto per ruolo, dare − avere, sulle scritture vive (con `$chi`, solo le righe di quella persona). */
function ceConto(array $s, string $ruolo, ?string $chi = null): int
{
    $q = DB::table('righe_scritture as r')->join('scritture_contabili as sc', 'sc.id', '=', 'r.scrittura_id')->join('conti_contabili as cc', 'cc.id', '=', 'r.conto_contabile_id')
        ->where('sc.condominio_id', $s['c']->id)->whereNull('sc.deleted_at')->where('cc.ruolo', $ruolo);
    if ($chi !== null) {
        $q->where('r.anagrafica_id', $s['persone'][$chi]->id);
    }

    return (int) $q->sum(DB::raw("CASE WHEN r.tipo_riga = 'dare' THEN r.importo ELSE -r.importo END"));
}

/** Le righe della scrittura di emissione della rata: [ruolo, persona, verso, importo], in ordine. */
function ceRigheEmissione(array $s, int $numero): array
{
    $id = DB::table('rate_quote')->where('rata_id', ceRata($s, $numero))->whereNotNull('scrittura_contabile_id')->value('scrittura_contabile_id');
    if ($id === null) {
        return [];
    }
    $nomi = collect($s['persone'])->mapWithKeys(fn ($a, $k) => [$a->id => $k]);

    return DB::table('righe_scritture as r')->join('conti_contabili as cc', 'cc.id', '=', 'r.conto_contabile_id')->where('r.scrittura_id', $id)->orderBy('r.id')
        ->get(['cc.ruolo', 'r.anagrafica_id', 'r.tipo_riga', 'r.importo'])
        ->map(fn ($r) => [$r->ruolo, $r->anagrafica_id ? $nomi[$r->anagrafica_id] : null, $r->tipo_riga, (int) $r->importo])->all();
}

/** Le props della pagina dell'estratto conto della persona. */
function ceEstratto($test, array $s, string $chi): array
{
    $r = $test->actingAs($test->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone'][$chi]]));
    $r->assertOk();

    return $r->viewData('page')['props'];
}

/** L'estratto conto in PDF: i dati passati alla vista della stampa e il suo testo, reso dalla stessa vista. */
function ceEstrattoPdf($test, array $s, string $chi): array
{
    $dati = null;
    \Illuminate\Support\Facades\View::composer('pdf.gestionale.estratto_conto_anagrafica', function ($v) use (&$dati) {
        $dati ??= $v->getData();
    });
    $r = $test->actingAs($test->user)->get(route('admin.gestionale.anagrafiche.estratto-conto.print', [$s['c'], $s['persone'][$chi]]));
    $r->assertOk();
    expect($r->headers->get('Content-Type'))->toBe('application/pdf');
    $testo = preg_replace('/\s+/u', ' ', strip_tags(view('pdf.gestionale.estratto_conto_anagrafica', $dati)->render()));

    return ['stats' => $dati['stats'], 'timeline' => $dati['timeline'], 'testo' => $testo];
}

/** Il capolinea: lo stato patrimoniale della pagina, per ruolo (attivo dare − avere, passivo avere − dare). */
function ceStatoPatrimoniale(array $s): array
{
    $p = app(\App\Services\Gestionale\StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e'], '2026-12-31');
    $voci = collect($p['situazione']['attivo']['gruppi'])->merge($p['situazione']['passivo']['gruppi'])->flatMap(fn ($g) => $g['voci']);
    $per = fn (string $ruolo) => (int) $voci->where('ruolo', $ruolo)->sum('saldo');

    return ['crediti_condomini' => (int) $p['sintesi']['crediti_condomini'], 'gestione_rate' => $per('gestione_rate'), 'passate_gestioni' => $per('passate_gestioni')];
}

/** Gli incassi attesi della previsione di cassa (TreasuryGuardianService, finestra di 30 giorni da oggi). */
function cePrevisione(array $s): int
{
    return app(\App\Services\Treasury\TreasuryGuardianService::class)->perCondominio($s['c']->id)->incassiAttesiCents;
}

// ─── P3: l'emissione con la decisione 71 (DC2, DC2-b, DC2-c, O10, S5) ─────────────────────────────────────────────────────

it('DC2 — la rata di soli crediti va a giornale con la spesa in dare e il credito in avere, diventa «emessa», e lo stato patrimoniale non mostra più un credito che non c\'è', function () {
    // 120000 in 12 rate da 10000, «prima rata», credito di Ugo −20000: rata 1 = 10000 − 20000 = −10000; rata 2 = 10000.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', -20000]]);
    $r = ceEmetti($this, $s, [1, 2], '2026-02-28');
    $r->assertSessionHasNoErrors();

    expect(DB::table('rate')->whereIn('id', [ceRata($s, 1), ceRata($s, 2)])->orderBy('numero_rata')->pluck('stato')->all())->toBe(['emessa', 'emessa'])
        ->and($r->getSession()->get('esito_emissione'))->toBe(['titolo' => 'Emissione completata', 'testo' => 'Sono state emesse 2 rate.'])
        // Rata 1: spesa 10000 in DARE e credito 20000 in AVERE a nome di Ugo; contropartite AVERE «Gestione rate» 10000 (la spesa)
        // e DARE «Passate gestioni» 20000 (il riporto).
        ->and(ceRigheEmissione($s, 1))->toBe([
            ['crediti_condomini', 'ugo', 'dare', 10000], ['crediti_condomini', 'ugo', 'avere', 20000],
            ['gestione_rate', null, 'avere', 10000], ['passate_gestioni', null, 'dare', 20000],
        ]);

    // Capolinea, lo stato patrimoniale: «Crediti verso condomini» 10000 − 20000 + 10000 (rata 2) = 0 (oggi 10000: il credito di Ugo
    // non è nel giornale e la rata 2 sembra da incassare); «Quote emesse» (Gestione rate) 10000 + 10000 = 20000 (oggi 10000: la
    // spesa della rata 1 manca); «Riporti» (Passate gestioni, passivo avere − dare) −20000.
    expect(ceStatoPatrimoniale($s))->toBe(['crediti_condomini' => 0, 'gestione_rate' => 20000, 'passate_gestioni' => -20000]);

    // L'estratto: saldo iniziale −20000 (il credito riportato, `saldo_usato`), più le due spese da 10000: 0. Due righe d'emissione
    // in DARE e nessuna in AVERE: il credito è già nel saldo iniziale (niente riga fantasma «A copertura Rata n.1»).
    $p = ceEstratto($this, $s, 'ugo');
    expect($p['stats']['saldo_raw'])->toBe(0)
        ->and($p['stats']['saldo_iniziale_raw'])->toBe(-20000)
        ->and(collect($p['timeline'])->where('tipo_movimento', 'emissione_rata')->map(fn ($r) => [$r['dare'], $r['avere']])->values()->all())->toBe([[10000, 0], [10000, 0]]);
});

it('DC2-b — rata mista: anche la quota a credito di Ugo va a giornale, e la gestione rate conta tutta la spesa', function () {
    // A e B da 500 millesimi: 60000 l'anno ciascuna, 5000 a rata. Ugo su A con −20000: rata 1 di Ugo = 5000 − 20000 = −15000.
    $s = ceScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'prima_rata', [['ugo', 'A', -20000]]);
    ceEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();

    expect(DB::table('rate_quote')->where('id', ceQuota($s, 'ugo', 1))->value('scrittura_contabile_id'))->not->toBeNull()
        // Ugo: −20000 + 5000 + 5000 = −10000; Bice: 5000 + 5000 = 10000; Gestione rate: 4 quote × 5000 = 20000 (oggi 15000).
        ->and(ceEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(-10000)
        ->and(ceEstratto($this, $s, 'bice')['stats']['saldo_raw'])->toBe(10000)
        ->and(ceConto($s, 'gestione_rate'))->toBe(-20000)
        // Crediti di Ugo: 5000 − 20000 + 5000 = −10000, come il suo estratto.
        ->and(ceConto($s, 'crediti_condomini', 'ugo'))->toBe(-10000);
});

it('DC2-c — il credito più grande della spesa: tutte e dodici le rate vanno a giornale, e restano 30000 di credito', function () {
    // «Tutte le rate», credito −150000: ogni quota 10000 − 12500 = −2500.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'tutte_rate', [['ugo', 'A', -150000]]);
    $r = ceEmetti($this, $s, range(1, 12), '2026-12-31');

    expect(DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('stato', 'emessa')->count())->toBe(12)
        ->and($r->getSession()->get('esito_emissione')['testo'])->toBe('Sono state emesse 12 rate.')
        // −150000 + 12 × 10000 = −30000; Gestione rate 12 × 10000 = 120000; Crediti di Ugo 120000 − 150000 = −30000.
        ->and(ceEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(-30000)
        ->and(ceConto($s, 'gestione_rate'))->toBe(-120000)
        ->and(ceConto($s, 'crediti_condomini', 'ugo'))->toBe(-30000)
        // Passate gestioni: 12 × 12500 = 150000 in dare.
        ->and(ceConto($s, 'passate_gestioni'))->toBe(150000);
});

it('O10 — l\'estratto conto di chi vende con le quote a credito: emesse le rate 1–4, venduta l\'unità il 1/5 ed emessa la rata 5, Ugo resta a credito di 130274 e Elsa deve 5274', function () {
    // A e B da 500 millesimi: 60000 l'anno ciascuna, 5000 a rata. Credito di Ugo −150000 su dodici rate («tutte le rate»):
    // −12500 a rata, ogni quota di Ugo 5000 − 12500 = −7500. Emesse le rate 1–4 (con le quote di Bice), vendita di A a Elsa con
    // decorrenza 1/5 (il piano è fermo: lo prende il conguaglio), poi emessa la rata 5. È l'O10 del laboratorio
    // (`tests/Lab/Contabilita/OracoloBozzeACreditoTest.php`), che la suite del prodotto non lancia.
    $s = ceScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'tutte_rate', [['ugo', 'A', -150000]]);
    ceEmetti($this, $s, [1, 2, 3, 4], '2026-04-30')->assertSessionHasNoErrors();
    $s['persone']['elsa'] = tap(Anagrafica::forceCreate(['nome' => 'Elsa', 'email' => 'ce-elsa-o10@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'ELSAXXO10O10O10O']),
        fn ($a) => $a->condomini()->syncWithoutDetaching([$s['c']->id]));
    $rigaUgo = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['persone']['ugo']->id)->where('immobile_id', $s['immobili']['A']->id)->value('id');
    $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['immobili']['A']]), [
        'tipo' => 'vendita', 'riga_uscente_id' => $rigaUgo, 'anagrafica_entrante_id' => $s['persone']['elsa']->id, 'decorrenza' => '2026-05-01',
        'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-05-01',
        'estremi_titolo' => 'atto notaio Verdi, rep. 12345', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto',
    ])->assertSessionHasNoErrors()->assertRedirect();
    ceEmetti($this, $s, [5], '2026-05-31')->assertSessionHasNoErrors();

    // Ugo dal 1/1 al 30/4, 120 giorni: 60000 × 120/365 = 19726,03 → 19726; la spesa delle sue quote a giornale è 4 × 5000 =
    // 20000: la coppia gli rende 274 (Elsa +274). Le bozze 5–12 passano a Elsa per la spesa (8 × 5000), le gemelle del pregresso
    // (8 × −12500) restano a Ugo (decisione 25). Ugo: −150000 di credito riportato − 274 + 20000 di spesa a giornale = −130274
    // (oggi −150274: la spesa delle sue quote a credito non va a giornale). Elsa: 274 + 5000 (rata 5) = 5274.
    expect((int) Saldo::whereNotNull('subentro_id')->where('anagrafica_id', $s['persone']['ugo']->id)->sum('saldo_iniziale'))->toBe(-274)
        ->and([ceEstratto($this, $s, 'ugo')['stats']['saldo_raw'], ceEstratto($this, $s, 'elsa')['stats']['saldo_raw']])->toBe([-130274, 5274]);
});

it('S5 — una quota a zero perché il credito è pari alla spesa va a giornale con le sue due righe', function () {
    // «Prima rata», credito di Ugo −10000: rata 1 = 10000 − 10000 = 0, che non è una rata vuota: ha una spesa e un credito.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', -10000]]);
    $r = ceEmetti($this, $s, [1], '2026-01-31');

    expect(DB::table('rate_quote')->where('rata_id', ceRata($s, 1))->value('importo'))->toBe(0)
        ->and(DB::table('rate')->where('id', ceRata($s, 1))->value('stato'))->toBe('emessa')
        ->and($r->getSession()->get('esito_emissione')['testo'])->toBe('È stata emessa 1 rata.')
        ->and(ceRigheEmissione($s, 1))->toBe([['crediti_condomini', 'ugo', 'dare', 10000], ['crediti_condomini', 'ugo', 'avere', 10000],
            ['gestione_rate', null, 'avere', 10000], ['passate_gestioni', null, 'dare', 10000]])
        // −10000 di saldo iniziale + 10000 di spesa = 0.
        ->and(ceEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(0);
});

it('rata zero fatta di un debito e di un credito uguali (la coppia ±548 di un conguaglio): va a giornale per tutti e due, senza contropartite', function () {
    // «Rata zero»: Elsa deve 548, Ugo ha 548 di credito. Rata 0 = Ugo −548 + Elsa 548 = 0; spese 0, riporti −548 + 548 = 0.
    $s = ceScenario(['A' => ['ugo', 500], 'B' => ['elsa', 500]], 12, 'rata_zero', [['ugo', 'A', -548], ['elsa', 'B', 548]]);
    ceEmetti($this, $s, [0], '2026-01-05')->assertSessionHasNoErrors();

    expect(DB::table('rate')->where('id', ceRata($s, 0))->value('stato'))->toBe('emessa')
        // Le due righe sono le uniche: le contropartite si annullano (0 su «Gestione rate», −548 + 548 = 0 su «Passate»).
        ->and(ceRigheEmissione($s, 0))->toEqualCanonicalizing([['crediti_condomini', 'ugo', 'avere', 548], ['crediti_condomini', 'elsa', 'dare', 548]])
        ->and(ceConto($s, 'crediti_condomini', 'elsa'))->toBe(548)
        ->and(ceConto($s, 'crediti_condomini', 'ugo'))->toBe(-548)
        ->and(ceConto($s, 'passate_gestioni'))->toBe(0);
});

it('l\'emissione senza il conto «Passate gestioni» si rifiuta per tutta la richiesta: una quota a credito non esce più dal giornale in silenzio', function () {
    // «Prima rata», −20000: rata 1 = −10000 (porta un riporto), rata 2 = 10000 (no). Il conto «Passate gestioni» non c'è.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', -20000]]);
    ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'passate_gestioni')->delete();
    $r = ceEmetti($this, $s, [1, 2], '2026-02-28');

    // Il riporto della rata 1 non ha una contropartita: l'emissione si ferma, e l'errore annulla anche la rata 2 (stessa
    // transazione). Oggi la rata 2 va a giornale e la rata 1 resta fuori «perché non ha quote da pagare».
    expect($r->getSession()->get('message')['type'])->toBe('error')
        ->and($r->getSession()->get('message')['message'])->toContain('Passate')
        ->and(DB::table('rate')->whereIn('id', [ceRata($s, 1), ceRata($s, 2)])->pluck('stato')->unique()->values()->all())->toBe(['bozza'])
        ->and(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'emissione_rata')->count())->toBe(0);
});

// ─── P1 e P3: il credito già usato prima dell'emissione, letto dal giornale ───────────────────────────────────────────────

it('il credito usato prima dell\'emissione non torna su «Crediti»: la rata zero porta a giornale solo quello che resta', function () {
    // «Rata zero», credito di Ugo −20000: rata 0 = −20000 (spesa 0); rate da 10000. Emesse le rate 1 e 2; Ugo usa 5000 del credito
    // della rata 0 sulla rata 1, con la rata 0 ancora in bozza (il prelievo esce da «Passate gestioni», DC1); poi si emette la
    // rata 0: in avere 20000 − 5000 = 15000.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    ceEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    ceIncassa($this, $s, 'ugo', [ceQuota($s, 'ugo', 0) => -5000, ceQuota($s, 'ugo', 1) => 5000], '2026-03-10');
    ceEmetti($this, $s, [0], '2026-03-15')->assertSessionHasNoErrors();

    expect(ceRigheEmissione($s, 0))->toBe([['crediti_condomini', 'ugo', 'avere', 15000], ['passate_gestioni', null, 'dare', 15000]])
        // Crediti di Ugo: 20000 (rate 1 e 2) − 5000 (rata 1 pagata col credito) − 15000 = 0; Passate: 5000 + 15000 = 20000 in dare.
        ->and(ceConto($s, 'crediti_condomini', 'ugo'))->toBe(0)
        ->and(ceConto($s, 'passate_gestioni'))->toBe(20000)
        // Estratto: −20000 + 20000 + 5000 (prelievo) − 5000 (compensazione) = 0.
        ->and(ceEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(0);
});

it('storno della compensazione dopo l\'emissione, annullamento e nuova emissione: il credito si conta una volta sola', function () {
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    ceEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    $incasso = ceIncassa($this, $s, 'ugo', [ceQuota($s, 'ugo', 0) => -5000, ceQuota($s, 'ugo', 1) => 5000], '2026-03-10');
    ceEmetti($this, $s, [0], '2026-03-15')->assertSessionHasNoErrors();
    ceStorna($this, $s, $incasso);
    // Dopo lo storno (lo specchio della compensazione più la figlia dell'emissione che porta 5000 su «Crediti», decisione 76.3):
    // Crediti di Ugo 20000 (rate 1 e 2) − 5000 (compensazione) − 15000 (emissione della rata 0) + 5000 (specchio) − 5000 (figlia)
    // = 0; Passate 5000 (prelievo) + 15000 (emissione) − 5000 (specchio) + 5000 (figlia) = 20000 in dare.
    expect([ceConto($s, 'crediti_condomini', 'ugo'), ceConto($s, 'passate_gestioni')])->toBe([0, 20000]);

    ceAnnullaEmissione($this, $s, 0)->assertSessionHasNoErrors();
    // L'annullamento cancella l'emissione con la sua figlia: il credito torna tutto fuori dal giornale (la rata 0 è in bozza).
    // Crediti di Ugo 20000 (le rate 1 e 2 da pagare), Passate 5000 − 5000 = 0. Con la rettifica «sul conto di adesso» (disegno A
    // della 0.2) restavano 15000 e 5000.
    expect(DB::table('rate')->where('id', ceRata($s, 0))->value('stato'))->toBe('bozza')
        ->and([ceConto($s, 'crediti_condomini', 'ugo'), ceConto($s, 'passate_gestioni')])->toBe([20000, 0]);

    ceEmetti($this, $s, [0], '2026-03-20')->assertSessionHasNoErrors();
    // Il credito di Ugo (20000) è di nuovo tutto disponibile e va tutto su «Crediti»: 20000 − 20000 = 0; Passate 20000 in dare.
    expect(ceRigheEmissione($s, 0))->toBe([['crediti_condomini', 'ugo', 'avere', 20000], ['passate_gestioni', null, 'dare', 20000]])
        ->and(ceConto($s, 'crediti_condomini', 'ugo'))->toBe(0)
        ->and(ceConto($s, 'passate_gestioni'))->toBe(20000)
        ->and(ceEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(0);
});

it('S2 — dopo lo storno e l\'annullamento il credito si usa tutto con la rata zero in bozza: la nuova emissione non porta niente, e niente resta su «Crediti»', function () {
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    ceEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    $incasso = ceIncassa($this, $s, 'ugo', [ceQuota($s, 'ugo', 0) => -5000, ceQuota($s, 'ugo', 1) => 5000], '2026-03-10');
    ceEmetti($this, $s, [0], '2026-03-15')->assertSessionHasNoErrors();
    ceStorna($this, $s, $incasso);
    ceAnnullaEmissione($this, $s, 0)->assertSessionHasNoErrors();
    // Il credito di Ugo (20000, di nuovo tutto disponibile) paga le rate 1 e 2, con la rata 0 in bozza: esce da «Passate».
    ceIncassa($this, $s, 'ugo', [ceQuota($s, 'ugo', 0) => -20000, ceQuota($s, 'ugo', 1) => 10000, ceQuota($s, 'ugo', 2) => 10000], '2026-03-20');
    $r = ceEmetti($this, $s, [0], '2026-03-25');

    // Uscito da «Passate» il credito intero (5000 − 5000 + 20000 = 20000), la rata 0 non ha niente da portare e resta in bozza.
    // Crediti di Ugo: 20000 − 20000 = 0; Passate 20000 in dare; estratto −20000 + 20000 = 0. Con il disegno A: −5000 e 25000.
    expect(DB::table('rate')->where('id', ceRata($s, 0))->value('stato'))->toBe('bozza')
        ->and($r->getSession()->get('esito_emissione')['titolo'])->toBe('Nessuna rata emessa')
        ->and([ceConto($s, 'crediti_condomini', 'ugo'), ceConto($s, 'passate_gestioni'), ceEstratto($this, $s, 'ugo')['stats']['saldo_raw']])->toBe([0, 20000, 0]);
});

it('dati della 1.10: un credito usato da «Crediti» con la rata zero in bozza (DC1) si sistema all\'emissione, perché il «già usato» si legge dal giornale', function () {
    // Come scrivevano la 1.9 e la 1.10 la compensazione: il prelievo dal credito della rata 0 su «Crediti verso condomini». Poi, con
    // la .49, si emette la rata 0: da «Passate gestioni» non è uscito niente, quindi in avere va tutto il credito (20000).
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    ceEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    ceIncassa($this, $s, 'ugo', [ceQuota($s, 'ugo', 0) => -5000, ceQuota($s, 'ugo', 1) => 5000], '2026-03-10');
    $crediti = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'crediti_condomini')->value('id');
    $passate = (int) DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'passate_gestioni')->value('id');
    DB::table('righe_scritture')->where('conto_contabile_id', $passate)->update(['conto_contabile_id' => $crediti]);
    ceEmetti($this, $s, [0], '2026-03-15')->assertSessionHasNoErrors();

    // Crediti di Ugo: 20000 (rate 1 e 2) + 5000 (prelievo) − 5000 (rata 1 pagata col credito) − 20000 (credito riportato) = 0;
    // Passate gestioni: 20000 in dare, il credito intero. Con il «già usato» letto da `importo_pagato` (−5000) l'emissione
    // porterebbe 15000: Crediti di Ugo 5000, Passate 15000.
    expect(ceRigheEmissione($s, 0))->toBe([['crediti_condomini', 'ugo', 'avere', 20000], ['passate_gestioni', null, 'dare', 20000]])
        ->and(ceConto($s, 'crediti_condomini', 'ugo'))->toBe(0)
        ->and(ceConto($s, 'passate_gestioni'))->toBe(20000)
        ->and(ceEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(0);
});

it('decisione 76.1 — dati della 1.9 e della 1.10: una rata di soli crediti segnata «emessa» senza righe la emette l\'amministratore dalla pagina del piano, con le righe della 71', function () {
    // «Prima rata», −20000; la rata 1 (−10000) segnata «emessa» senza scritture, come la lasciavano la 1.9 e la 1.10. Per la pagina
    // del piano non è emessa (`is_emessa` guarda la scrittura), quindi si spunta e si emette.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', -20000]]);
    DB::table('rate')->where('id', ceRata($s, 1))->update(['stato' => 'emessa', 'data_emissione' => '2026-01-13']);
    $r = ceEmetti($this, $s, [1, 2], '2026-02-28');

    expect($r->getSession()->get('esito_emissione')['testo'])->toBe('Sono state emesse 2 rate.')
        ->and(ceRigheEmissione($s, 1))->toBe([
            ['crediti_condomini', 'ugo', 'dare', 10000], ['crediti_condomini', 'ugo', 'avere', 20000],
            ['gestione_rate', null, 'avere', 10000], ['passate_gestioni', null, 'dare', 20000],
        ])
        ->and(ceEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(0);
});

it('rilievo LDA-3 — una rata il cui credito è già stato usato tutto prima dell\'emissione resta in bozza, con una frase sua: non quella delle rate vuote, che non hanno quote da pagare', function () {
    // Il credito della rata 0 (20000) usato tutto sulle rate 1 e 2 (10000 + 10000) prima dell'emissione della rata 0. La rata 0 ha
    // una quota a credito: «non ha quote da pagare» resta per le rate davvero vuote (decisione 71); per questa l'esito dice che il
    // credito è già stato usato o rimborsato per intero, come la guida del piano rate. «Resta in bozza» è quello che la pagina del
    // piano mostra (la rata non ha scritture).
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    ceEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    ceIncassa($this, $s, 'ugo', [ceQuota($s, 'ugo', 0) => -20000, ceQuota($s, 'ugo', 1) => 10000, ceQuota($s, 'ugo', 2) => 10000], '2026-03-10');
    $r = ceEmetti($this, $s, [0], '2026-03-15');
    $esito = $r->getSession()->get('esito_emissione');

    expect(DB::table('rate')->where('id', ceRata($s, 0))->value('stato'))->toBe('bozza')
        ->and($esito['titolo'])->toBe('Nessuna rata emessa')
        ->and($esito['testo'])->not->toContain('non ha quote da pagare')
        ->and($esito['testo'])->toStartWith('La rata 0 non è stata emessa')
        ->and($esito['testo'])->toContain('usato o rimborsato per intero')
        // «resta in bozza», con la maiuscola o senza: la frase può dirlo in una frase sua o in coda alla stessa.
        ->and(mb_strtolower($esito['testo']))->toContain('resta in bozza');
});

// ─── 76.3: le quote a credito senza il dettaglio del calcolo (piani della 1.7.x) ─────────────────────────────────────────

it('decisione 76.3, ritoccata (rilievo LDA-2) — dati della 1.7: una quota a credito senza il dettaglio del calcolo si ferma da sola, con una frase che nomina la persona e la rata; la rata fatta solo di quella quota non ha niente da portare e resta in bozza; le altre rate si emettono', function () {
    // «Prima rata», credito di Ugo −20000: rata 1 = 10000 − 20000 = −10000, rata 2 = 10000. Le quote senza `regole_calcolo` e i
    // saldi come li lascia la migrazione: la spesa dentro la rata 1 non si separa dal credito, e il programma non la indovina.
    // Ugo è l'unico condòmino: fermata la sua quota, la rata 1 non ha altro da portare a giornale. La rata mista, con le altre
    // quote a giornale, è in `CreditiRevisioneEmissioneTest` (LDA-2).
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', -20000]]);
    ceDati17($s);
    $r = ceEmetti($this, $s, [1, 2], '2026-02-28');
    $esito = $r->getSession()->get('esito_emissione');

    // La rata 1 resta in bozza, senza scrittura; la rata 2 (a debito: tutta spesa, come ha sempre fatto l'emissione) va a giornale.
    // La frase della rata 1 non è quella delle rate vuote: nomina Ugo e la rata, e dice che manca il dettaglio del calcolo.
    expect(DB::table('rate')->whereIn('id', [ceRata($s, 1), ceRata($s, 2)])->orderBy('numero_rata')->pluck('stato')->all())->toBe(['bozza', 'emessa'])
        ->and(DB::table('rate_quote')->where('rata_id', ceRata($s, 1))->whereNotNull('scrittura_contabile_id')->exists())->toBeFalse()
        ->and($esito['titolo'])->toBe('Emissione completata')
        ->and($esito['testo'])->toStartWith('È stata emessa 1 rata.')
        ->and($esito['testo'])->toContain('Ugo')
        ->and($esito['testo'])->toContain('rata 1')
        ->and($esito['testo'])->toContain('dettaglio del calcolo')
        ->and($esito['testo'])->not->toContain('non ha quote da pagare');
});

it('guardia, dati della 1.7 — finché la rata con il credito senza dettaglio resta in bozza, l\'estratto conto tiene il credito riportato', function () {
    // Come sopra. Il credito riportato (−20000) non è in nessuna riga del giornale, perché la rata 1 non va a giornale: resta nel
    // saldo iniziale, come oggi; la rata 2 pesa 10000: −20000 + 10000 = −10000. Togliere il saldo perché «assorbito» dal piano lo
    // farebbe sparire (+10000, un debito che Ugo non ha); portare a giornale la rata 1 lo conterebbe due volte (−20000).
    // La rata 2 si emette da sola, prima: così la guardia non dipende da come si ferma l'emissione (la sola quota, decisione 76.3
    // ritoccata l'11/10/2026; qui la rata 1 è fatta solo di quella quota e resta in bozza).
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', -20000]]);
    ceDati17($s);
    ceEmetti($this, $s, [2], '2026-02-28')->assertSessionHasNoErrors();
    ceEmetti($this, $s, [1], '2026-02-28');

    $p = ceEstratto($this, $s, 'ugo');
    expect([$p['stats']['saldo_iniziale_raw'], $p['stats']['saldo_raw']])->toBe([-20000, -10000]);
});

it('dati della 1.7: un debito riportato dentro una quota senza dettaglio non si conta due volte nell\'estratto conto', function () {
    // «Prima rata», saldo di Ugo +20000: rata 1 = 10000 + 20000 = 30000, senza `regole_calcolo`; saldi come li lascia la migrazione.
    // Emessa la rata 1, la sua riga porta l'importo intero (30000, il riporto è dentro): il saldo non si somma una seconda volta.
    // Ugo deve 30000; pagati 30000: 0. Oggi 20000 + 30000 = 50000, e 20000 dopo l'incasso.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', 20000]]);
    ceDati17($s);
    ceEmetti($this, $s, [1], '2026-01-31')->assertSessionHasNoErrors();
    $dopoEmissione = ceEstratto($this, $s, 'ugo')['stats']['saldo_raw'];
    ceIncassa($this, $s, 'ugo', [ceQuota($s, 'ugo', 1) => 30000], '2026-02-10');

    expect([$dopoEmissione, ceEstratto($this, $s, 'ugo')['stats']['saldo_raw']])->toBe([30000, 0]);
});

it('guardia, dati della 1.7 — prima dell\'emissione l\'estratto conto mostra il debito riportato', function () {
    // Come sopra, nessuna rata emessa: il debito riportato (20000) non è in nessuna riga, e il saldo iniziale lo dice, come oggi.
    // Togliere il saldo perché «assorbito» dal piano lo nasconderebbe (0) finché la rata 1 non va a giornale.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', 20000]]);
    ceDati17($s);

    $p = ceEstratto($this, $s, 'ugo');
    expect([$p['stats']['saldo_iniziale_raw'], $p['stats']['saldo_raw']])->toBe([20000, 20000]);
});

// ─── P5: DC3, il saldo iniziale dell'estratto conto ───────────────────────────────────────────────────────────────────────

it('DC3 — il saldo pregresso dell\'unità entra nell\'estratto conto di chi lo paga, prima e dopo l\'emissione', function () {
    // Saldo dell'unità (senza persona, art. 63) +20000; Ugo unico titolare: rata 1 = 10000 + 20000 = 30000 (`saldo_usato` 20000).
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [[null, 'A', 20000]]);
    $estratto = fn () => ceEstratto($this, $s, 'ugo')['stats'];

    // Prima dell'emissione 20000 di pregresso; emessa la rata 1, 20000 + 10000 = 30000; pagati 30000: 0. Oggi il pregresso
    // dell'unità non entra (Ugo non ha saldi a suo nome): 0, 10000, −20000.
    $prima = $estratto()['saldo_raw'];
    ceEmetti($this, $s, [1], '2026-01-31')->assertSessionHasNoErrors();
    $dopoEmissione = $estratto()['saldo_raw'];
    ceIncassa($this, $s, 'ugo', [ceQuota($s, 'ugo', 1) => 30000], '2026-02-10');
    expect([$prima, $dopoEmissione, $estratto()['saldo_raw'], $estratto()['saldo_iniziale_raw']])->toBe([20000, 30000, 0, 20000]);
});

it('DC3 — un piano senza esercizio_id: l\'esercizio si deduce, e il pregresso resta nell\'estratto conto', function (?string $diChi) {
    // Saldo +20000 (di Ugo, o dell'unità); rata 1 = 30000 emessa e pagata: saldo iniziale 20000, saldo finale 0.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [[$diChi, 'A', 20000]]);
    DB::table('piani_rate')->where('id', $s['piano']->id)->update(['esercizio_id' => null]);
    ceEmetti($this, $s, [1], '2026-01-31')->assertSessionHasNoErrors();
    ceIncassa($this, $s, 'ugo', [ceQuota($s, 'ugo', 1) => 30000], '2026-02-10');

    $p = ceEstratto($this, $s, 'ugo');
    expect([$p['stats']['saldo_iniziale_raw'], $p['stats']['saldo_raw']])->toBe([20000, 0]);
})->with(['saldo di Ugo' => 'ugo', 'saldo dell\'unità' => null]);

it('S1, guardia — dati della 1.8: i saldi assorbiti dal piano con `is_applicato` falso dopo la migrazione non si contano due volte', function () {
    // Saldo di Ugo +20000, «prima rata»: rata 1 = 10000 + 20000 = 30000, con `saldo_usato` 20000 nel dettaglio (come lo scriveva
    // la 1.8.0); il saldo con `is_applicato` 0 e senza `piano_rate_id`, come lo lascia la migrazione 2026_03_01_153020.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', 20000]]);
    ceDati18($s);
    ceEmetti($this, $s, [1], '2026-01-31')->assertSessionHasNoErrors();
    ceIncassa($this, $s, 'ugo', [ceQuota($s, 'ugo', 1) => 30000], '2026-02-10');

    // 20000 di pregresso + 10000 di spesa − 30000 pagati = 0. Sommare i saldi con `is_applicato` falso e anche il `saldo_usato`
    // delle quote darebbe 40000 di saldo iniziale e 20000 di saldo.
    $p = ceEstratto($this, $s, 'ugo');
    expect([$p['stats']['saldo_iniziale_raw'], $p['stats']['saldo_raw']])->toBe([20000, 0]);
});

// ─── P5: l'abbinamento riga-quota con due quote gemelle (decisione 25) ───────────────────────────────────────────────────

it('due quote della stessa persona sulla stessa rata e unità (decisione 25): la riga della spesa va alla quota che l\'ha scritta', function () {
    // «Prima rata», credito di Ugo −25000: rata 1 = 10000 − 25000 = −15000. La quota si divide come dopo una vendita e una ricompra:
    // una gemella del pregresso più vecchia G (−5000: spesa 0, `saldo_usato` −5000) e la quota X (−10000: spesa 10000,
    // `saldo_usato` −20000). Stesse cifre in tutto: spesa 10000, credito 25000.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', -25000]]);
    $x = (array) DB::table('rate_quote')->where('id', ceQuota($s, 'ugo', 1))->first();
    DB::table('rate_quote')->where('id', $x['id'])->delete();
    unset($x['id']);
    $regole = fn (int $spesa, int $saldo) => json_encode(['importi' => ['quota_pura_gestione' => $spesa, 'saldo_usato' => $saldo, 'totale_calcolato' => $spesa + $saldo]]);
    DB::table('rate_quote')->insert(array_replace($x, ['importo' => -5000, 'regole_calcolo' => $regole(0, -5000)]));
    DB::table('rate_quote')->insert(array_replace($x, ['importo' => -10000, 'regole_calcolo' => $regole(10000, -20000)]));
    ceEmetti($this, $s, [1], '2026-01-31')->assertSessionHasNoErrors();

    // Saldo iniziale −20000 − 5000 = −25000; la riga in DARE da 10000 è la spesa di X e pesa 10000: −15000. Abbinata per importo
    // (nessuna quota vale 10000) finiva sulla prima non usata, G, e pesava la sua spesa, 0: −25000.
    $p = ceEstratto($this, $s, 'ugo');
    expect([$p['stats']['saldo_iniziale_raw'], $p['stats']['saldo_raw']])->toBe([-25000, -15000]);
});

// ─── P5: i totali dell'estratto conto senza le coppie di storno (decisione 75.5), a video e nel PDF ───────────────────────

it('un incasso stornato non gonfia «Totale addebiti» e «Totale versato», a video e nel PDF, e la sua riga porta il bollino «stornata»', function () {
    // Rata 1 da 10000 emessa, incasso da 10000, storno. Oggi: addebiti 10000 + 10000 (la rettifica) = 20000, versato 10000.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    ceEmetti($this, $s, [1], '2026-01-31')->assertSessionHasNoErrors();
    $incasso = ceIncassa($this, $s, 'ugo', [ceQuota($s, 'ugo', 1) => 10000], '2026-02-10');
    ceStorna($this, $s, $incasso);

    // L'incasso e la sua rettifica restano nell'elenco (spiegano il saldo) ma non nei totali: addebiti 10000, versato 0, saldo
    // 10000 (saldo iniziale 0 + 10000 − 0).
    $p = ceEstratto($this, $s, 'ugo');
    $rigaIncasso = collect($p['timeline'])->firstWhere('tipo_movimento', 'incasso_rata');
    expect([$p['stats']['totale_addebiti'], $p['stats']['totale_versamenti'], $p['stats']['saldo_raw']])->toBe(['€ 100,00', '€ 0,00', 10000])
        ->and(collect($p['timeline'])->count())->toBe(3)
        ->and($rigaIncasso['dettagli'][0]['status'])->toBe('stornata');

    // Capolinea, il PDF: gli stessi totali, stampati nei due riquadri (in testa e in fondo).
    $pdf = ceEstrattoPdf($this, $s, 'ugo');
    expect([$pdf['stats']['totale_addebiti'], $pdf['stats']['totale_versamenti']])->toBe(['€ 100,00', '€ 0,00'])
        ->and(substr_count($pdf['testo'], 'Totale addebiti € 100,00'))->toBe(2)
        ->and(substr_count($pdf['testo'], 'Totale versato € 0,00'))->toBe(2)
        ->and($pdf['testo'])->not->toContain('€ 200,00');
});

it('un rimborso stornato non gonfia «Totale addebiti» e «Totale versato»', function () {
    // «Rata zero», credito di Ugo −5000: rata 0 = −5000; rata 1 da 10000 emessa e pagata (la cassa ha 10000). Rimborso dei 5000
    // della rata 0, poi storno del rimborso.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -5000]]);
    ceEmetti($this, $s, [1], '2026-01-31')->assertSessionHasNoErrors();
    ceIncassa($this, $s, 'ugo', [ceQuota($s, 'ugo', 1) => 10000], '2026-02-10');
    $this->actingAs($this->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['persone']['ugo']]),
        ['rata_quote_id' => ceQuota($s, 'ugo', 0), 'cassa_id' => $s['cassa']->id, 'data_rimborso' => '2026-03-05', 'importo' => '50,00'])->assertSessionHasNoErrors();
    $rimborso = (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'rimborso_condomino')->value('id');
    ceStorna($this, $s, $rimborso);

    // Saldo: −5000 + 10000 − 10000 + 5000 (rimborso) − 5000 (storno) = −5000. Totali senza la coppia: addebiti 10000 (la rata),
    // versato 10000 (l'incasso). Oggi: 15000 e 15000.
    $p = ceEstratto($this, $s, 'ugo');
    expect([$p['stats']['totale_addebiti'], $p['stats']['totale_versamenti'], $p['stats']['saldo_raw']])->toBe(['€ 100,00', '€ 100,00', -5000]);
});

// ─── P6: la previsione di cassa netta (`Rata::incassi`) ───────────────────────────────────────────────────────────────────

it('la previsione di cassa torna a contare come atteso un incasso stornato', function () {
    // Rata 1 da 10000 emessa: attesi 10000; incassata: 0; stornato l'incasso: di nuovo 10000 (oggi resta 0, perché la riga in
    // avere dell'incasso annullato conta ancora e la rettifica in dare no).
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    ceEmetti($this, $s, [1], '2026-01-05')->assertSessionHasNoErrors();
    $prima = cePrevisione($s);
    $incasso = ceIncassa($this, $s, 'ugo', [ceQuota($s, 'ugo', 1) => 10000], '2026-01-10');
    $pagata = cePrevisione($s);
    ceStorna($this, $s, $incasso);

    expect([$prima, $pagata, cePrevisione($s)])->toBe([10000, 0, 10000]);
});

it('la previsione di cassa non conta come incassato il credito riportato scritto all\'emissione, né un incasso stornato sulla stessa rata', function () {
    // A e B da 500 (5000 a rata). Ugo con −7000: rata 1 di Ugo = 5000 − 7000 = −2000; Bice 5000; importo della rata 3000.
    // Bice paga 5000 (la rata esce dalla previsione: 3000 − 5000 < 0), poi l'incasso si storna. Attesi: 3000 (importo della rata
    // meno gli incassi netti: 5000 − 5000 = 0; l'AVERE di 7000 che l'emissione scrive per il credito di Ugo non è un incasso).
    $s = ceScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'prima_rata', [['ugo', 'A', -7000]]);
    ceEmetti($this, $s, [1], '2026-01-05')->assertSessionHasNoErrors();
    $incasso = ceIncassa($this, $s, 'bice', [ceQuota($s, 'bice', 1) => 5000], '2026-01-10');
    ceStorna($this, $s, $incasso);

    // Con il solo filtro sull'emissione: 0 (l'incasso annullato conta ancora: 3000 − 5000 < 0). Con la sola forma netta, senza il
    // filtro: 6000 (le righe d'emissione con la rata entrano nel netto: 7000 − 5000 − 5000 = −3000, e 3000 + 3000 = 6000).
    expect(cePrevisione($s))->toBe(3000);
});

// ─── Mutazioni sopravvissute (fase finale della 1.11.0-beta.49): i test che le prendono ────────────────────────────────────

/**
 * Le quote gemelle di Ugo sulla rata 1 (decisione 25: stessa persona, rata e unità), come dopo una vendita e una ricompra, al
 * posto della quota che il generatore ha scritto: [importo, spesa, saldo usato, stato] per quota, nell'ordine di creazione.
 * Restituisce gli id, nello stesso ordine.
 *
 * Lo stato conta: l'emissione legge le quote della rata senza un ordine esplicito (`Rata::rateQuote`), e il database le dà
 * nell'ordine dell'indice `(rata_id, stato)`, poi per id. Una quota a credito usata diventa «pagata» (`ricalcolaStato`, −5000 ≥
 * −20000) e passa dopo una quota a debito «da_pagare»; una mai usata («credito») passa prima.
 */
function ceGemelleRata1(array $s, array $quote): array
{
    $x = (array) DB::table('rate_quote')->where('id', ceQuota($s, 'ugo', 1))->first();
    DB::table('rate_quote')->where('id', $x['id'])->delete();
    unset($x['id']);
    $ids = [];
    foreach ($quote as [$importo, $spesa, $saldo, $stato]) {
        $ids[] = DB::table('rate_quote')->insertGetId(array_replace($x, ['importo' => $importo, 'importo_pagato' => 0, 'stato' => $stato,
            'regole_calcolo' => json_encode(['importi' => ['quota_pura_gestione' => $spesa, 'saldo_usato' => $saldo, 'totale_calcolato' => $spesa + $saldo]])]));
    }

    return $ids;
}

/** Le righe della scrittura d'emissione della rata 1 per conto e verso, sommate: l'ordine delle righe fra le gemelle non conta. */
function ceTotaliEmissione1(array $s): array
{
    return collect(ceRigheEmissione($s, 1))->groupBy(fn ($r) => $r[0] . '|' . $r[2])->map(fn ($g) => (int) $g->sum(3))->sortKeys()->all();
}

it('gemelle a credito (decisione 25) — il credito già uscito da «Passate gestioni» si consuma una volta sola, una quota dopo l\'altra', function () {
    // «Prima rata», credito di Ugo −25000: rata 1 = 10000 − 25000 = −15000, divisa in G −5000 (spesa 0, saldo usato −5000), creata
    // prima, e X −10000 (spesa 10000, saldo usato −20000). Emessa la rata 2 (10000), Ugo usa i 5000 di G sulla rata 2 con la rata 1
    // in bozza: DARE «Passate gestioni» 5000 a nome di Ugo sulla rata 1. Poi si emette la rata 1.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', -25000]]);
    [$g] = ceGemelleRata1($s, [[-5000, 0, -5000, 'credito'], [-10000, 10000, -20000, 'credito']]);
    ceEmetti($this, $s, [2], '2026-02-28')->assertSessionHasNoErrors();
    ceIncassa($this, $s, 'ugo', [$g => -5000, ceQuota($s, 'ugo', 2) => 5000], '2026-03-10');
    ceEmetti($this, $s, [1], '2026-03-15')->assertSessionHasNoErrors();

    // A mano. Credito delle due gemelle 25000, già uscito 5000: a giornale ne vanno 20000, quale che sia la gemella che consuma i
    // 5000 (dopo l'uso G è «pagata», e l'emissione legge prima X: X porta 15000, G 5000). Spesa di X 10000 in DARE. Contropartite:
    // AVERE «Gestione rate» 10000, DARE «Passate» 20000. «Crediti» di Ugo: 10000 (rata 2) − 5000 (compensazione) + 10000 − 20000 =
    // −5000; «Passate»: 5000 + 20000 = 25000, il credito intero. Se ogni gemella sottraesse i 5000 per conto suo (senza consumarli):
    // 15000 in AVERE, «Crediti» 0 e «Passate» 20000, 5000 di credito persi.
    expect(ceTotaliEmissione1($s))->toBe([
        'crediti_condomini|avere' => 20000, 'crediti_condomini|dare' => 10000, 'gestione_rate|avere' => 10000, 'passate_gestioni|dare' => 20000,
    ])
        ->and(ceConto($s, 'crediti_condomini', 'ugo'))->toBe(-5000)
        ->and(ceConto($s, 'passate_gestioni'))->toBe(25000)
        // Estratto: −25000 + 10000 (rata 2) + 5000 (prelievo) − 5000 (compensazione) + 10000 (spesa della rata 1) = −5000.
        ->and(ceEstratto($this, $s, 'ugo')['stats']['saldo_raw'])->toBe(-5000);
});

it('gemelle con una quota a debito in mezzo — una quota a debito non consuma il credito già uscito: quello che resta va alla gemella a credito dopo di lei', function () {
    // «Prima rata», credito di Ugo −10000: rata 1 = 10000 − 10000 = 0, divisa in tre quote della stessa persona e unità, create
    // in quest'ordine: G2 −4000 (spesa 0, saldo usato −4000), D 5000 (spesa 10000, saldo usato −5000), G1 −1000 (spesa 0, saldo
    // usato −1000). Emessa la rata 2 (10000), Ugo usa 4000 di credito sulla rata 2 con la rata 1 in bozza: l'incasso li prende
    // tutti da G2, la prima quota a credito, che diventa «pagata»; DARE «Passate» 4000. L'emissione legge le quote per stato (vedi
    // `ceGemelleRata1`): G1 («credito», mai usata), poi D («da_pagare»), poi G2 («pagata»): una quota a debito in mezzo.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', -10000]]);
    [$g2, , $g1] = ceGemelleRata1($s, [[-4000, 0, -4000, 'credito'], [5000, 10000, -5000, 'da_pagare'], [-1000, 0, -1000, 'credito']]);
    ceEmetti($this, $s, [2], '2026-02-28')->assertSessionHasNoErrors();
    ceIncassa($this, $s, 'ugo', [$g2 => -4000, ceQuota($s, 'ugo', 2) => 4000], '2026-03-10');
    expect([DB::table('rate_quote')->where('id', $g1)->value('stato'), (int) DB::table('rate_quote')->where('id', $g2)->value('importo_pagato')])->toBe(['credito', -4000]);
    ceEmetti($this, $s, [1], '2026-03-15')->assertSessionHasNoErrors();

    // A mano. Già uscito 4000. G1: credito 1000, ne consuma 1000 → niente righe; restano 3000. D (a debito): DARE 5000, il suo
    // credito è già dentro l'importo e non consuma niente. G2: credito 4000 − 3000 = AVERE 1000. «Gestione rate» AVERE 10000 (la
    // spesa di D); «Passate» DARE 10000 − 5000 + 1000 = 6000. «Passate» in tutto: 4000 + 6000 = 10000, il credito intero. Se D
    // consumasse i 3000 rimasti, G2 porterebbe AVERE 4000 e «Passate» arriverebbe a 13000: 3000 di credito contati due volte.
    expect(DB::table('rate_quote')->where('id', $g2)->value('stato'))->toBe('pagata')
        ->and(ceTotaliEmissione1($s))->toBe([
            'crediti_condomini|avere' => 1000, 'crediti_condomini|dare' => 5000, 'gestione_rate|avere' => 10000, 'passate_gestioni|dare' => 6000,
        ])
        ->and(ceConto($s, 'passate_gestioni'))->toBe(10000)
        // «Crediti» di Ugo: 10000 (rata 2) − 4000 + 5000 − 1000 = 10000: deve la rata 2, la rata 1 è pagata dal credito.
        ->and(ceConto($s, 'crediti_condomini', 'ugo'))->toBe(10000);
});

it('gemelle con la quota a debito creata prima (vendita e ricompra): il credito già uscito si legge dal giornale anche se la prima quota della persona è a debito', function () {
    // «Prima rata», credito di Ugo −5000: rata 1 = 10000 − 5000 = 5000. Divisa come dopo una vendita e una ricompra: D 10000 (spesa
    // 10000, saldo usato 0), la quota tornata a Ugo, creata prima; G −5000 (spesa 0, saldo usato −5000), la gemella del pregresso.
    // Emessa la rata 2, Ugo usa 3000 del credito di G sulla rata 2 con la rata 1 in bozza: DARE «Passate» 3000.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', -5000]]);
    [, $g] = ceGemelleRata1($s, [[10000, 10000, 0, 'da_pagare'], [-5000, 0, -5000, 'credito']]);
    ceEmetti($this, $s, [2], '2026-02-28')->assertSessionHasNoErrors();
    ceIncassa($this, $s, 'ugo', [$g => -3000, ceQuota($s, 'ugo', 2) => 3000], '2026-03-10');
    ceEmetti($this, $s, [1], '2026-03-15')->assertSessionHasNoErrors();

    // A mano. D: DARE 10000. G: credito 5000 − 3000 già uscito = AVERE 2000. «Gestione rate» AVERE 10000, «Passate» DARE 2000.
    // «Crediti» di Ugo: 10000 − 3000 + 10000 − 2000 = 15000 (deve 20000 di rate, ha 5000 di credito); «Passate»: 3000 + 2000 =
    // 5000, il credito intero. Oggi `scritturaDiEmissione()` legge il «già uscito» della chiave dalla prima quota che incontra —
    // D, perché G dopo l'uso è «pagata» e l'indice (rata_id, stato) la mette dopo «da_pagare» — e `creditoUscitoDaPassate()` di una
    // quota a debito vale 0: G porta tutti i 5000, «Crediti» 12000 e «Passate» 8000, più del credito che Ugo ha mai avuto.
    expect(DB::table('rate_quote')->where('id', $g)->value('stato'))->toBe('pagata')
        ->and(ceTotaliEmissione1($s))->toBe([
            'crediti_condomini|avere' => 2000, 'crediti_condomini|dare' => 10000, 'gestione_rate|avere' => 10000, 'passate_gestioni|dare' => 2000,
        ])
        ->and(ceConto($s, 'crediti_condomini', 'ugo'))->toBe(15000)
        ->and(ceConto($s, 'passate_gestioni'))->toBe(5000);
});

it('dati della 1.8 — un saldo scritto dopo la generazione del piano non è nelle sue quote: il saldo iniziale dell\'estratto lo conta', function () {
    // Saldo di Ugo +20000, «prima rata»: rata 1 = 30000 (`saldo_usato` 20000); i saldi come li lascia la migrazione del 01/03/2026
    // (`is_applicato` falso, senza piano). Il giorno dopo la generazione si aggiunge un altro saldo di Ugo, +5000, stessa unità,
    // gestione ed esercizio, senza piano: il piano non l'ha assorbito, perché è nato dopo le sue quote.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', 20000]]);
    ceDati18($s);
    $this->travel(1)->days();
    Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['immobili']['A']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 5000, 'origine' => 'manuale', 'is_applicato' => false]);

    // Saldo iniziale: 20000 (il riporto nelle quote) + 5000 (il saldo libero) = 25000; niente emesso, saldo 25000. Se ogni saldo
    // senza piano dell'esercizio valesse come assorbito, i 5000 sparirebbero: 20000.
    $p = ceEstratto($this, $s, 'ugo');
    expect([$p['stats']['saldo_iniziale_raw'], $p['stats']['saldo_raw']])->toBe([25000, 25000]);
});

it('decisione 76.3 sui dati della 1.10 — la rata con il credito senza dettaglio che la 1.10 aveva segnato «emessa» senza righe, fermata dall\'emissione, torna in bozza', function () {
    // «Prima rata», credito di Ugo −20000: rata 1 = −10000, quote senza `regole_calcolo` (1.7.x). La 1.10 l'aveva segnata «emessa»
    // senza scritture. L'emissione nuova la ferma (76.3) e, come ogni rata che non va a giornale (decisione 34), la riporta in bozza:
    // «emessa» vuol dire a giornale.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', -20000]]);
    ceDati17($s);
    DB::table('rate')->where('id', ceRata($s, 1))->update(['stato' => 'emessa', 'data_emissione' => '2026-01-13']);
    $r = ceEmetti($this, $s, [1], '2026-02-28');

    expect(DB::table('rate')->where('id', ceRata($s, 1))->value('stato'))->toBe('bozza')
        ->and(DB::table('rate_quote')->where('rata_id', ceRata($s, 1))->whereNotNull('scrittura_contabile_id')->exists())->toBeFalse()
        ->and($r->getSession()->get('esito_emissione')['titolo'])->toBe('Nessuna rata emessa')
        ->and($r->getSession()->get('esito_emissione')['testo'])->toContain('dettaglio del calcolo');
});

it('dati della 1.8 — il saldo libero di un\'altra gestione dello stesso esercizio non è nelle quote del piano ordinario: il saldo iniziale lo conta', function () {
    // Saldo di Ugo +20000 nella gestione ordinaria, assorbito dalla rata 1 (`saldo_usato` 20000). Un saldo di Ugo di +3000 nella
    // gestione straordinaria dello stesso esercizio, nato prima delle quote, che il piano ordinario non prende (un piano prende i
    // saldi della sua gestione). I saldi come li lascia la migrazione del 01/03/2026: `is_applicato` falso, senza piano.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', 20000]]);
    $straordinaria = Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Facciata 2026', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    $prima = DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->min('created_at');
    $id = Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['immobili']['A']->id,
        'gestione_id' => $straordinaria->id, 'saldo_iniziale' => 3000, 'origine' => 'manuale', 'is_applicato' => false])->id;
    DB::table('saldi')->where('id', $id)->update(['created_at' => \Illuminate\Support\Carbon::parse($prima)->subDay()]);
    ceDati18($s);

    // Saldo iniziale: 20000 (il riporto nelle quote del piano ordinario) + 3000 (il saldo libero della straordinaria) = 23000. Se
    // il saldo dell'altra gestione valesse come assorbito, sparirebbe: 20000.
    $p = ceEstratto($this, $s, 'ugo');
    expect([$p['stats']['saldo_iniziale_raw'], $p['stats']['saldo_raw']])->toBe([23000, 23000]);
});

it('dati della 1.8 — un piano che non ha applicato i saldi: il saldo nato prima delle sue quote resta libero, perché le quote non portano riporto', function () {
    // «Prima rata», nessun saldo alla generazione: le quote hanno il dettaglio con `saldo_usato` 0. Poi un saldo di Ugo di +20000,
    // stessa unità, gestione ed esercizio, datato prima delle quote (come i saldi della 1.8 che il piano non ha applicato), e i saldi
    // come li lascia la migrazione. Il piano non l'ha assorbito: le sue quote non portano riporto.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata');
    $prima = DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->min('created_at');
    $id = Saldo::create(['esercizio_id' => $s['e']->id, 'condominio_id' => $s['c']->id, 'anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['immobili']['A']->id,
        'gestione_id' => $s['g']->id, 'saldo_iniziale' => 20000, 'origine' => 'manuale', 'is_applicato' => false])->id;
    DB::table('saldi')->where('id', $id)->update(['created_at' => \Illuminate\Support\Carbon::parse($prima)->subDay()]);
    ceDati18($s);

    // Saldo iniziale 20000, saldo 20000 (niente emesso). Se bastasse «nato prima delle quote» il saldo sparirebbe: 0.
    $p = ceEstratto($this, $s, 'ugo');
    expect([$p['stats']['saldo_iniziale_raw'], $p['stats']['saldo_raw']])->toBe([20000, 20000]);
});

it('dati della 1.7 — una riga d\'emissione in AVERE per il credito di una quota senza dettaglio resta nell\'estratto e riduce «Totale addebiti», non diventa un versamento', function () {
    // «Prima rata», credito di Ugo −20000: rata 1 = −10000, quote senza `regole_calcolo` (1.7.x), saldi come li lascia la
    // migrazione. Il giornale porta la quota della rata 1 scritta per intero come credito (AVERE «Crediti» 10000 a nome di Ugo,
    // DARE «Passate» 10000): la forma che l'estratto dichiara di tenere per una quota senza dettaglio, dove il riporto non è nel
    // saldo iniziale. Poi la rata 2 (10000) emessa come sempre.
    $s = ceScenario(['A' => ['ugo', 1000]], 12, 'prima_rata', [['ugo', 'A', -20000]]);
    ceDati17($s);
    $conti = DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->pluck('id', 'ruolo');
    $em = \App\Models\Gestionale\ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['g']->id,
        'data_registrazione' => '2026-01-31', 'data_competenza' => '2026-01-31', 'causale' => 'Emissione Rata 1', 'tipo_movimento' => 'emissione_rata', 'stato' => 'registrata']);
    $em->righe()->create(['conto_contabile_id' => $conti['crediti_condomini'], 'anagrafica_id' => $s['persone']['ugo']->id, 'immobile_id' => $s['immobili']['A']->id,
        'rata_id' => ceRata($s, 1), 'tipo_riga' => 'avere', 'importo' => 10000, 'note' => 'Quota a credito']);
    $em->righe()->create(['conto_contabile_id' => $conti['passate_gestioni'], 'tipo_riga' => 'dare', 'importo' => 10000, 'note' => 'Riporto']);
    DB::table('rate_quote')->where('id', ceQuota($s, 'ugo', 1))->update(['scrittura_contabile_id' => $em->id]);
    DB::table('rate')->where('id', ceRata($s, 1))->update(['stato' => 'emessa']);
    ceEmetti($this, $s, [2], '2026-02-28')->assertSessionHasNoErrors();

    // Saldo iniziale 0: il saldo di −20000 è dentro la prima rata, ormai a giornale (la sua riga pesa per intero). Righe: AVERE
    // 10000 (rata 1, «Credito riportato…») e DARE 10000 (rata 2): saldo 0, quanto Ugo ha davvero (−20000 + 10000 + 10000).
    // Totali: addebiti 10000 − 10000 = 0 (il credito riportato riduce gli addebiti della sua rata), versato 0: 0 + 0 − 0 = 0.
    $p = ceEstratto($this, $s, 'ugo');
    expect([$p['stats']['saldo_iniziale_raw'], $p['stats']['saldo_raw']])->toBe([0, 0])
        ->and([$p['stats']['totale_addebiti'], $p['stats']['totale_versamenti']])->toBe(['€ 0,00', '€ 0,00'])
        ->and(collect($p['timeline'])->where('tipo_movimento', 'emissione_rata')->map(fn ($r) => [$r['dare'], $r['avere']])->values()->all())->toBe([[0, 10000], [10000, 0]]);
});
