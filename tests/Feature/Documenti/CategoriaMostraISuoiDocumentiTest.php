<?php

/**
 * Cliccare una categoria dell'archivio porta ai documenti di quella categoria.
 *
 * ## Origine: segnalazione dal forum, agosto 2026
 *
 * > *«andando su "Elenco categorie archivio documenti" da Documenti → Categorie e cliccando su
 * > una qualsiasi delle categorie presenti mi compare l'errore: Call to undefined method […]
 * > CategoriaDocumentoController::show(). Sinceramente non so neanche cosa dovrebbe fare il
 * > software cliccando su una categoria.»*
 *
 * Il nome della categoria era un link verso `categorie.show`, che `Route::resource` registrava e
 * il controller non implementava: **500** a chiunque cliccasse. La seconda frase è la parte che
 * conta — il link non prometteva niente di preciso, e chi ci cliccava non sapeva cosa aspettarsi.
 *
 * ## Perché mostra i documenti e non «niente»
 *
 * La strada più stretta sarebbe stata togliere il link. Il prodotto però **quella funzione ce
 * l'ha già**, dall'altro lato: il condòmino sfoglia l'archivio per categoria, e
 * `Documenti\Utenti\CategoriaDocumentoController@show` gli mostra i documenti di quella. Fare la
 * stessa cosa lato amministratore non è inventare una funzione: è togliere una differenza fra le
 * due aree che nessuno aveva deciso.
 *
 * E non costa un metodo nuovo: l'elenco documenti accetta già il filtro `category_id`, quindi il
 * link punta lì.
 *
 * ## Il filtro che il server applicava e la schermata non dichiarava
 *
 * ⚠️ **La domanda del perimetro di raggiungibilità, e stavolta ha trovato qualcosa.** L'elenco
 * documenti accettava `category_id` dall'indirizzo e filtrava correttamente, ma la barra dei
 * filtri **partiva vuota**: si arrivava a una pagina filtrata che non diceva di esserlo, e al
 * primo tocco sulla barra il filtro spariva allargando l'elenco senza spiegare perché. Finché
 * nessuno costruiva quell'indirizzo il difetto era latente; il link lo avrebbe reso quotidiano.
 *
 * Per questo la correzione è in due pezzi: il link, e la reidratazione della barra. Il secondo
 * senza il primo sarebbe stato inutile; il primo senza il secondo, una trappola.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la reidratazione lato client, che sta in
 * `resources/js/composables/useReidratazioneFiltri.test.ts`: qui si prova che il server filtra e
 * che **rimanda indietro** il filtro applicato, cioè che il dato per reidratare esista.
 * Non copre la vista del condòmino, che passa da un controller e da una schermata diversi.
 */

use App\Enums\Permission;
use App\Models\CategoriaDocumento;
use App\Models\Documento;
use App\Models\User;
use Spatie\Permission\Models\Permission as SpatiePermission;

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    $this->admin = User::factory()->create();

    foreach ([Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_ARCHIVE_DOCUMENTS->value] as $nome) {
        SpatiePermission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
        $this->admin->givePermissionTo($nome);
    }

    $this->verbali = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'Assemblee']);
    $this->fatture = CategoriaDocumento::create(['name' => 'Fatture', 'description' => 'Ciclo passivo']);

    foreach ([['Verbale del 3 marzo', $this->verbali], ['Verbale del 9 luglio', $this->verbali], ['Fattura 128', $this->fatture]] as [$titolo, $categoria]) {
        $documento = Documento::create([
            'name'         => $titolo,
            'description'  => $titolo,
            'path'         => 'documenti/'.md5($titolo).'.pdf',
            // ⚠️ `mime_type` e `file_size` non sono decorazione della fixture: senza,
            // `DocumentoResource::getMimeTypeLabel()` riceve `null` su un parametro tipizzato
            // `string` e l'elenco risponde 500. La colonna è nullable, quindi il caso è
            // rappresentabile — solo, nessuna schermata lo produce. Annotato per la revisione.
            'mime_type'    => 'application/pdf',
            'file_size'    => 122880,
            'is_published' => true,
            'is_approved'  => true,
            'created_by'   => $this->admin->id,
        ]);

        // ⚠️ Dalla 1.11.0-beta.10 la categoria è un **legame**, non una colonna: si attacca dopo la
        // creazione. La fixture qui ne mette una sola perché è quello che questo file prova — che
        // l'elenco filtrato mostri i documenti della categoria chiesta e non gli altri.
        $documento->categorie()->attach($categoria->id);
    }
});

it("l'elenco documenti filtrato su una categoria mostra solo i suoi", function () {
    $this->actingAs($this->admin)
        ->get(route('admin.documenti.index', ['category_id' => [$this->verbali->id]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('documenti/DocumentiList')
            ->has('documenti', 2)
        );
});

it('e rimanda indietro il filtro applicato, che è ciò che serve a dichiararlo a video', function () {
    // ⚠️ Senza questa prop la barra dei filtri non ha da dove reidratarsi, e la pagina resta
    // filtrata **in silenzio**: il difetto che il link nuovo avrebbe reso quotidiano.
    $this->actingAs($this->admin)
        ->get(route('admin.documenti.index', ['category_id' => [$this->verbali->id]]))
        ->assertInertia(fn ($page) => $page
            ->where('filters.category_id', [$this->verbali->id])
        );
});

it('senza filtro si vedono tutti, e la prop del filtro resta vuota', function () {
    // Controprova: la reidratazione non deve inventare un filtro dove non ce n'è uno, o l'elenco
    // completo si presenterebbe come filtrato.
    $this->actingAs($this->admin)
        ->get(route('admin.documenti.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('documenti', 3)
            ->missing('filters.category_id')
        );
});

it('la rotta che rispondeva 500 non esiste più: chi ha un segnalibro non trova un guasto', function () {
    // La segnalazione arrivava da un click, ma l'indirizzo resta battibile a mano.
    //
    // ⚠️ La risposta è **405**, non 404, e va detto perché non è un dettaglio: l'indirizzo
    // `/admin/categorie/{id}` esiste ancora per `PUT` e `DELETE` — una categoria si rinomina e si
    // elimina da lì — quindi Laravel riconosce la strada e rifiuta il verbo. È la risposta
    // corretta, ed è comunque un rifiuto e non un guasto: il punto della correzione è che non sia
    // più un 500, cioè che il programma non sembri rotto a chi ci arriva.
    expect(route('admin.categorie.index'))->toContain('/admin/categorie');

    $this->actingAs($this->admin)
        ->get('/admin/categorie/'.$this->verbali->id)
        ->assertMethodNotAllowed();
});

it('i due filtri tornano indietro col tipo che la loro barra si aspetta, e sono due tipi diversi', function () {
    // ⚠️ **Questa asimmetria è deliberata, e senza un test è indistinguibile da una svista.**
    //
    // Le due composable che alimentano i filtri emettono tipi opposti: `useCategorieDocumenti`
    // un numero, `useCondomini` una stringa. Il confronto nella barra è un `Set.has()`, che non
    // converte, quindi ciascun filtro va reidratato **col suo** tipo: un cast unico ne accende uno
    // e spegne l'altro. La correzione giusta — allineare le due composable — porterebbe la beta su
    // tre pagine che non c'entrano, ed è in roadmap.
    //
    // Il giorno che qualcuno le allinea, questo test diventa rosso e dice di togliere il cast.
    $altroCondominio = \App\Models\Condominio::factory()->create();

    $this->actingAs($this->admin)
        ->get(route('admin.documenti.index', [
            'category_id'   => [$this->verbali->id],
            'condominio_id' => [$altroCondominio->id],
        ]))
        ->assertInertia(fn ($page) => $page
            ->where('filters.category_id', [$this->verbali->id])            // interi
            ->where('filters.condominio_id', [(string) $altroCondominio->id]) // stringhe
        );
});
