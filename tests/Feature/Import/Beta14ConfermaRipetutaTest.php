<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Import\Controlli\ControlliPostImport;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Spatie\Permission\Models\Permission;

/**
 * La conferma ripetuta su un'importazione che è già entrata (1.12.0-beta.14, tappa 5).
 *
 * Il caso. Un doppio clic su «Importa N record», o «Indietro» del browser e di nuovo il pulsante: la seconda conferma rifaceva la verifica
 * su un lotto «completato», trovava il condominio appena scritto come «c'è già» senza decisione, si fermava al primo livello e segnava il
 * lotto «parziale», «fermata a Condominio», riscrivendo il rapporto con quel solo livello: delle voci di «Da controllare dopo
 * l'importazione» non ne restava nessuna, e l'ingresso la offriva fra quelle da riprendere.
 *
 * Adesso: un lotto completato non si riconferma, si torna al suo esito; uno annullato nemmeno; e due conferme dello stesso lotto non
 * lavorano mai insieme. I file sono quelli della prova di Danea.
 */
function b14crUtente(): User
{
    foreach (['Crea condomini', 'Accesso pannello amministratore', 'Importa dati'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = User::factory()->create();
    $u->givePermissionTo(['Crea condomini', 'Importa dati']);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

function b14crFixture(string $nome): UploadedFile
{
    return new UploadedFile(base_path('tests/Fixtures/import/danea/'.$nome), $nome, 'application/vnd.ms-excel', test: true);
}

/** I tre file di Danea caricati, le persone dai nomi doppi lasciate come sono: il lotto è pronto per la conferma. */
function b14crPronto(User $chi): ImportBatch
{
    test()->actingAs($chi)->post(route('import.store'), ['file' => [
        b14crFixture('elenco_unita.xls'), b14crFixture('riparto_consuntivo.xls'), b14crFixture('millesimi_tabelle_miste.xls'),
    ]])->assertSessionHasNoErrors();
    $batch = ImportBatch::latest('id')->first();

    $props = test()->actingAs($chi)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
        test()->actingAs($chi)->put(route('import.decisione', $batch->uuid), ['chiave' => 'soggetto:'.$d['chiave'], 'scelta' => 'lascia']);
    }

    return $batch;
}

/** @return array{stato: string, livello_corrente: ?string, condomini: int, unita: int, da_controllare: int, livelli_nel_rapporto: int} */
function b14crFotografia(ImportBatch $batch): array
{
    $b = $batch->fresh();

    return [
        'stato' => $b->stato,
        'livello_corrente' => $b->livello_corrente,
        'condomini' => Condominio::count(),
        'unita' => Immobile::count(),
        'da_controllare' => count(app(ControlliPostImport::class)->perLotto($b)),
        'livelli_nel_rapporto' => count($b->rapporto['livelli'] ?? []),
    ];
}

beforeEach(function () {
    test()->seed(TipologieImmobiliSeeder::class);
    Storage::fake('local');
});

it('la seconda conferma di un\'importazione completata non la tocca: torna al suo esito, con lo stesso rapporto', function () {
    $utente = b14crUtente();
    $batch = b14crPronto($utente);

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));
    $prima = b14crFotografia($batch);
    expect($prima['stato'])->toBe(ImportBatch::STATO_COMPLETATO)
        ->and($prima['unita'])->toBe(11)
        ->and($prima['da_controllare'])->toBeGreaterThan(0)
        ->and($prima['livelli_nel_rapporto'])->toBe(8);
    $rapporto = $batch->fresh()->rapporto;

    // Il doppio clic, o «Indietro» e di nuovo «Importa».
    $risposta = $this->actingAs($utente)->post(route('import.conferma', $batch->uuid));

    expect(b14crFotografia($batch))->toBe($prima)
        ->and($batch->fresh()->rapporto)->toBe($rapporto);
    $risposta->assertRedirect(route('import.esito', $batch->uuid))
        ->assertSessionHas('message', fn ($m) => ($m['message'] ?? null) === 'Questa importazione è già entrata: la trovi qui.');
});

it('un\'importazione annullata non si conferma: si torna all\'ingresso, e non entra niente', function () {
    $utente = b14crUtente();
    $batch = b14crPronto($utente);
    $this->actingAs($utente)->delete(route('import.scarta', $batch->uuid))->assertRedirect(route('import.index'));

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))
        ->assertRedirect(route('import.index'))
        ->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'error'
            && $m['message'] === 'Questa importazione è stata annullata: per rifarla, carica di nuovo i file.');

    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_ANNULLATO)
        ->and(Condominio::count())->toBe(0);
});

it('due conferme dello stesso lotto non lavorano insieme: la seconda aspetta la prima, e se non finisce lo dice senza scrivere', function () {
    $utente = b14crUtente();
    $batch = b14crPronto($utente);

    // La prima conferma è ancora al lavoro (un'altra scheda, il primo dei due clic): tiene il blocco del lotto.
    $prima = Cache::lock('import:conferma:'.$batch->uuid, 900);
    expect($prima->get())->toBeTrue();
    Sleep::fake(syncWithCarbon: true);

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))
        ->assertRedirect(route('import.anteprima', $batch->uuid))
        ->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'error' && str_contains($m['message'], 'sta già entrando in archivio'));

    expect(Condominio::count())->toBe(0)
        ->and($batch->fresh()->stato)->toBe(ImportBatch::STATO_IN_CORSO);

    // Quando la prima ha finito, il blocco si libera e la conferma parte.
    $prima->release();
    Sleep::fake(false);
    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));
    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO);
});
