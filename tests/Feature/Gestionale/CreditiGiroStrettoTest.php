<?php

/**
 * 1.11.0-beta.49, la contabilità dei crediti — il giro stretto sulle correzioni della Fase 1-bis (`docs/piano_esecutivo_beta49.md`,
 * «Giro stretto sulle correzioni (11/10/2026)»; decisioni 71, 75 e 76 del registro, con il ritocco della 76.3 dell'11/10/2026: si
 * ferma la sola quota della 1.7 senza il dettaglio del calcolo).
 *
 * - G1, LT-1 e GS-1, lo stesso difetto trovato da tutte e tre le lenti: lo storno di un rimborso, o di un incasso, che ha usato il
 *   credito di una quota ferma della 1.7 la portava a giornale con una scrittura figlia dell'emissione della sua rata, indovinando
 *   spesa 0 e tutto l'importo come riporto: proprio la divisione che la 76.3 vieta di indovinare. Il credito si contava due volte
 *   nell'estratto (o spariva, con la prima rata a giornale) e la voce spariva dalla card. La quota resta ferma anche dopo lo storno:
 *   basta lo specchio, il credito torna su «Passate gestioni», come la tengono l'emissione e il comando.
 * - LT-2: senza il conto «Fondo Passate Gestioni» il rifiuto diceva di ricrearlo «dal piano dei conti», che nel programma è il
 *   preventivo (ci nasce una voce di spesa, non il conto di sistema), e chiamava il conto con due nomi.
 * - LT-3 e G3: per una quota a zero della 1.7 l'esito dell'emissione diceva «il credito resta a Ugo, da usare o rimborsare», e il
 *   motivo della card «con il suo credito», ma non c'è niente da usare né da rimborsare; la strada non nominava i rimborsi.
 * - LT-5 e G2: il rimborso di una quota ferma in una rata emessa scriveva nella nota «con la sua rata non ancora a giornale».
 * - LT-6: i motivi della card in sola lettura non dicevano la strada, anche dove c'è.
 * - LT-8: «senza quella quota» detto di due quote.
 *
 * Cosa NON copre: i testi delle pagine Vue e delle guide (LT-4, LT-7, e le parti di LT-2, LT-3, LT-5, LT-6 e LT-8 che stanno nei
 * template), che non hanno un test PHP; G4 (riallineamento e nuova compensazione sulla stessa quota in contemporanea), che su
 * SQLite non si riproduce.
 *
 * I DATI DELLA 1.7: come in `CreditiRevisioneEmissioneTest` (`lda49Dati17`), le quote senza `regole_calcolo` e i saldi con
 * `is_applicato` falso e senza piano. I DATI DELLA 1.10: l'emissione rifatta riga per riga come la scriveva la 1.10.0.
 *
 * Tutte le cifre sono in centesimi. Segno dei saldi: positivo = debito, negativo = credito.
 */

use App\Actions\Gestionale\Movimenti\StoreIncassoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-11 12:00:00'));
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

// ─── Aiuti (prefisso crgs) ────────────────────────────────────────────────────────────────────────────────────────────────────

/**
 * Preventivo ordinario da 120000 in `$numeroRate` rate mensili dal 5 gennaio 2026, ripartito a millesimi sulle unità date
 * (['A' => ['ugo', 500], 'B' => ['bice', 500]]); i saldi pregressi [[persona, unità, centesimi]]; i cinque conti per emettere e la
 * banca con `$cassa` di apertura (per i rimborsi). La persona si chiama con il nome dato, con la maiuscola («Ugo», «Bice»).
 */
function crgsScenario(array $unita, int $numeroRate, string $metodo, array $saldi = [], int $cassa = 100000): array
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
    ContoContabile::create(['condominio_id' => $c->id, 'codice' => '2301', 'nome' => 'Fondo Passate Gestioni', 'tipo' => 'passivo', 'ruolo' => 'passate_gestioni', 'categoria' => 'debiti']);
    $cassaBanca = Cassa::create(['condominio_id' => $c->id, 'conto_contabile_id' => $banca->id, 'nome' => 'Banca principale', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => $cassa]);

    $persone = [];
    $immobili = [];
    foreach ($unita as $nome => [$chi, $millesimi]) {
        $immobili[$nome] = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "CRGS-{$seq}-{$nome}", 'nome' => "Interno {$nome}", 'interno' => $nome]);
        DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $immobili[$nome]->id, 'valore' => (float) $millesimi, 'created_at' => now(), 'updated_at' => now()]);
        $persone[$chi] ??= tap(Anagrafica::forceCreate(['nome' => ucfirst($chi), 'email' => "crgs-{$chi}{$seq}@test.it", 'indirizzo' => 'Via Roma 1',
            'codice_fiscale' => strtoupper(substr(str_pad('CG' . $chi, 6, 'X'), 0, 6)) . str_pad((string) $seq, 10, '0', STR_PAD_LEFT)]), fn ($a) => $a->condomini()->syncWithoutDetaching([$c->id]));
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $persone[$chi]->id, 'immobile_id' => $immobili[$nome]->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
            'data_inizio' => '2019-01-01', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    }
    foreach ($saldi as [$chi, $nome, $importo]) {
        Saldo::create(['esercizio_id' => $e->id, 'condominio_id' => $c->id, 'anagrafica_id' => $persone[$chi]->id, 'immobile_id' => $immobili[$nome]->id,
            'gestione_id' => $g->id, 'saldo_iniziale' => $importo, 'origine' => 'manuale', 'is_applicato' => false]);
    }
    $piano = PianoRate::create([
        'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => $numeroRate, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'metodo_distribuzione' => $metodo, 'applica_saldi' => true,
    ]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $e);

    return ['c' => $c, 'e' => $e, 'g' => $g, 'immobili' => $immobili, 'persone' => $persone, 'piano' => $piano, 'cassa' => $cassaBanca];
}

/** I dati di un piano generato con la 1.7.x e portato avanti dalle migrazioni: le quote senza `regole_calcolo`. */
function crgsDati17(array $s): void
{
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->update(['regole_calcolo' => null]);
    DB::table('saldi')->where('condominio_id', $s['c']->id)->update(['is_applicato' => false, 'piano_rate_id' => null]);
}

function crgsRata(array $s, int $numero): int
{
    return (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', $numero)->value('id');
}

function crgsQuota(array $s, string $chi, int $numero, ?string $unita = null): RataQuote
{
    return RataQuote::where('rata_id', crgsRata($s, $numero))->where('anagrafica_id', $s['persone'][$chi]->id)
        ->when($unita !== null, fn ($q) => $q->where('immobile_id', $s['immobili'][$unita]->id))->firstOrFail();
}

/** L'emissione vera, dalla rotta della pagina del piano. */
function crgsEmetti($test, array $s, array $numeri, string $data)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => array_map(fn ($n) => crgsRata($s, $n), $numeri), 'data_emissione' => $data, 'invia_notifiche' => false,
    ]);
}

/**
 * L'emissione della 1.10.0, riga per riga (v1.10.0 EmissioneRateController.php:121-268, come `lda49EmettiComeLa110`): salta le
 * quote ≤ 0, scrive in DARE l'importo intero delle altre, chiude su «Gestione rate» e «Passate gestioni», cancella la testata
 * rimasta senza righe e segna «emessa» ogni rata richiesta.
 */
function crgsEmettiComeLa110(array $s, array $numeri, string $data): void
{
    $conti = DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->pluck('id', 'ruolo');
    DB::transaction(function () use ($s, $numeri, $data, $conti) {
        foreach ($numeri as $n) {
            $rata = Rata::with('rateQuote')->findOrFail(crgsRata($s, $n));
            $sc = ScritturaContabile::create(['condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'gestione_id' => $s['piano']->gestione_id,
                'data_registrazione' => now(), 'data_competenza' => $data, 'causale' => 'Emissione '.$rata->descrizione, 'tipo_movimento' => 'emissione_rata', 'stato' => 'registrata']);
            $tg = 0;
            $tp = 0;
            foreach ($rata->rateQuote as $q) {
                $imp = (int) $q->importo;
                if ($imp <= 0) {
                    continue;
                }
                $regole = is_string($q->regole_calcolo) ? json_decode($q->regole_calcolo, true) : $q->regole_calcolo;
                $g = (int) ($regole['importi']['quota_pura_gestione'] ?? $imp);
                $sc->righe()->create(['conto_contabile_id' => $conti['crediti_condomini'], 'anagrafica_id' => $q->anagrafica_id, 'immobile_id' => $q->immobile_id, 'rata_id' => $rata->id,
                    'tipo_riga' => 'dare', 'importo' => $imp, 'note' => 'Quota '.$rata->descrizione]);
                $q->update(['scrittura_contabile_id' => $sc->id]);
                $tg += $g;
                $tp += $imp - $g;
            }
            if ($tg + $tp > 0) {
                foreach ([[$conti['gestione_rate'], $tg, 'Totale emissione '], [$conti['passate_gestioni'], $tp, 'Riporto esercizi precedenti — ']] as [$conto, $t, $nota]) {
                    if ($t !== 0) {
                        $sc->righe()->create(['conto_contabile_id' => $conto, 'tipo_riga' => $t > 0 ? 'avere' : 'dare', 'importo' => abs($t), 'note' => $nota.$rata->descrizione]);
                    }
                }
            } else {
                $sc->forceDelete();
            }
        }
        Rata::whereIn('id', array_map(fn ($n) => crgsRata($s, $n), $numeri))->update(['stato' => 'emessa']);
    });
}

/**
 * «Usa credito» di `$chi` dalla sua quota a credito della rata `$daRata` sulle sue quote delle rate `$su` (centesimi per rata), come
 * la 1.10: il prelievo e il riaccredito su «Crediti verso condomini» (come `lda49CompensaComeLa110`).
 */
function crgsCompensaComeLa110(array $s, string $chi, int $daRata, array $su, string $il): void
{
    $p = $s['persone'][$chi];
    $righe = [['rata_id' => crgsQuota($s, $chi, $daRata)->id, 'importo' => -array_sum($su) / 100]];
    foreach ($su as $n => $cent) {
        $righe[] = ['rata_id' => crgsQuota($s, $chi, $n)->id, 'importo' => $cent / 100];
    }
    $incasso = app(StoreIncassoRateAction::class)->execute(['pagante_id' => $p->id, 'cassa_id' => $s['cassa']->id, 'gestione_id' => $s['g']->id,
        'data_pagamento' => $il, 'importo_totale' => 0, 'descrizione' => 'Compensazione', 'dettaglio_pagamenti' => $righe], $s['c'], $s['e']);
    $comp = ScritturaContabile::where('scrittura_padre_id', $incasso->id)->where('tipo_movimento', 'storno_credito')->firstOrFail();
    $conti = DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->pluck('id', 'ruolo');
    DB::table('righe_scritture')->where('scrittura_id', $comp->id)->where('rata_id', crgsRata($s, $daRata))->where('anagrafica_id', $p->id)
        ->where('conto_contabile_id', $conti['passate_gestioni'])->update(['conto_contabile_id' => $conti['crediti_condomini']]);
}

/** I centesimi come li manda il modulo degli incassi («50.00»): la conversione la fa la Request, una volta sola. */
function crgsEuro(int $c): string
{
    return sprintf('%s%d.%02d', $c < 0 ? '-' : '', intdiv(abs($c), 100), abs($c) % 100);
}

/** «Usa credito» dalla rotta vera: `$chi` paga le quote `$su` ([id della quota => centesimi]) con il credito della quota `$da`. */
function crgsCompensa($test, array $s, string $chi, RataQuote $da, array $su, string $il): int
{
    $righe = [['rata_id' => $da->id, 'importo' => crgsEuro(-array_sum($su))]];
    foreach ($su as $quotaId => $cent) {
        $righe[] = ['rata_id' => $quotaId, 'importo' => crgsEuro($cent)];
    }
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone'][$chi]->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $il, 'importo_totale' => '0.00',
        'descrizione' => 'Compensazione', 'eccedenza' => '0.00', 'dettaglio_pagamenti' => $righe,
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
}

/** Il rimborso dalla rotta vera dell'estratto conto («Rimborsa il credito»). */
function crgsRimborsa($test, array $s, string $chi, RataQuote $da, int $importo, string $il): int
{
    $test->actingAs($test->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['persone'][$chi]]), [
        'rata_quote_id' => $da->id, 'cassa_id' => $s['cassa']->id, 'data_rimborso' => $il, 'importo' => number_format($importo / 100, 2, ',', ''),
    ])->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'rimborso_condomino')->max('id');
}

/** Lo storno dalla rotta vera (incassi e rimborsi passano dalla stessa). */
function crgsStorna($test, array $s, int $id)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $id]));
}

/** Il saldo di un conto per ruolo, dare − avere, sulle scritture vive (con `$chi`, solo le righe di quella persona). */
function crgsConto(array $s, string $ruolo, ?string $chi = null): int
{
    $q = DB::table('righe_scritture as r')->join('scritture_contabili as sc', 'sc.id', '=', 'r.scrittura_id')->join('conti_contabili as cc', 'cc.id', '=', 'r.conto_contabile_id')
        ->where('sc.condominio_id', $s['c']->id)->whereNull('sc.deleted_at')->where('cc.ruolo', $ruolo);
    if ($chi !== null) {
        $q->where('r.anagrafica_id', $s['persone'][$chi]->id);
    }

    return (int) $q->sum(DB::raw("CASE WHEN r.tipo_riga = 'dare' THEN r.importo ELSE -r.importo END"));
}

/** L'id del conto per ruolo. */
function crgsContoId(array $s, string $ruolo): int
{
    return (int) ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', $ruolo)->value('id');
}

/** [«Crediti verso condomini» di Ugo, «Gestione rate», «Passate gestioni»]. */
function crgsConti(array $s): array
{
    return [crgsConto($s, 'crediti_condomini', 'ugo'), crgsConto($s, 'gestione_rate'), crgsConto($s, 'passate_gestioni')];
}

/** Le righe di una scrittura: [ruolo, persona, verso, importo], in ordine. */
function crgsRighe(array $s, int $scritturaId): array
{
    $nomi = collect($s['persone'])->mapWithKeys(fn ($a, $k) => [$a->id => $k]);

    return DB::table('righe_scritture as r')->join('conti_contabili as cc', 'cc.id', '=', 'r.conto_contabile_id')->where('r.scrittura_id', $scritturaId)->orderBy('r.id')
        ->get(['cc.ruolo', 'r.anagrafica_id', 'r.tipo_riga', 'r.importo'])
        ->map(fn ($r) => [$r->ruolo, $r->anagrafica_id ? $nomi[$r->anagrafica_id] : null, $r->tipo_riga, (int) $r->importo])->all();
}

/** Le scritture figlie di un'emissione (quelle dello storno e del comando sono di tipo `emissione_rata` con un padre). */
function crgsFiglieDiEmissione(array $s): int
{
    return DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'emissione_rata')->whereNotNull('scrittura_padre_id')->count();
}

/** Il saldo dell'estratto conto della persona, dalla pagina. */
function crgsEstratto($test, array $s, string $chi): int
{
    return (int) $test->actingAs($test->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone'][$chi]]))
        ->assertOk()->viewData('page')['props']['stats']['saldo_raw'];
}

/** La prop della card «Crediti da riallineare» nella pagina «Incassi rate» (null: la card non compare). */
function crgsCard($test, array $s): ?array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.movimenti-rate.index', [$s['c']]))->assertOk()->viewData('page')['props']['riallineamento_crediti'];
}

/** I motivi delle voci che restano come sono, come li mostrano la card e il comando. */
function crgsMotivi(?array $rilevato): array
{
    return collect($rilevato['restano'] ?? [])->pluck('motivo')->all();
}

/** La card elenca la quota ferma di `$nome` sulla rata `$numero` fra le voci che restano come sono, senza niente da scrivere. */
function crgsCardConLaQuotaFerma($test, array $s, string $nome, int $numero): bool
{
    $card = crgsCard($test, $s);

    return $card !== null
        && collect(crgsMotivi($card))->contains(fn ($m) => str_contains($m, $nome) && str_contains($m, "rata {$numero}") && str_contains($m, 'dettaglio del calcolo'))
        && $card['compensazioni'] === []
        && collect($card['rate'])->where('azione', 'scrivi')->isEmpty();
}

// ─── G1, LT-1, GS-1: lo storno non porta a giornale la quota ferma della 1.7 ─────────────────────────────────────────────────

it('G1, LT-1, GS-1 — dati della 1.7, «tutte le rate», la rata 3 emessa dalla .49 senza la quota ferma di Ugo: il rimborso di € 10,00 da quella quota, poi stornato, lascia la quota ferma, senza figlie dell\'emissione, nella card e con l\'estratto di prima', function () {
    // A e B da 500 millesimi: 5000 a rata ciascuna. Credito di Ugo −150000 su dodici rate: −12500 a rata, quota di Ugo sulla rata 3
    // 5000 − 12500 = −7500, senza `regole_calcolo` (1.7.x). L'emissione della rata 3 porta a giornale la sola Bice (76.3 ritoccata).
    $s = crgsScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'tutte_rate', [['ugo', 'A', -150000]]);
    crgsDati17($s);
    $q3 = crgsQuota($s, 'ugo', 3);
    expect((int) $q3->importo)->toBe(-7500);

    crgsEmetti($this, $s, [3], '2026-03-31')->assertSessionHasNoErrors();
    expect(DB::table('rate')->where('id', crgsRata($s, 3))->value('stato'))->toBe('emessa')
        ->and($q3->fresh()->scrittura_contabile_id)->toBeNull()
        ->and([crgsConti($s), crgsEstratto($this, $s, 'ugo')])->toBe([[0, -5000, 0], -150000])
        ->and(crgsCardConLaQuotaFerma($this, $s, 'Ugo', 3))->toBeTrue();

    // Il rimborso esce da «Passate gestioni», dove sta il credito della quota ferma: DARE «Passate» 1000 / AVERE banca 1000.
    $rimborso = crgsRimborsa($this, $s, 'ugo', $q3, 1000, '2026-04-10');
    expect(crgsRighe($s, $rimborso))->toBe([['passate_gestioni', 'ugo', 'dare', 1000], ['banca', null, 'avere', 1000]])
        ->and(crgsEstratto($this, $s, 'ugo'))->toBe(-149000);

    crgsStorna($this, $s, $rimborso)->assertSessionHasNoErrors();

    // A mano: lo specchio scrive AVERE «Passate» 1000 / DARE banca 1000, e basta. «Crediti» di Ugo 0, «Gestione rate» −5000 (Bice),
    // «Passate» 1000 − 1000 = 0; l'estratto torna a −150000 (il saldo resta libero: nessuna quota di Ugo è a giornale).
    // Oggi lo storno scrive anche una figlia dell'emissione della rata 3, AVERE «Crediti» 7500 a nome di Ugo / DARE «Passate» 7500,
    // con spesa 0 e tutto l'importo come riporto, e le lega la quota: [−7500, −5000, 7500], estratto −157500, card senza la voce.
    expect([crgsConti($s), crgsEstratto($this, $s, 'ugo')])->toBe([[0, -5000, 0], -150000])
        ->and(crgsFiglieDiEmissione($s))->toBe(0)
        ->and($q3->fresh()->scrittura_contabile_id)->toBeNull()
        ->and($q3->fresh()->ruoloDelCredito())->toBe('passate_gestioni')
        ->and($q3->fresh()->creditoSuPassate(crgsContoId($s, 'passate_gestioni')))->toBe(7500)
        ->and(crgsCardConLaQuotaFerma($this, $s, 'Ugo', 3))->toBeTrue();
});

it('G1, LT-1, GS-1 — dati della 1.10: la rata 1 emessa dalla 1.10 senza la quota di Ugo; il rimborso da quella quota, poi stornato, non la porta a giornale e l\'estratto di Ugo torna a −150000', function () {
    // Lo stesso piano della 1.7 («tutte le rate», Ugo −7500 a rata). La 1.10 ha emesso la rata 1 saltando la quota di Ugo: DARE
    // «Crediti» Bice 5000 / AVERE «Gestione rate» 5000.
    $s = crgsScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'tutte_rate', [['ugo', 'A', -150000]]);
    crgsDati17($s);
    crgsEmettiComeLa110($s, [1], '2026-01-31');
    $q1 = crgsQuota($s, 'ugo', 1);
    expect((int) $q1->importo)->toBe(-7500)
        ->and($q1->scrittura_contabile_id)->toBeNull()
        ->and([crgsConti($s), crgsEstratto($this, $s, 'ugo')])->toBe([[0, -5000, 0], -150000])
        ->and(crgsCardConLaQuotaFerma($this, $s, 'Ugo', 1))->toBeTrue();

    $rimborso = crgsRimborsa($this, $s, 'ugo', $q1, 1000, '2026-02-10');
    crgsStorna($this, $s, $rimborso)->assertSessionHasNoErrors();

    // A mano come sopra: lo specchio, e il credito di nuovo su «Passate gestioni». Oggi la figlia da 7500 lega la quota della prima
    // rata di Ugo: l'estratto toglie il saldo (`saldoGiaNelleQuote`) e tiene la sola riga, −7500 invece di −150000.
    expect([crgsConti($s), crgsEstratto($this, $s, 'ugo')])->toBe([[0, -5000, 0], -150000])
        ->and(crgsFiglieDiEmissione($s))->toBe(0)
        ->and($q1->fresh()->scrittura_contabile_id)->toBeNull()
        ->and(crgsCardConLaQuotaFerma($this, $s, 'Ugo', 1))->toBeTrue();
});

it('G1, LT-1, GS-1 — dati della 1.7, «prima rata», rate 1 e 2 emesse dalla .49: la compensazione di € 50,00 dalla quota ferma di Ugo, poi stornata con il suo incasso, lascia la quota ferma e i conti di prima', function () {
    // «Prima rata»: rata 1 di Ugo 5000 − 150000 = −145000 (ferma), rata 2 di Ugo 5000; Bice 5000 su tutte e due. Emesse le rate 1 e 2:
    // «Crediti» di Ugo 5000 (rata 2), «Gestione rate» −15000, «Passate» 0; estratto di Ugo −150000 + 5000 = −145000.
    $s = crgsScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'prima_rata', [['ugo', 'A', -150000]]);
    crgsDati17($s);
    $q1 = crgsQuota($s, 'ugo', 1);
    $q2 = crgsQuota($s, 'ugo', 2);
    expect([(int) $q1->importo, (int) $q2->importo])->toBe([-145000, 5000]);
    crgsEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    expect($q1->fresh()->scrittura_contabile_id)->toBeNull()
        ->and([crgsConti($s), crgsEstratto($this, $s, 'ugo')])->toBe([[5000, -15000, 0], -145000]);

    // La compensazione preleva da «Passate gestioni» (DARE 5000) e paga la rata 2 (AVERE «Crediti» 5000).
    $incasso = crgsCompensa($this, $s, 'ugo', $q1, [$q2->id => 5000], '2026-03-10');
    expect(crgsConti($s))->toBe([0, -15000, 5000]);

    crgsStorna($this, $s, $incasso)->assertSessionHasNoErrors();

    // A mano: lo specchio della compensazione, AVERE «Passate» 5000 / DARE «Crediti» Ugo 5000: [5000, −15000, 0], estratto −145000.
    // Oggi la figlia dell'emissione della rata 1 porta tutta la quota come riporto, AVERE «Crediti» 145000 / DARE «Passate» 145000:
    // [−140000, −15000, 145000], estratto −140000, card senza la voce.
    expect([crgsConti($s), crgsEstratto($this, $s, 'ugo')])->toBe([[5000, -15000, 0], -145000])
        ->and(crgsFiglieDiEmissione($s))->toBe(0)
        ->and($q1->fresh()->scrittura_contabile_id)->toBeNull()
        ->and(crgsCardConLaQuotaFerma($this, $s, 'Ugo', 1))->toBeTrue();
});

// ─── LT-5 e G2: la nota del rimborso di una quota ferma in una rata emessa ───────────────────────────────────────────────────

it('LT-5, G2 — il rimborso della quota ferma di Ugo nella rata 3 «emessa» esce da «Passate gestioni», e la nota della scrittura non dice «con la sua rata non ancora a giornale»: è la quota a non esserlo', function () {
    $s = crgsScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'tutte_rate', [['ugo', 'A', -150000]]);
    crgsDati17($s);
    $q3 = crgsQuota($s, 'ugo', 3);
    crgsEmetti($this, $s, [3], '2026-03-31')->assertSessionHasNoErrors();
    expect(DB::table('rate')->where('id', crgsRata($s, 3))->value('stato'))->toBe('emessa');

    $rimborso = crgsRimborsa($this, $s, 'ugo', $q3, 7500, '2026-04-10');
    $nota = (string) DB::table('scritture_contabili')->where('id', $rimborso)->value('note');

    // Oggi: «Credito riportato da un esercizio precedente, con la sua rata non ancora a giornale: contropartita Passate gestioni.»,
    // con la rata 3 emessa. La contropartita è giusta: quel credito non è mai entrato in «Crediti verso condomini».
    expect($nota)->not->toContain('rata non ancora a giornale')
        ->and($nota)->toContain('quota non ancora a giornale')
        ->and(crgsRighe($s, $rimborso))->toBe([['passate_gestioni', 'ugo', 'dare', 7500], ['banca', null, 'avere', 7500]])
        ->and(DB::table('rate')->where('id', crgsRata($s, 3))->value('stato'))->toBe('emessa');
});

// ─── LT-3 e G3: la quota a zero della 1.7 non ha un credito da usare o rimborsare ────────────────────────────────────────────

it('LT-3, G3 — dati della 1.7, «prima rata», la quota di Ugo a zero (il credito pari alla spesa): l\'esito non dice «il credito resta a Ugo, da usare o rimborsare», la card non dice «con il suo credito», e la strada nomina i rimborsi', function () {
    // Il caso K6: credito di Ugo −5000, rata 1 di Ugo 5000 − 5000 = 0, senza `regole_calcolo`. Il credito disponibile della quota è 0:
    // il modulo del rimborso non la elenca, e «Usa credito» non ha niente da prelevare.
    $s = crgsScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'prima_rata', [['ugo', 'A', -5000]]);
    crgsDati17($s);
    $q1 = crgsQuota($s, 'ugo', 1);
    expect([(int) $q1->importo, (int) $q1->credito_disponibile])->toBe([0, 0]);

    $esito = crgsEmetti($this, $s, [1], '2026-01-31')->getSession()->get('esito_emissione');

    // Oggi: «… è a zero senza il dettaglio del calcolo … Il credito resta a Ugo, da usare o rimborsare. Per portare la quota a giornale
    // si ricalcola il piano, dopo aver stornato gli incassi e annullato le emissioni; …».
    expect($esito['testo'])->not->toContain('Il credito resta a')
        ->and($esito['testo'])->not->toContain('da usare o rimborsare')
        ->and($esito['testo'])->toContain('non c\'è un credito da usare né da rimborsare')
        ->and($esito['testo'])->toContain('Ugo')
        ->and($esito['testo'])->toContain('rata 1')
        ->and($esito['testo'])->toContain('le compensazioni e i rimborsi');

    // Il motivo della card per la quota a zero: oggi «… resta fuori dal giornale con il suo credito».
    $motivo = collect(crgsMotivi(crgsCard($this, $s)))->first(fn ($m) => str_contains($m, 'Ugo') && str_contains($m, 'rata 1'));
    expect($motivo)->not->toBeNull()
        ->and($motivo)->toContain('a zero')
        ->and($motivo)->not->toContain('con il suo credito')
        ->and($motivo)->toContain('non c\'è un credito da usare né da rimborsare');
});

it('LT-3, G3 — sulla stessa rata una quota a zero (Ugo) e una a credito (Rita): «il credito resta a» nomina solo Rita, e la quota a zero ha la sua frase', function () {
    // Bice 500 ‰ = 5000; Ugo 250 ‰ = 2500 con saldo −2500, quindi 0; Rita 250 ‰ = 2500 con saldo −4000, quindi −1500. Credito
    // disponibile: Ugo 0, Rita 1500.
    $s = crgsScenario(['A' => ['ugo', 250], 'B' => ['bice', 500], 'C' => ['rita', 250]], 12, 'prima_rata', [['ugo', 'A', -2500], ['rita', 'C', -4000]]);
    crgsDati17($s);
    expect([(int) crgsQuota($s, 'ugo', 1)->importo, (int) crgsQuota($s, 'rita', 1)->importo])->toBe([0, -1500]);

    $esito = crgsEmetti($this, $s, [1], '2026-01-31')->getSession()->get('esito_emissione');

    // Oggi: «Il credito resta a Ugo e Rita, da usare o rimborsare.», ma Ugo non ha niente.
    expect($esito['testo'])->toContain('Il credito resta a Rita, da usare o rimborsare.')
        ->and($esito['testo'])->not->toContain('Ugo e Rita, da usare')
        ->and($esito['testo'])->toContain('Per la quota a zero di Ugo sulla rata 1')
        ->and($esito['titolo'])->toBe('Emissione completata');
});

// ─── LT-8: le concordanze dell'esito ─────────────────────────────────────────────────────────────────────────────────────────

it('LT-8 — dati della 1.7, «prima rata», le quote di Ugo e di Bice tutte e due ferme sulla rata 1: l\'esito dice «senza quelle quote», non «senza quella quota»; la rata resta in bozza e la card non la elenca', function () {
    // Saldi −20000 e −10000: rata 1 di Ugo 5000 − 20000 = −15000, di Bice 5000 − 10000 = −5000. Tutte e due ferme: la rata resta in
    // bozza. Oggi: «La rata 1 resta in bozza: senza quella quota non ha niente da portare a giornale.» (il singolare si sceglieva sul
    // numero delle rate, non delle quote).
    $s = crgsScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'prima_rata', [['ugo', 'A', -20000], ['bice', 'B', -10000]]);
    crgsDati17($s);
    expect([(int) crgsQuota($s, 'ugo', 1)->importo, (int) crgsQuota($s, 'bice', 1)->importo])->toBe([-15000, -5000]);

    $esito = crgsEmetti($this, $s, [1], '2026-01-31')->getSession()->get('esito_emissione');

    expect($esito['testo'])->toContain('La rata 1 resta in bozza: senza quelle quote non ha niente da portare a giornale.')
        ->and($esito['testo'])->not->toContain('senza quella quota')
        ->and($esito['titolo'])->toBe('Nessuna rata emessa')
        ->and(DB::table('rate')->where('id', crgsRata($s, 1))->value('stato'))->toBe('bozza')
        // LT-4: una rata fatta solo di quote ferme resta in bozza e la card non la elenca (la guida lo dice; il perché lo dà l'esito).
        ->and(crgsCard($this, $s))->toBeNull();
});

// ─── LT-2: il conto mancante, con un nome solo e una strada vera ─────────────────────────────────────────────────────────────

it('LT-2 — senza il conto «Fondo Passate Gestioni» la compensazione, il rimborso e l\'emissione di un credito riportato si rifiutano senza mandare al «piano dei conti» (il preventivo), e chiamano il conto con un nome solo', function () {
    // «Rata zero», credito di Ugo −20000; emesse le rate 1 e 2 (10000 ciascuna, senza riporto); poi il conto non c'è più.
    $s = crgsScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]], 10000);
    crgsEmetti($this, $s, [1, 2], '2026-02-28')->assertSessionHasNoErrors();
    DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'passate_gestioni')->delete();

    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['ugo']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-10', 'importo_totale' => '0.00', 'descrizione' => 'Compensazione', 'eccedenza' => '0.00',
        'dettaglio_pagamenti' => [['rata_id' => crgsQuota($s, 'ugo', 1)->id, 'importo' => '100.00'], ['rata_id' => crgsQuota($s, 'ugo', 0)->id, 'importo' => '-100.00']],
    ])->assertSessionHasErrors('dettaglio_pagamenti');
    $fraseIncasso = session('errors')->first('dettaglio_pagamenti');

    $this->actingAs($this->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['persone']['ugo']]), [
        'rata_quote_id' => crgsQuota($s, 'ugo', 0)->id, 'cassa_id' => $s['cassa']->id, 'data_rimborso' => '2026-03-11', 'importo' => '50,00',
    ])->assertSessionHasErrors('rata_quote_id');
    $fraseRimborso = session('errors')->first('rata_quote_id');

    // L'emissione della rata 0, fatta solo del credito riportato: senza il conto si ferma tutta la richiesta.
    $fraseEmissione = (string) (crgsEmetti($this, $s, [0], '2026-03-12')->getSession()->get('message')['message'] ?? '');

    // Oggi: «Manca il conto «Passate gestioni» del condominio: … Ricrealo dal piano dei conti («Fondo Passate Gestioni», 2301) e
    // riprova.», e per l'emissione «… nel piano dei conti di questo condominio manca il «Fondo Passate Gestioni» (2301). Ricrealo dal
    // piano dei conti e riprova …». La pagina «Piani dei conti» è il preventivo: lì nasce una voce di spesa, non il conto di sistema.
    expect($fraseIncasso)->not->toContain('piano dei conti')
        ->and($fraseIncasso)->toContain('«Fondo Passate Gestioni» (2301)')
        ->and($fraseIncasso)->not->toContain('«Passate gestioni»')
        ->and($fraseRimborso)->toBe($fraseIncasso)
        ->and($fraseEmissione)->toContain('«Fondo Passate Gestioni» (2301)')
        ->and($fraseEmissione)->not->toContain('piano dei conti')
        ->and(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->whereIn('tipo_movimento', ['incasso_rata', 'storno_credito', 'rimborso_condomino'])->count())->toBe(0)
        ->and(DB::table('rate')->where('id', crgsRata($s, 0))->value('stato'))->toBe('bozza');
});

it('LT-2 — senza il conto «Fondo Passate Gestioni» la card e il comando del riallineamento si rifiutano con lo stesso nome del conto, senza mandare al «piano dei conti»', function () {
    // DC1 della 1.10: rata zero di soli crediti (Ugo −20000) emessa come la 1.10, il credito usato tutto sulle rate 1 e 2 con il prelievo
    // su «Crediti». C'è una rettifica da scrivere; poi il conto non c'è più.
    $s = crgsScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    crgsEmettiComeLa110($s, [0, 1, 2], '2026-02-28');
    crgsCompensaComeLa110($s, 'ugo', 0, [1 => 10000, 2 => 10000], '2026-03-10');
    DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'passate_gestioni')->delete();

    $r = $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$s['c']]));
    $fraseCard = (string) ($r->getSession()->get('message')['message'] ?? '');

    $esito = Artisan::call('kondomanager:riallinea-crediti', ['--condominio' => $s['c']->id]);
    $uscita = Artisan::output();

    expect($fraseCard)->toContain('«Fondo Passate Gestioni» (2301)')
        ->and($fraseCard)->not->toContain('piano dei conti')
        ->and($fraseCard)->toContain('Niente è stato scritto')
        ->and($esito)->toBe(1)
        ->and($uscita)->toContain('«Fondo Passate Gestioni» (2301)')
        ->and($uscita)->not->toContain('piano dei conti')
        ->and(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'rettifica')->count())->toBe(0);
});

// ─── LT-6: i motivi della card dicono la strada ──────────────────────────────────────────────────────────────────────────────

it('LT-6 — la card in sola lettura dice la strada dove c\'è: la rata «emessa» senza scritture con il credito già uscito tutto si emette dalla pagina del piano; la quota ferma della 1.7 si porta a giornale con il ricalcolo, non emettendo la rata', function () {
    // (a) DC1 della 1.10 già riallineato: rata zero di soli crediti (Ugo −20000) segnata «emessa» senza righe, il credito usato tutto
    // sulle rate 1 e 2 e la rettifica scritta. Resta la rata 0 in sola lettura. Oggi il motivo dice «a giornale non c'è niente da
    // portare, e la pagina del piano la mostra in bozza», e non che emetterla la toglie da qui.
    $a = crgsScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    crgsEmettiComeLa110($a, [0, 1, 2], '2026-02-28');
    crgsCompensaComeLa110($a, 'ugo', 0, [1 => 10000, 2 => 10000], '2026-03-10');
    $this->actingAs($this->user)->post(route('admin.gestionale.riallinea-crediti', [$a['c']]))->assertRedirect();
    $cardA = crgsCard($this, $a);
    expect($cardA)->not->toBeNull()
        ->and($cardA['compensazioni'])->toBe([])
        ->and($cardA['da_emettere'])->toBe([]);
    $motivoA = collect(crgsMotivi($cardA))->first(fn ($m) => str_contains($m, 'rata 0'));
    expect($motivoA)->not->toBeNull()
        ->and($motivoA)->toContain('se la emetti dalla pagina del piano resta in bozza, e questa voce non compare più');

    // (b) La quota ferma della 1.7 (lo scenario di LDA-2): oggi il motivo non dice la strada.
    $b = crgsScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'tutte_rate', [['ugo', 'A', -150000]]);
    crgsDati17($b);
    crgsEmetti($this, $b, [3], '2026-03-31')->assertSessionHasNoErrors();
    $motivoB = collect(crgsMotivi(crgsCard($this, $b)))->first(fn ($m) => str_contains($m, 'Ugo') && str_contains($m, 'rata 3'));
    expect($motivoB)->not->toBeNull()
        ->and($motivoB)->toContain('si ricalcola il piano')
        ->and($motivoB)->toContain('emettere la rata non basta');
});
