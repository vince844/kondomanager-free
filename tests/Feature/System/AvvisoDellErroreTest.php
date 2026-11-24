<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * L'avviso dell'errore allo studio (decisioni 698 (4) e 701; bozza docs/design/beta14_pagine_errore_grafica_bozza.html, scene 1, 4 e 11).
 *
 * La catena: un errore su una pagina → il codice nella pagina, con la firma dell'avviso → «Avvisa lo studio» con la nota → la volta segnata «avvisata» da
 * chi, con l'ora → un compito «Errori avvisati» nella Action inbox, uno per errore, che legge e conta solo chi ha «Gestisci impostazioni generali» →
 * «Apri l'errore» porta alla scheda «Errori» con il codice cercato. In più un'email, solo a chi ha acceso «Errore avvisato» nelle sue preferenze (spenta di
 * serie, e visibile solo a chi ha il permesso), con il codice, l'ora, la pagina, chi ha avvisato e «Apri l'errore»: niente dettaglio tecnico, niente nota.
 * Un avviso per codice, cinque all'ora per persona; il visitatore non collegato e la demo pubblica non ne mandano.
 */

use App\Enums\Permission;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Errori\Errore;
use App\Models\Errori\VoltaDellErrore;
use App\Models\Evento;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Errori\ErroreAvvisatoNotification;
use App\Services\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    config(['app.debug' => false]);
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-22 09:42:00', 'UTC'));
    Route::middleware('web')->get('/admin/prova-ricevuta/{incasso}', fn () => throw new \RuntimeException('Call to a member function nome() on null'));
    Route::middleware('web')->get('/admin/prova-altra', fn () => throw new \LogicException('Un altro errore'));

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

/** La pagina d'errore vista da chi, e i campi dell'avviso che porta. */
function adePagina($test, User $chi, string $indirizzo = '/admin/prova-ricevuta/345'): array
{
    $html = $test->actingAs($chi)->get($indirizzo)->assertStatus(500)->getContent();
    preg_match('/name="codice" value="([^"]+)"/', $html, $codice);
    preg_match('/name="firma" value="([^"]+)"/', $html, $firma);

    return ['html' => $html, 'codice' => $codice[1] ?? null, 'firma' => $firma[1] ?? null];
}

function adeAvvisa($test, User $chi, array $pagina, ?string $nota = null)
{
    return $test->actingAs($chi)->postJson(route('errori.avviso'), ['codice' => $pagina['codice'], 'firma' => $pagina['firma'], 'nota' => $nota]);
}

/** I titoli dei compiti della Action inbox che vede chi la apre. */
function adeInbox($test, User $chi): array
{
    Cache::flush();

    return collect($test->actingAs($chi)->get(route('admin.inbox'))->assertOk()->viewData('page')['props']['tasks']['data'])->pluck('title')->all();
}

it('il condòmino avvisa lo studio: la volta è segnata da chi e quando, con la nota; l\'errore è «avvisato»', function () {
    $pagina = adePagina($this, $this->elena);
    expect($pagina['codice'])->not->toBeNull()->and($pagina['firma'])->not->toBeNull();

    adeAvvisa($this, $this->elena, $pagina, 'Scaricavo la ricevuta della rata di novembre')->assertOk()
        ->assertJsonPath('messaggio', 'Ha ricevuto il codice '.$pagina['codice'].', l\'ora e la pagina, con la tua nota. Non devi fare altro.');

    $volta = VoltaDellErrore::where('codice', $pagina['codice'])->sole();
    expect($volta->avvisato_da)->toBe($this->elena->id)
        ->and($volta->avvisato_ruolo)->toBe('utente')
        ->and($volta->nota_avviso)->toBe('Scaricavo la ricevuta della rata di novembre')
        ->and($volta->avvisato_il)->not->toBeNull()
        ->and($volta->errore->stato)->toBe(Errore::AVVISATO);
});

it('l\'avviso diventa un compito «Errori avvisati» con «Apri l\'errore», che vede solo chi ha «Gestisci impostazioni generali»', function () {
    $pagina = adePagina($this, $this->elena);
    adeAvvisa($this, $this->elena, $pagina, 'Scaricavo la ricevuta')->assertOk();

    $compito = Evento::where('tipo', 'errore_avvisato')->sole();
    expect($compito->title)->toBe('Errore '.$pagina['codice'].' avvisato')
        ->and($compito->description)->toBe('Ti ha avvisato Elena Rossi: «Scaricavo la ricevuta»')
        ->and($compito->meta['action_url'])->toContain('scheda=errori')
        ->and($compito->meta['action_url'])->toContain('cerca='.$pagina['codice'])
        ->and($compito->condomini()->pluck('condomini.id')->all())->toBe([$this->condominio->id]);

    expect(adeInbox($this, $this->vincenzo))->toContain($compito->title);
    // Il collaboratore ha il pannello e l'agenda, non le impostazioni generali: il compito non lo legge, e non lo conta.
    expect($this->mario->checkPermissionTo(Permission::VIEW_EVENTS->value))->toBeTrue();
    expect(adeInbox($this, $this->mario))->not->toContain($compito->title);
    Cache::flush();
    expect(\App\Services\Gestionale\InboxService::numeroPerIlMenu(
        \App\Services\Gestionale\Inbox\CompitoDellaSegnalazione::chiVede($this->mario), \App\Services\Gestionale\Inbox\CompitoDaModerare::chiVede($this->mario)))->toBe(0)
        ->and(\App\Services\Gestionale\InboxService::numeroPerIlMenu(
        \App\Services\Gestionale\Inbox\CompitoDellaSegnalazione::chiVede($this->vincenzo), \App\Services\Gestionale\Inbox\CompitoDaModerare::chiVede($this->vincenzo)))->toBe(1);

    // «Apri l'errore» porta alla scheda, che apre l'errore da sola.
    $dettaglio = $this->actingAs($this->vincenzo)->get($compito->meta['action_url'])->assertOk()->viewData('page')['props']['dettaglio'];
    expect($dettaglio['volte'][0]['codice'])->toBe($pagina['codice']);
});

it('un compito per errore: un secondo avviso dello stesso errore, da un\'altra persona, si aggiunge al compito che c\'è', function () {
    adeAvvisa($this, $this->elena, adePagina($this, $this->elena))->assertOk();
    auth()->logout();
    adeAvvisa($this, $this->mario, adePagina($this, $this->mario))->assertOk();

    $compito = Evento::where('tipo', 'errore_avvisato')->sole();
    expect($compito->meta['avvisi'])->toBe(2)
        ->and(Errore::count())->toBe(1);

    // Un errore diverso è un compito diverso.
    adeAvvisa($this, $this->elena, adePagina($this, $this->elena, '/admin/prova-altra'))->assertOk();
    expect(Evento::where('tipo', 'errore_avvisato')->count())->toBe(2);
});

it('un avviso per codice; la firma sbagliata, il visitatore e la demo pubblica non mandano niente', function () {
    $pagina = adePagina($this, $this->elena);
    adeAvvisa($this, $this->elena, $pagina)->assertOk();
    adeAvvisa($this, $this->elena, $pagina)->assertStatus(409)->assertJsonPath('messaggio', 'Lo studio è già avvisato di questo errore.');

    $altra = adePagina($this, $this->elena);
    $this->actingAs($this->elena)->postJson(route('errori.avviso'), ['codice' => $altra['codice'], 'firma' => str_repeat('a', 64)])->assertStatus(403);
    // La firma è di Elena: un altro utente non la usa.
    adeAvvisa($this, $this->mario, $altra)->assertStatus(403);

    auth()->logout();
    $this->postJson(route('errori.avviso'), ['codice' => $altra['codice'], 'firma' => $altra['firma']])->assertStatus(401);

    // La demo come la vede l'immagine (`artisan optimize`): dalla configurazione, `kondomanager.demo`.
    config(['kondomanager.demo' => true]);
    adeAvvisa($this, $this->elena, $altra)->assertStatus(403);

    expect(VoltaDellErrore::whereNotNull('avvisato_il')->count())->toBe(1)
        ->and(Evento::where('tipo', 'errore_avvisato')->count())->toBe(1);
});

it('cinque avvisi all\'ora per persona: il sesto si ferma', function () {
    foreach (range(1, 5) as $i) {
        adeAvvisa($this, $this->elena, adePagina($this, $this->elena))->assertOk();
    }
    adeAvvisa($this, $this->elena, adePagina($this, $this->elena))->assertStatus(429);
});

it('la nota si ferma a 300 caratteri', function () {
    adeAvvisa($this, $this->elena, adePagina($this, $this->elena), str_repeat('a', 301))->assertStatus(422);
});

it('senza JavaScript il modulo manda l\'avviso e risponde con una pagina che lo dice', function () {
    $pagina = adePagina($this, $this->elena);

    $this->actingAs($this->elena)->post(route('errori.avviso'), ['codice' => $pagina['codice'], 'firma' => $pagina['firma'], 'nota' => ''])
        ->assertOk()->assertSee('Lo studio è avvisato')->assertSee($pagina['codice']);
});

it('l\'email dell\'avviso: spenta di serie, nessuna email; accesa, una email senza dettaglio né nota; chi non ha il permesso non la riceve', function () {
    Notification::fake();
    // Il collaboratore accende la voce a mano nel database: senza il permesso non la riceve lo stesso.
    NotificationPreference::create(['user_id' => $this->mario->id, 'type' => 'error_reported', 'enabled' => true]);

    adeAvvisa($this, $this->elena, adePagina($this, $this->elena), 'Scaricavo la ricevuta di Elena Rossi')->assertOk();
    Notification::assertNothingSent();

    NotificationPreference::create(['user_id' => $this->vincenzo->id, 'type' => 'error_reported', 'enabled' => true]);
    $pagina = adePagina($this, $this->elena, '/admin/prova-altra');
    adeAvvisa($this, $this->elena, $pagina, 'Scaricavo la ricevuta di Elena Rossi')->assertOk();

    Notification::assertSentTo($this->vincenzo, ErroreAvvisatoNotification::class, function (ErroreAvvisatoNotification $n) use ($pagina) {
        $mail = $n->toMail($this->vincenzo);
        $testo = $mail->subject."\n".implode("\n", $mail->introLines)."\n".implode("\n", $mail->outroLines);
        expect($testo)->toContain($pagina['codice'])
            ->toContain('Elena Rossi')
            ->toContain('/admin/prova-altra')
            ->not->toContain('Un altro errore')
            ->not->toContain('LogicException')
            ->not->toContain('Scaricavo la ricevuta');
        expect($mail->actionText)->toBe('Apri l\'errore')->and($mail->actionUrl)->toContain('cerca='.$pagina['codice']);

        return true;
    });
    Notification::assertNotSentTo($this->mario, ErroreAvvisatoNotification::class);
    Notification::assertNotSentTo($this->elena, ErroreAvvisatoNotification::class);
});

it('la voce «Errore avvisato» nelle preferenze la vede solo chi ha «Gestisci impostazioni generali», ed è spenta di serie', function () {
    $service = app(NotificationPreferenceService::class);
    expect(array_keys($service->getVisibleNotificationTypes($this->vincenzo)))->toContain('error_reported')
        ->and(array_keys($service->getVisibleNotificationTypes($this->mario)))->not->toContain('error_reported')
        ->and(array_keys($service->getVisibleNotificationTypes($this->elena)))->not->toContain('error_reported');

    $voce = collect($this->actingAs($this->vincenzo)->get(route('admin.settings.notifications.index'))->assertOk()->viewData('page')['props']['preferences'])
        ->firstWhere('type', 'error_reported');
    expect($voce['label'])->toBe('Errore avvisato')->and($voce['enabled'])->toBeFalse();
});

it('il compito «Errori avvisati» non si apre per indirizzo senza «Gestisci impostazioni generali»: il collaboratore ha 403 su vedere, modificare, eliminare', function () {
    adeAvvisa($this, $this->elena, adePagina($this, $this->elena), 'Pagavo la rata della mia casa in Via Roma 12, int. 4')->assertOk();
    $compito = Evento::where('tipo', 'errore_avvisato')->sole();

    // Il collaboratore ha il pannello e «Modifica scadenza in agenda», non le impostazioni generali.
    expect($this->mario->checkPermissionTo(Permission::EDIT_EVENTS->value))->toBeTrue()
        ->and($this->mario->checkPermissionTo(Permission::MANAGE_GENERAL_SETTINGS->value))->toBeFalse();
    $this->actingAs($this->mario)->get(route('admin.eventi.edit', $compito))->assertForbidden();
    $this->actingAs($this->mario)->delete(route('admin.eventi.destroy', $compito))->assertForbidden();
    expect(\Illuminate\Support\Facades\Gate::forUser($this->mario)->allows('view', $compito))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Gate::forUser($this->mario)->allows('update', $compito))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Gate::forUser($this->mario)->allows('delete', $compito))->toBeFalse();
    expect(Evento::whereKey($compito->id)->exists())->toBeTrue();

    // Chi ha il permesso lo apre come prima.
    $this->actingAs($this->vincenzo)->get(route('admin.eventi.edit', $compito))->assertOk();
    expect(\Illuminate\Support\Facades\Gate::forUser($this->vincenzo)->allows('view', $compito))->toBeTrue();
});

it('«Ricordamelo» sul compito «Errori avvisati» scrive nell\'agenda «Riprendi un errore avvisato», senza il codice, il nome e la nota: il collaboratore non li legge', function () {
    $pagina = adePagina($this, $this->elena);
    adeAvvisa($this, $this->elena, $pagina, 'Pagavo la rata della mia casa in Via Roma 12, int. 4')->assertOk();
    $compito = Evento::where('tipo', 'errore_avvisato')->sole();
    $inizio = $compito->start_time->toDateTimeString();

    $this->actingAs($this->vincenzo)->from(route('admin.inbox'))
        ->post(route('admin.inbox.risposta', $compito), ['tipo' => 'ricordamelo', 'giorno' => '2026-11-25'])
        ->assertSessionHasNoErrors();

    $agenda = json_encode($this->actingAs($this->mario)->get(route('admin.eventi.index'))->assertOk()->viewData('page')['props'], JSON_UNESCAPED_UNICODE);
    expect($agenda)->toContain('Riprendi un errore avvisato')
        ->and($agenda)->not->toContain($pagina['codice'])
        ->and($agenda)->not->toContain('Elena Rossi')
        ->and($agenda)->not->toContain('int. 4');
    // Il compito resta al suo giorno, nascosto nell'Inbox fino al 25/11, e il collaboratore non lo apre.
    expect($compito->fresh()->start_time->toDateTimeString())->toBe($inizio)
        ->and(adeInbox($this, $this->vincenzo))->not->toContain($compito->title);
    $this->actingAs($this->mario)->get(route('admin.eventi.edit', $compito))->assertForbidden();
});

it('il titolo del compito non ha il nome di chi ha avvisato: il registro delle modifiche, che lo copia, non lo mostra a chi non ha «Gestisci impostazioni generali»', function () {
    $pagina = adePagina($this, $this->elena);
    adeAvvisa($this, $this->elena, $pagina, 'Pagavo la rata della mia casa in Via Roma 12, int. 4')->assertOk();
    $compito = Evento::where('tipo', 'errore_avvisato')->sole();
    // Il nome e la nota stanno nel corpo del compito, che legge solo chi ha il permesso; il titolo dice il codice.
    expect($compito->title)->toBe('Errore '.$pagina['codice'].' avvisato')
        ->and($compito->description)->toContain('Elena Rossi')->toContain('int. 4');

    // L'amministratore chiude il compito: la riga del registro copia il titolo.
    $this->actingAs($this->vincenzo)->from(route('admin.inbox'))->post(route('admin.inbox.risposta', $compito), ['tipo' => 'fatto'])
        ->assertSessionHasNoErrors();
    $riga = \App\Models\RegistroModifica::query()->where('area', 'agenda')->latest('id')->firstOrFail();
    expect($riga->oggetto_nome)->toContain('Errore '.$pagina['codice'].' avvisato')->not->toContain('Elena Rossi');

    // Il collaboratore con «Vede la storia delle schede»: la storia del condominio.
    expect($this->mario->checkPermissionTo(Permission::VIEW_STORIA_SCHEDE->value))->toBeTrue();
    $storia = $this->actingAs($this->mario)->getJson(route('admin.registro-modifiche.storia', ['tipo' => 'condominio', 'id' => $this->condominio->id]))->assertOk()->getContent();
    expect($storia)->not->toContain('Elena Rossi')->not->toContain('int. 4');

    // E se gli si dà anche «Vede il registro delle modifiche», senza le impostazioni generali: il registro del condominio ha la riga, senza il nome.
    $this->mario->givePermissionTo(Permission::VIEW_REGISTRO_MODIFICHE->value);
    $registro = json_encode($this->actingAs($this->mario)->get(route('admin.gestionale.registro-modifiche.index', $this->condominio))->assertOk()
        ->viewData('page')['props'], JSON_UNESCAPED_UNICODE);
    expect($registro)->toContain('Errore '.$pagina['codice'].' avvisato')
        ->and($registro)->not->toContain('Elena Rossi')
        ->and($registro)->not->toContain('int. 4');
});
