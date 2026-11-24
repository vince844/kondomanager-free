<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Anagrafica;
use App\Models\ImportBatch;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;

/**
 * Il CSV come lo scrive Excel in italiano: il punto delle migliaia e la virgola dei decimali (1.12.0-beta.14, tappa 5).
 *
 * Il dubbio. La prova del CSV della Coda 596 fa i file con PhpSpreadsheet, che scrive i numeri con il punto («-1202.84»); Excel in
 * italiano scrive quello che si vede, «-1.202,84» e «1.000,00». I lettori hanno il doppio percorso di `MoneyHelper::toCents()` per la
 * forma italiana, ma nessuna prova lo percorreva dentro un CSV. Qui i tre file di Danea salvati come li salverebbe Excel in italiano:
 * gli importi del riparto con il punto delle migliaia e due decimali, i millesimi con la virgola, e una tabella con «1.000,00».
 *
 * Resta fuori, e lo dice il rapporto: un intero con il punto delle migliaia senza decimali («1.000») è ambiguo anche in un CSV con il
 * punto e virgola (i millesimi con tre decimali scritti con il punto, «166.667», hanno la stessa forma) e si legge come lo scrive il
 * lettore del foglio, cioè 1.
 */
function b14ciUtente(): User
{
    foreach (['Crea condomini', 'Accesso pannello amministratore', 'Importa dati'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = User::factory()->create();
    $u->givePermissionTo(['Crea condomini', 'Importa dati']);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

/**
 * Un file della prova di Danea salvato in CSV con il punto e virgola, con i numeri scritti all'italiana: `$decimali` cifre dopo la
 * virgola (almeno), il punto delle migliaia. Le celle di testo restano come sono; `$celle` cambia dei valori prima di scrivere.
 *
 * @param  array<string, mixed>  $celle
 */
function b14ciCsv(string $fixture, string $nome, int $decimali, array $celle = []): UploadedFile
{
    $foglio = IOFactory::load(base_path('tests/Fixtures/import/danea/'.$fixture))->getActiveSheet();
    foreach ($celle as $cella => $valore) {
        $foglio->setCellValue($cella, $valore);
    }

    $percorso = tempnam(sys_get_temp_dir(), 'b14ci_').'.csv';
    $uscita = fopen($percorso, 'w');
    foreach ($foglio->toArray(null, true, false, false) as $riga) {
        // Come Excel: il punto e virgola fra le celle, le virgolette attorno a una cella che va a capo o lo contiene.
        fputcsv($uscita, array_map(function ($v) use ($decimali) {
            if (is_int($v) || is_float($v)) {
                [$intera, $decimale] = explode(',', number_format((float) $v, 4, ',', '.'));

                return $intera.','.str_pad(rtrim($decimale, '0'), $decimali, '0');
            }

            return (string) ($v ?? '');
        }, $riga), ';', '"', '', "\r\n");
    }
    fclose($uscita);

    return new UploadedFile($percorso, $nome, 'text/csv', test: true);
}

beforeEach(function () {
    test()->seed(TipologieImmobiliSeeder::class);
    Storage::fake('local');
});

it('i tre file di Danea in CSV scritti da Excel in italiano: «-1.202,84» è € 1.202,84 di debito, «1.000,00» millesimi sono mille', function () {
    $utente = b14ciUtente();
    $riparto = b14ciCsv('riparto_consuntivo.xls', 'riparto_consuntivo.csv', 2);
    // Nella tabella delle cassette postali l'unità 1 porta tutto, le altre niente: «1.000,00» e «0,00».
    $millesimi = b14ciCsv('millesimi_tabelle_miste.xls', 'millesimi.csv', 2, ['J2' => 1000, ...array_fill_keys(array_map(fn ($r) => 'J'.$r, range(3, 7)), 0)]);

    expect(file_get_contents($riparto->getRealPath()))->toContain('-1.202,84')->toContain('1.000,00')
        ->and(file_get_contents($millesimi->getRealPath()))->toContain(';1.000,00')->toContain('228,5002');

    $this->actingAs($utente)->post(route('import.store'), ['file' => [b14ciCsv('elenco_unita.xls', 'elenco_unita.csv', 2), $riparto, $millesimi]])
        ->assertSessionHasNoErrors();
    $batch = ImportBatch::latest('id')->first();

    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    expect($props['anteprima']['saldi']['totale_riferimento'])->toBe('€ 1.261,74')
        ->and($props['anteprima']['saldi']['quadra'])->toBeTrue();

    foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => 'soggetto:'.$d['chiave'], 'scelta' => 'lascia']);
    }
    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));
    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO);

    // Il saldo di «GIALLI FRANCESCA»: -1.202,84 nel riparto (Danea: negativo = debito) → +€ 1.202,84 di debito, in centesimi.
    $gialli = Anagrafica::where('nome', 'GIALLI FRANCESCA')->value('id');
    expect((int) Saldo::where('anagrafica_id', $gialli)->sum('saldo_iniziale'))->toBe(120284);

    // La tabella delle cassette postali: l'unità con «1.000,00» ha mille millesimi.
    $tabella = Tabella::where('nome', 'like', 'Cassete postali%')->firstOrFail();
    expect((float) DB::table('quote_tabella')->where('tabella_id', $tabella->id)->max('valore'))->toBe(1000.0);
});
