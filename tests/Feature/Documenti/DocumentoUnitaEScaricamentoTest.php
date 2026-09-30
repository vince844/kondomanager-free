<?php

/**
 * Giro di sicurezza della 1.11.0-beta.39, secondo giro di revisione.
 *
 * `DocumentoPolicy::view()` guardava solo il palazzo in comune. Un condòmino di serie (ruolo
 * UTENTE, e allo stesso modo FORNITORE: hanno «Visualizza documenti archivio») scaricava per id,
 * da `user.documenti.download`, il rogito o il contratto che l'amministratore aveva caricato dalla
 * scheda dell'unità di un altro, e i documenti d'archivio non ancora pubblicati o approvati.
 * L'elenco del portale non li mostrava (`DocumentoService::getUserBaseQuery`), ma gli id sono
 * sequenziali. Ora il download segue la stessa regola dell'elenco: solo archivio, pubblicato e
 * approvato (o proprio), e poi, come prima, il documento indirizzato a un condòmino va solo a lui,
 * quello del condominio a tutto il palazzo. I documenti d'unità restano dell'amministratore
 * (decisione di Vincenzo del 30/09/2026).
 *
 * Con i permessi larghi di modifica e cancellazione concessi senza pannello, lo stesso documento
 * d'unità si sostituiva o si cancellava: `PerimetroFuoriPannello` lo vedeva «del palazzo».
 *
 * Il documento d'unità del test è pubblicato e approvato: è il caso peggiore, e il rifiuto non
 * dipende dalla pubblicazione.
 *
 * **Cosa resta scoperto**: il caricamento dall'unità ignora ancora la scelta «ad uso interno» e
 * pubblica il documento (in roadmap): con questa correzione non ha più effetti sul portale, perché
 * i documenti d'unità non arrivano ai condòmini in nessun caso.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\Immobile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['kondomanager.disco_documenti' => 'local']);
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->amministratore = User::factory()->create(['email_verified_at' => now()]);
    $this->amministratore->assignRole(Role::AMMINISTRATORE->value);

    $this->condominio = Condominio::factory()->create();
    \App\Models\Esercizio::factory()->create(['condominio_id' => $this->condominio->id]);
    \App\Models\Gestionale\PianoConto::factory()->create(['condominio_id' => $this->condominio->id]);
});

/** Un condòmino del palazzo con il ruolo di serie, più eventuali permessi diretti. */
function condominoDelPalazzo(Condominio $condominio, array $permessi = []): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::UTENTE->value);
    if ($permessi !== []) {
        $user->givePermissionTo($permessi);
    }

    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    return $user;
}

/** Il documento caricato dall'amministratore dalla scheda dell'unità: la rotta vera. */
function documentoDellUnita(Condominio $condominio, User $amministratore): Documento
{
    $immobile = Immobile::create([
        'condominio_id' => $condominio->id,
        'nome'          => 'Interno 7',
        'descrizione'   => 'Prova',
        'interno'       => '7',
    ]);

    test()->actingAs($amministratore)
        ->post(route('admin.gestionale.immobili.documenti.store', [
            'condominio' => $condominio->id,
            'immobile'   => $immobile->id,
        ]), [
            'name'         => 'Contratto di locazione',
            'file'         => UploadedFile::fake()->create('contratto.pdf', 120, 'application/pdf'),
            'is_published' => true,
            'is_approved'  => true,
            'created_by'   => $amministratore->id,
        ])
        ->assertSessionHasNoErrors();

    // La riga, non l'assenza di errori, prova che il caricamento è avvenuto.
    $documento = Documento::where('name', 'Contratto di locazione')->sole();
    // Pubblicato e approvato: il rifiuto del download deve poter venire solo dal fatto che è un
    // documento d'unità, non dalla pubblicazione.
    expect($documento->documentable_type)->not->toBeNull()
        ->and((bool) $documento->is_published)->toBeTrue()
        ->and((bool) $documento->is_approved)->toBeTrue()
        ->and($documento->anagrafiche()->count())->toBe(0)
        ->and($documento->condomini()->pluck('condomini.id')->map(fn ($id) => (int) $id)->all())->toBe([$condominio->id]);

    return $documento;
}

function documentoDArchivio(Condominio $condominio, User $autore, array $campi = []): Documento
{
    $path = 'documenti/'.uniqid().'.pdf';
    Storage::disk('local')->put($path, 'contenuto di prova');

    $documento = Documento::create(array_merge([
        'name'         => 'Verbale di assemblea',
        'description'  => 'Prova',
        'created_by'   => $autore->id,
        'is_published' => true,
        'is_approved'  => true,
        'path'         => $path,
        'mime_type'    => 'application/pdf',
        'file_size'    => 18,
    ], $campi));
    $documento->condomini()->attach($condominio->id);

    return $documento;
}

test('un condòmino di serie non scarica per id il documento dell\'unità di un altro', function () {
    $documento = documentoDellUnita($this->condominio, $this->amministratore);
    $vicino = condominoDelPalazzo($this->condominio);

    $this->actingAs($vicino)->get(route('user.documenti.download', $documento))->assertForbidden();

    // L'amministratore, con il pannello, continua a scaricarlo.
    expect($this->amministratore->can('view', $documento))->toBeTrue();
});

test('con i permessi larghi senza pannello il documento dell\'unità non si modifica né si cancella', function () {
    $documento = documentoDellUnita($this->condominio, $this->amministratore);
    $vicino = condominoDelPalazzo($this->condominio, [
        Permission::EDIT_ARCHIVE_DOCUMENTS->value,
        Permission::DELETE_ARCHIVE_DOCUMENTS->value,
    ]);

    expect($vicino->can('update', $documento))->toBeFalse()
        ->and($vicino->can('delete', $documento))->toBeFalse();

    $this->actingAs($vicino)->delete(route('user.documenti.destroy', $documento))->assertForbidden();

    expect(Documento::whereKey($documento->id)->exists())->toBeTrue()
        ->and(Storage::disk('local')->exists($documento->path))->toBeTrue()
        ->and($this->amministratore->can('update', $documento))->toBeTrue();
});

test('un documento d\'archivio non pubblicato o non approvato di un altro non si scarica per id', function () {
    $nonPubblicato = documentoDArchivio($this->condominio, $this->amministratore, ['is_published' => false]);
    $nonApprovato = documentoDArchivio($this->condominio, $this->amministratore, ['is_approved' => false]);
    $condomino = condominoDelPalazzo($this->condominio);

    $this->actingAs($condomino)->get(route('user.documenti.download', $nonPubblicato))->assertForbidden();
    $this->actingAs($condomino)->get(route('user.documenti.download', $nonApprovato))->assertForbidden();
});

test('il documento del condominio pubblicato si scarica, e il proprio in attesa anche', function () {
    $condomino = condominoDelPalazzo($this->condominio);
    $delPalazzo = documentoDArchivio($this->condominio, $this->amministratore);
    $proprioInAttesa = documentoDArchivio($this->condominio, $condomino, ['is_published' => false, 'is_approved' => false]);

    $this->actingAs($condomino)->get(route('user.documenti.download', $delPalazzo))->assertOk();
    $this->actingAs($condomino)->get(route('user.documenti.download', $proprioInAttesa))->assertOk();
});

test('il documento indirizzato a un condòmino lo scarica lui, non il vicino', function () {
    $destinatario = condominoDelPalazzo($this->condominio);
    $vicino = condominoDelPalazzo($this->condominio);
    $documento = documentoDArchivio($this->condominio, $this->amministratore);
    $documento->anagrafiche()->attach($destinatario->anagrafica->id);

    $this->actingAs($destinatario)->get(route('user.documenti.download', $documento))->assertOk();
    $this->actingAs($vicino)->get(route('user.documenti.download', $documento))->assertForbidden();
});
