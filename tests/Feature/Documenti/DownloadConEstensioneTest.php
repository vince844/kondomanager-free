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
        'category_id'  => CategoriaDocumento::firstOrCreate(['name' => 'Verbali'])->id,
        'is_published' => true,
        'is_approved'  => true,
        'created_by'   => $autore->id,
    ]);

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
