<?php

/**
 * Giro di sicurezza della 1.11.0-beta.39, Coda 185 allargata ai documenti (decisione del 30/09/2026
 * dopo la revisione avversariale): `DocumentoPolicy::update` e `delete` rispondevano sì a
 * **qualunque** documento a chi aveva `EDIT_ARCHIVE_DOCUMENTS` o `DELETE_ARCHIVE_DOCUMENTS`. Con quei
 * permessi concessi da soli, senza «Accesso pannello amministratore», un condòmino sostituiva o
 * cancellava i documenti di un altro palazzo, dalle rotte `user.*` che hanno solo il login. Con i
 * ruoli predefiniti non si raggiunge: `COLLABORATORE` ha `EDIT_ARCHIVE_DOCUMENTS` insieme al
 * pannello, `UTENTE` non ce l'ha.
 *
 * **Cosa resta scoperto**: il ramo `EDIT_OWN_ARCHIVE_DOCUMENTS` / `DELETE_OWN_ARCHIVE_DOCUMENTS` non
 * cambia. Il download per id e i documenti caricati dalla scheda di un'unità sono in
 * `DocumentoUnitaEScaricamentoTest`.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
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
function condominoConPermessoDocumenti(Condominio $condominio, array $permessi): array
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

function documentoIn(Condominio $condominio, User $autore): Documento
{
    $documento = Documento::create([
        'name'         => 'Verbale di assemblea',
        'description'  => 'Descrizione di prova',
        'created_by'   => $autore->id,
        'is_published' => true,
        'is_approved'  => true,
        'path'         => 'documenti/prova.pdf',
        'mime_type'    => 'application/pdf',
        'file_size'    => 1024,
    ]);
    $documento->condomini()->attach($condominio->id);

    return $documento;
}

test('EDIT_ARCHIVE_DOCUMENTS e DELETE_ARCHIVE_DOCUMENTS senza pannello non toccano un altro palazzo', function () {
    $suo = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    [$user] = condominoConPermessoDocumenti($suo, [
        Permission::EDIT_ARCHIVE_DOCUMENTS->value,
        Permission::DELETE_ARCHIVE_DOCUMENTS->value,
    ]);
    $documento = documentoIn($altro, $this->staff);

    expect($user->can('update', $documento))->toBeFalse()
        ->and($user->can('delete', $documento))->toBeFalse();

    $this->actingAs($user)->delete(route('user.documenti.destroy', $documento))->assertForbidden();
    expect(Documento::whereKey($documento->id)->exists())->toBeTrue();
});

test('un documento indirizzato a un\'altra persona resta fuori anche nel proprio palazzo', function () {
    $condominio = Condominio::factory()->create();
    [, $altraPersona] = condominoConPermessoDocumenti($condominio, []);
    [$user] = condominoConPermessoDocumenti($condominio, [
        Permission::EDIT_ARCHIVE_DOCUMENTS->value,
        Permission::DELETE_ARCHIVE_DOCUMENTS->value,
    ]);
    $documento = documentoIn($condominio, $this->staff);
    $documento->anagrafiche()->attach($altraPersona->id);

    expect($user->can('update', $documento))->toBeFalse()
        ->and($user->can('delete', $documento))->toBeFalse();
});

test('un documento senza condominio resta fuori', function () {
    $condominio = Condominio::factory()->create();
    [$user] = condominoConPermessoDocumenti($condominio, [Permission::EDIT_ARCHIVE_DOCUMENTS->value]);
    $documento = documentoIn($condominio, $this->staff);
    $documento->condomini()->detach();

    expect($user->can('update', $documento))->toBeFalse();
});

test('i permessi larghi senza pannello bastano per un documento del proprio palazzo', function () {
    $condominio = Condominio::factory()->create();
    [$user] = condominoConPermessoDocumenti($condominio, [
        Permission::EDIT_ARCHIVE_DOCUMENTS->value,
        Permission::DELETE_ARCHIVE_DOCUMENTS->value,
    ]);
    $documento = documentoIn($condominio, $this->staff);

    expect($user->can('update', $documento))->toBeTrue()
        ->and($user->can('delete', $documento))->toBeTrue();
});

test('amministratore e collaboratore, con il pannello, modificano ogni documento', function () {
    $altro = Condominio::factory()->create();
    $documento = documentoIn($altro, $this->staff);

    $amministratore = User::factory()->create(['email_verified_at' => now()]);
    $amministratore->assignRole(Role::AMMINISTRATORE->value);
    $collaboratore = User::factory()->create(['email_verified_at' => now()]);
    $collaboratore->assignRole(Role::COLLABORATORE->value);

    expect($amministratore->can('update', $documento))->toBeTrue()
        ->and($amministratore->can('delete', $documento))->toBeTrue()
        ->and($collaboratore->can('update', $documento))->toBeTrue();
});
