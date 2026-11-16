<?php

use App\Enums\Permission;
use App\Http\Resources\Documenti\DocumentoResource;
use App\Models\CategoriaDocumento;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * L'utente che ha caricato un documento viene eliminato: il documento resta, senza autore, e le pagine dicono «utente eliminato».
 *
 * Fra i documenti ci sono quelli che il programma conserva da sé (il PDF del rendiconto fermato per l'assemblea, il verbale dell'approvazione, le
 * lettere mandate): cancellarli insieme a chi li ha caricati toglieva dall'archivio un documento che va conservato, e lasciava il file sul disco
 * senza nessuno che lo trovasse.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $this->admin = User::factory()->create();
    foreach ([Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_ARCHIVE_DOCUMENTS->value] as $nome) {
        SpatiePermission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
        $this->admin->givePermissionTo($nome);
    }
    $this->autore = User::factory()->create(['name' => 'Rossi collaboratore']);
    $this->documento = Documento::create([
        'name' => 'Rendiconto 2026 approvato', 'description' => 'Il fascicolo approvato.', 'path' => 'documenti/rendiconto.pdf',
        'mime_type' => 'application/pdf', 'file_size' => 1024, 'created_by' => $this->autore->id, 'is_published' => true, 'is_approved' => true,
    ]);
    $this->documento->categorie()->attach(CategoriaDocumento::where('name', 'Verbali')->firstOrFail()->id);
    $this->documento->condomini()->attach(Condominio::factory()->create()->id);
});

it('eliminato l\'utente che l\'ha caricato, il documento resta con l\'autore vuoto', function () {
    $this->autore->delete();

    $documento = Documento::find($this->documento->id);
    expect($documento)->not->toBeNull()
        ->and($documento->created_by)->toBeNull();
});

it('la risorsa del documento dice «utente eliminato», con la stessa forma di quando l\'autore c\'è', function () {
    $conAutore = (new DocumentoResource($this->documento->fresh()->load('createdBy.anagrafica')))->resolve();
    $this->autore->delete();
    $senza = (new DocumentoResource($this->documento->fresh()->load('createdBy.anagrafica')))->resolve();

    expect($conAutore['created_by']['user']->resolve()['name'] ?? null)->toBe('Rossi collaboratore')
        ->and($senza['created_by'])->toBe(['user' => ['id' => null, 'name' => 'utente eliminato'], 'anagrafica' => null]);
});

it('l\'archivio dei documenti nel pannello si apre e dice «utente eliminato»', function () {
    $this->autore->delete();

    $this->actingAs($this->admin)->get(route('admin.documenti.index'))->assertOk()
        ->assertInertia(fn ($pagina) => $pagina->where('documenti.0.id', $this->documento->id)
            ->where('documenti.0.created_by.user.name', 'utente eliminato')
            ->where('documenti.0.created_by.user.id', null));
});

it('anche il portale riceve la forma promessa, con il nome vuoto: la riga dice «utente eliminato»', function () {
    $this->autore->delete();

    $portale = (new \App\Http\Resources\Portale\DocumentoPortaleResource($this->documento->fresh()->load('createdBy')))->resolve();

    expect($portale['created_by'])->toBe(['user' => ['id' => null, 'name' => null]]);
});
