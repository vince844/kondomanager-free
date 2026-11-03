<?php

/**
 * # La casella delle attività del pannello chiede i permessi dell'agenda (Coda 402)
 *
 * La casella delle attività (`Dashboard\ActionInboxController`, la pagina `dashboard/ActionInbox.vue`) elenca i compiti
 * dell'amministratore: le verifiche dei pagamenti segnalati dai condòmini, con l'importo dichiarato e il nome della
 * persona, le segnalazioni di guasto, le scadenze amministrative. I compiti sono scadenze in agenda, e la casella
 * segue i permessi dell'agenda:
 *
 * - leggerla chiede «Visualizza scadenze in agenda» (`EventoPolicy::viewAny`), la regola con cui la dashboard del
 *   pannello mostra le scadenze (`DashboardDelPannelloSoloCioCheSiApreTest.php`);
 * - «Rifiuta» e «Completa» chiedono «Modifica scadenza in agenda», prima di ogni altra verifica;
 * - «Rifiuta» chiude soltanto un compito aperto della casella (che chiede un'azione, non privato, non già chiuso):
 *   non una scadenza privata di un condòmino, non una scadenza qualunque dell'agenda, non un compito già chiuso, e
 *   la segnalazione di pagamento collegata si segna «rifiutata» solo così.
 *
 * I ruoli di serie: l'amministratore ha tutto; il collaboratore ha «Visualizza» e «Modifica scadenza in agenda»: li ha
 * tutti e due.
 *
 * Cosa NON copre: i compiti della casella mostrati nella dashboard del gestionale (Coda 110); i conteggi in testa alle
 * pagine, che sono numeri e non contenuti.
 */

use App\Enums\EventoTipo;
use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\VisibilityStatus;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\User;
use App\Services\Gestionale\InboxService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Spatie\Permission\Models\Role as RuoloSpatie;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    $this->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $this->condomino = User::factory()->create(['email_verified_at' => now()]);
    $this->condomino->assignRole(Role::UTENTE->value);
    $this->mario = Anagrafica::create(['user_id' => $this->condomino->id, 'nome' => "Mario D'Àngelo", 'indirizzo' => 'Via Roma 1']);
    $this->mario->condomini()->attach($this->aurora->id);

    // La rata del condòmino, che lui ha segnalato come pagata: una scadenza privata, che chiede un'azione a lui
    // (`AggiornaScadenziarioCondomino` scrive `requires_action` sulle rate non pagate), non alla casella.
    $this->rata = Evento::create([
        'title' => 'Rata 3 di Mario', 'start_time' => now()->subDay(), 'end_time' => now()->subDay()->addHour(),
        'created_by' => $this->condomino->id, 'visibility' => VisibilityStatus::PRIVATE->value, 'is_approved' => true,
        'tipo' => EventoTipo::SCADENZA_RATA_CONDOMINO, 'meta' => ['status' => 'reported', 'totale_rata' => 12345, 'requires_action' => true],
    ]);
    $this->rata->condomini()->attach($this->aurora->id);
    $this->rata->anagrafiche()->attach($this->mario->id);

    $this->compito = casellaCompito($this, 'Verifica incasso della rata 3 di Mario');
});

/** Un compito della casella come lo crea `PaymentReportingController`, con la rata collegata. */
function casellaCompito($test, string $titolo): Evento
{
    return InboxService::createTask(
        tipo: EventoTipo::VERIFICA_PAGAMENTO,
        title: $titolo,
        description: "Il condòmino Mario D'Àngelo ha segnalato di aver pagato € 123,45.",
        scadenza: now(),
        createdByUserId: $test->condomino->id,
        condominioId: $test->aurora->id,
        context: ['related_event_id' => $test->rata->id, 'anagrafica_id' => $test->mario->id],
        extraMeta: ['importo_dichiarato' => 12345],
    );
}

function casellaUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

function casellaSuMisura(array $permessi): User
{
    RuoloSpatie::findOrCreate('Su misura', 'web')->syncPermissions(array_merge([Permission::ACCESS_ADMIN_PANEL->value], $permessi));

    return casellaUtente('Su misura');
}

/** I compiti che la pagina riceve. */
function casellaCompitiDellaPagina($test, User $chi): array
{
    $props = $test->actingAs($chi)->get(route('admin.inbox'))->assertOk()->viewData('page')['props'];

    return collect($props['tasks']['data'] ?? $props['tasks'])->pluck('title')->all();
}

function casellaRifiuta($test, User $chi, Evento $evento)
{
    return $test->actingAs($chi)->from(route('admin.inbox'))->post(route('admin.inbox.reject', $evento), ['reason' => 'Bonifico non ancora arrivato']);
}

function casellaCompleta($test, User $chi, Evento $evento)
{
    return $test->actingAs($chi)->from(route('admin.inbox'))->patch(route('admin.inbox.complete', $evento));
}

test('Coda 402 — con il solo accesso al pannello la casella delle attività non si apre', function () {
    $risposta = $this->actingAs(casellaSuMisura([]))->get(route('admin.inbox'));

    $risposta->assertForbidden();
    expect($risposta->getContent())->not->toContain('Verifica incasso della rata 3')->not->toContain("Mario D'Àngelo")->not->toContain('12345');
});

test('Coda 402 — la casella delle attività si apre con «Visualizza scadenze in agenda», come le scadenze della dashboard del pannello', function () {
    $agenda = casellaSuMisura([Permission::VIEW_EVENTS->value]);

    expect(casellaCompitiDellaPagina($this, $agenda))->toBe(['Verifica incasso della rata 3 di Mario']);
    $this->actingAs($agenda)->get(route('admin.dashboard'))->assertOk()->assertInertia(fn ($pagina) => expect($pagina->toArray()['props'])->toHaveKey('eventi'));
});

test('Coda 402 — senza «Modifica scadenza in agenda» un compito della casella non si rifiuta e non si completa', function (array $permessi) {
    $chi = casellaSuMisura($permessi);

    casellaRifiuta($this, $chi, $this->compito)->assertForbidden();
    casellaCompleta($this, $chi, $this->compito)->assertForbidden();
    // Il permesso prima della validazione: senza il motivo il rifiuto è lo stesso.
    $this->actingAs($chi)->postJson(route('admin.inbox.reject', $this->compito), [])
        ->assertForbidden()->assertJson(['message' => __('policies.edit_events')])->assertJsonMissingPath('errors');

    expect($this->compito->fresh()->is_completed)->toBeFalsy()
        ->and($this->rata->fresh()->meta['status'])->toBe('reported');
})->with([
    'il solo accesso' => [[]],
    'la sola consultazione dell\'agenda' => [[Permission::VIEW_EVENTS->value]],
]);

test('Coda 402 — con «Modifica scadenza in agenda» un compito della casella si rifiuta, e la segnalazione collegata si segna rifiutata; un altro si completa', function () {
    $chi = casellaSuMisura([Permission::VIEW_EVENTS->value, Permission::EDIT_EVENTS->value]);
    $altro = casellaCompito($this, 'Verifica incasso di un altro pagamento');

    casellaRifiuta($this, $chi, $this->compito)->assertRedirect(route('admin.inbox'));
    casellaCompleta($this, $chi, $altro)->assertRedirect(route('admin.inbox'));

    $rata = $this->rata->fresh();
    expect($this->compito->fresh()->is_completed)->toBeTruthy()
        ->and($altro->fresh()->is_completed)->toBeTruthy()
        ->and($rata->meta['status'])->toBe('rejected')
        ->and($rata->meta['rejection_reason'])->toBe('Bonifico non ancora arrivato');
});

test('Coda 402 — «Rifiuta» chiude soltanto un compito aperto della casella: non una scadenza privata di un condòmino, non una scadenza qualunque, non un compito già chiuso', function () {
    $admin = casellaUtente(Role::AMMINISTRATORE->value);
    $pubblica = Evento::create([
        'title' => 'Assemblea di primavera', 'start_time' => now()->addDays(3), 'end_time' => now()->addDays(3)->addHour(),
        'created_by' => $admin->id, 'visibility' => VisibilityStatus::PUBLIC->value, 'is_approved' => true,
    ]);
    $pubblica->condomini()->attach($this->aurora->id);
    $chiuso = casellaCompito($this, 'Verifica già fatta');
    $chiuso->update(['is_completed' => true, 'completed_at' => now()->subHour()]);

    foreach (['la rata privata del condòmino' => $this->rata, 'una scadenza dell\'agenda' => $pubblica, 'un compito già chiuso' => $chiuso] as $caso => $evento) {
        expect(casellaRifiuta($this, $admin, $evento)->status(), $caso)->toBe(404);
    }

    $rata = $this->rata->fresh();
    expect($rata->is_completed)->toBeFalsy()
        ->and($rata->meta['status'])->toBe('reported')
        ->and($rata->meta)->not->toHaveKey('rejection_reason')
        ->and($pubblica->fresh()->is_completed)->toBeFalsy()
        ->and($chiuso->fresh()->completed_at->lt(now()->subMinutes(30)))->toBeTrue();
});

test('Coda 402 — l\'amministratore e il collaboratore aprono la casella, rifiutano e completano i compiti', function (string $ruolo) {
    $chi = casellaUtente($ruolo);
    $altro = casellaCompito($this, 'Verifica incasso di un altro pagamento');

    expect(casellaCompitiDellaPagina($this, $chi))->toContain('Verifica incasso della rata 3 di Mario');
    casellaRifiuta($this, $chi, $this->compito)->assertRedirect(route('admin.inbox'));
    casellaCompleta($this, $chi, $altro)->assertRedirect(route('admin.inbox'));

    expect($this->compito->fresh()->is_completed)->toBeTruthy()
        ->and($altro->fresh()->is_completed)->toBeTruthy()
        ->and($this->rata->fresh()->meta['status'])->toBe('rejected');
})->with([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value]);
