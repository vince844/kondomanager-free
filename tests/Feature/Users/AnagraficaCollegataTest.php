<?php

/**
 * La scheda di un utente con l'anagrafica collegata.
 *
 * ## Perché questo file esiste
 *
 * `UpdateUserRequest` controlla che l'anagrafica scelta non sia già di un altro utente, e lascia
 * passare quella che è già sua. Il confronto era fra i due oggetti `User` con `==`: dalla
 * 1.10.0-beta.55 le regole sui ruoli caricano i ruoli del bersaglio prima di arrivare all'anagrafica,
 * i due oggetti non risultano più uguali, e la scheda di un condòmino con la sua anagrafica non si
 * salvava più — nemmeno rimandata com'era — con l'errore «Questa anagrafica è già associata
 * all'utente» e il nome dello stesso utente. Il confronto ora è per id (decisione 130.3).
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la creazione di un utente (`CreateUserRequest` rifiuta ogni anagrafica già collegata,
 * e un utente nuovo non ne ha una sua), né chi può modificare chi (`PorteLateraliTest.php`,
 * `PrivilegiUtentiTest.php`).
 */

use App\Enums\Role as RoleEnum;
use App\Models\Anagrafica;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $this->collaboratore->assignRole(RoleEnum::COLLABORATORE->value);

    $this->condomino = User::factory()->create(['name' => "D'Àngelo Niccolò", 'email' => 'niccolo.dangelo@example.com', 'email_verified_at' => now()]);
    $this->condomino->assignRole(RoleEnum::UTENTE->value);

    $this->anagrafica = Anagrafica::factory()->create(['user_id' => $this->condomino->id, 'nome' => "D'Àngelo Niccolò"]);
});

/** Il corpo come lo costruisce `ModificaUtente.vue` dalle props della pagina di modifica. */
function corpoDallaPaginaDiModifica($test, User $chiModifica, User $utente): array
{
    $props = null;
    $test->actingAs($chiModifica)
        ->get(route('utenti.edit', ['utenti' => $utente->id]))
        ->assertOk()
        ->assertInertia(function ($pagina) use (&$props) {
            $props = $pagina->toArray()['props']['user'];
        });

    $permessiDelRuolo = collect($props['roles'][0]['permissions'] ?? [])->pluck('id')->all();

    return [
        'name'        => $props['name'],
        'email'       => $props['email'],
        'roles'       => $props['roles'][0]['id'],
        'permissions' => collect($props['permissions'])->pluck('id')->reject(fn ($id) => in_array($id, $permessiDelRuolo, true))->values()->all(),
        'anagrafica'  => $props['anagrafica']['id'] ?? null,
    ];
}

it('l\'amministratore rimanda uguale la scheda di un condòmino con l\'anagrafica collegata: si salva', function () {
    $this->actingAs($this->amministratore)
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), [
            'name'        => $this->condomino->name,
            'email'       => $this->condomino->email,
            'roles'       => $this->condomino->roles->first()->id,
            'permissions' => [],
            'anagrafica'  => $this->anagrafica->id,
        ])
        ->assertSessionHasNoErrors();

    expect($this->anagrafica->fresh()->user_id)->toBe($this->condomino->id);
});

it('la scheda di un condòmino con l\'anagrafica collegata, aperta e mandata senza toccare niente, si salva com\'era', function () {
    foreach ([$this->amministratore, $this->collaboratore] as $chiModifica) {
        $corpo = corpoDallaPaginaDiModifica($this, $chiModifica, $this->condomino);
        expect($corpo['anagrafica'])->toBe($this->anagrafica->id);

        $this->actingAs($chiModifica)
            ->put(route('utenti.update', ['utenti' => $this->condomino->id]), $corpo)
            ->assertSessionHasNoErrors();

        $dopo = $this->condomino->fresh();
        expect($dopo->name)->toBe("D'Àngelo Niccolò")
            ->and($dopo->email)->toBe('niccolo.dangelo@example.com')
            ->and($dopo->getRoleNames()->all())->toBe([RoleEnum::UTENTE->value])
            ->and($dopo->email_verified_at)->not->toBeNull()
            ->and($this->anagrafica->fresh()->user_id)->toBe($this->condomino->id);
    }
});

it('l\'anagrafica collegata a un altro utente resta un errore', function () {
    $altro = User::factory()->create(['email_verified_at' => now()]);
    $altro->assignRole(RoleEnum::UTENTE->value);
    $diUnAltro = Anagrafica::factory()->create(['user_id' => $altro->id]);

    $this->actingAs($this->amministratore)
        ->put(route('utenti.update', ['utenti' => $this->condomino->id]), [
            'name'        => $this->condomino->name,
            'email'       => $this->condomino->email,
            'roles'       => $this->condomino->roles->first()->id,
            'permissions' => [],
            'anagrafica'  => $diUnAltro->id,
        ])
        ->assertSessionHasErrors('anagrafica');

    expect($diUnAltro->fresh()->user_id)->toBe($altro->id)
        ->and($this->anagrafica->fresh()->user_id)->toBe($this->condomino->id);
});
