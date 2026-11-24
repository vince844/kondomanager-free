<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Import\ImportVerificaService;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

/**
 * Le cose che esistono già in archivio contano nella conferma come le altre decisioni (1.12.0-beta.14, tappa 5).
 *
 * Il caso. La schermata di conferma teneva spento il pulsante finché restava da decidere «unisci o lascia com'è» su una persona che c'è
 * già, ma il controllo del server (`confermabile`) non le vedeva: le calcolava l'anteprima, non la verifica. Una conferma scritta a mano,
 * o partita da una pagina caricata prima che la persona entrasse in archivio, scriveva condominio ed esercizio e si fermava a «Persone»:
 * l'importazione a metà che il controllo del server doveva chiudere.
 *
 * Adesso la verifica le dice come decisioni da prendere, con la chiave della decisione: `confermabile` è falso per la pagina e per il
 * server, la conferma non scrive niente e dice che cosa resta; presa la decisione, si prosegue. I file sono quelli della prova di Danea.
 */
function b14ceUtente(): User
{
    foreach (['Crea condomini', 'Accesso pannello amministratore', 'Importa dati'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = User::factory()->create();
    $u->givePermissionTo(['Crea condomini', 'Importa dati']);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

function b14ceCarica(User $chi): ImportBatch
{
    $file = [];
    foreach (['elenco_unita.xls', 'riparto_consuntivo.xls', 'millesimi_tabelle_miste.xls'] as $nome) {
        $file[] = new UploadedFile(base_path('tests/Fixtures/import/danea/'.$nome), $nome, 'application/vnd.ms-excel', test: true);
    }
    test()->actingAs($chi)->post(route('import.store'), ['file' => $file])->assertSessionHasNoErrors();

    return ImportBatch::latest('id')->first();
}

beforeEach(function () {
    test()->seed(TipologieImmobiliSeeder::class);
    Storage::fake('local');
});

it('una persona che c\'è già, non decisa, chiude la conferma anche per il server: non si scrive niente, e si dice che cosa resta', function () {
    $utente = b14ceUtente();
    $batch = b14ceCarica($utente);

    // Una persona del file è già in archivio, con lo stesso nome e lo stesso codice fiscale.
    $prima = collect(app(ImportVerificaService::class)->verifica($batch)['canonici']['soggetti'])->first();
    Anagrafica::create(['nome' => $prima->nome, 'codice_fiscale' => $prima->codiceFiscale, 'indirizzo' => 'Via Altrove 1']);

    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => 'soggetto:'.$d['chiave'], 'scelta' => 'lascia']);
    }
    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    $aperte = collect($props['anteprima']['collisioni']['voci'])->reject(fn ($c) => isset($props['decisioni'][$c['chiave_decisione']]));
    expect($aperte->pluck('cosa')->all())->toBe(['persona'])
        ->and($props['confermabile'])->toBeFalse();

    // La verifica la conta fra le decisioni da prendere, con la sua chiave, e lascia arrivare alla conferma.
    $verifica = $this->actingAs($utente)->get(route('import.verifica', $batch->uuid))->viewData('page')['props'];
    $persone = collect($verifica['livelli'])->firstWhere('chiave', 'soggetti');
    expect($verifica['senza_errori'])->toBeTrue()
        ->and(collect($persone['da_decidere'])->pluck('chiave_decisione')->all())->toContain($aperte->first()['chiave_decisione']);

    // La conferma scritta a mano: rifiutata, niente in archivio.
    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))
        ->assertRedirect(route('import.anteprima', $batch->uuid))
        ->assertSessionHas('message', fn ($m) => ($m['type'] ?? null) === 'error'
            && $m['message'] === 'Non ho importato niente: resta da decidere 1 cosa che esiste già, nella schermata di conferma.');

    expect(Condominio::count())->toBe(0)
        ->and(Esercizio::count())->toBe(0)
        ->and(Immobile::count())->toBe(0)
        ->and($batch->fresh()->stato)->toBe(ImportBatch::STATO_IN_CORSO);

    // Presa la decisione, si prosegue fino in fondo.
    $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => $aperte->first()['chiave_decisione'], 'scelta' => 'salta']);
    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    expect($props['confermabile'])->toBeTrue();

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));
    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(Immobile::count())->toBe(11);
});
