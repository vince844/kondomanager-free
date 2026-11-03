<?php

/**
 * # L'elenco del portale a chi vede solo le proprie (decisione 134.4)
 *
 * Il permesso «Visualizza proprie segnalazioni» (e il gemello delle comunicazioni) lo dà un ruolo su
 * misura: la scheda gli apre le proprie. L'elenco del portale però chiedeva alla policy `view` con un
 * modello vuoto, che per «solo le proprie» confronta l'autore di nessuna segnalazione con l'utente, e
 * rispondeva 403: chi apriva una segnalazione dal portale veniva rimandato all'elenco, e lì rifiutato.
 *
 * Ora l'elenco si apre a chi ha uno dei due permessi, e mostra ciò che la regola del portale gli
 * concede (`scopeVisibiliNelPortale()`): a «solo le proprie», le sue pubblicate e approvate. Chi non ha
 * nessuno dei due riceve ancora 403.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role as RuoloSpatie;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->aurora = Condominio::factory()->create(['nome' => "Condominio L'Aurora"]);
    $this->vicino = User::factory()->create(['name' => 'Luigi Verdi']);

    RuoloSpatie::findOrCreate('Proprie', 'web')->syncPermissions([
        Permission::CREATE_SEGNALAZIONI->value, Permission::PUBLISH_SEGNALAZIONI->value, Permission::VIEW_OWN_SEGNALAZIONI->value,
        Permission::VIEW_OWN_COMUNICAZIONI->value,
    ]);
    RuoloSpatie::findOrCreate('Solo agenda', 'web')->syncPermissions([Permission::VIEW_EVENTS->value]);
});

function elencoProprieUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now(), 'name' => 'Mario Rossi']);
    $user->assignRole($ruolo);
    Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => 'Mario Rossi'])->condomini()->attach(test()->aurora->id);

    return $user->fresh();
}

function elencoProprieSegnalazione(User $autore, string $oggetto, array $stato = []): Segnalazione
{
    return Segnalazione::create(array_merge([
        'subject' => $oggetto, 'description' => 'x', 'priority' => 'alta', 'stato' => 'aperta',
        'condominio_id' => test()->aurora->id, 'created_by' => $autore->id, 'is_private' => false,
        'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => true,
    ], $stato));
}

function elencoProprieComunicazione(User $autore, string $oggetto, array $stato = []): Comunicazione
{
    $c = Comunicazione::create(array_merge([
        'subject' => $oggetto, 'description' => 'x', 'priority' => 'media', 'created_by' => $autore->id,
        'is_private' => false, 'is_published' => true, 'is_approved' => true, 'is_featured' => false, 'can_comment' => false,
    ], $stato));
    $c->condomini()->attach(test()->aurora->id);

    return $c;
}

/** Gli oggetti che l'elenco del portale manda alla pagina. */
function elencoProprieOggetti(User $user, string $rotta, string $chiave): array
{
    $props = test()->actingAs($user)->get(route($rotta))->assertOk()->viewData('page')['props'];

    return collect($props[$chiave]['data'])->pluck('subject')->sort()->values()->all();
}

test('decisione 134 — chi vede solo le proprie segnalazioni apre una segnalazione dal portale, e l\'elenco dove arriva si apre con le sue', function () {
    $mario = elencoProprieUtente('Proprie');
    elencoProprieSegnalazione($this->vicino, 'Ascensore fermo del vicino');
    elencoProprieSegnalazione($mario, "Perdita nel box «2»");
    elencoProprieSegnalazione($mario, 'La mia in moderazione', ['is_approved' => false]);

    $this->actingAs($mario)->post(route('user.segnalazioni.store'), [
        'subject' => "Cancello dell'ingresso rotto", 'description' => 'x', 'priority' => 'alta', 'stato' => 'aperta',
        'condominio_id' => $this->aurora->id, 'is_private' => false,
    ])->assertSessionHasNoErrors()->assertRedirect(route('user.segnalazioni.index'));

    // Le sue pubblicate e approvate; non quella del vicino, e non la sua in moderazione (Coda 389).
    expect(elencoProprieOggetti($mario, 'user.segnalazioni.index', 'segnalazioni'))
        ->toBe(["Cancello dell'ingresso rotto", "Perdita nel box «2»"]);
});

test('decisione 134 — chi vede solo le proprie comunicazioni apre l\'elenco del portale e ci trova le sue', function () {
    $mario = elencoProprieUtente('Proprie');
    elencoProprieComunicazione($this->vicino, 'Turni delle pulizie del vicino');
    elencoProprieComunicazione($mario, "L'assemblea di giugno");

    expect(elencoProprieOggetti($mario, 'user.comunicazioni.index', 'comunicazioni'))->toBe(["L'assemblea di giugno"]);
});

test('decisione 134 — chi vede solo le proprie comunicazioni, e nient\'altro, apre l\'elenco delle comunicazioni e non quello delle segnalazioni', function () {
    // Un permesso solo: ognuna delle due policy deve guardare il suo, non quello del gemello.
    RuoloSpatie::findOrCreate('Solo proprie comunicazioni', 'web')->syncPermissions([Permission::VIEW_OWN_COMUNICAZIONI->value]);
    $mario = elencoProprieUtente('Solo proprie comunicazioni');
    elencoProprieComunicazione($this->vicino, 'Turni delle pulizie del vicino');
    elencoProprieComunicazione($mario, "L'assemblea di giugno");
    elencoProprieSegnalazione($mario, 'La sua segnalazione');

    expect(elencoProprieOggetti($mario, 'user.comunicazioni.index', 'comunicazioni'))->toBe(["L'assemblea di giugno"]);
    $this->actingAs($mario)->get(route('user.segnalazioni.index'))->assertForbidden();
});

test('decisione 134 — chi non vede né le segnalazioni né le comunicazioni non apre i due elenchi del portale', function () {
    $mario = elencoProprieUtente('Solo agenda');
    elencoProprieSegnalazione($mario, 'La sua');

    $this->actingAs($mario)->get(route('user.segnalazioni.index'))->assertForbidden();
    $this->actingAs($mario)->get(route('user.comunicazioni.index'))->assertForbidden();
});

test('decisione 134 — il condòmino di serie apre i due elenchi come prima, con quelle del palazzo', function () {
    $mario = elencoProprieUtente(Role::UTENTE->value);
    elencoProprieSegnalazione($this->vicino, 'Ascensore fermo del vicino');
    elencoProprieComunicazione($this->vicino, 'Turni delle pulizie del vicino');

    expect(elencoProprieOggetti($mario, 'user.segnalazioni.index', 'segnalazioni'))->toBe(['Ascensore fermo del vicino'])
        ->and(elencoProprieOggetti($mario, 'user.comunicazioni.index', 'comunicazioni'))->toBe(['Turni delle pulizie del vicino']);
});

test('decisione 134 — chi vede tutte le comunicazioni, e nient\'altro, apre l\'elenco delle comunicazioni con quelle del palazzo e non quello delle segnalazioni', function () {
    // Il primo dei due permessi di ciascuna policy, da solo: ognuna deve guardare il suo, non quello del gemello.
    RuoloSpatie::findOrCreate('Solo comunicazioni', 'web')->syncPermissions([Permission::VIEW_COMUNICAZIONI->value]);
    $mario = elencoProprieUtente('Solo comunicazioni');
    elencoProprieComunicazione($this->vicino, 'Turni delle pulizie del vicino');
    elencoProprieSegnalazione($this->vicino, 'Ascensore fermo del vicino');

    expect(elencoProprieOggetti($mario, 'user.comunicazioni.index', 'comunicazioni'))->toBe(['Turni delle pulizie del vicino']);
    $this->actingAs($mario)->get(route('user.segnalazioni.index'))->assertForbidden();
});

test('decisione 134 — chi vede tutte le segnalazioni, e nient\'altro, apre l\'elenco delle segnalazioni con quelle del palazzo e non quello delle comunicazioni', function () {
    RuoloSpatie::findOrCreate('Solo segnalazioni', 'web')->syncPermissions([Permission::VIEW_SEGNALAZIONI->value]);
    $mario = elencoProprieUtente('Solo segnalazioni');
    elencoProprieSegnalazione($this->vicino, 'Ascensore fermo del vicino');
    elencoProprieComunicazione($this->vicino, 'Turni delle pulizie del vicino');

    expect(elencoProprieOggetti($mario, 'user.segnalazioni.index', 'segnalazioni'))->toBe(['Ascensore fermo del vicino']);
    $this->actingAs($mario)->get(route('user.comunicazioni.index'))->assertForbidden();
});
