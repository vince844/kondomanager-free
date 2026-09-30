<?php

/**
 * Giro di sicurezza della 1.11.0-beta.39, Coda 185 allargata alle comunicazioni (decisione del
 * 30/09/2026 dopo la revisione avversariale): `ComunicazionePolicy::update` e `delete` rispondevano
 * sì a **qualunque** comunicazione a chi aveva `EDIT_COMUNICAZIONI` o `DELETE_COMUNICAZIONI`. Con
 * quei permessi concessi da soli, senza «Accesso pannello amministratore», un condòmino riscriveva
 * o cancellava le comunicazioni della bacheca di un altro palazzo, dalle rotte `user.*` che hanno
 * solo il login. Con i ruoli predefiniti non si raggiunge: `COLLABORATORE` ha `EDIT_COMUNICAZIONI`
 * insieme al pannello, `UTENTE` non ce l'ha.
 *
 * **Cosa resta scoperto**: il ramo `EDIT_OWN_COMUNICAZIONI` / `DELETE_OWN_COMUNICAZIONI` non cambia;
 * `view` e `approve` della stessa policy non sono toccate.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\Condominio;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->staff = User::factory()->create(['email_verified_at' => now()]);
});

/**
 * @param  array<int, string>  $permessi
 * @return array{0: User, 1: Anagrafica}
 */
function condominoConPermessoComunicazioni(Condominio $condominio, array $permessi): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::UTENTE->value);
    if ($permessi !== []) {
        $user->givePermissionTo($permessi);
    }

    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    return [$user, $anagrafica];
}

function comunicazioneIn(Condominio $condominio, User $autore): Comunicazione
{
    $comunicazione = Comunicazione::factory()->create([
        'created_by' => $autore->id,
        'subject'    => 'Avviso in bacheca',
        'is_private' => false,
    ]);
    $comunicazione->condomini()->attach($condominio->id);

    return $comunicazione;
}

test('EDIT_COMUNICAZIONI e DELETE_COMUNICAZIONI senza pannello non toccano un altro palazzo', function () {
    $suo = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    [$user] = condominoConPermessoComunicazioni($suo, [
        Permission::EDIT_COMUNICAZIONI->value,
        Permission::DELETE_COMUNICAZIONI->value,
    ]);
    $comunicazione = comunicazioneIn($altro, $this->staff);

    expect($user->can('update', $comunicazione))->toBeFalse()
        ->and($user->can('delete', $comunicazione))->toBeFalse();

    $this->actingAs($user)->delete(route('user.comunicazioni.destroy', $comunicazione))->assertForbidden();
    expect(Comunicazione::whereKey($comunicazione->id)->exists())->toBeTrue();
});

test('una comunicazione indirizzata a un\'altra persona resta fuori anche nel proprio palazzo', function () {
    $condominio = Condominio::factory()->create();
    [, $altraPersona] = condominoConPermessoComunicazioni($condominio, []);
    [$user] = condominoConPermessoComunicazioni($condominio, [
        Permission::EDIT_COMUNICAZIONI->value,
        Permission::DELETE_COMUNICAZIONI->value,
    ]);
    $comunicazione = comunicazioneIn($condominio, $this->staff);
    $comunicazione->anagrafiche()->attach($altraPersona->id);

    expect($user->can('update', $comunicazione))->toBeFalse()
        ->and($user->can('delete', $comunicazione))->toBeFalse();
});

test('i permessi larghi senza pannello bastano per una comunicazione del proprio palazzo', function () {
    $condominio = Condominio::factory()->create();
    [$user] = condominoConPermessoComunicazioni($condominio, [
        Permission::EDIT_COMUNICAZIONI->value,
        Permission::DELETE_COMUNICAZIONI->value,
    ]);
    $comunicazione = comunicazioneIn($condominio, $this->staff);

    expect($user->can('update', $comunicazione))->toBeTrue()
        ->and($user->can('delete', $comunicazione))->toBeTrue();
});

test('amministratore e collaboratore, con il pannello, modificano ogni comunicazione', function () {
    $altro = Condominio::factory()->create();
    $comunicazione = comunicazioneIn($altro, $this->staff);

    $amministratore = User::factory()->create(['email_verified_at' => now()]);
    $amministratore->assignRole(Role::AMMINISTRATORE->value);
    $collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $collaboratore->assignRole(Role::COLLABORATORE->value);

    expect($amministratore->can('update', $comunicazione))->toBeTrue()
        ->and($amministratore->can('delete', $comunicazione))->toBeTrue()
        ->and($collaboratore->can('update', $comunicazione))->toBeTrue();
});
