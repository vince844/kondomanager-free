<?php

/**
 * La scelta «Persona» della pagina «Rate scadute»: la pagina riceve le persone del condominio, con il solo id e il nome, in ordine di nome;
 * una persona di un altro condominio non c'è, nemmeno quando ha lo stesso amministratore; una persona del condominio senza niente di scaduto
 * c'è (la scelta è fra le persone, non fra le righe). La persona arrivata dall'indirizzo resta quella di prima.
 */

use App\Models\Anagrafica;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

/** Le proprietà della pagina, per un condominio. */
function rspfApri($test, int $condominioId, array $query = []): array
{
    $r = $test->actingAs($test->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $condominioId] + $query));
    $r->assertOk();

    return $r->viewData('page')['props'];
}

it('la pagina riceve le persone del condominio, id e nome in ordine di nome, e non quelle di un altro condominio', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $qui = lcdScenario(['A' => ['ugo', 600], 'B' => ['bice', 400]], 12, 'prima_rata', [], 120000);
    $altrove = lcdScenario(['A' => ['zeno', 1000]], 12, 'prima_rata', [], 120000);
    // Una persona del condominio senza unità e senza rate: c'è anche lei, perché la scelta è fra le persone del condominio.
    $senzaRate = tap(Anagrafica::forceCreate(['nome' => 'Anna Verdi', 'email' => 'anna-rspf@test.it', 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'VRDNNA80A41H501X']), fn ($a) => $a->condomini()->syncWithoutDetaching([$qui['c']->id]));
    $this->travelTo('2026-03-20 10:00:00');

    $persone = rspfApri($this, $qui['c']->id)['persone'];

    expect($persone)->toBe([
        ['id' => $senzaRate->id, 'nome' => 'Anna Verdi'],
        ['id' => $qui['persone']['bice']->id, 'nome' => 'Bice'],
        ['id' => $qui['persone']['ugo']->id, 'nome' => 'Ugo'],
    ]);
    expect(collect($persone)->pluck('id'))->not->toContain($altrove['persone']['zeno']->id);
    expect(array_keys($persone[0]))->toBe(['id', 'nome']);
});

it('la persona arrivata dall\'indirizzo è nei filtri e nel nome della porta; una di un altro condominio è un 404', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $qui = lcdScenario(['A' => ['ugo', 600], 'B' => ['bice', 400]], 12, 'prima_rata', [], 120000);
    $altrove = lcdScenario(['A' => ['zeno', 1000]], 12, 'prima_rata', [], 120000);
    lcdEmetti($this, $qui, [1, 2, 3], '2026-01-02');
    $this->travelTo('2026-03-20 10:00:00');

    $p = rspfApri($this, $qui['c']->id, ['anagrafica_id' => $qui['persone']['bice']->id]);
    expect($p['filtri']['anagrafica_id'])->toBe($qui['persone']['bice']->id);
    expect($p['persona_della_porta'])->toBe('Bice');
    expect(collect($p['righe']['data'])->pluck('anagrafica_id')->unique()->values()->all())->toBe([$qui['persone']['bice']->id]);

    $this->actingAs($this->user)
        ->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $qui['c']->id, 'anagrafica_id' => $altrove['persone']['zeno']->id]))
        ->assertNotFound();
});
