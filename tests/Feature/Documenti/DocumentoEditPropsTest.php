<?php

/**
 * Giro di sicurezza della 1.11.0-beta.39, Coda 183 — la più seria delle falle trovate dalla
 * revisione, fuori dai tre commit della PR: `Documenti/Utenti/DocumentoController::edit()`
 * mandava al browser `Condominio::all()` e `Anagrafica::all()` (quest'ultima con codice fiscale,
 * email, PEC e telefono di **ogni** persona, in **ogni** condominio), e nessuno dei due prop era
 * letto da `DocumentiEdit.vue`: dati morti, e per di più personali.
 *
 * Serve `CREATE_ARCHIVE_DOCUMENTS` più `EDIT_OWN_ARCHIVE_DOCUMENTS` insieme — nessuno dei due è
 * nel ruolo predefinito `utente` — quindi con i ruoli di serie questa pagina non si raggiunge
 * affatto: la prova è che, concessi i due permessi, i due prop non compaiono più.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Documento;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('la pagina di modifica documento non manda più condomini né anagrafiche', function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::UTENTE->value);
    $user->givePermissionTo([
        Permission::CREATE_ARCHIVE_DOCUMENTS->value,
        Permission::EDIT_OWN_ARCHIVE_DOCUMENTS->value,
    ]);

    $documento = Documento::create([
        'name'         => 'Verbale di prova',
        'description'  => 'Descrizione di prova',
        'created_by'   => $user->id,
        'is_published' => true,
        'is_approved'  => true,
        'path'         => 'documenti/prova.pdf',
        'mime_type'    => 'application/pdf',
        'file_size'    => 1024,
    ]);

    $response = $this->actingAs($user)->get(route('user.documenti.edit', $documento));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('documenti/user/DocumentiEdit')
        ->missing('condomini')
        ->missing('anagrafiche')
        ->has('documento')
        ->has('categories')
        ->has('limiteFile'));
});
