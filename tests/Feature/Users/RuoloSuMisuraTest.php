<?php

/**
 * La porta laterale: costruirsi un ruolo su misura invece di prendersi quello privilegiato.
 *
 * ## Perché questo file esiste
 *
 * La beta.55 ha chiuso l'assegnazione dei ruoli privilegiati — `amministratore` e `collaboratore`
 * li concede solo un amministratore — e la concessione dei permessi singoli. Resta da verificare
 * la terza strada: `RolePolicy` lascia **creare ruoli** anche al collaboratore, e un ruolo creato
 * a mano non è privilegiato per definizione. Se dentro ci si possono mettere permessi che l'attore
 * non ha, e poi lo si può indossare, la regola sui ruoli si aggira costruendone uno nuovo.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la modifica dei ruoli esistenti oltre al caso qui verificato, né la revoca di un
 * permesso da un ruolo: se il difetto è reale, la correzione va coperta su entrambi i versi.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $this->collaboratore->assignRole(RoleEnum::COLLABORATORE->value);
});

it('un collaboratore non può mettere in un ruolo nuovo un permesso che non possiede', function () {
    // «Elimina utenti» non è fra i permessi del collaboratore.
    expect($this->collaboratore->hasPermissionTo(PermissionEnum::DELETE_USERS->value))->toBeFalse();

    $permesso = Permission::where('name', PermissionEnum::DELETE_USERS->value)->firstOrFail();

    $this->actingAs($this->collaboratore)->post(route('ruoli.store'), [
        'name'        => 'Ruolo su misura',
        'description' => 'Creato per aggirare la regola',
        'permissions' => [$permesso->id],
        'accessAdmin' => false,
    ]);

    $ruolo = Role::where('name', 'Ruolo su misura')->first();

    expect($ruolo?->hasPermissionTo(PermissionEnum::DELETE_USERS->value) ?? false)->toBeFalse();
});

it('e non può indossarlo per ritrovarsi i poteri che non gli spettano', function () {
    $permesso = Permission::where('name', PermissionEnum::DELETE_USERS->value)->firstOrFail();

    $this->actingAs($this->collaboratore)->post(route('ruoli.store'), [
        'name'        => 'Ruolo su misura',
        'description' => 'Creato per aggirare la regola',
        'permissions' => [$permesso->id],
        'accessAdmin' => false,
    ]);

    $ruolo = Role::where('name', 'Ruolo su misura')->first();

    if ($ruolo) {
        $this->actingAs($this->collaboratore)->put(
            route('utenti.update', ['utenti' => $this->collaboratore->id]),
            [
                'name'        => $this->collaboratore->name,
                'email'       => $this->collaboratore->email,
                'roles'       => $ruolo->id,
                'permissions' => [],
                'anagrafica'  => null,
            ]
        );
    }

    expect($this->collaboratore->fresh()->hasPermissionTo(PermissionEnum::DELETE_USERS->value))->toBeFalse();
});
