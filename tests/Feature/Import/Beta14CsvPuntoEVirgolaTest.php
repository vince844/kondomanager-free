<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Import\ImportUploadService;
use App\Services\Import\SpreadsheetReader;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;

/**
 * Coda 596 (1.12.0-beta.14, tappa 5): il CSV con il punto e virgola, il separatore che Excel usa in italiano.
 *
 * La validazione guardava solo il contenuto (`mimes`): un CSV con il punto e virgola è testo semplice, il programma lo leggeva come
 * `.txt` e lo rifiutava con «Accetto solo file xls, xlsx, csv». E se fosse passato, il file si salvava con l'estensione indovinata
 * dal contenuto, e il lettore lo rifiutava di nuovo («Formato «txt» non gestito»). Ora il nome dice il formato (fra i tre ammessi),
 * il contenuto deve essere un foglio o del testo, e il file si salva con l'estensione dichiarata. I file della prova sono scritti
 * qui, con nomi inventati.
 */
function b14csvUtente(): User
{
    foreach (['Crea condomini', 'Accesso pannello amministratore', 'Importa dati'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = User::factory()->create();
    $u->givePermissionTo(['Crea condomini', 'Importa dati']);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

/** Un file di testo scritto nel test, caricato con il nome dato. */
function b14csvFile(string $contenuto, string $nome): UploadedFile
{
    $percorso = tempnam(sys_get_temp_dir(), 'b14csv_');
    file_put_contents($percorso, $contenuto);

    return new UploadedFile($percorso, $nome, 'text/csv', test: true);
}

/** Un file della prova di Danea riscritto in CSV con il punto e virgola, come lo salva Excel in italiano. */
function b14csvDaDanea(string $fixture, string $nome): UploadedFile
{
    $foglio = IOFactory::load(base_path('tests/Fixtures/import/danea/'.$fixture));
    $percorso = tempnam(sys_get_temp_dir(), 'b14csv_').'.csv';
    $writer = IOFactory::createWriter($foglio, 'Csv');
    $writer->setDelimiter(';');
    $writer->save($percorso);

    return new UploadedFile($percorso, $nome, 'text/csv', test: true);
}

beforeEach(function () {
    test()->seed(TipologieImmobiliSeeder::class);
    Storage::fake('local');
});

it('un CSV con il punto e virgola entra, si salva come .csv e il riconoscimento lo legge', function () {
    $utente = b14csvUtente();

    $risposta = $this->actingAs($utente)->post(route('import.store'), [
        'file' => [b14csvFile("unita;nome;ruolo;quota_pct\nA/1;UGO ESEMPIO;proprietario;\nA/2;ELSA ESEMPIO;proprietario;\n", 'unita.csv')],
    ]);

    expect(session('errors')?->first('file.0'))->toBeNull();

    $batch = ImportBatch::latest()->first();
    expect($batch)->not->toBeNull();
    $risposta->assertRedirect(route('import.riconoscimento', $batch->uuid));

    $file = $batch->files()->first();
    expect($file->percorso)->toEndWith('.csv')
        ->and($file->nome_originale)->toBe('unita.csv');

    // Il lettore lo apre, e indovina il separatore da solo: quattro colonne, non una.
    $fogli = (new SpreadsheetReader)->leggi(Storage::disk(ImportUploadService::DISCO)->path($file->percorso));
    expect(array_values(array_filter($fogli[0]->righe[0], fn ($v) => $v !== null && $v !== '')))->toBe(['unita', 'nome', 'ruolo', 'quota_pct']);

    $props = $this->actingAs($utente)->get(route('import.riconoscimento', $batch->uuid))->assertOk()->viewData('page')['props'];
    expect($props['file'][0]['errore'])->toBeNull();
});

it('l\'estensione si scrive in minuscolo, e il nome sul disco non è quello del file', function () {
    $this->actingAs(b14csvUtente())->post(route('import.store'), [
        'file' => [b14csvFile("unita;nome\nA/1;UGO ESEMPIO\n", 'ELENCO DI PROVA.CSV')],
    ])->assertSessionHasNoErrors();

    $file = ImportBatch::latest()->first()->files()->first();
    expect($file->percorso)->toEndWith('.csv')
        ->and($file->percorso)->not->toContain('ELENCO')
        ->and($file->nome_originale)->toBe('ELENCO DI PROVA.CSV');
});

it('un file di testo con un\'altra estensione resta fuori, con la frase dei formati', function () {
    $this->actingAs(b14csvUtente())->post(route('import.store'), [
        'file' => [b14csvFile("unita;nome\nA/1;UGO ESEMPIO\n", 'unita.txt')],
    ])->assertSessionHasErrors(['file.0' => 'Accetto solo file xls, xlsx, csv. Se il tuo gestionale esporta in un altro formato, aprilo in Excel e salvalo come .xls.']);

    expect(ImportBatch::count())->toBe(0);
});

it('un file che non è né un foglio né testo resta fuori anche con il nome .csv', function () {
    // Un'immagine rinominata: il nome dice CSV, il contenuto no. La lente della sicurezza: il nome da solo non basta.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');

    $this->actingAs(b14csvUtente())->post(route('import.store'), [
        'file' => [b14csvFile($png, 'unita.csv')],
    ])->assertSessionHasErrors('file.0');

    expect(ImportBatch::count())->toBe(0);
});

it('i tre file di Danea salvati in CSV con il punto e virgola arrivano fino all\'esito', function () {
    $utente = b14csvUtente();

    $this->actingAs($utente)->post(route('import.store'), [
        'file' => [
            b14csvDaDanea('elenco_unita.xls', 'elenco_unita.csv'),
            b14csvDaDanea('riparto_consuntivo.xls', 'riparto_consuntivo.csv'),
            b14csvDaDanea('millesimi_tabelle_miste.xls', 'millesimi.csv'),
        ],
    ])->assertSessionHasNoErrors();

    $batch = ImportBatch::latest()->first();
    $riconoscimento = $this->actingAs($utente)->get(route('import.riconoscimento', $batch->uuid))->viewData('page')['props'];
    expect(collect($riconoscimento['file'])->pluck('tipo')->sort()->values()->all())
        ->toBe(['anagrafica_millesimi', 'elenco_unita', 'riparto_consuntivo'])
        ->and($riconoscimento['mancanti'])->toBe([]);

    $this->actingAs($utente)->get(route('import.verifica', $batch->uuid))->assertOk();

    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->assertOk()->viewData('page')['props'];
    expect($props['anteprima']['saldi']['quadra'])->toBeTrue();

    foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => 'soggetto:'.$d['chiave'], 'scelta' => 'lascia']);
    }

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));

    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO);
    $this->actingAs($utente)->get(route('import.esito', $batch->uuid))->assertOk();
});
