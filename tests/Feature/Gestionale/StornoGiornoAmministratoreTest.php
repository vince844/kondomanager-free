<?php

/**
 * Lo storno di un incasso sta alla data della sua rettifica, e quella data è il giorno dell'amministratore (1.12.0-beta.3): alle
 * 00:30 del 01/01 a Roma il server è ancora al 31/12 in UTC, e la rettifica finiva nell'anno prima. Vale anche per la scrittura
 * figlia dell'emissione che lo storno scrive quando restituisce un credito riportato già usato.
 */

use App\Models\User;
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

it('alle 00:30 del 01/01/2027 a Roma la rettifica dello storno ha la data del 01/01/2027, e lo storno vale dal 2027', function () {
    $this->travelTo('2026-10-30 09:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 1, 'prima_rata', [], 30000, '2026-11-30', 30);
    $this->travelTo('2026-11-01 10:00:00');
    lcdEmetti($this, $s, [1], '2026-11-01');
    $this->travelTo('2026-12-10 10:00:00');
    $incasso = lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 1) => 30000], '2026-12-10');

    $this->travelTo(Carbon::parse('2027-01-01 00:30:00', 'Europe/Rome'));
    expect(now()->toDateString())->toBe('2026-12-31');
    lcdStorna($this, $s, $incasso);

    $rettifica = DB::table('scritture_contabili')->where('scrittura_padre_id', $incasso)->where('tipo_movimento', 'rettifica')->first();
    expect(substr((string) $rettifica->data_competenza, 0, 10))->toBe('2027-01-01')
        // Al 31/12 il versamento c'era: lo storno, un versamento tornato indietro, vale dal giorno in cui si fa. La rata saldata non è fra le
        // righe: la lettura sommata nel database (dalla 1.12.0-beta.4) non porta più la chiave a zero.
        ->and(lcdRiga(lcdLeggi($s, 'ugo', '2026-12-31'), $s, 'A', 1))->toBeNull()
        ->and(lcdRiga(lcdLeggi($s, 'ugo', '2027-01-01'), $s, 'A', 1))->toMatchArray(['residuo' => 30000]);
});

it('la figlia dell\'emissione che restituisce il credito riportato già usato ha anche lei il giorno dell\'amministratore', function () {
    // Il credito riportato di Ugo (20000, rata 0) usato per 5000 sulla rata 1 con la rata 0 in bozza; poi la rata 0 si emette; lo storno
    // della compensazione, alle 00:30 del 21/03 a Roma, restituisce il credito con una figlia dell'emissione della rata 0.
    $this->travelTo('2026-03-01 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 1000]], 12, 'rata_zero', [['ugo', 'A', -20000]]);
    lcdEmetti($this, $s, [1, 2], '2026-02-28');
    $this->travelTo('2026-03-10 10:00:00');
    $incasso = lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 0) => -5000, lcdQuota($s, 'ugo', 1) => 5000], '2026-03-10');
    $this->travelTo('2026-03-15 10:00:00');
    lcdEmetti($this, $s, [0], '2026-03-15');
    $emissione = (int) DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 0))->value('scrittura_contabile_id');

    $this->travelTo(Carbon::parse('2026-03-21 00:30:00', 'Europe/Rome'));
    lcdStorna($this, $s, $incasso);

    $figlia = DB::table('scritture_contabili')->where('scrittura_padre_id', $emissione)->where('tipo_movimento', 'emissione_rata')->first();
    expect($figlia)->not->toBeNull()
        ->and(substr((string) $figlia->data_competenza, 0, 10))->toBe('2026-03-21');
});

it('anche la figlia che porta a giornale la quota rimasta fuori dall\'emissione ha il giorno dell\'amministratore', function () {
    // Ugo usa tutto il suo credito riportato (20000) sulle rate da 1 a 4 con la rata 0 in bozza; la rata 0 va a giornale per Bice (pregresso
    // a debito) e la quota di Ugo, senza niente da portare, resta fuori. Lo storno della compensazione, alle 00:30 del 21/03 a Roma, la
    // porta a giornale con una figlia dell'emissione.
    $this->travelTo('2026-03-01 10:00:00');
    $s = lcdScenario(['A' => ['ugo', 500], 'B' => ['bice', 500]], 12, 'rata_zero', [['ugo', 'A', -20000], ['bice', 'B', 5000]]);
    lcdEmetti($this, $s, [1, 2, 3, 4], '2026-02-28');
    $this->travelTo('2026-03-10 10:00:00');
    $incasso = lcdIncassa($this, $s, 'ugo', [lcdQuota($s, 'ugo', 0) => -20000, lcdQuota($s, 'ugo', 1) => 5000, lcdQuota($s, 'ugo', 2) => 5000, lcdQuota($s, 'ugo', 3) => 5000, lcdQuota($s, 'ugo', 4) => 5000], '2026-03-10');
    $this->travelTo('2026-03-15 10:00:00');
    lcdEmetti($this, $s, [0], '2026-03-15');
    expect(DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 0))->value('scrittura_contabile_id'))->toBeNull();
    $emissione = (int) DB::table('rate_quote')->where('id', lcdQuota($s, 'bice', 0))->value('scrittura_contabile_id');

    $this->travelTo(Carbon::parse('2026-03-21 00:30:00', 'Europe/Rome'));
    lcdStorna($this, $s, $incasso);

    $figlia = DB::table('scritture_contabili')->where('scrittura_padre_id', $emissione)->where('tipo_movimento', 'emissione_rata')->first();
    expect($figlia)->not->toBeNull()
        ->and((int) DB::table('rate_quote')->where('id', lcdQuota($s, 'ugo', 0))->value('scrittura_contabile_id'))->toBe((int) $figlia->id)
        ->and(substr((string) $figlia->data_competenza, 0, 10))->toBe('2026-03-21');
});
