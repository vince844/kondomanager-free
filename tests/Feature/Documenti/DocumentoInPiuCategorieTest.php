<?php

use App\Models\CategoriaDocumento;
use App\Models\Condominio;
use App\Models\Documento;
use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Un documento può stare in **più categorie** — 1.11.0-beta.10.
 *
 * ## Da dove viene
 *
 * Richiesta dal forum: *«sarebbe utile poter assegnare a un documento archiviato più categorie,
 * attualmente è possibile associarlo a un'unica categoria. In alcuni casi aiuterebbe la ricerca dei
 * documenti»*. Il verbale dell'assemblea che approva il bilancio si trova adesso sia sotto
 * «Verbali» sia sotto «Bilanci».
 *
 * ## ⚠️ La guardia che conta è la terza
 *
 * Le prime due provano che la funzione funziona. La terza prova che **non distrugge dati**: un
 * aggiornamento che non porta le categorie non deve azzerarle. È il caso reale del condòmino, che
 * apre il modulo da **una** categoria — quella che stava sfogliando — e potrebbe correggere il nome
 * di un documento che ne ha tre. Con un `sync` incondizionato ne perderebbe due, senza errore e
 * senza messaggio.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre l'area del condòmino da capo a fondo (servirebbe un utente non amministratore con la
 * sua anagrafica e i suoi condomìni): copre il **contratto del server**, che è dove il dato si
 * perde o si salva. Non copre la resa a schermo delle due etichette più «+N».
 */
beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $this->admin = User::factory()->create();

    // ⚠️ I permessi dell'archivio servono tutti: le policy dei documenti li chiedono uno per uno, e
    // `hasPermissionTo()` **lancia** se il permesso non esiste a database — non restituisce falso.
    foreach ([
        Permission::ACCESS_ADMIN_PANEL->value,
        Permission::VIEW_ARCHIVE_DOCUMENTS->value,
        Permission::EDIT_ARCHIVE_DOCUMENTS->value,
        Permission::PUBLISH_ARCHIVE_DOCUMENTS->value,
    ] as $nome) {
        SpatiePermission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
        $this->admin->givePermissionTo($nome);
    }

    $this->actingAs($this->admin);

    $this->condominio = Condominio::factory()->create();

    $this->verbali   = CategoriaDocumento::where('name', 'Verbali')->firstOrFail();
    $this->bilanci   = CategoriaDocumento::where('name', 'Bilanci')->firstOrFail();
    $this->contratti = CategoriaDocumento::where('name', 'Contratti')->firstOrFail();
});

function documentoConCategorie(array $categorie, string $nome = 'Verbale assemblea 2026'): Documento
{
    $documento = Documento::create([
        'name'         => $nome,
        'description'  => 'Documento di prova.',
        'path'         => 'documenti/'.md5($nome).'.pdf',
        'mime_type'    => 'application/pdf',
        'file_size'    => 1024,
        'created_by'   => test()->admin->id,
        'is_published' => true,
        'is_approved'  => true,
    ]);

    $documento->categorie()->attach($categorie);
    $documento->condomini()->attach(test()->condominio->id);

    return $documento;
}

it('⚠️ la colonna category_id non esiste più: il legame è una tabella a sé', function () {
    // ⚠️ Tenerla «per sicurezza» sarebbe stata la scelta pericolosa: un punto del codice non
    // convertito avrebbe continuato a funzionare leggendo **una** categoria di N — dato giusto a
    // metà, nessun errore. Senza colonna, un punto dimenticato fallisce alla prima richiesta.
    expect(Schema::hasColumn('documenti', 'category_id'))->toBeFalse()
        ->and(Schema::hasTable('documento_categoria'))->toBeTrue();
});

it('un documento sta in più categorie, e si trova cercando ognuna', function () {
    $documento = documentoConCategorie([$this->verbali->id, $this->bilanci->id]);

    expect($documento->categorie()->count())->toBe(2);

    // È il cuore della richiesta del forum: lo stesso documento compare nell'elenco filtrato per
    // «Verbali» **e** in quello filtrato per «Bilanci».
    foreach ([$this->verbali, $this->bilanci] as $categoria) {
        $this->get(route('admin.documenti.index', ['category_id' => [$categoria->id]]))
            ->assertOk()
            ->assertInertia(function ($pagina) use ($documento, $categoria) {
                $trovati = collect($pagina->toArray()['props']['documenti'])->pluck('id')->all();

                // ⚠️ `toContain` di Pest accetta **più valori da cercare**, non un messaggio: il
                // secondo parametro diventerebbe una seconda cosa da trovare, e il test fallirebbe
                // con «non contiene "manca nell'elenco di Verbali"». Il contesto va nell'`expect`.
                expect(in_array($documento->id, $trovati, true))
                    ->toBeTrue("il documento manca nell'elenco filtrato per «{$categoria->name}»");
            });
    }

    // E non compare in una categoria che non ha.
    $this->get(route('admin.documenti.index', ['category_id' => [$this->contratti->id]]))
        ->assertOk()
        ->assertInertia(fn ($pagina) => expect(
            collect($pagina->toArray()['props']['documenti'])->pluck('id')
        )->not->toContain($documento->id));
});

it('⚠️ modificare un documento SENZA mandare le categorie non le cancella', function () {
    // ⚠️ **È la guardia che protegge dal difetto peggiore di questa beta.**
    //
    // Il modulo del condòmino si apre da **una** categoria, quella che stava sfogliando. Con un
    // `sync` incondizionato nel controller, correggere il nome di un documento che sta in tre
    // categorie ne cancellerebbe due — nessun errore, nessun messaggio, e chi l'ha fatto non ha
    // modo di accorgersene. La regola di validazione è `sometimes` proprio per rendere legittima
    // l'assenza, e il controller deve rispettarla.
    $documento = documentoConCategorie([$this->verbali->id, $this->bilanci->id, $this->contratti->id]);

    $this->put(route('admin.documenti.update', ['documento' => $documento->id]), [
        'name'         => 'Nome corretto',
        'description'  => 'Documento di prova.',
        'is_published' => true,
        'is_approved'  => true,
    ])->assertRedirect();

    expect($documento->refresh()->name)->toBe('Nome corretto')
        ->and($documento->categorie()->count())->toBe(3);
});

it('mandando le categorie, quelle diventano le sue — anche togliendone', function () {
    $documento = documentoConCategorie([$this->verbali->id, $this->bilanci->id]);

    $this->put(route('admin.documenti.update', ['documento' => $documento->id]), [
        'name'         => $documento->name,
        'description'  => $documento->description,
        'categorie'    => [$this->contratti->id],
        'is_published' => true,
        'is_approved'  => true,
    ])->assertRedirect();

    expect($documento->categorie()->pluck('categorie_documento.id')->all())->toBe([$this->contratti->id]);
});

it('⚠️ un documento d\'archivio senza nessuna categoria viene rifiutato', function () {
    // La regola vive nei moduli dell'archivio e **non** a livello di schema: i documenti caricati
    // su un soggetto — fornitore, unità, anagrafica — non hanno categoria di proposito, e un
    // vincolo sul database romperebbe quei caricamenti.
    $documento = documentoConCategorie([$this->verbali->id]);

    $this->put(route('admin.documenti.update', ['documento' => $documento->id]), [
        'name'         => $documento->name,
        'description'  => $documento->description,
        'categorie'    => [],
        'is_published' => true,
        'is_approved'  => true,
    ])->assertSessionHasErrors('categorie');

    expect($documento->categorie()->count())->toBe(1);
});

it('i documenti di un soggetto restano senza categoria, e non è un difetto', function () {
    // ⚠️ Il caricamento su un fornitore non chiede la categoria e non deve rompersi: è il motivo
    // per cui l'obbligo sta nei moduli dell'archivio e non nello schema.
    $documento = Documento::create([
        'name'         => 'Contratto di appalto',
        'description'  => 'Caricato sulla scheda del fornitore.',
        'path'         => 'documenti/appalto.pdf',
        'mime_type'    => 'application/pdf',
        'file_size'    => 2048,
        'created_by'   => $this->admin->id,
        'is_published' => false,
        'is_approved'  => true,
    ]);

    expect($documento->categorie()->count())->toBe(0);
});

it('⚠️ una categoria usata da un documento non si elimina — e la guardia regge sul legame nuovo', function () {
    // ⚠️ **`CategoriaDocumentoController::destroy()` chiama `$categoria->documenti()->exists()`**, e
    // quella relazione è passata da `hasMany` su una colonna a `belongsToMany` su una tabella
    // ponte. La domanda «questa categoria ha documenti?» ha la stessa risposta con l'una e con
    // l'altra — ma **nessun test lo verificava**, e cambiare la fondazione di una guardia senza
    // provarla è il modo in cui una protezione si svuota in silenzio.
    $documento = documentoConCategorie([$this->verbali->id]);

    $this->delete(route('admin.categorie.destroy', ['categoria' => $this->verbali->id]))
        ->assertRedirect();

    expect(CategoriaDocumento::find($this->verbali->id))->not->toBeNull()
        // E il documento conserva il suo legame: il rifiuto non deve lasciare macerie.
        ->and($documento->refresh()->categorie()->count())->toBe(1);
});

it('una categoria che non usa nessuno si elimina', function () {
    // Il contro-esempio, senza il quale il test qui sopra proverebbe solo che la cancellazione non
    // funziona mai.
    $libera = CategoriaDocumento::create(['name' => 'Categoria libera', 'description' => 'Non la usa nessuno.']);

    $this->delete(route('admin.categorie.destroy', ['categoria' => $libera->id]))
        ->assertRedirect();

    expect(CategoriaDocumento::find($libera->id))->toBeNull();
});

it('⚠️ togliere l\'ultima categoria a un documento libera la sua categoria', function () {
    // Il caso che le categorie multiple rendono nuovo: prima un documento aveva una categoria e
    // basta, quindi «liberare» una categoria voleva dire cancellare il documento o spostarlo.
    // Adesso basta toglierla dall'elenco, e la categoria diventa cancellabile.
    $documento = documentoConCategorie([$this->verbali->id, $this->bilanci->id]);

    $this->put(route('admin.documenti.update', ['documento' => $documento->id]), [
        'name'         => $documento->name,
        'description'  => $documento->description,
        'categorie'    => [$this->bilanci->id],
        'is_published' => true,
        'is_approved'  => true,
    ])->assertRedirect();

    $this->delete(route('admin.categorie.destroy', ['categoria' => $this->verbali->id]))
        ->assertRedirect();

    expect(CategoriaDocumento::find($this->verbali->id))->toBeNull()
        ->and($documento->refresh()->categorie()->count())->toBe(1);
});
