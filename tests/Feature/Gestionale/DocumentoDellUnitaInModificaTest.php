<?php

/**
 * # Il modulo di modifica di un documento dell'unità apre il documento dell'indirizzo
 *
 * Dalla scheda «Documenti» di un'unità (`gestionale/immobili/documenti`) il menù della riga apre
 * `admin.gestionale.immobili.documenti.edit` con l'unità e il documento, e il modulo salva sul documento che riceve
 * (`DocumentiEdit.vue`, `props.documento.id`). Il modulo apre il documento dell'indirizzo, anche quando l'unità ne ha più
 * d'uno; il documento di un'altra unità, da quell'indirizzo, non c'è (404), come per salvarlo ed eliminarlo (le rotte
 * annidate del gestionale cercano il documento dentro l'unità).
 *
 * Cosa NON copre: scaricare e approvare il documento dell'unità (`tests/Feature/Documenti/ArchivioSoloDocumentiDellArchivioTest.php`).
 */

use App\Enums\Role;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoConto;
use App\Models\Immobile;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['kondomanager.disco_documenti' => 'local']);
    Storage::fake('local');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    $this->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    Esercizio::factory()->create(['condominio_id' => $this->aurora->id]);
    PianoConto::factory()->create(['condominio_id' => $this->aurora->id]);
    $this->interno7 = Immobile::create(['condominio_id' => $this->aurora->id, 'nome' => 'Interno 7', 'descrizione' => 'Prova', 'interno' => '7']);
    $this->interno8 = Immobile::create(['condominio_id' => $this->aurora->id, 'nome' => 'Interno 8', 'descrizione' => 'Prova', 'interno' => '8']);

    $autore = User::factory()->create();
    $this->rogito = $this->interno7->documenti()->create(docUnitaDati('Titolo di provenienza', 'rogito', $autore));
    $this->planimetria = $this->interno7->documenti()->create(docUnitaDati("Planimetria dell'unità", 'planimetria', $autore));
    $this->contratto = $this->interno8->documenti()->create(docUnitaDati('Contratto di locazione', 'contratto', $autore));
});

function docUnitaDati(string $nome, string $file, User $autore): array
{
    Storage::disk('local')->put("documenti/{$file}.pdf", "%PDF-1.4 {$file}");

    return [
        'name' => $nome, 'description' => 'Descrizione', 'path' => "documenti/{$file}.pdf", 'mime_type' => 'application/pdf',
        'file_size' => 20, 'created_by' => $autore->id, 'is_published' => true, 'is_approved' => true,
    ];
}

function docUnitaUtente(string $ruolo): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole($ruolo);

    return $user->fresh();
}

test('il modulo di modifica apre il documento dell\'indirizzo, non il primo dell\'unità', function (string $ruolo) {
    $props = $this->actingAs(docUnitaUtente($ruolo))
        ->get(route('admin.gestionale.immobili.documenti.edit', ['condominio' => $this->aurora, 'immobile' => $this->interno7, 'documento' => $this->planimetria]))
        ->assertOk()->viewData('page')['props'];

    expect($props['documento']['id'])->toBe($this->planimetria->id)
        ->and($props['documento']['name'])->toBe("Planimetria dell'unità");
})->with([
    'l\'amministratore' => [Role::AMMINISTRATORE->value],
    'il collaboratore' => [Role::COLLABORATORE->value],
]);

test('il modulo di modifica non apre dall\'indirizzo di un\'unità il documento di un\'altra', function () {
    $this->actingAs(docUnitaUtente(Role::AMMINISTRATORE->value))
        ->get(route('admin.gestionale.immobili.documenti.edit', ['condominio' => $this->aurora, 'immobile' => $this->interno7, 'documento' => $this->contratto]))
        ->assertNotFound();
});

test('salvato senza toccare niente, il modulo resta sul documento che ha aperto', function () {
    $amministratore = docUnitaUtente(Role::AMMINISTRATORE->value);
    $indirizzo = ['condominio' => $this->aurora, 'immobile' => $this->interno7, 'documento' => $this->planimetria];
    $documento = $this->actingAs($amministratore)->get(route('admin.gestionale.immobili.documenti.edit', $indirizzo))
        ->assertOk()->viewData('page')['props']['documento'];

    // Come lo manda `DocumentiEdit.vue`: i campi del documento ricevuto, verso il documento ricevuto.
    $this->actingAs($amministratore)->post(route('admin.gestionale.immobili.documenti.update', ['condominio' => $this->aurora, 'immobile' => $this->interno7, 'documento' => $documento['id']]), [
        '_method' => 'PUT', 'name' => $documento['name'], 'description' => $documento['description'],
        'is_published' => $documento['is_published'] ? '1' : '0',
    ])->assertRedirect();

    expect($this->planimetria->fresh()->name)->toBe("Planimetria dell'unità")
        ->and($this->rogito->fresh()->name)->toBe('Titolo di provenienza');
});
