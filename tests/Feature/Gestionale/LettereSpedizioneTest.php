<?php

/**
 * La spedizione delle lettere stampate, registrata a mano: dall'elenco delle rate scadute, in blocco, il giorno dopo (il filtro «stampate,
 * spedizione da registrare» e le righe di ogni lettera); una data non prima della lettera; il giorno dell'evento in quello
 * dell'amministratore; e per «nessuna lettera negli ultimi N giorni» la data di una lettera spedita è quella della spedizione.
 */

use App\Models\Gestionale\RegistroInvio;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LettereScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create(['email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
});

function lspElenco($test, array $s, array $query = []): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id] + $query))->assertOk()->viewData('page')['props'];
}

it('il giorno dopo la stampa: il filtro «stampate, spedizione da registrare» trova le lettere da spedire, ognuna con le sue righe, e la spedizione si registra in blocco', function () {
    $s = lttScenario($this);
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['tipo' => 'diffida', 'testo' => 'Diffida']))->assertOk();
    $this->travelTo('2026-03-21 10:00:00');

    $daSpedire = lspElenco($this, $s, ['da_spedire' => 1]);
    $righe = collect($daSpedire['righe']['data'])->keyBy('nome');
    expect($righe->keys()->sort()->values()->all())->toBe(['Bice', 'Ugo'])
        ->and($daSpedire['filtri']['da_spedire'])->toBeTrue()
        ->and($righe['Ugo']['ultima_lettera']['invii'])->toBe([lttRighe($s, 'ugo')->sole()->id]);

    $invii = $righe->flatMap(fn ($r) => $r['ultima_lettera']['invii'])->values()->all();
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), ['invii' => $invii, 'canale' => 'raccomandata', 'data' => '2026-03-21',
        'numeri' => [lttRighe($s, 'ugo')->sole()->id => 'RA1', lttRighe($s, 'bice')->sole()->id => 'RA2']])->assertOk()->assertJson(['spedite' => 2]);

    expect(lspElenco($this, $s, ['da_spedire' => 1])['righe']['data'])->toBe([])
        ->and(lttRighe($s, 'bice')->sole()->dati['spedizione'])->toMatchArray(['numero' => 'RA2', 'data' => '2026-03-21']);
});

it('una lettera mandata per email non è «da spedire»', function () {
    \Illuminate\Support\Facades\Queue::fake();
    config(['mail.default' => 'array']);
    $s = lttScenario($this);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s))->assertOk();

    expect(lspElenco($this, $s, ['da_spedire' => 1])['righe']['data'])->toBe([]);
});

it('«nessuna lettera negli ultimi N giorni»: per una lettera spedita conta il giorno della spedizione, non quello della stampa', function () {
    $s = lttScenario($this);
    $riga = lttRigaScritta($s, 'ugo', 'diffida', RegistroInvio::STATO_STAMPATA, 40);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), ['invii' => [$riga->id], 'canale' => 'raccomandata', 'data' => '2026-03-15', 'numeri' => [$riga->id => 'RA1']])
        ->assertOk();

    $nomi = collect(lspElenco($this, $s, ['senza_lettere_da' => 30])['righe']['data'])->pluck('nome')->all();
    $tutti = collect(lspElenco($this, $s, ['senza_lettere_da' => 3])['righe']['data'])->pluck('nome')->all();

    // Spedita il 15/03: cinque giorni fa. Negli ultimi 30 giorni c'è una lettera; negli ultimi 3 no.
    expect($nomi)->toBe(['Bice'])
        ->and($tutti)->toBe(['Ugo', 'Bice']);
});

it('la spedizione non si registra con una data prima della lettera', function () {
    $s = lttScenario($this);
    $riga = lttRigaScritta($s, 'ugo', 'diffida', RegistroInvio::STATO_STAMPATA, 0);

    $r = $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), ['invii' => [$riga->id], 'canale' => 'raccomandata', 'data' => '2020-01-01']);

    expect($r->status())->toBe(422)
        ->and($r->json('message'))->toBe('La spedizione non può essere prima della lettera, del 20/03/2026.')
        ->and($riga->fresh()->stato)->toBe(RegistroInvio::STATO_STAMPATA);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), ['invii' => [$riga->id], 'canale' => 'raccomandata', 'data' => '2026-03-20'])->assertOk();
});

it('alle 00:30 del 22/03 a Roma l\'evento della spedizione è del 22/03: il giorno lo scrive il server, in quello dell\'amministratore', function () {
    $s = lttScenario($this);
    $riga = lttRigaScritta($s, 'ugo', 'diffida', RegistroInvio::STATO_STAMPATA, 0);
    $this->travelTo(Carbon::parse('2026-03-22 00:30', 'Europe/Rome'));
    expect(now()->toDateString())->toBe('2026-03-21');

    $this->actingAs($this->user)->postJson(lttRotta($s, 'spedizione'), ['invii' => [$riga->id], 'canale' => 'a_mano', 'data' => '2026-03-22'])->assertOk();

    expect($riga->fresh()->dati['eventi'][0]['giorno'])->toBe('2026-03-22');
});
