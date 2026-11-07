<?php

/**
 * La situazione debitoria del modulo dell'incasso con le regole comuni (1.12.0-beta.3): `scaduta` dal predicato del giorno (il
 * giorno della scadenza non è scaduto, nel giorno dell'amministratore), `pregresso` dalla rata 0 e non dalla descrizione, le righe
 * nell'ordine che il programma propone (per scadenza, emesse o no; a pari scadenza la rata 0, poi le emesse, poi le bozze; poi
 * l'id della rata) con la loro posizione fra le righe a debito. E il netto per (persona, rata) uguale a quello della lettura comune.
 *
 * Centesimi nei calcoli; la risposta porta euro.
 */

use App\Models\User;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

/** Le righe della situazione debitoria di una persona, per id della rata. */
function sdoRighe($test, array $s, string $chi): array
{
    $json = $test->actingAs($test->user)->getJson(route('admin.gestionale.situazione-debitoria', ['condominio' => $s['c']->id, 'anagrafica_id' => $s['persone'][$chi]->id]))
        ->assertOk()->json('rate');

    return $json;
}

it('alle 11:00 del 15/12 la rata che scade il 15/12 non è scaduta; quella del 14/12 sì; la bozza del 10/12 è scaduta per data', function () {
    $s = lcdRossi($this);
    $this->travelTo(Carbon::parse('2026-12-15 11:00:00', 'Europe/Rome'));

    $righe = collect(sdoRighe($this, $s, 'rossi'))->keyBy('rata_padre_id');
    expect($righe[lcdRata($s, 1)]['scaduta'])->toBeTrue()
        ->and($righe[lcdRata($s, 2)]['scaduta'])->toBeFalse()
        ->and($righe[lcdRata($s, 3)]['scaduta'])->toBeFalse()
        ->and($righe[lcdRata($s, 4)]['scaduta'])->toBeTrue()
        ->and($righe[lcdRata($s, 4)]['is_emitted'])->toBeFalse()
        ->and($righe[lcdRata($s, 5)]['scaduta'])->toBeFalse();
});

it('alle 00:30 del 16/12 a Roma (le 23:30 del 15/12 in UTC) la rata del 15/12 è scaduta: il giorno è quello dell\'amministratore', function () {
    $s = lcdRossi($this);
    $this->travelTo(Carbon::parse('2026-12-16 00:30:00', 'Europe/Rome'));

    $righe = collect(sdoRighe($this, $s, 'rossi'))->keyBy('rata_padre_id');
    expect($righe[lcdRata($s, 2)]['scaduta'])->toBeTrue()
        ->and($righe[lcdRata($s, 3)]['scaduta'])->toBeFalse();
});

it('il pregresso è la rata 0, qualunque sia la descrizione o il tipo della quota; una rata con «saldo» nella descrizione non lo è', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', 5000]]);
    // Un piano di prima del 19/02/2026: la quota della rata 0 ha `tipo = 'ordinaria'`, e la descrizione non dice niente.
    DB::table('rate_quote')->where('rata_id', lcdRata($s, 0))->update(['tipo' => 'ordinaria']);
    DB::table('rate')->where('id', lcdRata($s, 0))->update(['descrizione' => 'Prima scadenza']);
    DB::table('rate')->where('id', lcdRata($s, 3))->update(['descrizione' => 'Saldo lavori']);

    $righe = collect(sdoRighe($this, $s, 'ugo'))->keyBy('rata_padre_id');
    expect($righe[lcdRata($s, 0)]['pregresso'])->toBeTrue()
        ->and($righe[lcdRata($s, 3)]['pregresso'])->toBeFalse()
        ->and($righe[lcdRata($s, 1)]['pregresso'])->toBeFalse();
});

it('le righe arrivano nell\'ordine proposto: per scadenza, emesse o no; a pari scadenza la rata 0, poi le emesse, poi le bozze; le righe a credito senza posizione', function () {
    $this->travelTo('2026-03-20 10:00:00');
    // Ugo ha un pregresso a debito di 5000 (rata 0) e, sull'unità B, un credito riportato di 2000 che sta sulla rata 0 di B.
    $s = lcdScenario(['A' => ['ugo', 500], 'B' => ['ugo', 500]], 12, 'rata_zero', [['ugo', 'A', 5000], ['ugo', 'B', -2000]]);
    lcdEmetti($this, $s, [1], '2026-02-28');
    // Rata 0, 1 (emessa) e 2 (bozza) con la stessa scadenza; la rata 3, in bozza, scade prima di tutte.
    foreach ([0 => '2026-02-05', 1 => '2026-02-05', 2 => '2026-02-05', 3 => '2026-01-20'] as $n => $data) {
        DB::table('rate')->where('id', lcdRata($s, $n))->update(['data_scadenza' => $data]);
        DB::table('rate_quote')->where('rata_id', lcdRata($s, $n))->update(['data_scadenza' => $data]);
    }

    $righe = collect(sdoRighe($this, $s, 'ugo'));
    $numero = fn ($r) => (int) DB::table('rate')->where('id', $r['rata_padre_id'])->value('numero_rata');
    // La rata 0 di Ugo: +5000 su A e −2000 su B, netto 3000 a debito. Le rate 4–12 scadono dopo.
    expect($righe->take(4)->map($numero)->all())->toBe([3, 0, 1, 2])
        ->and($righe->take(4)->pluck('ordine')->all())->toBe([1, 2, 3, 4])
        ->and($righe->pluck('ordine')->filter()->values()->all())->toBe(range(1, $righe->count()));
});

it('una riga solo a credito non ha posizione nell\'ordine', function () {
    $s = lcdRossi($this);
    $this->travelTo(Carbon::parse('2026-12-15 11:00:00', 'Europe/Rome'));

    $righe = collect(sdoRighe($this, $s, 'rossi'))->keyBy('rata_padre_id');
    // Le rate 6–12, in bozza, scadono dal 05/06 al 05/12: vengono prima della rata 4 (10/12), poi la 1 (14/12).
    expect($righe[lcdRata($s, 0)]['ordine'])->toBeNull()
        ->and($righe[lcdRata($s, 0)]['pregresso'])->toBeTrue()
        ->and($righe[lcdRata($s, 6)]['ordine'])->toBe(1)
        ->and($righe[lcdRata($s, 4)]['ordine'])->toBe(8)
        ->and($righe[lcdRata($s, 1)]['ordine'])->toBe(9);
});

it('il netto per (persona, rata) della lettura comune, su tutte le unità della persona, è il residuo della situazione debitoria sulle quote a giornale', function () {
    $this->travelTo('2026-03-20 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 600], 'G' => ['ugo', 200], 'B' => ['bice', 200]], 12, 'prima_rata', [['ugo', 'A', -7000]]);
    lcdEmetti($this, $s, [1, 2], '2026-02-28');
    lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 2) => 1500], '2026-03-10');

    $lettura = app(LetturaDebiti::class)->perPersona((int) $s['c']->id, (int) $s['persone']['ugo']->id);
    $nettoPerRata = [];
    foreach ($lettura['rate'] as $r) {
        $nettoPerRata[$r['rata_id']] = ($nettoPerRata[$r['rata_id']] ?? 0) + $r['residuo'] - $r['credito'];
    }
    $situazione = collect(sdoRighe($this, $s, 'ugo'))->filter(fn ($r) => $r['is_emitted'])
        ->mapWithKeys(fn ($r) => [(int) $r['rata_padre_id'] => (int) round($r['residuo'] * 100)])->all();
    ksort($nettoPerRata);
    ksort($situazione);

    // Rata 1: A 6000 − 7000 = −1000, G 2000 → 1000; rata 2: A 6000 + G 2000 − 1500 = 6500.
    expect($situazione)->toBe([lcdRata($s, 1) => 1000, lcdRata($s, 2) => 6500])
        ->and($nettoPerRata)->toBe($situazione);
});
