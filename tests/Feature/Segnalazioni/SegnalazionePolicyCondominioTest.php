<?php

/**
 * Giro di sicurezza della 1.11.0-beta.39, Coda 185: `SegnalazionePolicy::update` e `delete`
 * rispondevano sì a **qualunque** segnalazione a chi aveva `EDIT_SEGNALAZIONI` o
 * `DELETE_SEGNALAZIONI`, senza guardare il condominio. Nei ruoli di serie questi due permessi
 * vanno sempre insieme ad «Accesso pannello amministratore» (amministratore, collaboratore), che
 * gestisce l'intera installazione per disegno — la falla si apre solo quando un permesso viene
 * concesso **da solo**, a un ruolo su misura senza l'accesso al pannello: esattamente il caso
 * provato qui.
 *
 * Il secondo giro di revisione ha trovato la stessa lacuna delle altre tre policy: con il permesso
 * largo senza pannello si modificava e cancellava la segnalazione **privata di un vicino** dello
 * stesso palazzo, che `show` invece nega. Ora vale la stessa regola di `PerimetroFuoriPannello`:
 * niente segnalazioni indirizzate ad altre persone.
 *
 * **Cosa resta scoperto**: `view`/`show`/`create`/`approve` della stessa policy, non toccati da
 * questa Coda.
 */

use App\Enums\Permission;
use App\Models\Condominio;
use App\Models\Segnalazione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role as SpatieRole;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function utenteConPermessoLibero(Condominio $condominio, string $permesso): User
{
    // Un ruolo su misura: il permesso «largo» (EDIT_SEGNALAZIONI / DELETE_SEGNALAZIONI) senza
    // l'accesso al pannello, e un'anagrafica in un solo condominio.
    SpatieRole::firstOrCreate(['name' => 'ruolo-di-prova-'.$permesso]);
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole('ruolo-di-prova-'.$permesso);
    $user->givePermissionTo($permesso);

    $anagrafica = \App\Models\Anagrafica::factory()->create(['user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    return $user;
}

test('EDIT_SEGNALAZIONI da solo non basta per una segnalazione di un altro palazzo', function () {
    $suo = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    $user = utenteConPermessoLibero($suo, Permission::EDIT_SEGNALAZIONI->value);
    $segnalazione = Segnalazione::factory()->create(['condominio_id' => $altro->id]);

    expect($user->can('update', $segnalazione))->toBeFalse();
});

test('EDIT_SEGNALAZIONI da solo basta per il proprio palazzo', function () {
    $condominio = Condominio::factory()->create();
    $user = utenteConPermessoLibero($condominio, Permission::EDIT_SEGNALAZIONI->value);
    $segnalazione = Segnalazione::factory()->create(['condominio_id' => $condominio->id]);

    expect($user->can('update', $segnalazione))->toBeTrue();
});

test('DELETE_SEGNALAZIONI da solo non basta per una segnalazione di un altro palazzo', function () {
    $suo = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    $user = utenteConPermessoLibero($suo, Permission::DELETE_SEGNALAZIONI->value);
    $segnalazione = Segnalazione::factory()->create(['condominio_id' => $altro->id]);

    expect($user->can('delete', $segnalazione))->toBeFalse();
});

test('DELETE_SEGNALAZIONI da solo basta per il proprio palazzo', function () {
    $condominio = Condominio::factory()->create();
    $user = utenteConPermessoLibero($condominio, Permission::DELETE_SEGNALAZIONI->value);
    $segnalazione = Segnalazione::factory()->create(['condominio_id' => $condominio->id]);

    expect($user->can('delete', $segnalazione))->toBeTrue();
});

test('amministratore ed EDIT_SEGNALAZIONI insieme all\'accesso al pannello vedono ogni palazzo', function () {
    $altro = Condominio::factory()->create();
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(\App\Enums\Role::AMMINISTRATORE->value);
    $segnalazione = Segnalazione::factory()->create(['condominio_id' => $altro->id]);

    expect($user->can('update', $segnalazione))->toBeTrue()
        ->and($user->can('delete', $segnalazione))->toBeTrue();
});

test('il permesso largo senza pannello non tocca la segnalazione privata di un vicino dello stesso palazzo', function () {
    $condominio = Condominio::factory()->create();
    $user = utenteConPermessoLibero($condominio, Permission::EDIT_SEGNALAZIONI->value);
    $user->givePermissionTo(Permission::DELETE_SEGNALAZIONI->value);

    $vicino = \App\Models\Anagrafica::factory()->create();
    $vicino->condomini()->attach($condominio->id);
    $privata = Segnalazione::factory()->create(['condominio_id' => $condominio->id, 'is_private' => true]);
    $privata->anagrafiche()->attach($vicino->id);

    expect($user->can('update', $privata))->toBeFalse()
        ->and($user->can('delete', $privata))->toBeFalse();

    $this->actingAs($user)->delete(route('user.segnalazioni.destroy', $privata))->assertForbidden();
    expect(Segnalazione::whereKey($privata->id)->exists())->toBeTrue();

    // Controprove: la segnalazione pubblica del palazzo e la propria privata restano sue.
    $pubblica = Segnalazione::factory()->create(['condominio_id' => $condominio->id, 'is_private' => false]);
    $propria = Segnalazione::factory()->create(['condominio_id' => $condominio->id, 'is_private' => true]);
    $propria->anagrafiche()->attach($user->anagrafica->id);

    expect($user->can('update', $pubblica))->toBeTrue()
        ->and($user->can('update', $propria))->toBeTrue();
});
