<?php

/**
 * «Riprova le non partite»: una lettera con la data del termine già passata non si rifà, la risposta lo dice e la pagina non la conta fra quelle da
 * riprovare; con molte lettere da rifare, al massimo quelle del tetto del PDF per volta, anche con la coda vera.
 *
 * Nessuna email vera: la coda è finta e il trasporto è `array`.
 */

use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use App\Services\Gestionale\Lettere\EmissioneLettere;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LettereScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['mail.default' => 'array']);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create(['email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
});

it('una lettera con la data del termine passata non si rifà: la risposta lo dice, e la pagina non la conta fra quelle da riprovare', function () {
    $s = lttScenario($this);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id], 'termine_forma' => 'data', 'termine' => null,
        'termine_data' => '2026-03-25', 'indirizzi' => ['email']]))->assertOk();
    RegistroInvio::where('tipo', 'sollecito')->update(['stato' => RegistroInvio::STATO_NON_PARTITA, 'errore' => 'x']);
    expect(app(EmissioneLettere::class)->lettereDaRiprovare($s['c']))->toBe(1);

    // Il giorno è quello dell'amministratore, e la data del termine vale come per una lettera nuova (un giorno dopo oggi): alle 23:30 di Roma del
    // 24/03 la lettera si rifà ancora, alle 00:30 del 25/03 no.
    $this->travelTo(\Carbon\Carbon::parse('2026-03-24 23:30', 'Europe/Rome'));
    expect(app(EmissioneLettere::class)->lettereDaRiprovare($s['c']))->toBe(1);
    $this->travelTo(\Carbon\Carbon::parse('2026-03-25 00:30', 'Europe/Rome'));
    expect(app(EmissioneLettere::class)->lettereDaRiprovare($s['c']))->toBe(0);
    $this->travelTo('2026-03-26 10:00:00');
    expect(app(EmissioneLettere::class)->lettereDaRiprovare($s['c']))->toBe(0);
    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'riprova'), [])->assertOk()->json();
    expect($r)->toMatchArray(['lettere' => 0, 'termine_passato' => 1])
        ->and(lttRighe($s, 'ugo'))->toHaveCount(1)
        ->and($this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id]))->viewData('page')['props']['lettere_da_riprovare'])->toBe(0);
});

it('«Riprova le non partite» con 205 solleciti non partiti e la coda del database: ne rifà 200, ne restano 5', function () {
    config(['queue.default' => 'database']);
    $this->travelTo('2026-01-02 10:00:00');
    $unita = [];
    for ($i = 1; $i <= 205; $i++) {
        $unita["U{$i}"] = ["p{$i}", 1000 / 205];
    }
    $s = lcdScenario($unita, 3, 'prima_rata', [], 120000 * 205 / 10);
    lcdEmetti($this, $s, [1, 2, 3], '2026-01-02');
    $this->travelTo('2026-03-20 10:00:00');
    $corpo = ['tipo' => 'sollecito', 'tutte' => 1, 'filtri' => [], 'testo' => 'Gentile [nome], [totale].', 'termine_forma' => 'giorni', 'termine' => 15, 'indirizzi' => ['email']];
    $r1 = $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), $corpo)->assertOk()->json();
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), $corpo + ['viste' => $r1['restanti']['persone']])->assertOk();
    RegistroInvio::where('tipo', 'sollecito')->update(['stato' => RegistroInvio::STATO_NON_PARTITA]);

    $this->travelTo('2026-03-21 10:00:00');
    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'riprova'), [])->assertOk()->json();
    expect($r['lettere'])->toBe(EmissioneLettere::TETTO_PDF)->and($r['restanti']['lettere'] ?? null)->toBe(5)
        ->and(app(EmissioneLettere::class)->lettereDaRiprovare($s['c']))->toBe(5);
});
