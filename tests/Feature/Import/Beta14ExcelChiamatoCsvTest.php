<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\ImportBatch;
use App\Models\User;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

/**
 * Un file Excel vero con il nome «.csv» (1.12.0-beta.14, tappa 5).
 *
 * Il caso. Con la Coda 596 il file si salva con l'estensione **dichiarata** (un CSV con il punto e virgola è testo, e prima diventava
 * `.txt`). Ma un foglio Excel rinominato a mano, o salvato male, con il nome «elenco_unita.csv» finiva sul disco come `.csv`, il
 * lettore lo apriva come testo e il riconoscimento lo dava «non importabile», senza una frase; prima della Coda 596 si salvava `.xls` e
 * si riconosceva «Elenco unità».
 *
 * Adesso il contenuto vince quando è un foglio Excel: si salva con l'estensione del contenuto. Il CSV vero resta `.csv`.
 */
function b14xcUtente(): User
{
    foreach (['Crea condomini', 'Accesso pannello amministratore', 'Importa dati'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = User::factory()->create();
    $u->givePermissionTo(['Crea condomini', 'Importa dati']);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

beforeEach(function () {
    test()->seed(TipologieImmobiliSeeder::class);
    Storage::fake('local');
});

it('un .xls con il nome «.csv» si salva .xls e si riconosce «Elenco unità», come prima della Coda 596', function () {
    $utente = b14xcUtente();
    $file = new UploadedFile(base_path('tests/Fixtures/import/danea/elenco_unita.xls'), 'elenco_unita.csv', 'text/csv', test: true);

    $this->actingAs($utente)->post(route('import.store'), ['file' => [$file]])->assertSessionHasNoErrors();
    $f = ImportBatch::latest('id')->first()->files()->first();

    expect(pathinfo($f->percorso, PATHINFO_EXTENSION))->toBe('xls')
        ->and($f->report_type)->toBe('elenco_unita');
});

it('un .xlsx con il nome «.xls» si salva con l\'estensione del contenuto, e si legge', function () {
    $utente = b14xcUtente();
    $percorso = tempnam(sys_get_temp_dir(), 'b14xc_').'.xlsx';
    $libro = \PhpOffice\PhpSpreadsheet\IOFactory::load(base_path('tests/Fixtures/import/danea/elenco_unita.xls'));
    \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($libro, 'Xlsx')->save($percorso);
    $file = new UploadedFile($percorso, 'elenco_unita.xls', 'application/vnd.ms-excel', test: true);

    $this->actingAs($utente)->post(route('import.store'), ['file' => [$file]])->assertSessionHasNoErrors();
    $f = ImportBatch::latest('id')->first()->files()->first();

    expect(pathinfo($f->percorso, PATHINFO_EXTENSION))->toBe('xlsx')
        ->and($f->report_type)->toBe('elenco_unita');
});

it('il CSV vero con il punto e virgola resta .csv (Coda 596)', function () {
    $utente = b14xcUtente();
    $percorso = tempnam(sys_get_temp_dir(), 'b14xc_').'.csv';
    file_put_contents($percorso, "unita;nome;ruolo;quota_pct\nB1/1;Mario Bianchi;proprietario;100\n");
    $file = new UploadedFile($percorso, 'elenco.csv', 'text/csv', test: true);

    $this->actingAs($utente)->post(route('import.store'), ['file' => [$file]])->assertSessionHasNoErrors();
    $f = ImportBatch::latest('id')->first()->files()->first();

    expect(pathinfo($f->percorso, PATHINFO_EXTENSION))->toBe('csv')
        ->and($f->colonne['errore'] ?? null)->toBeNull();
});
