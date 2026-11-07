<?php

/**
 * Le lettere nel giorno dell'amministratore: alle 00:30 di Roma del 21/03 (in UTC è ancora il 20/03) la lettera dice la situazione al 21/03, il
 * ritardo contato al 21/03 e la data del 21/03 nel registro. E l'avviso delle lettere da rifare nella pagina delle rate scadute: una lettera
 * non partita la cui persona ha ancora da versare c'è; una la cui persona ha versato tutto no.
 */

use App\Models\User;
use App\Notifications\Gestionale\LetteraNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LettereScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    config(['app.user_timezone' => 'Europe/Rome']);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create(['email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
});

it('alle 00:30 di Roma la lettera è del giorno dell\'amministratore: la situazione, il ritardo e la data del registro', function () {
    $s = lttScenario($this);
    $this->travelTo(Carbon::parse('2026-03-21 00:30', 'Europe/Rome'));
    $this->actingAs($this->user)->post(lttRotta($s, 'stampa'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id]]))->assertOk();

    $riga = lttRighe($s, 'ugo')->sole();
    // La rata 1 scade il 05/01: al 21/03 sono 75 giorni (al 20/03, il giorno del server, sarebbero 74).
    expect($riga->dati['alla'])->toBe('2026-03-21')
        ->and($riga->dati['righe'][0]['ritardo'])->toBe('75 giorni');
    $lettere = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['persone']['ugo']]))->assertOk()->viewData('page')['props']['lettere'];
    expect($lettere[0]['emessa_il'])->toBe('2026-03-21')
        ->and($lettere[0]['ora'])->toBe('00:30');
});

it('l\'avviso delle lettere da rifare: c\'è finché la persona ha ancora da versare', function (bool $versaTutto) {
    Queue::fake();
    config(['mail.default' => 'array']);
    $s = lttScenario($this);
    $this->actingAs($this->user)->postJson(lttRotta($s, 'invia'), lttScelte($s, ['persone' => [$s['persone']['ugo']->id]]))->assertOk();
    $this->travelTo('2026-03-20 15:00:00');
    lcdIncassa($this, $s, 'ugo', $versaTutto
        ? [lcdQuota($s, 'ugo', 1) => 6000, lcdQuota($s, 'ugo', 2) => 6000, lcdQuota($s, 'ugo', 3) => 6000]
        : [lcdQuota($s, 'ugo', 1) => 6000], '2026-03-20');
    Queue::pushed(SendQueuedNotifications::class)->sole()->handle(app(ChannelManager::class));
    expect(lttRighe($s, 'ugo')->sole()->errore)->toBe(LetteraNotification::CAMBIATO_PRIMA_DELL_INVIO);

    $p = $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id]))->assertOk()->viewData('page')['props'];
    expect($p['lettere_da_riprovare'])->toBe($versaTutto ? 0 : 1);
})->with(['ha versato tutto' => true, 'ha versato una rata' => false]);
