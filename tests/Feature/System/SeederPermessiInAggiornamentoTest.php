<?php

/**
 * L'aggiornamento deve portare a database i permessi e la mappa dei ruoli.
 *
 * ## Perché questo file esiste
 *
 * Permessi e mappa ruoli vivono solo nel codice — `App\Enums\Permission` e `Role::permissions()` —
 * e a database ce li porta unicamente `RolesAndPermissionsSeeder`, che fino alla beta.55 girava
 * **solo dall'installer**. L'aggiornamento di un'installazione esistente eseguiva soltanto
 * `migrate --force`, quindi ogni modifica a quei due file restava nel codice e non arrivava mai.
 *
 * Non è teoria: la 1.9.1-beta.8 ha aggiunto tre assegnazioni ai ruoli `fornitore` e `utente`
 * (pubblicare, modificare ed eliminare i propri commenti sulle segnalazioni) e chi ha aggiornato
 * dal pannello non le ha mai ricevute. Nessun errore: i commenti dei condòmini continuano ad
 * andare in moderazione, e la funzione annunciata non si vede.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre il ripristino da backup, che passa dallo stesso finalizer ma parte da un database
 * altrui. Non copre l'installer, che il seeder l'ha sempre eseguito.
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\CategoriaDocumento;
use App\Services\System\SystemFinalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('ricrea un permesso che a database non esiste', function () {
    Permission::where('name', PermissionEnum::DELETE_USERS->value)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    app(SystemFinalizer::class)->finalize();

    expect(Permission::where('name', PermissionEnum::DELETE_USERS->value)->exists())->toBeTrue();
});

it('riallinea la mappa dei ruoli quando il codice ha aggiunto un permesso a un ruolo', function () {
    // Lo stato di chi ha aggiornato dal pannello dopo la 1.9.1-beta.8: il permesso c'è,
    // l'assegnazione al ruolo no.
    $utente = Role::where('name', RoleEnum::UTENTE->value)->firstOrFail();
    $utente->revokePermissionTo(PermissionEnum::PUBLISH_COMMENTS_SEGNALAZIONI->value);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($utente->fresh()->hasPermissionTo(PermissionEnum::PUBLISH_COMMENTS_SEGNALAZIONI->value))->toBeFalse();

    app(SystemFinalizer::class)->finalize();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($utente->fresh()->hasPermissionTo(PermissionEnum::PUBLISH_COMMENTS_SEGNALAZIONI->value))->toBeTrue();
});

it('semina anche quando l\'aggiornamento parte dalla schermata, non solo chiamando il servizio', function () {
    // ⚠️ I due test qui sopra chiamano `finalize()` direttamente. Dimostrano che il servizio fa la
    // cosa giusta, **non** che il percorso vero ci passi: se domani qualcuno togliesse la chiamata
    // dal controller, resterebbero verdi. Questo esercita la rotta che l'amministratore preme.
    $amministratore = \App\Models\User::factory()->create(['email_verified_at' => now()]);
    $amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    Permission::where('name', PermissionEnum::SUSPEND_USERS->value)->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->actingAs($amministratore)->post(route('system.upgrade.run'));

    expect(Permission::where('name', PermissionEnum::SUSPEND_USERS->value)->exists())->toBeTrue();
});

it('non fa risorgere le tabelle di lookup che l\'amministratore ha cancellato', function () {
    // È il motivo per cui la chiamata deve essere mirata e non `db:seed` intero: i quattro seeder
    // delle tabelle master usano `firstOrCreate` sul nome, e rimetterebbero in piedi ciò che
    // l'amministratore ha eliminato di proposito.
    $this->seed(\Database\Seeders\CategoriaDocumentoSeeder::class);

    $categoria = CategoriaDocumento::firstOrFail();
    $nome = $categoria->name;
    $categoria->delete();

    app(SystemFinalizer::class)->finalize();

    expect(CategoriaDocumento::where('name', $nome)->exists())->toBeFalse();
});
