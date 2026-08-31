<?php

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\CategoriaDocumento;
use App\Models\Documento;
use App\Models\Immobile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

/**
 * La scheda di un'anagrafica, costruita nella 1.11.0-beta.9.
 *
 * ## ⚠️ Il difetto che questo file nasce per non far tornare
 *
 * `AnagraficaController::show()` aveva il **corpo vuoto** — letteralmente `//` — e `Route::resource`
 * registrava comunque la rotta: `admin/anagrafiche/{id}` rispondeva **200 con niente dentro**.
 * Pagina bianca, nessun errore, nessuna riga di log. E non era irraggiungibile:
 * `resources/js/components/anagrafiche/columns.ts` ci punta col **nome della persona** nell'elenco
 * della rubrica, quindi bastava cliccare un nome.
 *
 * È la famiglia del difetto arrivato dal forum nella beta.62 — `categorie.show` verso un metodo
 * inesistente, 500 in faccia a chi ci arrivava — con una differenza che la rende **peggiore**: un
 * 500 lo si segnala, una pagina bianca fa pensare che sia colpa della propria connessione.
 *
 * ⚠️ **Un `assertOk()` da solo non lo avrebbe scoperto**, ed è il motivo per cui qui si verifica il
 * *componente reso*: la vecchia rotta rispondeva **200**.
 *
 * ## Cosa NON copre
 *
 * Non copre la resa a schermo dei riquadri, né il percorso a permessi negati, né la scheda dal lato
 * dell'utente non amministratore.
 */
/**
 * ⚠️ `anagrafiche.indirizzo` è **NOT NULL a schema**, senza valore predefinito: una `create()` col
 * solo nome fallisce con un errore di vincolo che non c'entra niente con quello che si sta
 * provando. L'helper tiene il minimo che il database pretende in un posto solo.
 */
function creaAnagrafica(array $override = []): Anagrafica
{
    return Anagrafica::create(array_merge([
        'nome'      => 'Mario Bianchi',
        'indirizzo' => 'via Roma 1',
    ], $override));
}

/**
 * Un documento di prova.
 *
 * ⚠️ **La categoria è facoltativa, e quasi sempre non c'è** (1.11.0-beta.10). I documenti di questo
 * file sono in gran parte **documenti di un soggetto** — associati a un'anagrafica con
 * `documentable()` — e quelli la categoria non ce l'hanno **di proposito**: i loro moduli non la
 * chiedono. Solo il documento *ricevuto*, che è d'archivio, ne ha una.
 *
 * Prima qui c'era `'category_id' => $categoria->id`, ed era una riga muta: `category_id` non è
 * `$fillable`, quindi l'assegnazione di massa la **scartava in silenzio** e il fixture dichiarava
 * una categoria che il documento non aveva. È il rovescio del taglio netto della beta.10 — i
 * lettori dimenticati esplodono, gli *scrittori* per assegnazione di massa no.
 */
function creaDocumento(string $nome, int $utente, ?CategoriaDocumento $categoria = null): Documento
{
    $documento = Documento::create([
        'name'         => $nome,
        'description'  => 'Documento di prova.',
        'path'         => 'documenti/prova.pdf',
        'mime_type'    => 'application/pdf',
        'file_size'    => 9,
        'created_by'   => $utente,
        'is_published' => true,
        'is_approved'  => true,
    ]);

    if ($categoria) {
        $documento->categorie()->attach($categoria->id);
    }

    return $documento;
}

beforeEach(function () {
    Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);

    $this->user = User::factory()->create();
    $this->user->givePermissionTo('Accesso pannello amministratore');
    $this->actingAs($this->user);
});

it('⚠️ la scheda risponde con una pagina vera, non con un 200 vuoto', function () {
    $anagrafica = creaAnagrafica(['email' => 'mario@example.test']);

    $this->get(route('admin.anagrafiche.show', ['anagrafica' => $anagrafica->id]))
        ->assertOk()
        ->assertInertia(function ($pagina) use ($anagrafica) {
            // ⚠️ **È questa riga la guardia, non l'`assertOk()` sopra.** Col metodo vuoto la rotta
            // rispondeva 200 e non rendeva nessun componente: solo guardando *cosa* è stato reso il
            // test distingue una pagina da una pagina bianca.
            expect($pagina->toArray()['component'])->toBe('anagrafiche/AnagraficheView');

            $props = $pagina->toArray()['props'];

            expect($props['anagrafica']['nome'])->toBe('Mario Bianchi')
                ->and($props['anagrafica']['id'])->toBe($anagrafica->id);
        });
});

it('la scheda porta le unità con il ruolo e la quota, non solo il loro numero', function () {
    $anagrafica = creaAnagrafica();
    $condominio = Condominio::factory()->create();

    // ⚠️ `Immobile` e `Documento` **non hanno una factory** in questo progetto: si creano a mano
    // col minimo che lo schema pretende, come fanno gli altri test del gestionale.
    $immobile = Immobile::create([
        'condominio_id' => $condominio->id,
        'nome'          => 'Interno 3',
        'descrizione'   => 'Appartamento di prova',
        'interno'       => '3',
    ]);

    $anagrafica->immobili()->attach($immobile->id, [
        'tipologia'   => 'proprietario',
        'quota'       => 100,
        'attivo'      => true,
        // `data_inizio` è NOT NULL sulla pivot: il legame persona-unità ha sempre un inizio, ed è
        // la data da cui il riparto lo considera.
        'data_inizio' => '2026-01-01',
    ]);

    $this->get(route('admin.anagrafiche.show', ['anagrafica' => $anagrafica->id]))
        ->assertOk()
        ->assertInertia(function ($pagina) {
            $unita = $pagina->toArray()['props']['immobili'];

            expect($unita)->toHaveCount(1);

            // Il ruolo e la quota stanno **sulla pivot**: senza, la scheda direbbe «occupa un'unità»
            // senza dire a che titolo, che è l'unica cosa che conta per capire chi paga cosa.
            expect($unita[0]['tipologia'])->toBe('proprietario')
                ->and((float) $unita[0]['quota'])->toBe(100.0)
                ->and($unita[0]['attivo'])->toBeTrue()
                ->and($unita[0]['condominio'])->not->toBeNull();
        });
});

it('⚠️ la scheda documenti elenca i documenti DELLA persona, non quelli che ha solo ricevuto', function () {
    // ⚠️ **Sono due relazioni diverse e la distinzione è il cuore di questa scheda.**
    // `documentiPropri()` è una morphMany su `documentable`: il documento **appartiene** alla
    // persona — copia del documento d'identità, deleghe — ed è quello che da qui si carica e si
    // cancella. `documenti()` è la belongsToMany su `anagrafica_documento`: là la persona è solo
    // *destinataria* di un documento dell'archivio, che riguarda tutti e vive altrove.
    //
    // Confonderle metterebbe nella stessa tabella un pulsante «elimina» che significa due cose:
    // togliere un documento a una persona, oppure cancellare dall'archivio il verbale
    // dell'assemblea.
    $anagrafica = creaAnagrafica();
    $categoria = CategoriaDocumento::create(['name' => 'Verbali', 'description' => 'Assemblee']);

    $suo = creaDocumento('Carta d\'identità', $this->user->id);
    $suo->documentable()->associate($anagrafica)->save();

    // Ricevuto ma non suo: **non** deve comparire.
    // Questo è d'archivio — attaccato all'anagrafica come destinataria — e la categoria ce l'ha.
    $ricevuto = creaDocumento('Verbale assemblea 2026', $this->user->id, $categoria);
    $anagrafica->documenti()->attach($ricevuto->id);

    // E di un'altra persona: nemmeno.
    $altraPersona = creaAnagrafica(['nome' => 'Anna Verdi']);
    $altrui = creaDocumento('Documento di un altro', $this->user->id);
    $altrui->documentable()->associate($altraPersona)->save();

    $this->get(route('admin.anagrafiche.documenti.index', ['anagrafica' => $anagrafica->id]))
        ->assertOk()
        ->assertInertia(function ($pagina) {
            expect($pagina->toArray()['component'])->toBe('anagrafiche/documenti/DocumentiList');

            $nomi = collect($pagina->toArray()['props']['documenti'])->pluck('name');

            expect($nomi)->toHaveCount(1)
                ->and($nomi->first())->toContain('arta d\'identità');
        });
});

it('un documento si carica sulla scheda, e resta legato a quella persona', function () {
    Storage::fake('local');

    $anagrafica = creaAnagrafica();

    $this->post(route('admin.anagrafiche.documenti.store', ['anagrafica' => $anagrafica->id]), [
        'name'         => 'Delega assemblea',
        'description'  => 'Delega per l\'assemblea di marzo.',
        'is_published' => false,
        'is_approved'  => true,
        'file'         => UploadedFile::fake()->create('delega.pdf', 12, 'application/pdf'),
    ])->assertRedirect();

    $documento = Documento::where('name', 'Delega assemblea')->first();

    expect($documento)->not->toBeNull()
        // Il legame morfologico è quello che rende il documento **suo**: senza, sarebbe una riga
        // sciolta nell'archivio che questa scheda non ritroverebbe mai.
        ->and($documento->documentable_type)->toBe(Anagrafica::class)
        ->and((int) $documento->documentable_id)->toBe($anagrafica->id)
        ->and($documento->created_by)->toBe($this->user->id);

    Storage::disk('local')->assertExists($documento->path);
});

it('un file che non è un PDF viene rifiutato', function () {
    Storage::fake('local');

    $anagrafica = creaAnagrafica();

    $this->post(route('admin.anagrafiche.documenti.store', ['anagrafica' => $anagrafica->id]), [
        'name'         => 'Foto',
        'is_published' => false,
        'is_approved'  => true,
        'file'         => UploadedFile::fake()->image('foto.jpg'),
    ])->assertSessionHasErrors('file');

    expect(Documento::where('name', 'Foto')->exists())->toBeFalse();
});

it('⚠️ non si può toccare il documento di un\'altra persona cambiando l\'id nell\'indirizzo', function () {
    // ⚠️ Il binding di rotta risolve i due parametri **in modo indipendente**: senza la guardia nel
    // controller, passando da questa scheda si modificherebbe il documento di un'altra anagrafica.
    // Nessun errore, nessuna traccia: solo il documento sbagliato che cambia nome.
    $anagrafica = creaAnagrafica();
    $altra = creaAnagrafica(['nome' => 'Anna Verdi']);

    $documentoAltrui = creaDocumento('Documento di Anna', $this->user->id);
    $documentoAltrui->documentable()->associate($altra)->save();

    $this->put(route('admin.anagrafiche.documenti.update', [
        'anagrafica' => $anagrafica->id,
        'documento'  => $documentoAltrui->id,
    ]), [
        'name'         => 'Rinominato di nascosto',
        'is_published' => true,
        'is_approved'  => true,
    ])->assertNotFound();

    expect($documentoAltrui->refresh()->name)->toBe('Documento di Anna');
});

it('un documento si elimina, e il file sparisce dal disco', function () {
    Storage::fake('local');

    $anagrafica = creaAnagrafica();

    $this->post(route('admin.anagrafiche.documenti.store', ['anagrafica' => $anagrafica->id]), [
        'name'         => 'Da eliminare',
        'is_published' => false,
        'is_approved'  => true,
        'file'         => UploadedFile::fake()->create('doc.pdf', 5, 'application/pdf'),
    ]);

    $documento = Documento::where('name', 'Da eliminare')->firstOrFail();
    $percorso = $documento->path;

    $this->delete(route('admin.anagrafiche.documenti.destroy', [
        'anagrafica' => $anagrafica->id,
        'documento'  => $documento->id,
    ]))->assertRedirect();

    expect(Documento::find($documento->id))->toBeNull();

    // Il file va tolto **anche dal disco**: una riga cancellata che lascia il PDF sul server è un
    // dato personale che resta lì senza che nessuna schermata lo mostri più.
    Storage::disk('local')->assertMissing($percorso);
});

it('la scheda documenti passa l\'anagrafica, che serve alla barra delle schede', function () {
    // ⚠️ Il layout legge `anagrafica` da `usePage()`: se il controller smettesse di passarla, le
    // due linguette sparirebbero **senza nessun errore**, e la pagina sembrerebbe solo «più
    // spoglia». È il tipo di regressione che nessuno collega alla sua causa.
    $anagrafica = creaAnagrafica();

    $this->get(route('admin.anagrafiche.documenti.index', ['anagrafica' => $anagrafica->id]))
        ->assertOk()
        ->assertInertia(fn ($pagina) => expect($pagina->toArray()['props']['anagrafica']['nome'])->toBe('Mario Bianchi'));
});

it('le rotte dei documenti dell\'anagrafica sono solo quelle che il controller sa fare', function () {
    // La famiglia già arrivata due volte dal forum: `Route::resource` ne registra sette, il
    // controller ne implementa sei, e quella di troppo — `show` — risponderebbe 500 a chi ci arriva
    // per URL. Un documento non ha una pagina di dettaglio: o si apre il file, o lo si modifica.
    $nomi = collect(Route::getRoutes()->getRoutesByName())
        ->keys()
        ->filter(fn (string $n) => str_starts_with($n, 'admin.anagrafiche.documenti.'))
        ->sort()
        ->values()
        ->all();

    expect($nomi)->toBe([
        'admin.anagrafiche.documenti.create',
        'admin.anagrafiche.documenti.destroy',
        'admin.anagrafiche.documenti.edit',
        'admin.anagrafiche.documenti.index',
        'admin.anagrafiche.documenti.store',
        'admin.anagrafiche.documenti.update',
    ]);
});

it('il modulo di caricamento è una pagina, come quello del fornitore', function () {
    $anagrafica = creaAnagrafica();

    $this->get(route('admin.anagrafiche.documenti.create', ['anagrafica' => $anagrafica->id]))
        ->assertOk()
        ->assertInertia(function ($pagina) {
            expect($pagina->toArray()['component'])->toBe('anagrafiche/documenti/DocumentiNew');

            // Il limite lo dichiara il server: se questa prop mancasse, la pagina prometterebbe un
            // limite inventato — o non lo direbbe affatto.
            expect($pagina->toArray()['props']['limiteFile'])->not->toBeEmpty();
        });
});

it('⚠️ non si apre il modulo di modifica del documento di un\'altra persona', function () {
    $anagrafica = creaAnagrafica();
    $altra = creaAnagrafica(['nome' => 'Anna Verdi']);

    $suoDiLei = creaDocumento('Documento di Anna', $this->user->id);
    $suoDiLei->documentable()->associate($altra)->save();

    // Senza la guardia il modulo si aprirebbe **con i dati di Anna dentro**, raggiunto dalla scheda
    // di un'altra persona: un dato personale mostrato a chi stava guardando altro.
    $this->get(route('admin.anagrafiche.documenti.edit', [
        'anagrafica' => $anagrafica->id,
        'documento'  => $suoDiLei->id,
    ]))->assertNotFound();
});
