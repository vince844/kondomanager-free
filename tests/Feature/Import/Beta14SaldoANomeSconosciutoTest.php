<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\ImportBatch;
use App\Models\Saldo;
use App\Models\User;
use App\Services\Import\ModelloManualeWriter;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;

/**
 * Un saldo intestato a un nome, o a un'unità, che non c'è: si dice prima della conferma, con «Importa senza i saldi» (1.12.0-beta.14, tappa 5).
 *
 * Il caso. Nel modello compilato a mano il foglio «2 persone» ha «Lucia Verdi», il foglio «4 saldi» ha «Lucia Verde» con € 80,00: è
 * l'errore più comune di chi scrive a mano. La verifica e l'anteprima non lo vedevano (dicevano prima solo i saldi senza nessuna persona
 * e lo scarto con il totale), la conferma era aperta e senza «Importa senza i saldi», e l'importazione scriveva condominio, unità e
 * persone e si fermava ai saldi: «parziale», l'importazione a metà.
 *
 * Adesso la verifica fa la stessa ricerca del livello dei saldi sulle persone e sulle unità dei file: una riga che non si trova è una
 * decisione da prendere, come lo scarto (decisione 707 (2)): correggere il file e ricaricarlo, oppure «Importa senza i saldi». La
 * conferma piena è chiusa e il server la rifiuta senza scrivere; quella senza i saldi arriva in fondo. Il modello si fa nel test, con
 * nomi inventati.
 */
function b14snUtente(): User
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
 * Il modello vero, con due unità, le persone e i saldi dati; le righe dei saldi partono dalla riga 6 del foglio «4 saldi».
 *
 * @param  list<array<string, string>>  $persone
 * @param  list<list<mixed>>  $saldi  unità, persona, importo, causale, esercizio
 */
function b14snModello(array $persone, array $saldi): UploadedFile
{
    $percorso = tempnam(sys_get_temp_dir(), 'b14sn_').'.xlsx';
    (new ModelloManualeWriter)->scriviSu($percorso);
    $libro = IOFactory::load($percorso);
    $svuota = function (string $foglio, int $da) use ($libro) {
        $f = $libro->getSheetByName($foglio);
        for ($r = $da; $r <= $da + 40; $r++) {
            foreach (range('A', 'Z') as $c) {
                $f->setCellValue($c.$r, null);
            }
        }

        return $f;
    };

    $copertina = $svuota('0 copertina', 6);
    $riga = 6;
    foreach (['condominio' => 'CONDOMINIO DEL NOME SBAGLIATO', 'codice_fiscale' => '97555000188', 'indirizzo' => 'Via del Modello 10, Roma',
        'esercizio' => '2026', 'data_inizio' => '01/01/2026', 'data_fine' => '31/12/2026'] as $campo => $valore) {
        $copertina->setCellValue('A'.$riga, $campo);
        $copertina->setCellValue('B'.$riga, $valore);
        $riga++;
    }
    $svuota('1 unita', 6)->fromArray([['B1/1', '1', 'A', '1', 'T', 'appartamento'], ['B1/2', '1', 'A', '2', '1', 'appartamento']], null, 'A6');
    $colonne = ['unita', 'nome', 'codice_fiscale', 'indirizzo', 'ruolo', 'quota_pct', 'email', 'telefono'];
    $f = $svuota('2 persone', 5);
    $f->fromArray($colonne, null, 'A5');
    $f->fromArray(array_map(fn (array $p) => array_map(fn (string $c) => $p[$c] ?? '', $colonne), $persone), null, 'A6');
    $svuota('3 tabelle', 6);
    $svuota('4 saldi', 6)->fromArray($saldi, null, 'A6');
    (new Xlsx($libro))->save($percorso);
    $libro->disconnectWorksheets();

    return new UploadedFile($percorso, 'modello.xlsx', null, test: true);
}

function b14snPersone(): array
{
    return [
        ['unita' => 'B1/1', 'nome' => 'Mario Bianchi', 'indirizzo' => 'Via del Modello 10, Roma', 'ruolo' => 'proprietario'],
        ['unita' => 'B1/2', 'nome' => 'Lucia Verdi', 'indirizzo' => 'Via del Modello 10, Roma', 'ruolo' => 'proprietario'],
    ];
}

/** @param  UploadedFile|list<UploadedFile>  $file */
function b14snCarica(User $chi, UploadedFile|array $file): ImportBatch
{
    test()->actingAs($chi)->post(route('import.store'), ['file' => is_array($file) ? $file : [$file]])->assertSessionHasNoErrors();

    return ImportBatch::latest('id')->first();
}

/**
 * I tre file di Danea della prova, con il riparto cambiato nelle celle date; le persone dai nomi doppi lasciate come sono.
 *
 * @param  array<string, string>  $celle
 */
function b14snCaricaDanea(User $chi, array $celle): ImportBatch
{
    $libro = IOFactory::load(base_path('tests/Fixtures/import/danea/riparto_consuntivo.xls'));
    foreach ($celle as $cella => $valore) {
        $libro->getActiveSheet()->setCellValueExplicit($cella, $valore, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    }
    $percorso = tempnam(sys_get_temp_dir(), 'b14sn_').'.xls';
    IOFactory::createWriter($libro, 'Xls')->save($percorso);

    $file = [new UploadedFile($percorso, 'riparto_consuntivo.xls', 'application/vnd.ms-excel', test: true)];
    foreach (['elenco_unita.xls', 'millesimi_tabelle_miste.xls'] as $nome) {
        $file[] = new UploadedFile(base_path('tests/Fixtures/import/danea/'.$nome), $nome, 'application/vnd.ms-excel', test: true);
    }
    $batch = b14snCarica($chi, $file);

    $props = test()->actingAs($chi)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
        test()->actingAs($chi)->put(route('import.decisione', $batch->uuid), ['chiave' => 'soggetto:'.$d['chiave'], 'scelta' => 'lascia']);
    }

    return $batch;
}

beforeEach(function () {
    test()->seed(TipologieImmobiliSeeder::class);
    Storage::fake('local');
});

it('un saldo intestato a un nome che non è fra le persone si dice nella verifica: conferma chiusa, «Importa senza i saldi», niente a metà', function () {
    $utente = b14snUtente();
    $batch = b14snCarica($utente, b14snModello(b14snPersone(), [
        ['B1/1', 'Mario Bianchi', 120.50, 'conguaglio', ''],
        ['B1/2', 'Lucia Verde', 80.00, 'conguaglio', ''], // «Verde» invece di «Verdi»
    ]));

    // La verifica: nessun errore (si arriva alla conferma), e la riga del saldo fra le decisioni da prendere.
    $verifica = $this->actingAs($utente)->get(route('import.verifica', $batch->uuid))->viewData('page')['props'];
    $saldi = collect($verifica['livelli'])->firstWhere('chiave', 'saldi');
    $rilievo = collect($saldi['da_decidere'])->firstWhere('codice', 'saldi.persona_sconosciuta');
    expect($verifica['senza_errori'])->toBeTrue()
        ->and($rilievo)->not->toBeNull()
        ->and($rilievo['messaggio'])->toBe('Nel foglio «4 saldi», riga 7, il saldo è intestato a «Lucia Verde», che non è nel foglio «2 persone».');

    // L'anteprima: la conferma piena chiusa con il motivo, «Importa senza i saldi» offerto.
    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    expect($props['confermabile'])->toBeFalse()
        ->and($props['senza_saldi']['possibile'])->toBeTrue()
        ->and(array_column($props['senza_saldi']['motivi'], 'codice'))->toBe(['saldi.persona_sconosciuta'])
        ->and($props['senza_saldi']['motivi'][0]['motivo'])->toContain('«Lucia Verde»');

    // La conferma piena, scritta a mano: rifiutata, niente in archivio.
    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))
        ->assertRedirect(route('import.anteprima', $batch->uuid))
        ->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'error' && str_contains($m['message'], '«Lucia Verde»')
            && str_contains($m['message'], 'oppure scegli «Importa senza i saldi»'));
    expect(Condominio::count())->toBe(0)
        ->and($batch->fresh()->stato)->toBe(ImportBatch::STATO_IN_CORSO);

    // «Importa senza i saldi»: entra tutto il resto, fino in fondo.
    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid), ['senza_saldi' => true])->assertRedirect(route('import.esito', $batch->uuid));
    $b = $batch->fresh();
    $riga = collect($b->rapporto['livelli'])->firstWhere('livello', 'saldi');
    expect($b->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(Immobile::count())->toBe(2)
        ->and(Anagrafica::count())->toBe(2)
        ->and(Saldo::count())->toBe(0)
        ->and($riga['per_scelta'] ?? false)->toBeTrue();
});

it('da Danea, un saldo del riparto su un\'unità o a un nome che l\'Elenco unità non ha si dice allo stesso modo', function () {
    $utente = b14snUtente();
    // Nel riparto della prova: l'interno 03 diventa 99, che l'Elenco unità non ha; «RUSSO LUCA» diventa «RUSSO LUCIA».
    $batch = b14snCaricaDanea($utente, ['A12' => '99', 'C13' => 'RUSSO LUCIA']);

    $verifica = $this->actingAs($utente)->get(route('import.verifica', $batch->uuid))->viewData('page')['props'];
    $saldi = collect($verifica['livelli'])->firstWhere('chiave', 'saldi');
    expect($verifica['senza_errori'])->toBeTrue()
        ->and(collect($saldi['da_decidere'])->pluck('codice')->all())->toBe(['saldi.unita_sconosciuta', 'saldi.persona_sconosciuta']);

    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    expect($props['confermabile'])->toBeFalse()
        ->and($props['senza_saldi']['possibile'])->toBeTrue()
        ->and(array_column($props['senza_saldi']['motivi'], 'codice'))->toBe(['saldi.unita_sconosciuta', 'saldi.persona_sconosciuta'])
        ->and($props['senza_saldi']['motivi'][0]['motivo'])->toContain('«99»')
        ->and($props['senza_saldi']['motivi'][1]['motivo'])->toContain('«RUSSO LUCIA»');

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.anteprima', $batch->uuid));
    expect(Condominio::count())->toBe(0);

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid), ['senza_saldi' => true])->assertRedirect(route('import.esito', $batch->uuid));
    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(Immobile::count())->toBe(11)
        ->and(Saldo::count())->toBe(0);
});

it('con i nomi e le unità giusti la conferma è aperta e i saldi entrano: la ricerca è quella del livello', function () {
    $utente = b14snUtente();
    $batch = b14snCarica($utente, b14snModello(b14snPersone(), [
        ['B1/1', 'Mario Bianchi', 120.50, 'conguaglio', ''],
        ['B1/2', '  lucia   VERDI ', 80.00, 'conguaglio', ''], // maiuscole e spazi non contano, come nel livello
        ['B1/2', '', 10.00, 'fondo', ''],                     // solidale sull'unità
    ]));

    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    expect($props['confermabile'])->toBeTrue()
        ->and($props['senza_saldi']['motivi'])->toBe([]);

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));
    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(Saldo::count())->toBe(3);
});
