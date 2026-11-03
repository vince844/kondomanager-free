<?php

/**
 * Coda 314 — lo scadenzario F24 raccoglie le ritenute e le deleghe aperte di tutti gli esercizi del condominio.
 *
 * Il caso che lo motiva: le ritenute operate a dicembre si versano entro il 16 gennaio dell'anno dopo (art. 25-ter
 * DPR 600/1973; `PlafondRitenuteService::maturaGruppi`), cioè quando l'amministratore ha già chiuso a mano il vecchio
 * esercizio per aprire il nuovo («un solo esercizio aperto per condominio»). Se lo scadenziario guarda il solo
 * esercizio corrente, quelle ritenute e le deleghe ancora da versare spariscono dall'elenco e dai contatori, senza
 * avviso, e la scadenza passa.
 *
 * Le scelte (decisione 153.6): si raccolgono ritenute e deleghe in bozza e confermate di tutti gli esercizi; le bozze
 * si rifanno per condominio; una delega nuova prende l'esercizio aperto; l'esercizio della scrittura del versamento
 * resta quello della delega (`ConfermaVersamentoF24Action`, fino al modulo di chiusura, Coda 295); il cumulo dei
 * € 500,00 e le scadenze restano quelli di `PlafondRitenuteService`, sull'elenco di tutti gli esercizi.
 *
 * Lo storico (decisione 154.3): oltre alle deleghe da versare di tutti gli esercizi, l'elenco mostra anche le versate, le
 * stornate e le annullate dei vecchi esercizi, ognuna con il nome del suo esercizio; il «Versato» resta quello dell'esercizio
 * corrente, cioè delle deleghe versate che appartengono a lui: la scrittura del versamento sta nell'esercizio della delega
 * (`ConfermaVersamentoF24Action`), quindi una delega del 2026 versata a gennaio 2027 è «Versato» del 2026.
 *
 * Le cifre si rifanno dalla regola: ritenuta = imponibile × 4 %; dicembre al 16 gennaio; sotto la soglia di € 500,00
 * le ritenute fino a novembre al 16 dicembre; raggiunta la soglia, il 16 del mese dopo; un sabato o un festivo slitta
 * al primo giorno lavorativo (16/01/2027 è sabato → lunedì 18/01/2027; 16/08/2026 è domenica → lunedì 17/08/2026).
 */

use App\Enums\Fiscale\StatoDelegaF24;
use App\Models\Esercizio;
use App\Models\Gestionale\DelegaF24;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\User;
use App\Services\Gestionale\PagamentoFornitoreService;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create();
    $this->admin->assignRole('amministratore');
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Il contesto dei pagamenti con un fornitore in appalto al 4 %, persona fisica (codice 1019). */
function b59fCtx(?string $dal = null, ?string $al = null, ?string $natura = 'persona_fisica_irpef'): array
{
    $ctx = setupPagamentiService();
    $ctx[3]->update([
        'soggetto_ritenuta' => true,
        'perc_ritenuta' => 4,
        'perc_imponibile_ritenuta' => 100,
        'tipo_ritenuta' => 'appalto_4',
        'natura_percipiente' => $natura,
    ]);
    $ctx[3]->refresh();

    if ($dal !== null) {
        $ctx[1]->update(['data_inizio' => $dal, 'data_fine' => $al, 'nome' => 'Esercizio '.substr($dal, 0, 4).'/'.substr($al, 0, 4)]);
        $ctx[1]->refresh();
    }

    return $ctx;
}

/** Una fattura con ritenuta, registrata nell'esercizio del contesto (che deve essere aperto). */
function b59fFattura(array $ctx, float $imponibileEuro)
{
    return registraFatturaServiceTest($ctx, [
        'applica_ritenuta' => true,
        'righe' => [[
            'descrizione' => 'Manutenzione',
            'importo_imponibile' => $imponibileEuro,
            'aliquota_iva' => 22,
            'conto_id' => $ctx[5]->id,
            'is_sopravvenienza' => false,
        ]],
    ]);
}

/** Il pagamento di una fattura alla data data, con la scrittura nell'esercizio indicato. */
function b59fPaga(array $ctx, $fattura, string $data, Esercizio $esercizio)
{
    return (new PagamentoFornitoreService)->registraPagamento(
        datiPagamento($ctx, $fattura, ['data_pagamento' => $data, 'esercizio_id' => $esercizio->id])
    );
}

/**
 * L'amministratore chiude a mano il vecchio esercizio e apre il nuovo: è l'unico modo, oggi, di passare all'anno dopo
 * (`CreateEsercizioRequest`: un solo esercizio aperto per condominio).
 */
function b59fPassaAlNuovo(array $ctx, string $nome, string $dal, string $al): Esercizio
{
    $ctx[1]->update(['stato' => 'chiuso']);

    $nuovo = Esercizio::create([
        'condominio_id' => $ctx[0]->id,
        'nome' => $nome,
        'stato' => 'aperto',
        'data_inizio' => $dal,
        'data_fine' => $al,
    ]);

    legaAEsercizio($nuovo, $ctx[2]->id);

    return $nuovo;
}

function b59fIndice($test, array $ctx)
{
    return $test->actingAs($test->admin)->get(route('admin.gestionale.f24.index', $ctx[0]->id))->assertOk();
}

function b59fAggiorna($test, array $ctx)
{
    return $test->actingAs($test->admin)
        ->from(route('admin.gestionale.f24.index', $ctx[0]->id))
        ->post(route('admin.gestionale.f24.genera', $ctx[0]->id));
}

/** Il versamento di una delega dalla sua scheda, come lo manda il modulo di `F24Show.vue`. */
function b59fVersa($test, array $ctx, DelegaF24 $delega, string $data)
{
    return $test->actingAs($test->admin)
        ->post(route('admin.gestionale.f24.versa', [$ctx[0]->id, $delega->id]), [
            'data_versamento' => $data,
            'conto_corrente_id' => $ctx[4],
        ])
        ->assertSessionHasNoErrors();
}

test('Coda 314 — la ritenuta di un pagamento del 20/12/2026 resta nello scadenzario dopo la chiusura del 2026: € 240,00 da versare al 18/01/2027', function () {
    Carbon::setTestNow('2026-12-01 10:00:00');
    $ctx = b59fCtx();
    $fattura = b59fFattura($ctx, 6000);
    $pagamento = b59fPaga($ctx, $fattura, '2026-12-20', $ctx[1]);
    // € 6.000,00 × 4 % = € 240,00
    expect((int) $pagamento->importo_ritenuta)->toBe(24000);

    $nuovo = b59fPassaAlNuovo($ctx, 'Esercizio 2027', '2027-01-01', '2027-12-31');
    Carbon::setTestNow('2027-01-10 10:00:00');

    // Prima di premere «Aggiorna scadenze»: il pulsante sa che c'è una ritenuta fuori da ogni delega.
    b59fIndice($this, $ctx)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('esercizio.id', $nuovo->id)
        ->where('da_calcolare.numero', 1)
        ->where('da_calcolare.totale_cents', 24000)
    );

    b59fAggiorna($this, $ctx)
        ->assertRedirect()
        ->assertSessionHas('message', fn ($m) => str_contains($m['message'] ?? '', '1 delega da versare, per un totale di € 240,00'));

    b59fIndice($this, $ctx)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('riepilogo.da_versare_cents', 24000)
        ->where('riepilogo.scadute', 0)
        // dicembre al 16 gennaio; il 16/01/2027 è sabato
        ->where('riepilogo.prossima_scadenza', '2027-01-18')
        ->has('deleghe.data', 1)
        // una delega nuova prende l'esercizio aperto (decisione 153.6)
        ->where('deleghe.data.0.esercizio_id', $nuovo->id)
        ->where('deleghe.data.0.esercizio_non_corrente', null)
        ->where('da_calcolare.numero', 0)
    );
});

test('Coda 314 — una bozza del 2026 compare nello scadenzario del 2027 con il suo esercizio, conta fra le «Scadute», e «Aggiorna scadenze» la rifà nell\'esercizio aperto senza doppioni', function () {
    Carbon::setTestNow('2026-11-05 10:00:00');
    $ctx = b59fCtx();
    $fattura = b59fFattura($ctx, 2500);
    b59fPaga($ctx, $fattura, '2026-11-10', $ctx[1]);

    // Novembre 2026, € 2.500,00 × 4 % = € 100,00, sotto la soglia: al 16 dicembre (mercoledì 16/12/2026).
    Carbon::setTestNow('2026-11-20 10:00:00');
    b59fAggiorna($this, $ctx)->assertRedirect();
    $vecchia = DelegaF24::where('condominio_id', $ctx[0]->id)->sole();
    expect($vecchia->esercizio_id)->toBe($ctx[1]->id)
        ->and($vecchia->data_scadenza->toDateString())->toBe('2026-12-16')
        ->and($vecchia->totale_debito)->toBe(10000);

    $nuovo = b59fPassaAlNuovo($ctx, 'Esercizio 2027', '2027-01-01', '2027-12-31');
    Carbon::setTestNow('2027-01-10 10:00:00');

    b59fIndice($this, $ctx)->assertInertia(fn (AssertableInertia $page) => $page
        ->has('deleghe.data', 1)
        ->where('deleghe.data.0.id', $vecchia->id)
        ->where('deleghe.data.0.esercizio_non_corrente', 'Esercizio 2026')
        ->where('riepilogo.da_versare_cents', 10000)
        ->where('riepilogo.scadute', 1)
        ->where('riepilogo.prossima_scadenza', '2026-12-16')
        // la ritenuta è già nella bozza: niente da ricalcolare
        ->where('da_calcolare.numero', 0)
    );

    b59fAggiorna($this, $ctx)->assertRedirect();

    $bozze = DelegaF24::where('condominio_id', $ctx[0]->id)->where('stato', StatoDelegaF24::BOZZA->value)->get();
    expect($bozze)->toHaveCount(1)
        ->and(DelegaF24::whereKey($vecchia->id)->exists())->toBeFalse()
        ->and($bozze->first()->esercizio_id)->toBe($nuovo->id)
        ->and($bozze->first()->totale_debito)->toBe(10000)
        // la scadenza di legge non si sposta perché cambia l'esercizio
        ->and($bozze->first()->data_scadenza->toDateString())->toBe('2026-12-16');
});

test('Coda 314 — una delega confermata e scaduta del 2026 conta fra le «Scadute» e nel «Da versare» del 2027, e «Aggiorna scadenze» non la tocca', function () {
    Carbon::setTestNow('2026-11-05 10:00:00');
    $ctx = b59fCtx();
    $fattura = b59fFattura($ctx, 2500);
    b59fPaga($ctx, $fattura, '2026-11-10', $ctx[1]);

    Carbon::setTestNow('2026-11-20 10:00:00');
    b59fAggiorna($this, $ctx)->assertRedirect();
    // Nessuna rotta porta oggi una delega a «confermata» (lo stato esiste nell'enum e nelle pagine): si scrive qui.
    $confermata = DelegaF24::where('condominio_id', $ctx[0]->id)->sole();
    $confermata->update(['stato' => StatoDelegaF24::CONFERMATA]);

    b59fPassaAlNuovo($ctx, 'Esercizio 2027', '2027-01-01', '2027-12-31');
    Carbon::setTestNow('2027-01-10 10:00:00');

    b59fIndice($this, $ctx)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('riepilogo.scadute', 1)
        ->where('riepilogo.da_versare_cents', 10000)
        ->has('deleghe.data', 1)
        ->where('deleghe.data.0.stato', 'confermata')
        ->where('deleghe.data.0.esercizio_non_corrente', 'Esercizio 2026')
    );

    b59fAggiorna($this, $ctx)->assertRedirect();

    expect(DelegaF24::where('condominio_id', $ctx[0]->id)->count())->toBe(1)
        ->and($confermata->fresh()->stato)->toBe(StatoDelegaF24::CONFERMATA)
        ->and($confermata->fresh()->esercizio_id)->toBe($ctx[1]->id);
});

test('Coda 314 — con l\'esercizio dal 01/11 al 31/10 le ritenute di ottobre e di novembre 2026 fanno un cumulo solo: € 600,00 al 16/12/2026', function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    $ctx = b59fCtx('2025-11-01', '2026-10-31');
    $ottobre = b59fFattura($ctx, 7500);
    $novembre = b59fFattura($ctx, 7500);
    b59fPaga($ctx, $ottobre, '2026-10-15', $ctx[1]);

    // Ottobre da solo: € 300,00, sotto la soglia → al 16 dicembre.
    Carbon::setTestNow('2026-10-20 10:00:00');
    b59fAggiorna($this, $ctx)->assertRedirect();

    $nuovo = b59fPassaAlNuovo($ctx, 'Esercizio 2026/2027', '2026-11-01', '2027-10-31');
    b59fPaga($ctx, $novembre, '2026-11-10', $nuovo);

    Carbon::setTestNow('2026-11-20 10:00:00');
    b59fAggiorna($this, $ctx)->assertRedirect();

    // € 300,00 + € 300,00 = € 600,00 ≥ € 500,00 raggiunta a novembre → il 16 del mese dopo, 16/12/2026 (mercoledì).
    // Il cumulo è un monte unico del condominio: il confine dell'esercizio non lo spezza.
    $aperte = DelegaF24::where('condominio_id', $ctx[0]->id)->aperte()->get();
    expect($aperte)->toHaveCount(1)
        ->and($aperte->first()->totale_debito)->toBe(60000)
        ->and($aperte->first()->data_scadenza->toDateString())->toBe('2026-12-16')
        ->and($aperte->first()->motivo_codice)->toBe('soglia_raggiunta')
        ->and($aperte->first()->esercizio_id)->toBe($nuovo->id)
        ->and($aperte->first()->righe->flatMap->pagamenti)->toHaveCount(2);
});

test('Coda 314 — con l\'esercizio dal 01/07 al 30/06 le ritenute di giugno e di luglio 2026 raggiungono la soglia a luglio: € 600,00 al 17/08/2026, non al 16 dicembre', function () {
    Carbon::setTestNow('2026-06-01 10:00:00');
    $ctx = b59fCtx('2025-07-01', '2026-06-30');
    $giugno = b59fFattura($ctx, 7500);
    $luglio = b59fFattura($ctx, 7500);
    b59fPaga($ctx, $giugno, '2026-06-15', $ctx[1]);

    Carbon::setTestNow('2026-06-20 10:00:00');
    b59fAggiorna($this, $ctx)->assertRedirect();

    $nuovo = b59fPassaAlNuovo($ctx, 'Esercizio 2026/2027', '2026-07-01', '2027-06-30');
    b59fPaga($ctx, $luglio, '2026-07-10', $nuovo);

    Carbon::setTestNow('2026-07-20 10:00:00');
    b59fAggiorna($this, $ctx)->assertRedirect();

    // Giugno € 300,00 (finestra giugno–novembre, nessuna data-limite fra le due) + luglio € 300,00 = € 600,00 a luglio
    // → 16/08/2026, domenica → 17/08/2026. Con il cumulo spezzato all'esercizio ciascuna andrebbe al 16 dicembre.
    $aperte = DelegaF24::where('condominio_id', $ctx[0]->id)->aperte()->get();
    expect($aperte)->toHaveCount(1)
        ->and($aperte->first()->totale_debito)->toBe(60000)
        ->and($aperte->first()->data_scadenza->toDateString())->toBe('2026-08-17');
});

test('Coda 314 — l\'avviso dei fornitori da classificare vede la ritenuta di dicembre del 2026 anche dopo l\'apertura del 2027', function () {
    Carbon::setTestNow('2026-12-01 10:00:00');
    $ctx = b59fCtx(natura: null);
    $ctx[3]->update(['ragione_sociale' => 'Impresa Pulizie Senza Natura']);
    $fattura = b59fFattura($ctx, 6000);
    b59fPaga($ctx, $fattura, '2026-12-20', $ctx[1]);

    b59fPassaAlNuovo($ctx, 'Esercizio 2027', '2027-01-01', '2027-12-31');
    Carbon::setTestNow('2027-01-10 10:00:00');

    b59fIndice($this, $ctx)->assertInertia(fn (AssertableInertia $page) => $page
        ->has('fornitori_da_classificare', 1)
        ->where('fornitori_da_classificare.0.ragione_sociale', 'Impresa Pulizie Senza Natura')
        ->where('da_calcolare.numero', 1)
    );
});

test('Coda 314 — una delega del 2026 versata a gennaio 2027 si vede nello storico del 2027 con il nome del suo esercizio, e non entra nel «Versato» del 2027 (decisione 154.3)', function () {
    Carbon::setTestNow('2026-12-01 10:00:00');
    $ctx = b59fCtx();
    $fattura = b59fFattura($ctx, 6000);
    b59fPaga($ctx, $fattura, '2026-12-20', $ctx[1]);
    Carbon::setTestNow('2026-12-21 10:00:00');
    b59fAggiorna($this, $ctx)->assertRedirect();
    $delega = DelegaF24::where('condominio_id', $ctx[0]->id)->sole();

    $nuovo = b59fPassaAlNuovo($ctx, 'Esercizio 2027', '2027-01-01', '2027-12-31');
    Carbon::setTestNow('2027-01-18 10:00:00');
    b59fVersa($this, $ctx, $delega, '2027-01-18');
    expect($delega->fresh()->stato)->toBe(StatoDelegaF24::VERSATA);

    // € 6.000,00 × 4 % = € 240,00, versati il 18/01/2027 con la scrittura nell'esercizio 2026 (la scheda lo dice).
    b59fIndice($this, $ctx)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('esercizio.id', $nuovo->id)
        ->has('deleghe.data', 1)
        ->where('deleghe.data.0.id', $delega->id)
        ->where('deleghe.data.0.stato', 'versata')
        ->where('deleghe.data.0.totale_debito', 24000)
        ->where('deleghe.data.0.esercizio_non_corrente', 'Esercizio 2026')
        ->where('riepilogo.versato_cents', 0)
        ->where('riepilogo.da_versare_cents', 0)
        ->where('riepilogo.scadute', 0)
        ->where('riepilogo.prossima_scadenza', null)
    );

    // «Aggiorna scadenze» non tocca una delega versata né la rifà: lo storico resta com'è.
    b59fAggiorna($this, $ctx)->assertRedirect();
    expect(DelegaF24::where('condominio_id', $ctx[0]->id)->count())->toBe(1)
        ->and($delega->fresh()->stato)->toBe(StatoDelegaF24::VERSATA);
});

test('Coda 314 — lo storico del 2027 elenca anche le deleghe stornate e annullate del 2026, ognuna con il nome del suo esercizio, e il filtro per stato le trova', function () {
    Carbon::setTestNow('2026-11-05 10:00:00');
    $ctx = b59fCtx();
    $fattura = b59fFattura($ctx, 2500);
    b59fPaga($ctx, $fattura, '2026-11-10', $ctx[1]);
    Carbon::setTestNow('2026-11-20 10:00:00');

    // Prima delega: versata e poi stornata (la ritenuta torna da versare).
    b59fAggiorna($this, $ctx)->assertRedirect();
    $d1 = DelegaF24::where('condominio_id', $ctx[0]->id)->sole();
    b59fVersa($this, $ctx, $d1, '2026-11-25');
    $this->actingAs($this->admin)
        ->post(route('admin.gestionale.f24.storna', [$ctx[0]->id, $d1->id]), ['motivo' => 'Versamento registrato per errore'])
        ->assertSessionHasNoErrors();

    // Seconda delega: annullata (la ritenuta torna da versare).
    b59fAggiorna($this, $ctx)->assertRedirect();
    $d2 = DelegaF24::where('condominio_id', $ctx[0]->id)->aperte()->sole();
    $this->actingAs($this->admin)
        ->post(route('admin.gestionale.f24.annulla', [$ctx[0]->id, $d2->id]), ['motivo' => 'Preparata due volte'])
        ->assertSessionHasNoErrors();

    // Terza delega: versata, e chiude la ritenuta.
    b59fAggiorna($this, $ctx)->assertRedirect();
    $d3 = DelegaF24::where('condominio_id', $ctx[0]->id)->aperte()->sole();
    b59fVersa($this, $ctx, $d3, '2026-12-10');
    expect([$d1->fresh()->stato, $d2->fresh()->stato, $d3->fresh()->stato])
        ->toBe([StatoDelegaF24::STORNATA, StatoDelegaF24::ANNULLATA, StatoDelegaF24::VERSATA]);

    b59fPassaAlNuovo($ctx, 'Esercizio 2027', '2027-01-01', '2027-12-31');
    Carbon::setTestNow('2027-01-10 10:00:00');

    b59fIndice($this, $ctx)->assertInertia(fn (AssertableInertia $page) => $page
        ->has('deleghe.data', 3)
        ->where('deleghe.data', fn ($righe) => collect($righe)->pluck('stato')->sort()->values()->all() === ['annullata', 'stornata', 'versata'])
        ->where('deleghe.data', fn ($righe) => collect($righe)->every(fn ($r) => $r['esercizio_non_corrente'] === 'Esercizio 2026'))
        ->where('da_calcolare.numero', 0)
        ->where('riepilogo.da_versare_cents', 0)
        ->where('riepilogo.versato_cents', 0)
    );

    foreach (['stornata' => $d1, 'annullata' => $d2, 'versata' => $d3] as $stato => $delega) {
        $this->actingAs($this->admin)
            ->get(route('admin.gestionale.f24.index', [$ctx[0]->id, 'stato' => $stato]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('deleghe.data', 1)
                ->where('deleghe.data.0.id', $delega->id)
            );
    }
});

test('Coda 314 — il «Versato» è delle deleghe versate dell\'esercizio corrente: la versata del 2027 si conta, quella del 2026 si vede nello storico e non si somma', function () {
    Carbon::setTestNow('2026-03-01 10:00:00');
    $ctx = b59fCtx();
    $vecchia = b59fFattura($ctx, 2500);
    b59fPaga($ctx, $vecchia, '2026-03-10', $ctx[1]);
    b59fAggiorna($this, $ctx)->assertRedirect();
    $delega2026 = DelegaF24::where('condominio_id', $ctx[0]->id)->sole();
    // Marzo 2026: € 2.500,00 × 4 % = € 100,00, sotto soglia → 16/06/2026 (la finestra di giugno); versata a marzo.
    b59fVersa($this, $ctx, $delega2026, '2026-03-20');

    $nuovo = b59fPassaAlNuovo($ctx, 'Esercizio 2027', '2027-01-01', '2027-12-31');
    Carbon::setTestNow('2027-02-01 10:00:00');
    $nuova = b59fFattura($ctx, 5000);
    b59fPaga($ctx, $nuova, '2027-02-05', $nuovo);
    b59fAggiorna($this, $ctx)->assertRedirect();
    $delega2027 = DelegaF24::where('condominio_id', $ctx[0]->id)->aperte()->sole();
    // Febbraio 2027: € 5.000,00 × 4 % = € 200,00, sotto soglia → 16/06/2027 (la finestra di giugno); versata a febbraio.
    b59fVersa($this, $ctx, $delega2027, '2027-02-10');

    b59fIndice($this, $ctx)->assertInertia(fn (AssertableInertia $page) => $page
        ->has('deleghe.data', 2)
        ->where('deleghe.data', fn ($righe) => collect($righe)->pluck('id')->sort()->values()->all() === collect([$delega2026->id, $delega2027->id])->sort()->values()->all())
        ->where('deleghe.data', fn ($righe) => collect($righe)->firstWhere('id', $delega2026->id)['esercizio_non_corrente'] === 'Esercizio 2026')
        ->where('deleghe.data', fn ($righe) => collect($righe)->firstWhere('id', $delega2027->id)['esercizio_non_corrente'] === null)
        // € 200,00 della delega del 2027: la sola dell'esercizio corrente. I € 100,00 del 2026 non si sommano.
        ->where('riepilogo.versato_cents', 20000)
    );
});

test('Coda 314 — una delega versata nell\'esercizio corrente compare nell\'elenco e nel «Versato»', function () {
    Carbon::setTestNow('2026-03-01 10:00:00');
    $ctx = b59fCtx();
    $fattura = b59fFattura($ctx, 20000);
    b59fPaga($ctx, $fattura, '2026-03-10', $ctx[1]);
    b59fAggiorna($this, $ctx)->assertRedirect();
    $delega = DelegaF24::where('condominio_id', $ctx[0]->id)->sole();
    b59fVersa($this, $ctx, $delega, '2026-04-16');

    // € 20.000,00 × 4 % = € 800,00.
    b59fIndice($this, $ctx)->assertInertia(fn (AssertableInertia $page) => $page
        ->has('deleghe.data', 1)
        ->where('deleghe.data.0.id', $delega->id)
        ->where('deleghe.data.0.stato', 'versata')
        ->where('deleghe.data.0.esercizio_non_corrente', null)
        ->where('riepilogo.versato_cents', 80000)
        ->where('riepilogo.da_versare_cents', 0)
    );
});

/** Annulla una delega non ancora versata, come fa il modulo di `F24Show.vue`. */
function b59fAnnulla($test, array $ctx, DelegaF24 $delega, string $motivo = 'Versata fuori dal programma')
{
    return $test->actingAs($test->admin)
        ->post(route('admin.gestionale.f24.annulla', [$ctx[0]->id, $delega->id]), ['motivo' => $motivo])
        ->assertSessionHasNoErrors();
}

test('Coda 314 — una ritenuta di un esercizio chiuso mai messa in una delega ricompare con la sua scadenza di legge anche passata; annullare la delega la toglie da «Da versare» e da «Scadute» ma non dal pulsante, che la rifà (come dice la guida)', function () {
    Carbon::setTestNow('2026-05-05 10:00:00');
    $ctx = b59fCtx('2025-07-01', '2026-06-30');
    $fattura = b59fFattura($ctx, 2500);
    b59fPaga($ctx, $fattura, '2026-05-10', $ctx[1]);

    // Il versamento di giugno, fuori dal programma, il programma non lo conosce. Si passa al nuovo esercizio senza mai
    // aver premuto «Aggiorna scadenze»: la ritenuta non è in nessuna delega.
    b59fPassaAlNuovo($ctx, 'Esercizio 2026/2027', '2026-07-01', '2027-06-30');
    Carbon::setTestNow('2026-10-30 10:00:00');

    // € 2.500,00 × 4 % = € 100,00 operati a maggio, sotto soglia: si versano entro il 16 giugno (martedì 16/06/2026).
    b59fIndice($this, $ctx)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('da_calcolare.numero', 1)
        ->where('da_calcolare.totale_cents', 10000)
        ->where('riepilogo.scadute', 0)
        ->where('riepilogo.da_versare_cents', 0)
    );

    b59fAggiorna($this, $ctx)->assertRedirect();
    $delega = DelegaF24::where('condominio_id', $ctx[0]->id)->sole();

    // Ricompare con la scadenza di legge, già passata.
    b59fIndice($this, $ctx)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('riepilogo.scadute', 1)
        ->where('riepilogo.da_versare_cents', 10000)
        ->where('riepilogo.prossima_scadenza', '2026-06-16')
        ->where('da_calcolare.numero', 0)
    );

    // Annullarla la toglie da «Da versare» e da «Scadute», ma il pulsante la conta ancora…
    b59fAnnulla($this, $ctx, $delega);
    b59fIndice($this, $ctx)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('riepilogo.scadute', 0)
        ->where('riepilogo.da_versare_cents', 0)
        ->where('da_calcolare.numero', 1)
        ->where('da_calcolare.totale_cents', 10000)
    );

    // …e il ricalcolo la rifà.
    b59fAggiorna($this, $ctx)->assertRedirect();
    $rifatta = DelegaF24::where('condominio_id', $ctx[0]->id)->aperte()->sole();
    expect($rifatta->id)->not->toBe($delega->id)
        ->and($rifatta->totale_debito)->toBe(10000)
        ->and($rifatta->data_scadenza->toDateString())->toBe('2026-06-16');
});

test('Coda 314 — la ritenuta di giugno di un esercizio chiuso, mai in una delega, si somma a quella di luglio del nuovo: annullando la delega da € 600,00 il pulsante le conta tutte e due e il ricalcolo le rifà insieme (come dice la guida)', function () {
    Carbon::setTestNow('2026-06-01 10:00:00');
    $ctx = b59fCtx('2025-07-01', '2026-06-30');
    $giugno = b59fFattura($ctx, 7500);
    $luglio = b59fFattura($ctx, 7500);
    b59fPaga($ctx, $giugno, '2026-06-15', $ctx[1]);

    $nuovo = b59fPassaAlNuovo($ctx, 'Esercizio 2026/2027', '2026-07-01', '2027-06-30');
    b59fPaga($ctx, $luglio, '2026-07-10', $nuovo);
    Carbon::setTestNow('2026-07-20 10:00:00');

    // Giugno € 300,00 + luglio € 300,00 = € 600,00 ≥ € 500,00 raggiunti a luglio: 16/08/2026 (domenica) → 17/08/2026.
    b59fAggiorna($this, $ctx)->assertRedirect();
    $delega = DelegaF24::where('condominio_id', $ctx[0]->id)->aperte()->sole();
    expect($delega->totale_debito)->toBe(60000)
        ->and($delega->data_scadenza->toDateString())->toBe('2026-08-17');

    // Se giugno era già stato versato fuori dal programma, annullare la delega toglie dai contatori anche luglio…
    b59fAnnulla($this, $ctx, $delega, 'Giugno versato fuori dal programma');
    b59fIndice($this, $ctx)->assertInertia(fn (AssertableInertia $page) => $page
        ->where('riepilogo.da_versare_cents', 0)
        ->where('da_calcolare.numero', 2)
        ->where('da_calcolare.totale_cents', 60000)
    );

    // …e il ricalcolo le rifà insieme, in una delega sola.
    b59fAggiorna($this, $ctx)->assertRedirect();
    $rifatta = DelegaF24::where('condominio_id', $ctx[0]->id)->aperte()->sole();
    expect($rifatta->totale_debito)->toBe(60000)
        ->and($rifatta->data_scadenza->toDateString())->toBe('2026-08-17');
});

test('Coda 314 — la scheda di una delega di un esercizio non corrente dice il suo esercizio; quella dell\'esercizio corrente no', function () {
    Carbon::setTestNow('2026-11-05 10:00:00');
    $ctx = b59fCtx();
    $fattura = b59fFattura($ctx, 2500);
    b59fPaga($ctx, $fattura, '2026-11-10', $ctx[1]);
    b59fAggiorna($this, $ctx)->assertRedirect();
    $delega = DelegaF24::where('condominio_id', $ctx[0]->id)->sole();

    $vedi = fn () => $this->actingAs($this->admin)
        ->get(route('admin.gestionale.f24.show', [$ctx[0]->id, $delega->id]))
        ->assertOk();

    $vedi()->assertInertia(fn (AssertableInertia $page) => $page->where('esercizio_non_corrente', null));

    b59fPassaAlNuovo($ctx, 'Esercizio 2027', '2027-01-01', '2027-12-31');
    Carbon::setTestNow('2027-01-10 10:00:00');

    $vedi()->assertInertia(fn (AssertableInertia $page) => $page->where('esercizio_non_corrente', 'Esercizio 2026'));
});

test('Coda 314 — il versamento di una bozza del 2026 registrato dalla sua scheda a gennaio 2027 scrive nell\'esercizio della delega, come dice la scheda', function () {
    // Decisione 153.6: l'esercizio della scrittura del versamento resta quello della delega (`ConfermaVersamentoF24Action`)
    // fino al modulo di chiusura (Coda 295). La frase della scheda lo dice; questo test la tiene vera.
    Carbon::setTestNow('2026-12-01 10:00:00');
    $ctx = b59fCtx();
    $fattura = b59fFattura($ctx, 6000);
    b59fPaga($ctx, $fattura, '2026-12-20', $ctx[1]);
    Carbon::setTestNow('2026-12-21 10:00:00');
    b59fAggiorna($this, $ctx)->assertRedirect();
    $delega = DelegaF24::where('condominio_id', $ctx[0]->id)->sole();

    b59fPassaAlNuovo($ctx, 'Esercizio 2027', '2027-01-01', '2027-12-31');
    Carbon::setTestNow('2027-01-18 10:00:00');

    $this->actingAs($this->admin)
        ->post(route('admin.gestionale.f24.versa', [$ctx[0]->id, $delega->id]), [
            'data_versamento' => '2027-01-18',
            'conto_corrente_id' => $ctx[4],
        ])
        ->assertSessionHasNoErrors();

    $delega->refresh();
    expect($delega->stato)->toBe(StatoDelegaF24::VERSATA)
        ->and((int) $delega->totale_debito)->toBe(24000)
        ->and((int) $delega->scrittura->esercizio_id)->toBe($ctx[1]->id)
        ->and($delega->scrittura->data_competenza->toDateString())->toBe('2027-01-18');
});

test('Coda 314 — lo storno, a gennaio 2027, del versamento di una delega del 2026 scrive nell\'esercizio della delega, come dice la scheda', function () {
    // Come per il versamento (decisione 153.6), la scrittura di storno sta nell'esercizio della delega
    // (`StornaVersamentoF24Action`) fino al modulo di chiusura (Coda 295). La frase della scheda di una delega versata di un
    // altro esercizio lo dice; questo test la tiene vera.
    Carbon::setTestNow('2026-11-05 10:00:00');
    $ctx = b59fCtx();
    $fattura = b59fFattura($ctx, 2500);
    b59fPaga($ctx, $fattura, '2026-11-10', $ctx[1]);
    Carbon::setTestNow('2026-11-20 10:00:00');
    b59fAggiorna($this, $ctx)->assertRedirect();
    $delega = DelegaF24::where('condominio_id', $ctx[0]->id)->sole();
    b59fVersa($this, $ctx, $delega, '2026-12-16');

    b59fPassaAlNuovo($ctx, 'Esercizio 2027', '2027-01-01', '2027-12-31');
    Carbon::setTestNow('2027-01-10 10:00:00');

    $this->actingAs($this->admin)
        ->post(route('admin.gestionale.f24.storna', [$ctx[0]->id, $delega->id]), ['motivo' => 'Conto sbagliato'])
        ->assertSessionHasNoErrors();

    $storno = ScritturaContabile::where('scrittura_padre_id', $delega->fresh()->scrittura_contabile_id)->sole();
    expect($delega->fresh()->stato)->toBe(StatoDelegaF24::STORNATA)
        ->and((int) $storno->esercizio_id)->toBe($ctx[1]->id)
        ->and($storno->data_competenza->toDateString())->toBe('2027-01-10');
});
