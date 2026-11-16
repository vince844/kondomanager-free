<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il compito di una segnalazione si legge, si conta e si tocca solo da chi apre la segnalazione (la regola delle due Inbox, `CompitoDellaSegnalazione::chiVede`),
 * anche fuori dalle Inbox:
 * - il promemoria di «Ricordamelo» va nell'agenda dello studio, che legge chi ha «Visualizza scadenze in agenda»: non porta l'oggetto della segnalazione, che si
 *   legge solo aprendola; il titolo è {@see RispostePromemoria::TITOLO_DEL_PROMEMORIA_DELLA_SEGNALAZIONE}, con il nome del condominio;
 * - la rotta delle risposte risponde 404 a chi non apre la segnalazione, e niente cambia;
 * - la pagina di modifica dell'agenda, per id, non apre il compito a chi non apre la segnalazione (`EventoPolicy::update` e `delete`);
 * - il numero dei compiti dello studio va solo a chi ha il pannello: un condòmino non lo riceve.
 */

use App\Enums\Permission;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\Gestionale\RispostaPromemoria;
use App\Models\Segnalazione;
use App\Models\User;
use App\Services\Gestionale\RispostePromemoria;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role as RuoloSpatie;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

function csaUtente(string $ruolo, array $permessi): User
{
    RuoloSpatie::findOrCreate($ruolo, 'web')->syncPermissions(array_map(fn (Permission $p) => $p->value, $permessi));
    $utente = User::factory()->create(['email_verified_at' => now()]);
    $utente->assignRole($ruolo);

    return $utente->fresh();
}

/** Una segnalazione privata e urgente del condominio, con il suo compito. */
function csaPrivata($test): Evento
{
    $s = Segnalazione::create(['subject' => 'Perdita dal soffitto del sig. Bianchi', 'description' => 'Testo riservato.', 'created_by' => $test->admin->id,
        'condominio_id' => $test->condominio->id, 'priority' => 'urgente', 'stato' => 'aperta', 'is_published' => true, 'is_approved' => true,
        'is_private' => true, 'can_comment' => true]);

    return Evento::where('tipo', 'segnalazione_guasto')->where('eventable_id', $s->id)->sole();
}

/** Tutto ciò che l'agenda dello studio e la dashboard dello studio mandano al browser di chi le apre. */
function csaAgendaEDashboard($test, User $chi): string
{
    Cache::flush();
    $agenda = $test->actingAs($chi)->get(route('admin.eventi.index'))->assertOk()->viewData('page')['props']['eventi'];
    $dashboard = $test->actingAs($chi)->get(route('admin.dashboard'))->assertOk()->viewData('page')['props']['eventi'] ?? [];

    return json_encode([$agenda, $dashboard], JSON_UNESCAPED_UNICODE);
}

function csaTitoloDelPromemoria(string $condominio): string
{
    return strtr(RispostePromemoria::TITOLO_DEL_PROMEMORIA_DELLA_SEGNALAZIONE, [':condominio' => $condominio]);
}

it('il «Ricordamelo» sul compito di una segnalazione privata scrive nell\'agenda «Riprendi una segnalazione» con il condominio, non l\'oggetto', function () {
    $compito = csaPrivata($this);
    $agenda = csaUtente('Solo agenda', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_EVENTS]);
    expect($agenda->can('view', Segnalazione::first()))->toBeFalse();
    expect(csaAgendaEDashboard($this, $agenda))->not->toContain('Perdita dal soffitto');

    icsRispondi($this, $compito, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-25'])->assertSessionHasNoErrors();

    $promemoria = Evento::where('tipo', 'promemoria')->sole();
    expect(csaAgendaEDashboard($this, $agenda))->not->toContain('Perdita dal soffitto')
        ->and($promemoria->title.' '.$promemoria->description)->not->toContain('Perdita dal soffitto')
        ->and($promemoria->title)->toBe('Riprendi una segnalazione · Condominio Aurora')
        ->and($promemoria->title)->toBe(csaTitoloDelPromemoria('Condominio Aurora'))
        // Chi apre la segnalazione il suo promemoria lo vede, con lo stesso titolo.
        ->and(csaAgendaEDashboard($this, $this->admin))->toContain('Riprendi una segnalazione · Condominio Aurora');
});

it('un secondo «Ricordamelo» sulla stessa segnalazione sposta lo stesso promemoria e non scrive l\'oggetto', function () {
    $compito = csaPrivata($this);
    icsRispondi($this, $compito, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-25'])->assertSessionHasNoErrors();
    icsRispondi($this, $compito, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-02'])->assertSessionHasNoErrors();

    $promemoria = Evento::where('tipo', 'promemoria')->sole();
    expect($promemoria->title.' '.$promemoria->description)->not->toContain('Perdita dal soffitto')
        ->and($promemoria->start_time->format('Y-m-d'))->toBe('2026-06-02')
        ->and($promemoria->title)->toBe(csaTitoloDelPromemoria('Condominio Aurora'));
});

it('il promemoria della segnalazione, aperto per id dalla pagina di modifica dell\'agenda, non dice l\'oggetto a chi non apre la segnalazione', function () {
    $compito = csaPrivata($this);
    icsRispondi($this, $compito, ['tipo' => 'ricordamelo', 'giorno' => '2026-05-25'])->assertSessionHasNoErrors();
    $promemoria = Evento::where('tipo', 'promemoria')->sole();
    $chi = csaUtente('Agenda senza segnalazioni', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_EVENTS, Permission::EDIT_EVENTS]);

    $r = $this->actingAs($chi)->get(route('admin.eventi.edit', $promemoria));

    expect($r->status() === 200 ? json_encode($r->viewData('page')['props'], JSON_UNESCAPED_UNICODE) : '')->not->toContain('Perdita dal soffitto');
});

it('chi chiude i compiti ma non apre le segnalazioni non risponde al compito di una segnalazione: 404, e niente cambia', function () {
    $compito = csaPrivata($this);
    $chi = csaUtente('Agenda senza segnalazioni', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_EVENTS, Permission::EDIT_EVENTS]);
    expect($chi->can('view', Segnalazione::first()))->toBeFalse();

    icsRispondi($this, $compito, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-30'], $chi)->assertNotFound();

    expect(RispostaPromemoria::count())->toBe(0)
        ->and(Evento::where('tipo', 'promemoria')->count())->toBe(0)
        // Il compito resta nell'Inbox dell'amministratore.
        ->and(icsCasella($this))->toContain('Perdita dal soffitto del sig. Bianchi');
});

it('chi apre le segnalazioni risponde al compito della segnalazione come prima', function () {
    $compito = csaPrivata($this);
    $chi = csaUtente('Agenda con le segnalazioni', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_EVENTS, Permission::EDIT_EVENTS, Permission::VIEW_SEGNALAZIONI]);

    icsRispondi($this, $compito, ['tipo' => 'ricordamelo', 'giorno' => '2026-06-30'], $chi)->assertSessionHasNoErrors();

    expect(RispostaPromemoria::count())->toBe(1)->and(Evento::where('tipo', 'promemoria')->count())->toBe(1);
});

it('la pagina di modifica dell\'agenda non apre il compito di una segnalazione a chi non apre le segnalazioni; e non lo modifica né lo cancella', function () {
    $compito = csaPrivata($this);
    $chi = csaUtente('Agenda senza segnalazioni', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_EVENTS, Permission::EDIT_EVENTS, Permission::DELETE_EVENTS]);

    expect($this->actingAs($chi)->get(route('admin.eventi.edit', $compito))->status())->toBeIn([403, 404])
        ->and($chi->can('update', $compito))->toBeFalse()
        ->and($chi->can('delete', $compito))->toBeFalse();

    $this->actingAs($chi)->delete(route('admin.eventi.destroy', $compito));
    expect(Evento::whereKey($compito->id)->exists())->toBeTrue();
});

it('chi apre le segnalazioni modifica il compito della segnalazione come prima; gli altri compiti restano a chi ha il permesso', function () {
    $compito = csaPrivata($this);
    $altro = icsCompito($this, 'controllo_incassi', [], 'Verifica incassi - Rata 3');
    $con = csaUtente('Agenda con le segnalazioni', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_EVENTS, Permission::EDIT_EVENTS, Permission::DELETE_EVENTS,
        Permission::VIEW_SEGNALAZIONI]);
    $senza = csaUtente('Agenda senza segnalazioni', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_EVENTS, Permission::EDIT_EVENTS, Permission::DELETE_EVENTS]);

    expect($con->can('update', $compito))->toBeTrue()
        ->and($con->can('delete', $compito))->toBeTrue()
        ->and($senza->can('update', $altro))->toBeTrue()
        ->and($senza->can('delete', $altro))->toBeTrue()
        ->and($this->admin->can('update', $compito))->toBeTrue();
});

it('il numero dei compiti dello studio non va al browser di un condòmino: la pagina che lo legge è solo del pannello', function () {
    csaPrivata($this);
    icsCompito($this, 'controllo_incassi', [], 'Verifica incassi - Rata 3');
    $altroPalazzo = Condominio::factory()->create(['nome' => 'Condominio Bora']);
    $condomino = User::factory()->create(['email_verified_at' => now()]);
    $condomino->assignRole(\App\Enums\Role::UTENTE->value);
    $anna = Anagrafica::create(['user_id' => $condomino->id, 'nome' => 'Anna Verdi', 'indirizzo' => 'Via Bora 1']);
    $anna->condomini()->attach($altroPalazzo->id);
    Cache::flush();

    $props = $this->actingAs($condomino->fresh())->get(route('user.dashboard'))->assertOk()->viewData('page')['props'];
    expect((int) ($props['inbox_count'] ?? 0))->toBe(0);

    // L'amministratore lo riceve ancora: il compito degli incassi e la segnalazione.
    Cache::flush();
    $props = $this->actingAs($this->admin)->get(route('admin.dashboard'))->assertOk()->viewData('page')['props'];
    expect((int) $props['inbox_count'])->toBe(2);
});
