<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 788, decisioni 713 e 718 (bozza docs/design/beta14_avvisi_errori_interruttore_bozza.html): un'email per errore, e l'interruttore «I condòmini possono
 * avvisare lo studio dalla pagina d'errore».
 *
 * - L'email «Errore avvisato» parte solo quando l'avviso fa nascere il compito della Action inbox (il primo avviso di quell'errore, o il primo dopo che il
 *   compito è stato chiuso); gli avvisi a compito aperto aumentano solo il conteggio.
 * - L'interruttore (`errori_avviso_dalla_pagina`, acceso di serie) vale per i condòmini e i fornitori; il collaboratore dello studio tiene «Avvisa
 *   l'amministratore» anche spento; il registro degli errori resta sempre acceso.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Errori\VoltaDellErrore;
use App\Models\Evento;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Errori\ErroreAvvisatoNotification;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    config(['app.debug' => false]);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-23 10:42:00', 'UTC'));
    Route::middleware('web')->get('/admin/prova-ricevuta/{incasso}', fn () => throw new \RuntimeException('Call to a member function nome() on null'));

    $this->condominio = Condominio::factory()->create(['nome' => 'Condominio Via Roma 12']);
    $this->elena = User::factory()->create(['name' => 'Elena Rossi', 'email_verified_at' => now()]);
    $this->elena->assignRole(Role::findByName('utente', 'web'));
    Anagrafica::create(['user_id' => $this->elena->id, 'nome' => 'Elena Rossi', 'indirizzo' => 'Via Roma 12', 'codice_fiscale' => 'RSSLNE80A41H501Z'])
        ->condomini()->attach($this->condominio->id);
    $this->vincenzo = User::factory()->create(['name' => 'Vincenzo', 'email_verified_at' => now()]);
    $this->vincenzo->assignRole(Role::findByName('amministratore', 'web'));
    $this->mario = User::factory()->create(['name' => 'Mario Bianchi', 'email_verified_at' => now()]);
    $this->mario->assignRole(Role::findByName('collaboratore', 'web'));
});

function c788Interruttore(bool $acceso): void
{
    $s = app(GeneralSettings::class);
    $s->errori_avviso_dalla_pagina = $acceso;
    $s->save();
}

function c788Pagina($test, User $chi): array
{
    $html = $test->actingAs($chi)->get('/admin/prova-ricevuta/345')->assertStatus(500)->getContent();
    preg_match('/name="codice" value="([^"]+)"/', $html, $codice);
    preg_match('/name="firma" value="([^"]+)"/', $html, $firma);

    return ['html' => $html, 'codice' => $codice[1] ?? null, 'firma' => $firma[1] ?? null];
}

function c788Avvisa($test, User $chi, array $pagina)
{
    return $test->actingAs($chi)->postJson(route('errori.avviso'), ['codice' => $pagina['codice'], 'firma' => $pagina['firma']]);
}

function c788Condomino(string $nome): User
{
    $u = User::factory()->create(['name' => $nome, 'email_verified_at' => now()]);
    $u->assignRole(Role::findByName('utente', 'web'));

    return $u;
}

it('un\'email per errore: venti avvisi di venti condòmini diversi sullo stesso errore, una sola email e un compito con venti avvisi; chiuso il compito, l\'avviso dopo ne manda un\'altra', function () {
    NotificationPreference::create(['user_id' => $this->vincenzo->id, 'type' => 'error_reported', 'enabled' => true]);
    Notification::fake();

    foreach (range(1, 20) as $i) {
        $utente = c788Condomino('Condomino '.$i);
        c788Avvisa($this, $utente, c788Pagina($this, $utente))->assertOk();
        auth()->logout();
    }

    $compito = Evento::where('tipo', 'errore_avvisato')->sole();
    expect($compito->meta['avvisi'])->toBe(20);
    Notification::assertSentToTimes($this->vincenzo, ErroreAvvisatoNotification::class, 1);

    // L'amministratore chiude il compito: l'errore è tornato, il primo avviso dopo manda di nuovo l'email.
    $this->actingAs($this->vincenzo)->from(route('admin.inbox'))->post(route('admin.inbox.risposta', $compito), ['tipo' => 'fatto'])->assertSessionHasNoErrors();
    auth()->logout();
    $dopo = c788Condomino('Anna Gialli');
    c788Avvisa($this, $dopo, c788Pagina($this, $dopo))->assertOk();

    Notification::assertSentToTimes($this->vincenzo, ErroreAvvisatoNotification::class, 2);
    expect(Evento::where('tipo', 'errore_avvisato')->count())->toBe(2);
});

it('l\'interruttore è acceso di serie: la pagina d\'errore del condòmino ha «Avvisa lo studio»', function () {
    expect(app(GeneralSettings::class)->errori_avviso_dalla_pagina)->toBeTrue()
        ->and(DB::table('settings')->where('group', 'general')->where('name', 'errori_avviso_dalla_pagina')->count())->toBe(1);

    $pagina = c788Pagina($this, $this->elena);
    expect($pagina['html'])->toContain('Avvisa lo studio')->and($pagina['codice'])->not->toBeNull();
});

it('spento, la pagina d\'errore del condòmino non ha il riquadro: il codice con «Copia», la frase e «Torna alla pagina iniziale» come pulsante principale', function () {
    c788Interruttore(false);

    $html = $this->actingAs($this->elena)->get('/admin/prova-ricevuta/345')->assertStatus(500)->getContent();

    expect($html)->not->toContain('Avvisa lo studio')
        ->not->toContain('class="ep-avviso"')
        ->not->toContain('name="firma"')
        ->toContain('data-copia=')
        ->toContain('Il codice di questo errore')
        ->toContain('Se ti serve aiuto, comunica questo codice allo studio: con il codice ritrova subito che cosa è successo.');
    expect($html)->toMatch('/class="eb pri" href="[^"]*" target="_top" data-pagina-iniziale/');
    // Il registro ha l'errore lo stesso.
    expect(VoltaDellErrore::count())->toBe(1);
});

it('spento, la rotta rifiuta il condòmino di una pagina rimasta aperta, e il registro ha già l\'errore', function () {
    $pagina = c788Pagina($this, $this->elena);
    c788Interruttore(false);

    c788Avvisa($this, $this->elena, $pagina)->assertStatus(403)
        ->assertJsonPath('messaggio', 'Lo studio non riceve avvisi dalla pagina d\'errore: comunica tu il codice allo studio.');

    expect(VoltaDellErrore::where('codice', $pagina['codice'])->sole()->avvisato_il)->toBeNull()
        ->and(Evento::where('tipo', 'errore_avvisato')->count())->toBe(0);
});

it('spento, il collaboratore dello studio ha ancora «Avvisa l\'amministratore» e il suo avviso passa', function () {
    c788Interruttore(false);

    $pagina = c788Pagina($this, $this->mario);
    expect($pagina['html'])->toContain('Avvisa l&#039;amministratore')->and($pagina['firma'])->not->toBeNull();

    c788Avvisa($this, $this->mario, $pagina)->assertOk();
    expect(Evento::where('tipo', 'errore_avvisato')->count())->toBe(1);
});
