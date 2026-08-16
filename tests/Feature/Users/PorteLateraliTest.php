<?php

/**
 * Le porte laterali: togliere poteri, non prenderseli.
 *
 * ## Perché questo file esiste
 *
 * La beta.55 ha chiuso tre modi di **prendersi** poteri che non spettano: assegnarsi un ruolo
 * privilegiato, concedersi un permesso diretto, costruirsi un ruolo su misura. Cercando se ne
 * restassero altri, la domanda è stata rovesciata — *e per togliere?* — e ha trovato tre porte
 * aperte in tre file diversi, tutte della stessa forma: **un'azione di governo senza guardia**.
 *
 * Non servono a scalare privilegi, servono a **chiudere fuori qualcun altro**, che sul piano
 * pratico è lo stesso danno: un'installazione governata da chi resta dentro.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre l'eliminazione di un ruolo (`RoleController::destroy`), che i ruoli protetti già
 * rifiuta e per i ruoli su misura riassegna gli utenti al ruolo predefinito. Non copre gli inviti.
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

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $this->collaboratore->assignRole(RoleEnum::COLLABORATORE->value);

    $this->condomino = User::factory()->create(['email_verified_at' => now()]);
    $this->condomino->assignRole(RoleEnum::UTENTE->value);
});

it('un condòmino non può revocare un permesso diretto a un altro utente', function () {
    $permesso = Permission::where('name', PermissionEnum::VIEW_USERS->value)->firstOrFail();
    $this->collaboratore->givePermissionTo($permesso);

    $this->actingAs($this->condomino)
        ->delete(route('users.permissions.destroy', [
            'user'       => $this->collaboratore->id,
            'permission' => $permesso->id,
        ]))
        ->assertForbidden();

    expect($this->collaboratore->fresh()->hasDirectPermission(PermissionEnum::VIEW_USERS->value))->toBeTrue();
});

it('un condòmino non può svuotare il ruolo amministratore un permesso alla volta', function () {
    // La porta peggiore delle tre: `RoleController` protegge i quattro ruoli di sistema, ma questa
    // rotta vive in un controller a parte e non passava da nessuna guardia.
    $permesso = Permission::where('name', PermissionEnum::DELETE_USERS->value)->firstOrFail();
    $ruolo = Role::where('name', RoleEnum::AMMINISTRATORE->value)->firstOrFail();

    $this->actingAs($this->condomino)
        ->delete(route('ruoli.permissions.destroy', [
            'role'       => $ruolo->id,
            'permission' => $permesso->id,
        ]))
        ->assertForbidden();

    expect($ruolo->fresh()->hasPermissionTo(PermissionEnum::DELETE_USERS->value))->toBeTrue();
});

it('nemmeno un collaboratore può toccare i permessi dei ruoli di sistema', function () {
    $permesso = Permission::where('name', PermissionEnum::DELETE_USERS->value)->firstOrFail();
    $ruolo = Role::where('name', RoleEnum::AMMINISTRATORE->value)->firstOrFail();

    $this->actingAs($this->collaboratore)
        ->delete(route('ruoli.permissions.destroy', [
            'role'       => $ruolo->id,
            'permission' => $permesso->id,
        ]))
        ->assertForbidden();

    expect($ruolo->fresh()->hasPermissionTo(PermissionEnum::DELETE_USERS->value))->toBeTrue();
});

it('un collaboratore non può togliere la verifica email a un amministratore', function () {
    // Togliere la verifica è un modo di chiudere fuori: il middleware `verified` sta su tutto il
    // programma, quindi un amministratore non verificato non arriva da nessuna parte.
    $this->actingAs($this->collaboratore)
        ->put(route('utenti.toggle-verification', ['user' => $this->amministratore->id]))
        ->assertForbidden();

    expect($this->amministratore->fresh()->email_verified_at)->not->toBeNull();
});

it('il collaboratore continua a poter verificare un condòmino', function () {
    // Controprova: la guardia non deve rompere il lavoro legittimo.
    $this->condomino->update(['email_verified_at' => null]);

    $this->actingAs($this->collaboratore)
        ->put(route('utenti.toggle-verification', ['user' => $this->condomino->id]));

    expect($this->condomino->fresh()->email_verified_at)->not->toBeNull();
});
