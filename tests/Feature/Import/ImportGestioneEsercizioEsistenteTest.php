<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\ImportBatch;
use App\Services\Import\Canonical\CanonicalEsercizio;
use App\Services\Import\ImportContext;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloEsercizi;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function utenteStraordinario(): App\Models\User
{
    foreach (['Crea condomini', 'Modifica condomini', 'Accesso pannello amministratore'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = App\Models\User::factory()->create();
    $u->givePermissionTo(['Crea condomini', 'Modifica condomini']);

    return $u;
}

/**
 * **Un esercizio già in archivio deve avere la sua gestione, o i saldi non entrano.**
 *
 * `LivelloSaldi` si ferma con `saldi.gestione_mancante` se non trova una gestione a cui
 * appoggiare il pregresso. Il livello «Esercizi» la creava **solo** quando l'esercizio era nuovo:
 * il ramo «esiste già» — che è sempre quello della destinazione scelta a mano — la saltava, e
 * l'importazione arrivava fino ai saldi per morire lì. Un esercizio nato dal middleware
 * `EnsureCondominioHasEsercizio` non ha nessuna gestione, quindi il caso è quello normale, non
 * quello limite.
 *
 * **Dal file non può arrivare**, e non è una lacuna del nostro lettore: Danea non ha le gestioni
 * come le abbiamo noi. Verificato sui riparti veri — una sola colonna «Totale gestione», e la
 * testata che dice «Esercizio *ordinario*»: lì la distinzione fra ordinario e straordinario sta
 * sull'esercizio, non su gestioni parallele dentro l'esercizio.
 */
it('aggancia la gestione ordinaria a un esercizio che ne era sprovvisto', function () {
    $condominio = Condominio::create([
        'nome' => 'CONDOMINIO CON ESERCIZIO NUDO',
        'codice_fiscale' => '97123456780',
        'indirizzo' => 'Via Senza Gestione 1',
    ]);

    // Come lo crea `EnsureCondominioHasEsercizio`: l'esercizio e basta.
    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome' => '2026',
        'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31',
        'stato' => 'aperto',
    ]);

    expect($condominio->gestioni()->count())->toBe(0);

    $batch = ImportBatch::create(['sorgente' => 'danea']);
    $ctx = (new ImportContext($batch))
        ->conCanonico(LivelloEsercizi::CHIAVE, new CanonicalEsercizio(
            etichetta: '2026',
            dataInizio: CarbonImmutable::parse('2026-01-01'),
            dataFine: CarbonImmutable::parse('2026-12-31'),
        ))
        ->conDecisioni([LivelloEsercizi::CHIAVE.':2026' => 'salta']);

    $ctx->risolvi(LivelloCondominio::CHIAVE, $condominio);

    $esito = (new LivelloEsercizi)->commit($ctx);

    expect($esito->riuscito())->toBeTrue();

    $gestione = $condominio->gestioni()->first();

    expect($gestione)->not->toBeNull()
        ->and($gestione->tipo)->toBe('ordinaria')
        // Agganciata **a quell'esercizio**: una gestione che esiste ma non è collegata lascia i
        // saldi esattamente dove erano, cioè fuori.
        ->and($gestione->esercizi()->where('esercizio_id', $esercizio->id)->exists())->toBeTrue();
});

it('avvisa quando il file dichiara un esercizio straordinario', function () {
    // ⚠️ **I due programmi modellano la cosa in modo diverso.** Danea distingue ordinario e
    // straordinario **sull'esercizio** — «Esercizio straordinario "2026"» nella testata — mentre
    // Kondomanager li tiene come due **gestioni** dentro lo stesso esercizio, che è la
    // separazione dell'art. 1130-bis.
    //
    // Il tipo veniva letto e finiva solo nella descrizione dell'esercizio, mentre la gestione
    // creata è **sempre** ordinaria: il pregresso di uno straordinario sarebbe finito nel
    // cassetto sbagliato senza che niente lo dicesse.
    //
    // La fixture è **sintetica**, e per una ragione che vale la pena scrivere: di export
    // straordinari non ne abbiamo mai visto uno. I sei file veri di tre amministratori diversi
    // dichiarano tutti «ordinario». Costruirla è l'unico modo di provare una strada che esiste
    // nel formato e non nel nostro campione — e non si inventa la mappatura giusta, si segnala.
    Storage::fake('local');

    test()->actingAs(utenteStraordinario())->post(route('import.store'), [
        'file' => [new UploadedFile(
            base_path('tests/Fixtures/import/danea/riparto_straordinario.xls'),
            'riparto_straordinario.xls',
            'application/vnd.ms-excel',
            test: true,
        )],
    ]);

    $letto = app(App\Services\Import\ImportVerificaService::class)
        ->verifica(ImportBatch::latest()->first());

    expect($letto['canonici']['esercizi']->tipo)->toBe('straordinario');

    $codici = collect($letto['esiti'])->flatMap(fn ($e) => $e->rilievi)->pluck('codice');
    expect($codici)->toContain('esercizio.straordinario_in_gestione_dedicata');

    // È un avviso, non un errore: non deve impedire l'importazione, deve farla notare.
    $rilievo = collect($letto['esiti'])->flatMap(fn ($e) => $e->rilievi)
        ->first(fn ($r) => $r->codice === 'esercizio.straordinario_in_gestione_dedicata');

    expect($rilievo->severita->value)->toBe('avviso');
});

it('non avvisa su un esercizio ordinario, che è il caso di tutti i file veri', function () {
    Storage::fake('local');

    test()->actingAs(utenteStraordinario())->post(route('import.store'), [
        'file' => [new UploadedFile(
            base_path('tests/Fixtures/import/danea/riparto_consuntivo.xls'),
            'riparto_consuntivo.xls',
            'application/vnd.ms-excel',
            test: true,
        )],
    ]);

    $letto = app(App\Services\Import\ImportVerificaService::class)
        ->verifica(ImportBatch::latest()->first());

    $codici = collect($letto['esiti'])->flatMap(fn ($e) => $e->rilievi)->pluck('codice');
    expect($codici)->not->toContain('esercizio.straordinario_in_gestione_dedicata');
});

it('non ne crea una seconda se il condominio ce l\'ha già', function () {
    $condominio = Condominio::create([
        'nome' => 'CONDOMINIO CON GESTIONE',
        'codice_fiscale' => '97123456781',
        'indirizzo' => 'Via Con Gestione 2',
    ]);

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome' => '2026',
        'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31',
        'stato' => 'aperto',
    ]);

    $condominio->gestioni()->create([
        'nome' => 'Gestione ordinaria',
        'tipo' => 'ordinaria',
        'attiva' => true,
        'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31',
    ]);

    $batch = ImportBatch::create(['sorgente' => 'danea']);
    $ctx = (new ImportContext($batch))
        ->conCanonico(LivelloEsercizi::CHIAVE, new CanonicalEsercizio(
            etichetta: '2026',
            dataInizio: CarbonImmutable::parse('2026-01-01'),
            dataFine: CarbonImmutable::parse('2026-12-31'),
        ))
        ->conDecisioni([LivelloEsercizi::CHIAVE.':2026' => 'salta']);

    $ctx->risolvi(LivelloCondominio::CHIAVE, $condominio);

    (new LivelloEsercizi)->commit($ctx);

    expect($condominio->gestioni()->count())->toBe(1)
        ->and($condominio->gestioni()->first()->esercizi()->where('esercizio_id', $esercizio->id)->exists())->toBeTrue();
});
