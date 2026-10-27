<?php

/**
 * # Le scadenze nella dashboard del portale (decisione 136.3)
 *
 * L'agenda del portale si apre solo a chi ha «Visualizza scadenze in agenda» (`EventoPolicy::viewAny`).
 * La dashboard del portale fa la stessa domanda prima di mandare le scadenze dei prossimi giorni:
 * un ruolo su misura senza l'agenda non le trova nemmeno nella prima pagina dopo l'accesso.
 *
 * Senza il permesso le scadenze non arrivano alla pagina, titoli compresi. Con il permesso,
 * come prima.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Enums\VisibilityStatus;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role as RuoloSpatie;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Notification::fake();
    $this->withoutVite();

    $this->condominio = Condominio::factory()->create(['nome' => "Condominio L'Aurora"]);
    $staff = User::factory()->create();
    $this->evento = Evento::create([
        'title' => "Assemblea straordinaria sull'ascensore", 'start_time' => now()->addDays(5), 'end_time' => now()->addDays(5)->addHours(2),
        'created_by' => $staff->id, 'visibility' => VisibilityStatus::PUBLIC->value, 'is_approved' => true,
    ]);
    $this->evento->condomini()->attach($this->condominio->id);

    RuoloSpatie::findOrCreate('Senza agenda', 'web')->syncPermissions([Permission::VIEW_SEGNALAZIONI->value]);
});

function scadenzeDashboardUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);
    Anagrafica::factory()->create(['user_id' => $user->id])->condomini()->attach(test()->condominio->id);

    return $user->fresh();
}

/** Le scadenze che la dashboard del portale manda alla pagina, come testo. */
function scadenzeDashboardEventi(User $user): string
{
    $props = test()->actingAs($user)->get(route('user.dashboard'))->assertOk()->viewData('page')['props'];

    return (string) json_encode($props['eventi'] ?? null, JSON_UNESCAPED_UNICODE);
}

test('decisione 136 — chi non ha «Visualizza scadenze in agenda» non trova le scadenze nella dashboard del portale, come l\'agenda gli nega', function () {
    $user = scadenzeDashboardUtente('Senza agenda');

    $this->actingAs($user)->get(route('user.eventi.index'))->assertForbidden();
    expect(scadenzeDashboardEventi($user))->not->toContain("Assemblea straordinaria sull'ascensore");
});

test('decisione 136 — il condòmino di serie trova le scadenze nella dashboard del portale come prima', function () {
    $user = scadenzeDashboardUtente(Role::UTENTE->value);

    $this->actingAs($user)->get(route('user.eventi.index'))->assertOk();
    expect(scadenzeDashboardEventi($user))->toContain("Assemblea straordinaria sull'ascensore");
});
