<?php

use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

/**
 * Le schermate S1 e S2 dell'importazione (§14.1), verificate dal lato HTTP.
 *
 * Il login a video lo fa Vincenzo — la sessione del dominio `.test` non vale su `127.0.0.1` e
 * le credenziali non passano da qui — quindi la verifica che possiamo fare noi è questa: che le
 * rotte esistano, che siano protette, e che portino alla pagina i dati che il disegno prevede.
 *
 * ## Cosa questi test NON coprono
 *
 * - **La resa a schermo**: la dropzone, il trascinamento e i menu di scelta si guardano con gli
 *   occhi, e vanno guardati prima di considerare fatte queste schermate.
 * - **Le schermate S3, S4 e S5**: verifica, conferma ed esito non esistono ancora.
 * - **Il pulsante «Verifica i file»**, che nella S2 è disabilitato perché la schermata a cui
 *   porta non c'è.
 */
function utenteImport(): User
{
    permessiBase();

    $user = User::factory()->create();
    $user->givePermissionTo('Crea condomini');

    return $user;
}

/**
 * I permessi che il guscio dell'applicazione dà per esistenti.
 *
 * `Accesso pannello amministratore` non serve all'import: lo interroga la **pagina 403**, che
 * altrimenti esplode mentre sta spiegando all'utente che non può entrare — un errore dentro la
 * gestione di un errore, che è il modo più rapido di trasformare un rifiuto pulito in una
 * schermata bianca.
 */
function permessiBase(): void
{
    foreach (['Crea condomini', 'Accesso pannello amministratore'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }
}

function fileFixture(string $nome): UploadedFile
{
    return new UploadedFile(
        base_path('tests/Fixtures/import/danea/'.$nome),
        $nome,
        'application/vnd.ms-excel',
        test: true,
    );
}

it('la pagina di ingresso è raggiungibile da chi può creare condomìni', function () {
    $this->actingAs(utenteImport())
        ->get(route('import.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('import/ImportHub')
            ->has('formati')
            // Il limite di dimensione si dichiara **prima** del caricamento (§7): scoprirlo
            // dopo è il momento peggiore per scoprirlo. Dalla beta.60 è **quello vero del server**
            // e non più solo il nostro tetto, quindi arriva già scritto per l'utente («25 MB»).
            ->where('dimensione_massima', \App\Support\LimiteCaricamento::etichetta(
                \App\Services\Import\SpreadsheetReader::DIMENSIONE_MASSIMA_BYTE / 1048576
            ))
        );
});

it('è chiusa a chi non ha il permesso', function () {
    permessiBase();

    $this->actingAs(User::factory()->create())
        ->get(route('import.index'))
        ->assertForbidden();
});

it('non è raggiungibile senza autenticazione', function () {
    $this->get(route('import.index'))->assertRedirect();
});

it('accetta tutti i file insieme e riconosce ciascuno', function () {
    // È la differenza più visibile dal concorrente: una dropzone sola invece di dodici cicli.
    Storage::fake('local');

    $risposta = $this->actingAs(utenteImport())->post(route('import.store'), [
        'file' => [
            fileFixture('elenco_unita.xls'),
            fileFixture('riparto_consuntivo.xls'),
            fileFixture('millesimi_tabelle_miste.xls'),
        ],
    ]);

    $batch = ImportBatch::latest()->first();

    expect($batch)->not->toBeNull()
        ->and($batch->files()->count())->toBe(3);

    $risposta->assertRedirect(route('import.riconoscimento', $batch->uuid));

    $tipi = $batch->files()->pluck('report_type')->all();
    expect($tipi)->toContain('elenco_unita')
        ->and($tipi)->toContain('riparto_consuntivo')
        ->and($tipi)->toContain('anagrafica_millesimi');
});

it('conserva i file nel disco privato, non fra quelli pubblici', function () {
    // Contengono codici fiscali, indirizzi ed email di condòmini reali.
    Storage::fake('local');

    $this->actingAs(utenteImport())->post(route('import.store'), [
        'file' => [fileFixture('elenco_unita.xls')],
    ]);

    $batch = ImportBatch::latest()->first();
    $percorso = $batch->files()->first()->percorso;

    expect($percorso)->toStartWith('import/'.$batch->uuid)
        ->and(Storage::disk('local')->exists($percorso))->toBeTrue();
});

it('rifiuta un formato che non sa leggere, spiegando cosa fare', function () {
    Storage::fake('local');

    $risposta = $this->actingAs(utenteImport())->post(route('import.store'), [
        'file' => [UploadedFile::fake()->create('bilancio.pdf', 10, 'application/pdf')],
    ]);

    $risposta->assertSessionHasErrors('file.0');

    expect(session('errors')->first('file.0'))->toContain('Excel');
    expect(ImportBatch::count())->toBe(0);
});

it('non ricarica due volte lo stesso identico file', function () {
    // Capita di continuo: l'amministratore corregge un file e ricarica **tutto** il gruppo.
    Storage::fake('local');

    $this->actingAs(utenteImport())->post(route('import.store'), [
        'file' => [fileFixture('elenco_unita.xls'), fileFixture('elenco_unita.xls')],
    ]);

    expect(ImportBatch::latest()->first()->files()->count())->toBe(1);
});

it('la schermata di riconoscimento dice cosa manca prima di dire cosa c\'è', function () {
    // Chi migra ha paura di perdere pezzi, non di averne troppi.
    Storage::fake('local');

    // La stessa persona carica e prosegue: dal 28/08/2026 un lotto è di chi l'ha caricato, e
    // due `utenteImport()` sono due persone diverse.
    $utente = utenteImport();

    $this->actingAs($utente)->post(route('import.store'), [
        'file' => [fileFixture('elenco_unita.xls')],
    ]);

    $batch = ImportBatch::latest()->first();

    $this->actingAs($utente)
        ->get(route('import.riconoscimento', $batch->uuid))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('import/ImportRiconoscimento')
            ->has('file', 1)
            // Manca il riparto: senza, niente saldi di apertura.
            ->has('mancanti', 2)
            ->where('file.0.tipo', 'elenco_unita')
            ->where('file.0.fiducia', 'media')
            // Le colonne ignorate si mostrano **sempre**, anche quando è tutto verde.
            ->has('file.0.colonne_ignorate')
        );
});

it('l\'ultima parola sul tipo è dell\'amministratore, e resta scritto che è stata sua', function () {
    Storage::fake('local');

    $this->actingAs(utenteImport())->post(route('import.store'), [
        'file' => [fileFixture('elenco_unita.xls')],
    ]);

    $batch = ImportBatch::latest()->first();
    $file = $batch->files()->first();

    $this->actingAs(utenteImport())
        ->put(route('import.file.tipo', [$batch->uuid, $file->id]), [
            'report_type' => 'anagrafica_millesimi',
        ])
        ->assertRedirect();

    $file->refresh();

    expect($file->report_type)->toBe('anagrafica_millesimi')
        // Non è statistica: il rapporto deve poter dire chi ha deciso.
        ->and($file->tipo_forzato)->toBeTrue();
});

it('un file si può escludere dall\'importazione', function () {
    Storage::fake('local');

    $this->actingAs(utenteImport())->post(route('import.store'), [
        'file' => [fileFixture('elenco_unita.xls'), fileFixture('riparto_consuntivo.xls')],
    ]);

    $batch = ImportBatch::latest()->first();
    $file = $batch->files()->first();

    $this->actingAs(utenteImport())
        ->delete(route('import.file.escludi', [$batch->uuid, $file->id]))
        ->assertRedirect();

    expect($batch->files()->count())->toBe(1);
});

it('propone di riprendere un\'importazione lasciata a metà', function () {
    // È la prima cosa che deve vedere: nessun altro gliela ricorderà.
    ImportBatch::create(['sorgente' => 'danea', 'stato' => ImportBatch::STATO_PARZIALE, 'livello_corrente' => 'unita']);

    $this->actingAs(utenteImport())
        ->get(route('import.index'))
        ->assertInertia(fn ($page) => $page
            ->where('interrotte.0.livello_corrente', 'unita')
        );
});

it('non propone di riprendere un\'importazione già completata', function () {
    ImportBatch::create(['sorgente' => 'danea', 'stato' => ImportBatch::STATO_COMPLETATO]);

    $this->actingAs(utenteImport())
        ->get(route('import.index'))
        ->assertInertia(fn ($page) => $page->where('interrotte', []));
});
