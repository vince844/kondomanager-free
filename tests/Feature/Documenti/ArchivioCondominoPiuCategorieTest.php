<?php

use App\Enums\Permission;
use App\Models\Anagrafica;
use App\Models\CategoriaDocumento;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * L'archivio **del condòmino** con le categorie multiple — 1.11.0-beta.10.
 *
 * ## Perché questo file esiste
 *
 * La beta.10 riscrive i due metodi che reggono l'area personale —
 * `DocumentoService::getUserDocumentCountsByCategoria()` e `getDocumentiByCategoria()` — passando
 * dalla colonna `documenti.category_id` alla tabella ponte. ⚠️ **Nessun test li chiamava**: la
 * copertura dei documenti stava tutta sull'area dell'amministratore, e i due metodi che il condòmino
 * usa ogni volta che apre l'archivio non erano provati né prima né dopo.
 *
 * È il buco che questo file chiude, e la domanda che risponde è quella posta da Vincenzo aprendo la
 * beta: *«nell'area personale dei condòmini, se un documento è su più categorie, lo mostriamo dentro
 * ogni categoria?»*. La risposta è **sì**, ed è il senso stesso della richiesta arrivata dal forum:
 * il verbale dell'assemblea che approva il bilancio si cerca sotto «Verbali» **o** sotto «Bilanci»,
 * a seconda di cosa si stava cercando, e deve trovarsi da tutte e due le parti.
 *
 * ## ⚠️ La somma dei conteggi può superare il numero dei documenti, ed è corretto
 *
 * Le schede delle categorie dicono quanti documenti contiene ognuna. Un documento in due categorie
 * viene contato in tutte e due, perché in tutte e due lo si trova: ogni conteggio è vero per la
 * **sua** categoria, ed è la somma a non voler dire niente — infatti nessuna schermata la mostra.
 * Chi in futuro leggesse un totale sbagliato da qui, sta facendo una domanda che questi numeri non
 * rispondono.
 *
 * ## Cosa questo file NON copre
 *
 * Non copre la resa a video delle schede né l'aspetto della pagina: copre il **contratto del
 * server**, cioè cosa il condòmino riceve. Non copre l'area dell'amministratore, che ha i suoi
 * (`DocumentoInPiuCategorieTest`). Non copre i permessi di caricamento del condòmino.
 */
beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    // ⚠️ Un condòmino, non un amministratore: `DocumentoService` sceglie la query in base al ruolo,
    // e con un amministratore proverebbe **l'altro ramo** — cioè non proverebbe niente di questo.
    $this->condomino = User::factory()->create();

    SpatiePermission::firstOrCreate(['name' => Permission::VIEW_ARCHIVE_DOCUMENTS->value, 'guard_name' => 'web']);
    $this->condomino->givePermissionTo(Permission::VIEW_ARCHIVE_DOCUMENTS->value);

    // ⚠️ Il permesso di amministratore deve **esistere** a database anche se non lo si concede:
    // `DocumentoService::isAdmin()` lo interroga con `hasPermissionTo()`, che **lancia** se il
    // permesso non è registrato — non restituisce falso. È la stessa trappola già annotata in
    // `DocumentoInPiuCategorieTest`, e qui morde al contrario: serve al condòmino per **non** esserlo.
    SpatiePermission::firstOrCreate(['name' => Permission::ACCESS_ADMIN_PANEL->value, 'guard_name' => 'web']);

    $this->condominio = Condominio::factory()->create();

    $this->anagrafica = Anagrafica::factory()->create([
        'user_id' => $this->condomino->id,
    ]);
    $this->anagrafica->condomini()->attach($this->condominio->id);

    $this->actingAs($this->condomino);

    $this->verbali   = CategoriaDocumento::where('name', 'Verbali')->firstOrFail();
    $this->bilanci   = CategoriaDocumento::where('name', 'Bilanci')->firstOrFail();
    $this->contratti = CategoriaDocumento::where('name', 'Contratti')->firstOrFail();
});

/**
 * Un documento d'archivio visibile al condòmino di questo file.
 *
 * Pubblicato **e** approvato, senza soggetto, e legato al condominio: sono le quattro condizioni di
 * `getUserBaseQuery()`, e sbagliarne una farebbe sparire il documento per una ragione che non
 * c'entra con le categorie.
 */
function documentoDiArchivio(string $nome, array $categorie): Documento
{
    $documento = Documento::create([
        'name'         => $nome,
        'description'  => 'Documento di prova.',
        'path'         => 'documenti/'.md5($nome).'.pdf',
        'mime_type'    => 'application/pdf',
        'file_size'    => 1024,
        'created_by'   => test()->condomino->id,
        'is_published' => true,
        'is_approved'  => true,
    ]);

    $documento->categorie()->attach($categorie);
    $documento->condomini()->attach(test()->condominio->id);

    return $documento;
}

/** Gli id dei documenti che la pagina di una categoria ha davvero mostrato. */
function idNellaCategoria($risposta): array
{
    $ids = [];

    $risposta->assertInertia(function ($pagina) use (&$ids) {
        $ids = collect($pagina->toArray()['props']['documenti']['data'])->pluck('id')->all();
    });

    return $ids;
}

it('⚠️ lo stesso documento si trova sotto OGNI categoria a cui appartiene', function () {
    // È la domanda posta aprendo la beta, e la ragione per cui la richiesta del forum ha senso.
    $verbale = documentoDiArchivio('Verbale assemblea che approva il bilancio', [
        $this->verbali->id,
        $this->bilanci->id,
    ]);

    foreach ([$this->verbali, $this->bilanci] as $categoria) {
        $risposta = $this->get(route('user.categorie-documenti.show', ['categoriaDocumento' => $categoria->id]))
            ->assertOk();

        expect(in_array($verbale->id, idNellaCategoria($risposta), true))
            ->toBeTrue("il documento manca dall'archivio del condòmino sotto «{$categoria->name}»");
    }
});

it('e non compare sotto una categoria che non ha', function () {
    documentoDiArchivio('Verbale assemblea 2026', [$this->verbali->id]);

    $risposta = $this->get(route('user.categorie-documenti.show', ['categoriaDocumento' => $this->contratti->id]))
        ->assertOk();

    expect(idNellaCategoria($risposta))->toBe([]);
});

it('⚠️ le schede contano il documento in tutte e due le categorie', function () {
    // La somma fa 2 su **un** documento solo, ed è giusto: ogni numero è vero per la sua categoria.
    // Se un giorno qualcuno «correggesse» questo comportamento per far tornare un totale, la scheda
    // «Bilanci» direbbe zero mentre aprendola ci si trova dentro un documento.
    documentoDiArchivio('Verbale assemblea che approva il bilancio', [
        $this->verbali->id,
        $this->bilanci->id,
    ]);

    $this->get(route('user.categorie-documenti.index'))
        ->assertOk()
        ->assertInertia(function ($pagina) {
            $conteggi = collect($pagina->toArray()['props']['categorie'])
                ->pluck('documenti_count', 'name');

            expect($conteggi['Verbali'])->toBe(1)
                ->and($conteggi['Bilanci'])->toBe(1)
                ->and($conteggi['Contratti'])->toBe(0);
        });
});

it('una categoria senza documenti dice zero, non sparisce dall\'elenco', function () {
    // Controprova: la scheda deve esserci comunque, altrimenti il condòmino non sa che quella
    // categoria esiste e crede che l'archivio sia più piccolo di com'è.
    $this->get(route('user.categorie-documenti.index'))
        ->assertOk()
        ->assertInertia(function ($pagina) {
            $categorie = collect($pagina->toArray()['props']['categorie']);

            expect($categorie->pluck('name'))->toContain('Verbali', 'Bilanci', 'Contratti')
                ->and($categorie->firstWhere('name', 'Verbali')['documenti_count'])->toBe(0);
        });
});

it('⚠️ un documento non pubblicato non compare, nemmeno nel conteggio', function () {
    // Il conteggio e l'elenco passano dalla **stessa** query di base: se si scollegassero, la
    // scheda direbbe «1 documento» e aprendola non ci sarebbe niente — che è il modo peggiore di
    // sbagliare, perché sembra che il documento sia sparito.
    $documento = documentoDiArchivio('Bozza riservata', [$this->verbali->id]);
    $documento->update(['is_published' => false]);

    $risposta = $this->get(route('user.categorie-documenti.show', ['categoriaDocumento' => $this->verbali->id]))
        ->assertOk();

    expect(idNellaCategoria($risposta))->toBe([]);

    $this->get(route('user.categorie-documenti.index'))
        ->assertOk()
        ->assertInertia(function ($pagina) {
            $conteggi = collect($pagina->toArray()['props']['categorie'])->pluck('documenti_count', 'name');

            expect($conteggi['Verbali'])->toBe(0);
        });
});

it('⚠️ un documento di un altro condominio non si vede, per quante categorie abbia', function () {
    // La scoperta di categorie multiple non deve diventare una scorciatoia per vedere l'archivio di
    // un palazzo che non è il proprio: il filtro per categoria si applica **dentro** il perimetro
    // del condòmino, non al posto suo.
    $altroCondominio = Condominio::factory()->create();

    $altrui = Documento::create([
        'name'         => 'Verbale di un altro palazzo',
        'description'  => 'Documento di prova.',
        'path'         => 'documenti/altrui.pdf',
        'mime_type'    => 'application/pdf',
        'file_size'    => 1024,
        'created_by'   => $this->condomino->id,
        'is_published' => true,
        'is_approved'  => true,
    ]);
    $altrui->categorie()->attach([$this->verbali->id, $this->bilanci->id]);
    $altrui->condomini()->attach($altroCondominio->id);

    foreach ([$this->verbali, $this->bilanci] as $categoria) {
        $risposta = $this->get(route('user.categorie-documenti.show', ['categoriaDocumento' => $categoria->id]))
            ->assertOk();

        expect(idNellaCategoria($risposta))->toBe([]);
    }
});

it('un documento di un soggetto non entra nell\'archivio del condòmino', function () {
    // I documenti caricati su un fornitore, un'unità o un'anagrafica **non hanno categoria** di
    // proposito, e non stanno nell'archivio: `whereNull('documentable_type')`. Questa riga è la
    // controprova della scelta dichiarata nel changelog della beta.10.
    $suo = documentoDiArchivio('Carta d\'identità', [$this->verbali->id]);
    $suo->documentable()->associate($this->anagrafica)->save();

    $risposta = $this->get(route('user.categorie-documenti.show', ['categoriaDocumento' => $this->verbali->id]))
        ->assertOk();

    expect(idNellaCategoria($risposta))->toBe([]);
});

/**
 * I permessi per caricare, dati al condòmino di questo file.
 *
 * Separati dal `beforeEach` di proposito: la maggior parte delle guardie qui sopra descrive un
 * condòmino che l'archivio lo **consulta e basta**, che è la configurazione più comune.
 */
function condominoCheCarica(): void
{
    foreach ([
        Permission::CREATE_ARCHIVE_DOCUMENTS->value,
        Permission::PUBLISH_ARCHIVE_DOCUMENTS->value,
    ] as $nome) {
        SpatiePermission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
        test()->condomino->givePermissionTo($nome);
    }

    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
}

it('⚠️ il modulo di caricamento del condòmino riceve l\'elenco delle categorie', function () {
    // ⚠️ **Senza questa prop il campo c'è e resta vuoto**, che è peggio del campo assente: la
    // categoria è obbligatoria, quindi il condòmino si troverebbe davanti un modulo che non può
    // compilare e nessuna spiegazione del perché.
    //
    // Fino alla 1.11.0-beta.10 il campo non c'era affatto — la categoria arrivava dall'indirizzo,
    // invisibile — e il modulo di *modifica* ne aveva uno: si potevano aggiungere categorie solo
    // dopo aver salvato. Trovato guardando la schermata, chiudendo la beta.
    condominoCheCarica();

    $this->get(route('user.documenti.create', ['categoria' => $this->bilanci->id]))
        ->assertOk()
        ->assertInertia(function ($pagina) {
            $props = $pagina->toArray()['props'];

            expect($props['component'] ?? $pagina->toArray()['component'])->toBe('documenti/user/DocumentiNew')
                ->and($props)->toHaveKey('categories')
                ->and(collect($props['categories'])->pluck('name'))->toContain('Verbali', 'Bilanci');

            // La categoria da cui è arrivato, che il modulo preseleziona.
            expect($props['categoria'])->toBe($this->bilanci->id);
        });
});

it('⚠️ il condòmino carica un documento in DUE categorie, e lo trova sotto tutte e due', function () {
    Storage::fake('local');
    condominoCheCarica();

    $this->post(route('user.documenti.store'), [
        'name'          => 'Verbale assemblea che approva il bilancio',
        'description'   => 'Caricato dal condòmino.',
        'condomini_ids' => [$this->condominio->id],
        'is_private'    => false,
        'categorie'     => [$this->bilanci->id, $this->verbali->id],
        'file'          => UploadedFile::fake()->create('verbale.pdf', 12, 'application/pdf'),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $documento = Documento::where('name', 'Verbale assemblea che approva il bilancio')->firstOrFail();

    expect($documento->categorie()->pluck('name')->sort()->values()->all())
        ->toBe(['Bilanci', 'Verbali']);

    foreach ([$this->verbali, $this->bilanci] as $categoria) {
        $risposta = $this->get(route('user.categorie-documenti.show', ['categoriaDocumento' => $categoria->id]))
            ->assertOk();

        expect(in_array($documento->id, idNellaCategoria($risposta), true))
            ->toBeTrue("il documento caricato dal condòmino manca sotto «{$categoria->name}»");
    }
});

it('⚠️ senza nemmeno una categoria il caricamento del condòmino viene rifiutato', function () {
    Storage::fake('local');
    condominoCheCarica();

    // È l'altra metà della decisione presa per questa beta: nell'archivio la categoria è
    // obbligatoria, perché un documento d'archivio senza categoria non compare in nessuna vista
    // per categoria e si trova solo cercandolo per nome.
    $this->post(route('user.documenti.store'), [
        'name'          => 'Documento senza casa',
        'description'   => 'Caricato dal condòmino.',
        'condomini_ids' => [$this->condominio->id],
        'is_private'    => false,
        'categorie'     => [],
        'file'          => UploadedFile::fake()->create('verbale.pdf', 12, 'application/pdf'),
    ])->assertSessionHasErrors('categorie');
});
