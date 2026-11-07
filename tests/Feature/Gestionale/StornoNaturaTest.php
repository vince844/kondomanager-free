<?php

/**
 * Lo storno di un incasso dice la sua natura, e la sceglie chi storna (decisione 217, D1).
 *
 * - **Errore di registrazione** (il versamento non c'è mai stato): ogni rettifica della famiglia ha la data della scrittura stornata, e
 *   la riga negativa della pivot la stessa; le figlie dell'emissione che lo storno scrive restituendo un credito riportato già usato
 *   hanno la data più tarda fra quella dell'emissione e quella dell'incasso stornato. Un errore non si retrodata dentro un esercizio
 *   chiuso: la guardia guarda l'esercizio che **contiene la data**, e se è chiuso, o non c'è, lo storno vale da oggi.
 * - **Versamento tornato indietro** (assegno insoluto, addebito respinto, bonifico richiamato): la rettifica e la riga negativa della
 *   pivot hanno la data dello storno, il giorno dell'amministratore.
 * - La natura sta sull'incasso stornato (`natura_storno`); la rettifica dice chi ha stornato (`created_by`). Per un incasso il server
 *   rifiuta lo storno senza la natura; lo storno di un rimborso non la chiede e resta com'è.
 */

use App\Models\Esercizio;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';
require_once __DIR__.'/LetturaDebitiProva.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

/** Bianca, una rata da 30000 con scadenza 30/11/2026, emessa il 01/11/2026; il suo versamento del 10/12/2026. */
function snBianca($test, bool $incassa = true): array
{
    $test->travelTo('2026-10-30 09:00:00');
    $s = lcdScenario(['B' => ['bianca', 1000]], 1, 'prima_rata', [], 30000, '2026-11-30', 30);
    $test->travelTo('2026-11-01 10:00:00');
    lcdEmetti($test, $s, [1], '2026-11-01');
    if ($incassa) {
        $test->travelTo('2026-12-10 10:00:00');
        $s['incasso'] = lcdIncassa($test, $s, 'bianca', [lcdQuota($s, 'bianca', 1) => 30000], '2026-12-10');
    }

    return $s;
}

/** L'esercizio 2027 del condominio dello scenario, aperto. */
function snEsercizio2027(array $s): Esercizio
{
    $e = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    legaAEsercizio($e, $s['g']->id);

    return $e;
}

/** La rettifica dell'incasso, e le date delle sue righe negative nella pivot. */
function snRettifica(int $incasso): array
{
    $r = DB::table('scritture_contabili')->where('scrittura_padre_id', $incasso)->where('tipo_movimento', 'rettifica')->first();

    return [
        'data'     => substr((string) $r->data_competenza, 0, 10),
        'pivot'    => DB::table('quota_scrittura')->where('scrittura_contabile_id', $r->id)->pluck('data_pagamento')->map(fn ($d) => substr((string) $d, 0, 10))->unique()->values()->all(),
        'chi'      => $r->created_by === null ? null : (int) $r->created_by,
        'natura'   => DB::table('scritture_contabili')->where('id', $incasso)->value('natura_storno'),
    ];
}

it('errore di registrazione: la rettifica e la riga negativa della pivot hanno la data del versamento; la natura sta sull\'incasso; la rettifica dice chi ha stornato', function () {
    $s = snBianca($this);
    $this->travelTo('2027-01-15 11:00:00');

    lcdStorna($this, $s, $s['incasso'], ['natura_storno' => 'errore_registrazione']);

    expect(snRettifica($s['incasso']))->toBe(['data' => '2026-12-10', 'pivot' => ['2026-12-10'], 'chi' => (int) $this->user->id, 'natura' => 'errore_registrazione'])
        ->and(lcdRiga(lcdLeggi($s, 'bianca', '2026-12-31'), $s, 'B', 1))->toMatchArray(['residuo' => 30000]);
});

it('versamento tornato indietro: la rettifica e anche la riga negativa della pivot hanno la data dello storno', function () {
    $s = snBianca($this);
    $this->travelTo('2027-01-15 11:00:00');

    lcdStorna($this, $s, $s['incasso'], ['natura_storno' => 'versamento_tornato']);

    expect(snRettifica($s['incasso']))->toBe(['data' => '2027-01-15', 'pivot' => ['2027-01-15'], 'chi' => (int) $this->user->id, 'natura' => 'versamento_tornato'])
        // Saldata al 31/12: la lettura sommata nel database (dalla 1.12.0-beta.4) non porta più la chiave a zero.
        ->and(lcdRiga(lcdLeggi($s, 'bianca', '2026-12-31'), $s, 'B', 1))->toBeNull();
});

it('per un incasso lo storno senza la natura, o con una natura che non c\'è, non si fa', function () {
    $s = snBianca($this);
    $this->travelTo('2027-01-15 11:00:00');

    $this->actingAs($this->user)->from('/incassi')->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $s['incasso']]))->assertSessionHasErrors('natura_storno');
    $this->actingAs($this->user)->from('/incassi')->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $s['incasso']]), ['natura_storno' => 'ripensamento'])->assertSessionHasErrors('natura_storno');

    expect(DB::table('scritture_contabili')->where('id', $s['incasso'])->value('stato'))->toBe('registrata')
        ->and(DB::table('scritture_contabili')->where('scrittura_padre_id', $s['incasso'])->count())->toBe(0);
});

it('l\'azione dello storno, l\'unica porta, non storna un incasso senza la natura e non scrive niente', function () {
    $s = snBianca($this);
    $incasso = \App\Models\Gestionale\ScritturaContabile::with(['righe', 'quotePagate', 'figlie'])->findOrFail($s['incasso']);

    expect(fn () => app(\App\Actions\Gestionale\Movimenti\StornoIncassoRateAction::class)->execute($incasso, $s['c']))->toThrow(\InvalidArgumentException::class)
        ->and(DB::table('scritture_contabili')->where('id', $s['incasso'])->value('stato'))->toBe('registrata')
        ->and(DB::table('scritture_contabili')->where('scrittura_padre_id', $s['incasso'])->count())->toBe(0);
});

it('un errore in un esercizio chiuso (quello che contiene la data) vale da oggi, la natura resta scritta, e la conferma lo dice prima', function () {
    $s = snBianca($this);
    snEsercizio2027($s);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $this->travelTo('2027-01-15 11:00:00');

    $anteprima = $this->actingAs($this->user)->getJson(route('admin.gestionale.movimenti-rate.storno.anteprima', [$s['c'], $s['incasso']]))->assertOk()->json('natura');
    lcdStorna($this, $s, $s['incasso'], ['natura_storno' => 'errore_registrazione']);

    expect($anteprima)->toBe([
        'errore_registrazione' => ['vale_dal' => '2027-01-15', 'esercizio_chiuso' => true],
        'versamento_tornato'   => ['vale_dal' => '2027-01-15'],
    ])
        ->and(snRettifica($s['incasso']))->toMatchArray(['data' => '2027-01-15', 'pivot' => ['2027-01-15'], 'natura' => 'errore_registrazione']);
});

it('la conferma dice da quando vale ogni natura: l\'errore dalla data del versamento, il versamento tornato da oggi', function () {
    $s = snBianca($this);
    $this->travelTo(Carbon::parse('2027-01-01 00:30:00', 'Europe/Rome'));

    expect($this->actingAs($this->user)->getJson(route('admin.gestionale.movimenti-rate.storno.anteprima', [$s['c'], $s['incasso']]))->assertOk()->json('natura'))->toBe([
        'errore_registrazione' => ['vale_dal' => '2026-12-10', 'esercizio_chiuso' => false],
        'versamento_tornato'   => ['vale_dal' => '2027-01-01'],
    ]);
});

it('un errore con la data fuori da ogni esercizio del condominio vale da oggi, e la conferma lo dice', function () {
    $s = snBianca($this, false);
    $this->travelTo('2026-12-10 10:00:00');
    // Un versamento con la data del 20/12/2025: il condominio ha solo l'esercizio 2026.
    $incasso = lcdIncassa($this, $s, 'bianca', [lcdQuota($s, 'bianca', 1) => 30000], '2025-12-20');
    $this->travelTo('2026-12-15 10:00:00');

    $anteprima = $this->actingAs($this->user)->getJson(route('admin.gestionale.movimenti-rate.storno.anteprima', [$s['c'], $incasso]))->assertOk()->json('natura.errore_registrazione');
    lcdStorna($this, $s, $incasso, ['natura_storno' => 'errore_registrazione']);

    expect($anteprima)->toBe(['vale_dal' => '2026-12-15', 'esercizio_chiuso' => true])
        ->and(snRettifica($incasso))->toMatchArray(['data' => '2026-12-15', 'natura' => 'errore_registrazione']);
});

it('un versamento di dicembre registrato a gennaio sta nell\'esercizio nuovo, ma la sua data è nel 2026 chiuso: l\'errore vale da oggi', function () {
    $s = snBianca($this, false);
    $nuovo = snEsercizio2027($s);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $this->travelTo('2027-01-10 11:00:00');
    $incasso = lcdIncassa($this, $s, 'bianca', [lcdQuota($s, 'bianca', 1) => 30000], '2026-12-20');
    expect((int) DB::table('scritture_contabili')->where('id', $incasso)->value('esercizio_id'))->toBe((int) $nuovo->id);
    $this->travelTo('2027-01-15 11:00:00');

    lcdStorna($this, $s, $incasso, ['natura_storno' => 'errore_registrazione']);

    expect(snRettifica($incasso))->toMatchArray(['data' => '2027-01-15', 'natura' => 'errore_registrazione']);
});

it('un versamento del 03/01/2027 registrato con il 2026 ancora aperto, stornato dopo la chiusura del 2026: la sua data è nel 2027 aperto, l\'errore si retrodata', function () {
    $s = snBianca($this, false);
    snEsercizio2027($s);
    $this->travelTo('2027-01-10 11:00:00');
    $incasso = lcdIncassa($this, $s, 'bianca', [lcdQuota($s, 'bianca', 1) => 30000], '2027-01-03');
    expect((int) DB::table('scritture_contabili')->where('id', $incasso)->value('esercizio_id'))->toBe((int) $s['e']->id);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $this->travelTo('2027-01-15 11:00:00');

    lcdStorna($this, $s, $incasso, ['natura_storno' => 'errore_registrazione']);

    expect(snRettifica($incasso))->toMatchArray(['data' => '2027-01-03', 'natura' => 'errore_registrazione']);
});

it('con l\'errore la figlia dell\'emissione che restituisce il credito riportato prende la data più tarda fra l\'emissione e il versamento', function () {
    // Il credito riportato di Ugo (20000, rata 0) usato per 5000 sulla rata 1 il 10/03 con la rata 0 in bozza; la rata 0 si emette con data
    // 15/03 in un caso, con data 01/03 nell'altro. Lo storno come errore restituisce il credito con una figlia dell'emissione.
    foreach (['2026-03-15' => '2026-03-15', '2026-03-01' => '2026-03-10'] as $dataEmissione => $attesa) {
        $this->travelTo('2026-03-01 10:00:00');
        $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
        lcdEmetti($this, $s, [1, 2], '2026-02-28');
        $this->travelTo('2026-03-10 10:00:00');
        $incasso = lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 0) => -5000, lcdQuota($s, 'ugo', 1) => 5000], '2026-03-10');
        $this->travelTo('2026-03-16 10:00:00');
        lcdEmetti($this, $s, [0], $dataEmissione);
        $emissione = (int) DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 0))->value('scrittura_contabile_id');
        $this->travelTo('2026-03-21 10:00:00');

        lcdStorna($this, $s, $incasso, ['natura_storno' => 'errore_registrazione']);

        $figlia = DB::table('scritture_contabili')->where('scrittura_padre_id', $emissione)->where('tipo_movimento', 'emissione_rata')->first();
        expect($figlia)->not->toBeNull()
            ->and(substr((string) $figlia->data_competenza, 0, 10))->toBe($attesa)
            ->and((int) $figlia->created_by)->toBe((int) $this->user->id);
    }
});

it('con l\'errore anche la figlia che porta a giornale la quota rimasta fuori prende la data più tarda fra l\'emissione e il versamento', function () {
    $this->travelTo('2026-03-01 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'rata_zero', [['ugo', 'A', -20000], ['bice', 'B', 5000]]);
    lcdEmetti($this, $s, [1, 2, 3, 4], '2026-02-28');
    $this->travelTo('2026-03-10 10:00:00');
    $incasso = lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 0) => -20000, lcdQuota($s, 'ugo', 1) => 5000, lcdQuota($s, 'ugo', 2) => 5000, lcdQuota($s, 'ugo', 3) => 5000, lcdQuota($s, 'ugo', 4) => 5000], '2026-03-10');
    $this->travelTo('2026-03-15 10:00:00');
    lcdEmetti($this, $s, [0], '2026-03-15');
    $emissione = (int) DB::table('rate_quote')->where('id', lcdQuota($s, 'bice', 0))->value('scrittura_contabile_id');
    $this->travelTo('2026-03-21 10:00:00');

    lcdStorna($this, $s, $incasso, ['natura_storno' => 'errore_registrazione']);

    $figlia = DB::table('scritture_contabili')->where('scrittura_padre_id', $emissione)->where('tipo_movimento', 'emissione_rata')->first();
    expect($figlia)->not->toBeNull()
        ->and(substr((string) $figlia->data_competenza, 0, 10))->toBe('2026-03-15');
});

it('con l\'errore in un esercizio chiuso la figlia dell\'emissione segue la data da cui vale la rettifica: la lettura a una data di mezzo non conta il credito due volte', function () {
    // Il versamento del 20/12/2025, registrato a gennaio, pagato con il credito riportato della rata 0 in bozza; la rata 0 emessa il
    // 15/01/2026; il 2025 chiuso dopo. Lo storno come errore vale da oggi (21/03/2026): anche la figlia che restituisce il credito.
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    $e2025 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2025', 'data_inizio' => '2025-01-01', 'data_fine' => '2025-12-31', 'stato' => 'aperto']);
    legaAEsercizio($e2025, $s['g']->id);
    lcdEmetti($this, $s, [1, 2], '2026-01-01');
    $this->travelTo('2026-01-10 10:00:00');
    $incasso = lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 0) => -5000, lcdQuota($s, 'ugo', 1) => 5000], '2025-12-20');
    $this->travelTo('2026-01-16 10:00:00');
    lcdEmetti($this, $s, [0], '2026-01-15');
    $emissione = (int) DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 0))->value('scrittura_contabile_id');
    DB::table('esercizi')->where('id', $e2025->id)->update(['stato' => 'chiuso']);
    $this->travelTo('2026-03-21 10:00:00');
    $prima = lcdLeggi($s, 'ugo', '2026-02-01')['totali'];

    lcdStorna($this, $s, $incasso, ['natura_storno' => 'errore_registrazione']);

    $figlia = DB::table('scritture_contabili')->where('scrittura_padre_id', $emissione)->where('tipo_movimento', 'emissione_rata')->value('data_competenza');
    $dopo = lcdLeggi($s, 'ugo', '2026-02-01')['totali'];
    expect(substr((string) $figlia, 0, 10))->toBe('2026-03-21')
        ->and($dopo['crediti'])->toBe($prima['crediti'])
        ->and($dopo['netto'])->toBe($prima['netto']);
});

it('con il versamento tornato la figlia dell\'emissione non sta prima dell\'emissione di cui è figlia: l\'emissione con data futura', function () {
    // Il credito riportato usato il 10/03 con la rata 0 in bozza; la rata 0 emessa con data 01/06 (futura); lo storno del 21/03 come
    // versamento tornato: la figlia prende la più tarda fra l'emissione e oggi.
    $this->travelTo('2026-03-01 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    lcdEmetti($this, $s, [1, 2], '2026-02-28');
    $this->travelTo('2026-03-10 10:00:00');
    $incasso = lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 0) => -5000, lcdQuota($s, 'ugo', 1) => 5000], '2026-03-10');
    $this->travelTo('2026-03-16 10:00:00');
    lcdEmetti($this, $s, [0], '2026-06-01');
    $emissione = (int) DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 0))->value('scrittura_contabile_id');
    $this->travelTo('2026-03-21 10:00:00');

    lcdStorna($this, $s, $incasso, ['natura_storno' => 'versamento_tornato']);

    $figlia = DB::table('scritture_contabili')->where('scrittura_padre_id', $emissione)->where('tipo_movimento', 'emissione_rata')->value('data_competenza');
    expect(substr((string) $figlia, 0, 10))->toBe('2026-06-01');
});

it('lo storno di un rimborso non chiede la natura e resta com\'è: la riga negativa della pivot ha la data del rimborso, la natura non c\'è', function () {
    $this->travelTo('2026-03-01 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    $this->travelTo('2026-03-06 10:00:00');
    $this->actingAs($this->user)->post(route('admin.gestionale.anagrafiche.rimborsi.store', [$s['c'], $s['persone']['ugo']]),
        ['rata_quote_id' => lcdQuota($s, 'ugo', 0), 'cassa_id' => $s['cassa']->id, 'data_rimborso' => '2026-03-05', 'importo' => '50,00'])->assertSessionHasNoErrors();
    $rimborso = (int) DB::table('scritture_contabili')->where('tipo_movimento', 'rimborso_condomino')->value('id');
    $this->travelTo('2026-03-21 10:00:00');

    $this->actingAs($this->user)->from('/estratto')->post(route('admin.gestionale.movimenti-rate.storno', [$s['c'], $rimborso]))->assertSessionHasNoErrors();

    expect(snRettifica($rimborso))->toBe(['data' => '2026-03-21', 'pivot' => ['2026-03-05'], 'chi' => (int) $this->user->id, 'natura' => null])
        ->and(DB::table('scritture_contabili')->where('id', $rimborso)->value('stato'))->toBe('annullata');
});
