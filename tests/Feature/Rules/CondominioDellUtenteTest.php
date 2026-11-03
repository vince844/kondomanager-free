<?php

/**
 * Giro di sicurezza della 1.11.0-beta.39, Coda 184: la stessa domanda — «questo condominio è fra
 * quelli dell'utente?» — era ripetuta, con la stessa forma, nelle richieste lato condòmino di
 * eventi, documenti e comunicazioni, e tre di loro la controllavano solo con
 * `exists:condomini,id`. Estratta in `App\Rules\CondominioDellUtente` (usata anche dalle
 * segnalazioni, già coperta in `SegnalazioneCondominioUtenteTest`, e da `SegnalazionePolicy` per
 * `update`/`delete`, coperta in `SegnalazionePolicyCondominioTest`).
 *
 * Qui: un test diretto sulla regola, isolato dalle tre richieste, più un test d'integrazione per
 * ciascuna delle tre — la prova che il collegamento è stato fatto, non solo la classe scritta.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\CategoriaDocumento;
use App\Models\Condominio;
use App\Models\User;
use App\Rules\CondominioDellUtente;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Event::fake();
});

function residentePer(Condominio $condominio, string $permesso): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::UTENTE->value);
    $user->givePermissionTo($permesso);

    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    return $user;
}

// --- la regola, isolata ---------------------------------------------------------------------

test('CondominioDellUtente::appartiene dice sì solo per i condomìni dell\'anagrafica', function () {
    $condominio = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    $user = User::factory()->create();
    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    expect(CondominioDellUtente::appartiene($user, $condominio->id))->toBeTrue()
        ->and(CondominioDellUtente::appartiene($user, $altro->id))->toBeFalse()
        ->and(CondominioDellUtente::appartiene(null, $condominio->id))->toBeFalse();
});

test('CondominioDellUtente::appartiene dice no per un utente senza anagrafica', function () {
    $condominio = Condominio::factory()->create();
    $user = User::factory()->create();

    expect(CondominioDellUtente::appartiene($user, $condominio->id))->toBeFalse();
});

test('la regola di validazione fallisce sull\'indice giusto e passa quando il condominio è il suo', function () {
    $condominio = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    $user = User::factory()->create();
    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    $this->actingAs($user);

    $fallisce = Validator::make(
        ['condomini_ids' => [$altro->id]],
        ['condomini_ids.*' => [new CondominioDellUtente()]],
    );
    $passa = Validator::make(
        ['condomini_ids' => [$condominio->id]],
        ['condomini_ids.*' => [new CondominioDellUtente()]],
    );

    expect($fallisce->fails())->toBeTrue()
        ->and($fallisce->errors()->has('condomini_ids.0'))->toBeTrue()
        ->and($passa->fails())->toBeFalse();
});

// --- integrazione: eventi, documenti, comunicazioni -----------------------------------------

test('il condòmino non crea un evento su un condominio che non è il suo (Coda 184)', function () {
    $suo = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    $user = residentePer($suo, Permission::CREATE_EVENTS->value);

    $response = $this->actingAs($user)->post(route('user.eventi.store'), [
        'title'        => 'Prova',
        'start_time'   => now()->addDay()->toDateTimeString(),
        'end_time'     => now()->addDay()->addHour()->toDateTimeString(),
        'category_id'  => null,
        'condomini_ids' => [$altro->id],
    ]);

    $response->assertSessionHasErrors('condomini_ids.0');
});

test('il condòmino non crea un documento in un condominio che non è il suo (Coda 184)', function () {
    Storage::fake('documenti');
    $suo = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    $user = residentePer($suo, Permission::CREATE_ARCHIVE_DOCUMENTS->value);
    $categoria = CategoriaDocumento::create(['name' => 'Prova', 'description' => 'Prova']);

    $response = $this->actingAs($user)->post(route('user.documenti.store'), [
        'name'          => 'Documento di prova',
        'description'   => 'Descrizione di prova',
        'categorie'     => [$categoria->id],
        'condomini_ids' => [$altro->id],
        'file'          => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
    ]);

    $response->assertSessionHasErrors('condomini_ids.0');
});

test('il condòmino non crea una comunicazione in un condominio che non è il suo (Coda 184)', function () {
    $suo = Condominio::factory()->create();
    $altro = Condominio::factory()->create();
    $user = residentePer($suo, Permission::CREATE_COMUNICAZIONI->value);

    $response = $this->actingAs($user)->post(route('user.comunicazioni.store'), [
        'subject'       => 'Prova',
        'description'   => 'Descrizione di prova',
        'priority'      => 'media',
        'is_featured'   => false,
        'condomini_ids' => [$altro->id],
    ]);

    $response->assertSessionHasErrors('condomini_ids.0');
});
