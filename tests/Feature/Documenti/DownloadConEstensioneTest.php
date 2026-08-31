<?php

/**
 * Il documento scaricato conserva l'estensione, **da tutte e due le porte**.
 *
 * ## Origine: due segnalazioni dal forum, la seconda che conferma la prima
 *
 * La prima, del 06/05/2026, arrivava con la diagnosi e la correzione già scritte:
 *
 * > *«nel download di un documento […] questo non conserva l'estensione originale»* — e il codice
 * > proposto: leggere l'estensione da `pathinfo($documento->path, PATHINFO_EXTENSION)` e
 * > appenderla a `$documento->name` se non c'è già.
 *
 * Il difetto è reale ed è di forma semplice: il file su disco nasce da `hashName()`, che
 * **preserva** l'estensione (`documenti/a1b2c3.pdf`), mentre `$documento->name` è il titolo
 * scritto dall'amministratore, che estensione non ne ha. Passando quel titolo come nome di
 * scaricamento, il browser riceve un file senza estensione.
 *
 * La correzione è stata applicata a `Documenti\DocumentoController@download` — e lì si è fermata.
 *
 * La seconda segnalazione, tre mesi dopo, dice la stessa cosa dall'altro lato:
 *
 * > *«Dal mio utente sono andato in "documenti" […] lo trovo in elenco e riesco a scaricarlo
 * > correttamente. Ho effettuato l'accesso con l'utente "condomino" […] il download del file
 * > avviene senza alcuna estensione.»*
 *
 * Non è un difetto nuovo: è **la metà non corretta del primo**. Il condòmino passa da
 * `Documenti\Utenti\DocumentoController@download`, un file diverso, rimasto alla versione di
 * prima di maggio. I due controller sono copie quasi riga per riga, e finché restano due copie
 * ogni correzione futura ha una probabilità su due di finire su una sola.
 *
 * ## Perché nessuno se n'era accorto
 *
 * `grep -rni "download" tests/` restituiva quattordici righe, e nessuna riguardava i documenti
 * d'archivio: l'unico `assertDownload` della suite era sui backup. La correzione di maggio non ha
 * lasciato dietro di sé nessun test, quindi non c'era niente che sapesse dell'esistenza del
 * gemello.
 *
 * E l'amministratore **non può riprodurre la segnalazione**: le due dashboard montano lo stesso
 * componente Vue, e `generateRoute()` lo instrada su due controller diversi a seconda del ruolo.
 * Chi prova dalla propria utenza vede il file con l'estensione e conclude che chi segnala si
 * sbaglia.
 *
 * ## Cosa questo file prova, e cosa NON prova
 *
 * Prova che **entrambe** le rotte di scaricamento restituiscono il nome con l'estensione, e che
 * il nome non la raddoppia quando c'è già. È scritto **per rotta**, non per controller: è la
 * simmetria fra le due porte a essere l'invariante, e un test per controller sarebbe di nuovo
 * una copia che si può correggere a metà.
 *
 * Non copre: il nome che contiene `/` o `\` (Symfony solleva e il documento diventa non
 * scaricabile — difetto preesistente e indipendente, che riguarda la validazione del titolo, non
 * lo scaricamento); i documenti di fornitore e di unità immobiliare, che pure hanno un `name`
 * senza estensione ma i cui link passano già dal controller corretto; il tipo MIME
 * dell'intestazione.
 */

use App\Enums\Permission;
use App\Models\Anagrafica;
use App\Models\CategoriaDocumento;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission as SpatiePermission;

/**
 * Il documento con il titolo **senza estensione** — cioè il caso della segnalazione — e il suo
 * file davvero presente sul disco `local`, che è quello su cui scrivono i due `store()`.
 */
function documentoDaScaricare(Condominio $condominio, User $autore, string $titolo = 'Verbale assemblea 2026'): Documento
{
    Storage::disk('local')->put('documenti/a1b2c3d4.pdf', '%PDF-1.4 finto ma con la sua estensione');

    $documento = Documento::create([
        'name'         => $titolo,
        'description'  => 'Documento di prova',
        'path'         => 'documenti/a1b2c3d4.pdf',
        'mime_type'    => 'application/pdf',
        'file_size'    => 39,
        'is_published' => true,
        'is_approved'  => true,
        'created_by'   => $autore->id,
    ]);

    // ⚠️ **Con il legame, non con una colonna** (1.11.0-beta.10): `category_id` è sparita, e
    // passarla qui dentro sarebbe stata una riga muta — non essendo `$fillable`, l'assegnazione di
    // massa la **scarta in silenzio** e il documento sarebbe rimasto senza categoria, mentre il
    // fixture dichiarava il contrario.
    $documento->categorie()->attach(CategoriaDocumento::firstOrCreate(['name' => 'Verbali'])->id);

    $documento->condomini()->attach($condominio->id);

    return $documento;
}

function amministratoreDocumenti(): User
{
    $user = User::factory()->create();

    foreach ([Permission::ACCESS_ADMIN_PANEL->value, Permission::VIEW_ARCHIVE_DOCUMENTS->value] as $nome) {
        SpatiePermission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
        $user->givePermissionTo($nome);
    }

    return $user;
}

/**
 * Il condòmino: nessun accesso al pannello, il permesso di vedere l'archivio, e il legame al
 * condominio che passa dall'anagrafica — che è la strada per cui `DocumentoPolicy::view()` lo
 * lascia entrare.
 */
function condominoDocumenti(Condominio $condominio): User
{
    $user = User::factory()->create();

    SpatiePermission::firstOrCreate(['name' => Permission::VIEW_ARCHIVE_DOCUMENTS->value, 'guard_name' => 'web']);
    $user->givePermissionTo(Permission::VIEW_ARCHIVE_DOCUMENTS->value);

    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id]);
    $anagrafica->condomini()->attach($condominio->id);

    return $user;
}

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

    Storage::fake('local');

    $this->condominio = Condominio::factory()->create();
    $this->admin = amministratoreDocumenti();
});

it("l'amministratore scarica il file con la sua estensione", function () {
    $documento = documentoDaScaricare($this->condominio, $this->admin);

    $this->actingAs($this->admin)
        ->get(route('admin.documenti.download', ['documento' => $documento->id]))
        ->assertOk()
        ->assertDownload('Verbale assemblea 2026.pdf');
});

it('il condòmino scarica lo stesso file con la stessa estensione', function () {
    // ⚠️ È **questo** il test che la segnalazione di agosto chiedeva. Prima della correzione
    // falliva con `Verbale assemblea 2026`, senza estensione: il titolo passato così com'era.
    $documento = documentoDaScaricare($this->condominio, $this->admin);
    $condomino = condominoDocumenti($this->condominio);

    $this->actingAs($condomino)
        ->get(route('user.documenti.download', ['documento' => $documento->id]))
        ->assertOk()
        ->assertDownload('Verbale assemblea 2026.pdf');
});

it("l'estensione non si raddoppia quando il titolo ce l'ha già, da nessuna delle due porte", function () {
    $documento = documentoDaScaricare($this->condominio, $this->admin, 'Regolamento.pdf');
    $condomino = condominoDocumenti($this->condominio);

    $this->actingAs($this->admin)
        ->get(route('admin.documenti.download', ['documento' => $documento->id]))
        ->assertDownload('Regolamento.pdf');

    $this->actingAs($condomino)
        ->get(route('user.documenti.download', ['documento' => $documento->id]))
        ->assertDownload('Regolamento.pdf');
});

it("nemmeno quando il titolo la scrive in maiuscolo", function () {
    // Il doppio `strtolower` del confronto serve a questo, ed è l'unica ragione per cui esiste:
    // senza, `Regolamento.PDF` diventerebbe `Regolamento.PDF.pdf`.
    $documento = documentoDaScaricare($this->condominio, $this->admin, 'Regolamento.PDF');
    $condomino = condominoDocumenti($this->condominio);

    $this->actingAs($this->admin)
        ->get(route('admin.documenti.download', ['documento' => $documento->id]))
        ->assertDownload('Regolamento.PDF');

    $this->actingAs($condomino)
        ->get(route('user.documenti.download', ['documento' => $documento->id]))
        ->assertDownload('Regolamento.PDF');
});

it('le due porte leggono il disco `local`, non quello di default', function () {
    // ⚠️ Non è pignoleria di stile. I due `store()` scrivono a mano su `local`; i due `download()`
    // leggevano — uno dei due leggeva ancora — dal disco di **default**. Oggi coincidono perché
    // `FILESYSTEM_DISK=local`, ma è una coincidenza di configurazione, non una garanzia: chi
    // mettesse `public` o `s3` vedrebbe **tutti** i documenti rispondere «file non trovato».
    // Verso il SaaS non è uno scenario teorico.
    config()->set('filesystems.default', 'public');
    Storage::fake('public');

    $documento = documentoDaScaricare($this->condominio, $this->admin);
    $condomino = condominoDocumenti($this->condominio);

    $this->actingAs($this->admin)
        ->get(route('admin.documenti.download', ['documento' => $documento->id]))
        ->assertOk()
        ->assertDownload('Verbale assemblea 2026.pdf');

    $this->actingAs($condomino)
        ->get(route('user.documenti.download', ['documento' => $documento->id]))
        ->assertOk()
        ->assertDownload('Verbale assemblea 2026.pdf');
});

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * La terza segnalazione: un carattere che in un nome di file non ci può stare
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * *«Entro in modifica del documento, cambio il titolo e salvo. Riprovo a scaricarlo e ottengo
 * l'errore generico "Si è verificato un errore durante il download del documento". Ripristino il
 * titolo di prima e il download torna a funzionare.»* — e poi, avendolo capito da sé: *«Se nel nome
 * documento utilizzo un carattere che non è ammesso nel nome di un file (nel mio caso il "/") il
 * download di quel documento fallisce.»*
 *
 * La diagnosi dell'amministratore è esatta. `Documento::nomeDiScaricamento()` restituiva il titolo
 * così com'era, e `HeaderUtils::makeDisposition()` di Symfony **solleva un'eccezione** se il nome
 * contiene `/` o `\`: *«The filename and the fallback cannot contain the "/" and "\" characters»*.
 * Laravel ripulisce il solo *fallback* da accenti e da `%` (`ResponseFactory::fallbackName()`), ma
 * le barre non le tocca — e comunque il controllo di Symfony guarda **tutti e due** i nomi.
 *
 * ⚠️ **Il titolo non si può vietare in ingresso**, ed è il punto che decide la forma della
 * correzione. `Verbale 12/2026` è un titolo giusto: in Italia i verbali d'assemblea si numerano
 * così. Il titolo è un dato dell'archivio; il nome del file è un artefatto che se ne ricava. A
 * doversi adattare è il secondo.
 */

it('il titolo con una barra non fa più fallire il download, da nessuna delle due porte', function () {
    // ⚠️ È il difetto: prima della correzione qui arrivava un 500 e a video l'errore generico.
    $documento = documentoDaScaricare($this->condominio, $this->admin, 'Verbale 12/2026');
    $condomino = condominoDocumenti($this->condominio);

    $this->actingAs($this->admin)
        ->get(route('admin.documenti.download', ['documento' => $documento->id]))
        ->assertOk()
        ->assertDownload('Verbale 12-2026.pdf');

    $this->actingAs($condomino)
        ->get(route('user.documenti.download', ['documento' => $documento->id]))
        ->assertOk()
        ->assertDownload('Verbale 12-2026.pdf');
});

it('la barra rovesciata vale come quella dritta', function () {
    // Symfony le rifiuta tutte e due nella stessa riga. Chi arriva da Windows batte questa.
    $documento = documentoDaScaricare($this->condominio, $this->admin, 'Verbale 12\\2026');

    $this->actingAs($this->admin)
        ->get(route('admin.documenti.download', ['documento' => $documento->id]))
        ->assertOk()
        ->assertDownload('Verbale 12-2026.pdf');
});

it("il titolo in archivio non viene toccato: a cambiare è solo il nome del file", function () {
    // ⚠️ La prova che la correzione è dove deve essere. Se avessimo ripulito il titolo al
    // salvataggio, l'amministratore si vedrebbe riscrivere `Verbale 12/2026` in `Verbale 12-2026`
    // in elenco, nella ricerca e nelle notifiche — cioè gli avremmo corretto un dato giusto.
    $documento = documentoDaScaricare($this->condominio, $this->admin, 'Verbale 12/2026');

    $this->actingAs($this->admin)
        ->get(route('admin.documenti.download', ['documento' => $documento->id]))
        ->assertOk();

    expect($documento->fresh()->name)->toBe('Verbale 12/2026');
});

it('gli altri caratteri che un nome di file non accetta si raddrizzano insieme', function (string $titolo, string $atteso) {
    // ⚠️ **Due gravità diverse, una correzione sola.** `/` e `\` fanno rispondere 500 al server —
    // è il difetto segnalato. Gli altri (`: * ? " < > |`) il server li lascia passare, ma su
    // Windows il file **non si salva**: l'utente vede il download partire e finire nel nulla.
    // Poiché la segnalazione è arrivata nella forma della classe — *«un carattere che non è
    // ammesso nel nome di un file»* — la classe si chiude tutta, non solo il caso che gridava.
    $documento = documentoDaScaricare($this->condominio, $this->admin, $titolo);

    $this->actingAs($this->admin)
        ->get(route('admin.documenti.download', ['documento' => $documento->id]))
        ->assertOk()
        ->assertDownload($atteso);
})->with([
    'due punti'          => ['Assemblea: convocazione', 'Assemblea- convocazione.pdf'],
    'asterisco'          => ['Nota *urgente*', 'Nota -urgente-.pdf'],
    'punto interrogativo' => ['Che fare?', 'Che fare-.pdf'],
    'virgolette'         => ['Verbale "definitivo"', 'Verbale -definitivo-.pdf'],
    'minore e maggiore'  => ['Delibera <bozza>', 'Delibera -bozza-.pdf'],
    'barra verticale'    => ['Spese | 2026', 'Spese - 2026.pdf'],
]);
