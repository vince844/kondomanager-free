<?php

/**
 * Eliminare un ruolo su misura dall'elenco dei ruoli (Coda 258, decisione 134.1).
 *
 * ## Perché questo file esiste
 *
 * Eliminare un ruolo fa tre cose: toglie il ruolo a chi ce l'ha, dà «utente» a chi resta senza ruoli, elimina il
 * ruolo con i suoi permessi. Le tre cose stanno in una transazione: se una non va a buon fine, la pagina dice
 * l'errore e il ruolo, i suoi permessi e i suoi utenti restano com'erano.
 *
 * La conferma di «Elimina» (`resources/js/components/roles/DataTableRowActions.vue`) conta gli utenti del ruolo che
 * l'elenco riceve (`users_count`) e dice che cosa succede loro: perdono quel ruolo, e chi non ne ha un altro riceve
 * «utente». Il secondo blocco fissa dalla rotta quello che la frase promette, con un utente che ha due ruoli.
 *
 * ## Cosa questo file NON copre
 *
 * Chi può eliminare un ruolo e i ruoli che non si eliminano (`ChiPuoToccareChiTest.php`,
 * `RuoloDeiNuoviIscrittiTest.php`); la frase della conferma (`DataTableRowActions.test.ts`).
 */

use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(RoleEnum::AMMINISTRATORE->value);

    $this->consigliere = Role::create(['name' => 'Consigliere «Aurora»', 'guard_name' => 'web', 'description' => 'Consiglio di condominio']);
    $this->consigliere->givePermissionTo(PermissionEnum::VIEW_COMUNICAZIONI->value, PermissionEnum::VIEW_SEGNALAZIONI->value);
});

function eurUtente(string|Role ...$ruoli): User
{
    $utente = User::factory()->create(['email_verified_at' => now()]);
    $utente->assignRole($ruoli);

    return $utente;
}

/** I ruoli di un utente letti dal database, in ordine. */
function eurRuoli(User $utente): array
{
    return User::findOrFail($utente->id)->getRoleNames()->sort()->values()->all();
}

/** «Elimina» confermato nell'elenco dei ruoli, come lo manda la pagina. */
function eurElimina($test, User $chi, Role $ruolo)
{
    return $test->actingAs($chi)->from(route('ruoli.index'))
        ->delete(route('ruoli.destroy', ['ruoli' => $ruolo->id]));
}

// ─── La transazione ─────────────────────────────────────────────────────────────────────────────────

it('Coda 258 — se l\'eliminazione del ruolo non va a buon fine, il ruolo, i suoi permessi e i suoi utenti restano com\'erano', function () {
    $soloConsigliere = eurUtente($this->consigliere);
    $dueRuoli = eurUtente($this->consigliere, RoleEnum::COLLABORATORE->value);

    Event::listen('eloquent.deleting: ' . Role::class, function () {
        throw new RuntimeException('eliminazione interrotta');
    });

    eurElimina($this, $this->amministratore, $this->consigliere)
        ->assertRedirect(route('ruoli.index'))
        ->assertSessionHas('message.type', 'error')
        ->assertSessionHas('message.message', __('ruoli.error_delete_role'));

    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $ruolo = Role::findOrFail($this->consigliere->id);

    expect($ruolo->name)->toBe('Consigliere «Aurora»')
        ->and($ruolo->permissions->pluck('name')->sort()->values()->all())
        ->toBe([PermissionEnum::VIEW_COMUNICAZIONI->value, PermissionEnum::VIEW_SEGNALAZIONI->value])
        ->and(eurRuoli($soloConsigliere))->toBe(['Consigliere «Aurora»'])
        ->and(eurRuoli($dueRuoli))->toBe(['Consigliere «Aurora»', RoleEnum::COLLABORATORE->value]);
});

it('Coda 258 — se dare «utente» a chi resta senza ruoli non va a buon fine, chi era già passato riprende il ruolo', function () {
    $primo = eurUtente($this->consigliere);
    $secondo = eurUtente($this->consigliere);

    // Il primo utente che resta senza ruoli perde il ruolo eliminato e riceve «utente»: lì l'errore.
    config(['permission.events_enabled' => true]);
    Event::listen(RoleAttachedEvent::class, function () {
        throw new RuntimeException('riassegnazione interrotta');
    });

    eurElimina($this, $this->amministratore, $this->consigliere)
        ->assertRedirect(route('ruoli.index'))
        ->assertSessionHas('message.type', 'error');

    expect(Role::whereKey($this->consigliere->id)->exists())->toBeTrue()
        ->and(eurRuoli($primo))->toBe(['Consigliere «Aurora»'])
        ->and(eurRuoli($secondo))->toBe(['Consigliere «Aurora»']);
});

// ─── Quello che la conferma promette ────────────────────────────────────────────────────────────────

it('Coda 258 — la conferma conta tutti gli utenti del ruolo: lo perdono tutti, e «utente» lo riceve solo chi resta senza ruoli', function () {
    $dueRuoli = eurUtente($this->consigliere, RoleEnum::COLLABORATORE->value);
    $soloConsigliere = eurUtente($this->consigliere);

    // Il numero che la conferma mostra: `users_count` della riga, come lo riceve l'elenco.
    $righe = collect($this->actingAs($this->amministratore)->get(route('ruoli.index'))
        ->assertOk()->viewData('page')['props']['roles']);
    $righe = collect($righe['data'] ?? $righe);
    expect($righe->firstWhere('id', $this->consigliere->id)['users_count'])->toBe(2);

    eurElimina($this, $this->amministratore, $this->consigliere)
        ->assertRedirect(route('ruoli.index'))
        ->assertSessionHas('message.message', __('ruoli.success_delete_with_reassign', [
            'count' => 1, 'default_role' => RoleEnum::UTENTE->value,
        ]));

    expect(Role::whereKey($this->consigliere->id)->exists())->toBeFalse()
        ->and(eurRuoli($dueRuoli))->toBe([RoleEnum::COLLABORATORE->value])
        ->and(eurRuoli($soloConsigliere))->toBe([RoleEnum::UTENTE->value]);
});
