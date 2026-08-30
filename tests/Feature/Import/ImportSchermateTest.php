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
/**
 * Chi può importare: dal 30/08/2026 chi ha **«Importa dati»**, non più chi ha «Crea condomini».
 *
 * ⚠️ Il cambio ha acceso otto casi di questo file, ed è la prova che serviva: erano tutti scritti
 * su un utente che oggi non passa più. Non sono stati «aggiustati» — è cambiata la regola, e con
 * lei chi la esercita.
 */
function utenteImport(): User
{
    permessiBase();

    $user = User::factory()->create();
    $user->givePermissionTo('Importa dati');
    $user->givePermissionTo('Crea condomini');

    return $user;
}

/** Un utente che ha il **vecchio** permesso e non quello nuovo: da oggi non importa più. */
function utenteSenzaImport(): User
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
    foreach (['Crea condomini', 'Accesso pannello amministratore', 'Importa dati'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
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

    // ⚠️ **Lo stesso utente per tutto il caso, e non `utenteImport()` due volte.**
    //
    // La seconda chiamata creava un utente **diverso**, e finché le due rotte dei file non
    // verificavano la proprietà del lotto la cosa non si vedeva: il caso passava esercitando il
    // buco, esattamente come la beta.3 aveva già raccontato di `caricaBundle()`. Chiusa la falla,
    // questo caso è diventato rosso — ed era la prova che la guardia serviva.
    $chiImporta = utenteImport();

    $this->actingAs($chiImporta)->post(route('import.store'), [
        'file' => [fileFixture('elenco_unita.xls')],
    ]);

    $batch = ImportBatch::latest()->first();
    $file = $batch->files()->first();

    $this->actingAs($chiImporta)
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

    // Lo stesso utente per tutto il caso: le rotte dei file verificano la proprietà del lotto.
    $chiImporta = utenteImport();

    $this->actingAs($chiImporta)->post(route('import.store'), [
        'file' => [fileFixture('elenco_unita.xls'), fileFixture('riparto_consuntivo.xls')],
    ]);

    $batch = ImportBatch::latest()->first();
    $file = $batch->files()->first();

    $this->actingAs($chiImporta)
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

/*
|--------------------------------------------------------------------------
| La tendina «Cambia…»: cosa succede scegliendo un tipo
|--------------------------------------------------------------------------
|
| Scritti il 30/08/2026 su richiesta di Vincenzo, che ha chiesto i test «di cosa succede se
| seleziono un'opzione dal menu a tendina».
|
| ⚠️ **Un test c'era già** — «l'ultima parola sul tipo è dell'amministratore» — e verifica la
| **colonna**: `report_type` cambia e `tipo_forzato` diventa vero. Ma la colonna non è quello che
| succede: è la traccia di quello che succede. Quello che l'amministratore vede è che il file
| **viene letto in un altro modo**, e quello nessuno lo provava. È la forma che questo progetto
| chiama «un test il cui nome promette più di quanto il corpo verifichi», applicata al perimetro
| invece che al nome.
*/

it('scegliere un tipo dalla tendina cambia davvero come il file viene letto, non solo la colonna', function () {
    Storage::fake('local');

    // ⚠️ Lo stesso utente per tutto il caso: `forzaTipo()` verifica la proprietà del lotto dal
    // 30/08/2026, e due `utenteImport()` sono due persone diverse.
    $chiImporta = utenteImport();

    // `elenco_unita.xls` riconosciuto per quello che è: produce persone, unità e titolarità.
    $this->actingAs($chiImporta)->post(route('import.store'), [
        'file' => [fileFixture('elenco_unita.xls')],
    ]);

    $batch = ImportBatch::orderByDesc('id')->first();
    $file = $batch->files()->first();

    expect($file->report_type)->toBe('elenco_unita');

    // Ora l'amministratore dice: no, questo è un altro report. La tendina manda esattamente questo.
    $this->actingAs($chiImporta)
        ->put(route('import.file.tipo', [$batch->uuid, $file->id]), ['report_type' => 'movimenti'])
        ->assertRedirect();

    // ⚠️ **La prova non è la colonna: è cosa il motore ne ricava adesso.** Letto come «movimenti»,
    // quel file non produce più né unità né titolarità — e questo è ciò che l'amministratore vedrà
    // nella schermata di verifica, che è il posto in cui si accorge di aver scelto male.
    $letto = app(\App\Services\Import\ImportVerificaService::class)->verifica($batch->fresh());

    expect(array_keys($letto['canonici']))->not->toContain('unita')
        ->and(array_keys($letto['canonici']))->not->toContain('titolarita');
});

it('la tendina non può proporre un tipo che non esiste, e il server non lo accetta comunque', function () {
    Storage::fake('local');

    $this->actingAs(utenteImport())->post(route('import.store'), [
        'file' => [fileFixture('elenco_unita.xls')],
    ]);

    $batch = ImportBatch::orderByDesc('id')->first();
    $file = $batch->files()->first();

    // La tendina offre un elenco chiuso, ma la rotta è raggiungibile lo stesso: la validazione è
    // la difesa vera, l'elenco è una comodità.
    $this->actingAs(utenteImport())
        ->put(route('import.file.tipo', [$batch->uuid, $file->id]), ['report_type' => 'non_esiste'])
        ->assertSessionHasErrors('report_type');

    expect($file->fresh()->report_type)->toBe('elenco_unita')
        // ⚠️ E soprattutto **non** deve restare marcato come forzato: un rifiuto non è una scelta.
        ->and($file->fresh()->tipo_forzato)->toBeFalse();
});

it('il tipo di un file si cambia solo dal lotto che lo contiene', function () {
    // ⚠️ La rotta porta **due** identificativi — l'uuid del lotto e l'id del file — e chi conosce
    // il secondo potrebbe non avere niente a che fare con il primo. È la stessa famiglia del buco
    // sulla proprietà del lotto chiuso nella beta.3: un id in una richiesta non è un'autorizzazione.
    Storage::fake('local');

    $this->actingAs(utenteImport())->post(route('import.store'), ['file' => [fileFixture('elenco_unita.xls')]]);
    $mio = ImportBatch::orderByDesc('id')->first();

    $this->actingAs(utenteImport())->post(route('import.store'), ['file' => [fileFixture('riparto_consuntivo.xls')]]);
    $altro = ImportBatch::orderByDesc('id')->first();
    $fileAltrui = $altro->files()->first();

    $this->actingAs(utenteImport())
        ->put(route('import.file.tipo', [$mio->uuid, $fileAltrui->id]), ['report_type' => 'movimenti'])
        ->assertNotFound();

    expect($fileAltrui->fresh()->report_type)->toBe('riparto_consuntivo');
});



it('⛔ chi non è amministratore non arriva all\'importazione, nemmeno alle rotte dei file', function () {
    // ⚠️ **Due difetti trovati insieme il 30/08/2026, e il secondo ha risolto il primo.**
    //
    // *Il primo:* `forzaTipo()` e `escludiFile()` erano le **uniche due** rotte del lotto a non
    // passare da `lotto()`, e quindi a non verificare di chi fosse il lotto. Non per una scelta:
    // hanno una firma diversa — un `ImportFile` risolto dal binding invece del solo uuid — e in
    // quella firma la riga non era mai stata scritta. Misurato: un utente con il solo permesso
    // «Crea condomini» **cambiava il tipo** di un file del lotto di un collega e **glielo
    // cancellava**, con due `302`, nessun 403 e nessuna traccia. La beta.3 aveva chiuso questa
    // falla dichiarando «tutte e nove le rotte del lotto»; quelle con un uuid sono **dodici**.
    //
    // *Il secondo, deciso da Vincenzo lo stesso giorno:* l'importazione è riservata agli
    // **amministratori**. Questo chiude il primo alla radice — chi non è amministratore non
    // arriva nemmeno alla rotta — e rende la guardia aggiunta una difesa in più invece che
    // l'unica. ⚠️ **Va detto che oggi quella guardia non è osservabile dall'esterno**: fra
    // amministratori `lotto()` lascia passare di proposito, perché vedere i lotti di tutti è il
    // loro mestiere. Resta perché la classe si chiude, non il caso: se un giorno il permesso si
    // allargasse di nuovo, quelle due rotte tornerebbero buchi da sole.
    Storage::fake('local');

    $amministratore = utenteImport();
    $senzaRuolo = utenteSenzaImport();

    $this->actingAs($amministratore)->post(route('import.store'), ['file' => [fileFixture('elenco_unita.xls')]]);
    $lotto = ImportBatch::orderByDesc('id')->first();
    $file = $lotto->files()->first();

    foreach ([
        fn () => $this->actingAs($senzaRuolo)->get(route('import.index')),
        fn () => $this->actingAs($senzaRuolo)->get(route('import.verifica', $lotto->uuid)),
        fn () => $this->actingAs($senzaRuolo)->put(route('import.file.tipo', [$lotto->uuid, $file->id]), ['report_type' => 'movimenti']),
        fn () => $this->actingAs($senzaRuolo)->delete(route('import.file.escludi', [$lotto->uuid, $file->id])),
    ] as $tentativo) {
        $tentativo()->assertForbidden();
    }

    // E il lotto è rimasto intatto: né il tipo cambiato, né il file sparito.
    expect($file->fresh()->report_type)->toBe('elenco_unita')
        ->and($lotto->fresh()->files()->count())->toBe(1);
});

it('il proprietario invece i suoi file li tocca eccome', function () {
    // ⚠️ **La controprova serve**: una guardia sulla proprietà si soddisfa anche rifiutando tutti,
    // e senza questo caso «nessuno può più cambiare il tipo di niente» sarebbe verde.
    Storage::fake('local');

    $proprietario = utenteImport();
    $this->actingAs($proprietario)->post(route('import.store'), ['file' => [fileFixture('elenco_unita.xls')]]);
    $lotto = ImportBatch::orderByDesc('id')->first();
    $file = $lotto->files()->first();

    $this->actingAs($proprietario)
        ->put(route('import.file.tipo', [$lotto->uuid, $file->id]), ['report_type' => 'movimenti'])
        ->assertRedirect();

    expect($file->fresh()->report_type)->toBe('movimenti');

    $this->actingAs($proprietario)
        ->delete(route('import.file.escludi', [$lotto->uuid, $file->id]))
        ->assertRedirect();

    expect($lotto->fresh()->files()->count())->toBe(0);
});

it('«Importa dati» arriva anche alle installazioni già in piedi, non solo a quelle nuove', function () {
    // ⚠️ **È la checklist della beta.59, e non è teorica.** Quella beta aveva introdotto l'elenco
    // dei Comuni con un seeder agganciato al solo `DatabaseSeeder`: su tutto il parco già
    // installato la tabella sarebbe rimasta vuota, senza un errore e senza un log. La regola che
    // ne è uscita: *se una beta introduce un dato di riferimento — un elenco, una mappa di
    // permessi — ci vuole (a) una riga in `SystemFinalizer::finalize()` che ce lo porta in
    // **aggiornamento**, e (b) un test che parte da **tabella vuota** e chiama `finalize()`, non
    // il comando.*
    //
    // Qui la riga (a) c'era già — `finalize()` lancia `RolesAndPermissionsSeeder` dalla beta.55 —
    // e questo è il (b): senza, il permesso nuovo esisterebbe solo per chi installa da zero, e
    // **ogni amministratore già esistente perderebbe l'importazione** al primo aggiornamento.
    expect(\Spatie\Permission\Models\Permission::where('name', 'Importa dati')->exists())->toBeFalse();

    (new Database\Seeders\RolesAndPermissionsSeeder)->run();

    expect(\Spatie\Permission\Models\Permission::where('name', 'Importa dati')->exists())->toBeTrue()
        ->and(\Spatie\Permission\Models\Role::findByName('amministratore', 'web')->hasPermissionTo('Importa dati'))->toBeTrue()
        // E il collaboratore **no**: è ciò che rende il permesso una delega invece di un regalo.
        ->and(\Spatie\Permission\Models\Role::findByName('collaboratore', 'web')->hasPermissionTo('Importa dati'))->toBeFalse();
});
