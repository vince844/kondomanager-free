<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\ImportBatch;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;

/**
 * Coda 759 (1) e (2), con la risposta di Vincenzo a D1 (decisione 707 (2)): l'importazione che si poteva confermare a metà.
 *
 * Il caso. Carichi da Danea il riparto e le tabelle ma non l'Elenco unità: il riconoscimento diceva «senza, non si prosegue», ma il
 * pulsante funzionava, entravano condominio, unità e tabelle e l'importazione si fermava ai saldi, «parziale». Lo stesso con il totale
 * del riparto che non torna di € 10,00: l'anteprima diceva «non ti farei proseguire» e si proseguiva.
 *
 * Adesso: il pulsante principale è spento con il motivo, e il server rifiuta la conferma senza scrivere niente; accanto c'è «Importa
 * senza i saldi», con lo scarto in cifre e l'elenco di ciò che non entra; l'esito dice che i saldi li scrive l'amministratore. Le
 * prove partono dalle rotte, con i file della prova di Danea (lo scarto si fa nel test, cambiando il totale del riparto).
 */
function b14ccUtente(): User
{
    foreach (['Crea condomini', 'Accesso pannello amministratore', 'Importa dati'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = User::factory()->create();
    $u->givePermissionTo(['Crea condomini', 'Importa dati']);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

function b14ccFixture(string $nome): UploadedFile
{
    return new UploadedFile(base_path('tests/Fixtures/import/danea/'.$nome), $nome, 'application/vnd.ms-excel', test: true);
}

/** Il riparto della prova con il totale complessivo spostato di `$centesimi`: le righe restano, il totale scritto non torna più. */
function b14ccRipartoConScarto(int $centesimi): UploadedFile
{
    $libro = IOFactory::load(base_path('tests/Fixtures/import/danea/riparto_consuntivo.xls'));
    $foglio = $libro->getActiveSheet();
    expect($foglio->getCell('A15')->getValue())->toBe('TOTALE COMPLESSIVO');
    $foglio->setCellValue('O15', round((float) $foglio->getCell('O15')->getValue() + $centesimi / 100, 2));

    $percorso = tempnam(sys_get_temp_dir(), 'b14cc_').'.xls';
    IOFactory::createWriter($libro, 'Xls')->save($percorso);

    return new UploadedFile($percorso, 'riparto_consuntivo.xls', 'application/vnd.ms-excel', test: true);
}

/** @param list<UploadedFile> $file */
function b14ccCarica(User $chi, array $file): ImportBatch
{
    test()->actingAs($chi)->post(route('import.store'), ['file' => $file])->assertSessionHasNoErrors();

    return ImportBatch::latest('id')->first();
}

/** Le decisioni dell'anteprima prese tutte («lascia com'è»), così resta solo quello che la prova guarda. */
function b14ccDecidi(User $chi, ImportBatch $batch): array
{
    $props = test()->actingAs($chi)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];

    foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
        test()->actingAs($chi)->put(route('import.decisione', $batch->uuid), ['chiave' => 'soggetto:'.$d['chiave'], 'scelta' => 'lascia']);
    }
    foreach ($props['anteprima']['collisioni']['voci'] as $c) {
        test()->actingAs($chi)->put(route('import.decisione', $batch->uuid), ['chiave' => $c['chiave_decisione'], 'scelta' => 'salta']);
    }

    return test()->actingAs($chi)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
}

beforeEach(function () {
    test()->seed(TipologieImmobiliSeeder::class);
    Storage::fake('local');
});

it('senza l\'Elenco unità la conferma è chiusa, dice perché, e il server la rifiuta senza scrivere niente', function () {
    $utente = b14ccUtente();
    $batch = b14ccCarica($utente, [b14ccFixture('riparto_consuntivo.xls'), b14ccFixture('millesimi_tabelle_miste.xls')]);

    // Il riconoscimento: l'Elenco unità manca, e qui ferma davvero (i saldi del riparto sono intestati a persone).
    $mancanti = $this->actingAs($utente)->get(route('import.riconoscimento', $batch->uuid))->viewData('page')['props']['mancanti'];
    expect(collect($mancanti)->firstWhere('cosa', 'l\'Elenco unità')['bloccante'])->toBeTrue();

    // La verifica lascia passare all'anteprima (lì si sceglie), e dice che cosa c'è da decidere sui saldi.
    $verifica = $this->actingAs($utente)->get(route('import.verifica', $batch->uuid))->viewData('page')['props'];
    $saldi = collect($verifica['livelli'])->firstWhere('chiave', 'saldi');
    expect($verifica['senza_errori'])->toBeTrue()
        ->and($verifica['confermabile'])->toBeFalse()
        ->and(collect($saldi['da_decidere'])->pluck('codice')->all())->toBe(['saldi.senza_persone']);

    $props = b14ccDecidi($utente, $batch);
    expect($props['confermabile'])->toBeFalse()
        ->and($props['senza_saldi']['possibile'])->toBeTrue()
        ->and(array_column($props['senza_saldi']['motivi'], 'motivo'))->toBe(['Manca l\'Elenco unità: i saldi del riparto sono intestati a persone che arrivano da quel file.'])
        ->and(array_column($props['senza_saldi']['motivi'], 'strada'))->toBe(['Carica l\'Elenco unità insieme agli altri file e ricaricali.']);

    $this->actingAs($utente)->from(route('import.anteprima', $batch->uuid))->post(route('import.conferma', $batch->uuid))
        ->assertRedirect(route('import.anteprima', $batch->uuid))
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'error' && str_contains($m['message'], 'Manca l\'Elenco unità'));

    expect(Condominio::count())->toBe(0)
        ->and(Immobile::count())->toBe(0)
        ->and($batch->fresh()->stato)->toBe(ImportBatch::STATO_IN_CORSO)
        ->and($batch->fresh()->rapporto)->toBeNull();
});

it('con lo scarto di € 10,00 la conferma è chiusa, lo scarto si legge in cifre, e il server la rifiuta', function () {
    $utente = b14ccUtente();
    $batch = b14ccCarica($utente, [b14ccFixture('elenco_unita.xls'), b14ccRipartoConScarto(1000), b14ccFixture('millesimi_tabelle_miste.xls')]);

    $verifica = $this->actingAs($utente)->get(route('import.verifica', $batch->uuid))->viewData('page')['props'];
    $saldi = collect($verifica['livelli'])->firstWhere('chiave', 'saldi');
    expect($verifica['senza_errori'])->toBeTrue()
        ->and(collect($saldi['da_decidere'])->pluck('codice')->all())->toBe(['saldi.non_quadrano']);

    $props = b14ccDecidi($utente, $batch);
    expect($props['confermabile'])->toBeFalse()
        ->and($props['anteprima']['saldi']['quadra'])->toBeFalse()
        ->and($props['senza_saldi']['possibile'])->toBeTrue()
        ->and(array_column($props['senza_saldi']['motivi'], 'motivo'))->toBe(['Lo scarto è di € 10,00: la somma dei saldi letti non torna con il totale scritto nel riparto.'])
        ->and($props['senza_saldi']['non_entra'])->toBe([
            ['cosa' => 'Saldi di apertura', 'dettaglio' => '6 posizioni dal riparto consuntivo'],
        ]);

    $this->actingAs($utente)->from(route('import.anteprima', $batch->uuid))->post(route('import.conferma', $batch->uuid))
        ->assertRedirect(route('import.anteprima', $batch->uuid));

    expect(Condominio::count())->toBe(0)
        ->and(Saldo::count())->toBe(0)
        ->and($batch->fresh()->stato)->toBe(ImportBatch::STATO_IN_CORSO);
});

it('«Importa senza i saldi» con lo scarto: entra tutto il resto, i saldi no, e l\'esito dice che li scrivi tu', function () {
    $utente = b14ccUtente();
    $batch = b14ccCarica($utente, [b14ccFixture('elenco_unita.xls'), b14ccRipartoConScarto(1000), b14ccFixture('millesimi_tabelle_miste.xls')]);
    b14ccDecidi($utente, $batch);

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid), ['senza_saldi' => true])
        ->assertRedirect(route('import.esito', $batch->uuid));

    $batch->refresh();
    expect($batch->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(Condominio::count())->toBe(1)
        ->and(Anagrafica::count())->toBeGreaterThan(0)
        ->and(Immobile::count())->toBeGreaterThan(0)
        ->and(Tabella::count())->toBeGreaterThan(0)
        ->and(Saldo::count())->toBe(0);

    $riga = collect($batch->rapporto['livelli'])->firstWhere('livello', 'saldi');
    expect($riga['saltato'])->toBeTrue()
        ->and($riga['per_scelta'])->toBeTrue()
        ->and($riga['avvisi'])->toBe([])
        ->and($riga['prerequisiti_mancanti'][0]['codice'])->toBe('saldi.non_importati_per_scelta')
        ->and($riga['prerequisiti_mancanti'][0]['cosa_manca'])->toBe('I saldi di apertura non li ho importati: hai scelto «Importa senza i saldi». Lo scarto con il totale del riparto era di € 10,00.')
        ->and($riga['prerequisiti_mancanti'][0]['rimedio'])->toContain('I saldi li scrivi tu')
        ->and($batch->rapporto['saldi'])->toBeNull();

    $this->actingAs($utente)->get(route('import.esito', $batch->uuid))->assertOk()
        ->assertInertia(fn ($p) => $p->component('import/ImportEsito')->where('lotto.stato', 'completato'));
});

it('«Importa senza i saldi» senza l\'Elenco unità: entrano condominio, unità e tabelle, e l\'elenco di ciò che non entra lo diceva', function () {
    $utente = b14ccUtente();
    $batch = b14ccCarica($utente, [b14ccFixture('riparto_consuntivo.xls'), b14ccFixture('millesimi_tabelle_miste.xls')]);
    $props = b14ccDecidi($utente, $batch);

    expect(collect($props['senza_saldi']['non_entra'])->pluck('cosa')->all())->toBe(['Saldi di apertura', 'Persone e chi possiede cosa']);

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid), ['senza_saldi' => true])
        ->assertRedirect(route('import.esito', $batch->uuid));

    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(Condominio::count())->toBe(1)
        ->and(Immobile::count())->toBeGreaterThan(0)
        ->and(Tabella::count())->toBeGreaterThan(0)
        ->and(Anagrafica::count())->toBe(0)
        ->and(Saldo::count())->toBe(0);

    $riga = collect($batch->fresh()->rapporto['livelli'])->firstWhere('livello', 'saldi');
    expect($riga['prerequisiti_mancanti'][0]['cosa_manca'])->toBe('I saldi di apertura non li ho importati: hai scelto «Importa senza i saldi». Mancava l\'Elenco unità, con le persone a cui sono intestati.');
});

it('con i file che tornano la conferma piena è aperta, e «Importa senza i saldi» non si offre', function () {
    $utente = b14ccUtente();
    $batch = b14ccCarica($utente, [b14ccFixture('elenco_unita.xls'), b14ccFixture('riparto_consuntivo.xls'), b14ccFixture('millesimi_tabelle_miste.xls')]);

    $props = b14ccDecidi($utente, $batch);
    expect($props['confermabile'])->toBeTrue()
        ->and($props['senza_saldi']['possibile'])->toBeFalse()
        ->and($props['senza_saldi']['motivi'])->toBe([]);
});

it('«Importa senza i saldi» non scavalca le decisioni che restano da prendere', function () {
    $utente = b14ccUtente();
    $batch = b14ccCarica($utente, [b14ccFixture('elenco_unita.xls'), b14ccRipartoConScarto(1000), b14ccFixture('millesimi_tabelle_miste.xls')]);

    // Nessuna decisione presa: i nomi doppi da dividere aspettano una risposta.
    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    expect($props['anteprima']['persone']['da_dividere'])->not->toBe([]);

    $this->actingAs($utente)->from(route('import.anteprima', $batch->uuid))->post(route('import.conferma', $batch->uuid), ['senza_saldi' => true])
        ->assertRedirect(route('import.anteprima', $batch->uuid))
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'error');

    expect(Condominio::count())->toBe(0);
});

it('il riconoscimento dice «senza, persone e saldi non entrano» solo quando l\'Elenco unità ferma davvero qualcosa', function () {
    $utente = b14ccUtente();

    // Solo le tabelle, da mettere in un condominio che c'è già: senza riparto non ci sono saldi da fermare, e l'importazione finisce.
    $soloTabelle = b14ccCarica($utente, [b14ccFixture('millesimi_tabelle_miste.xls')]);
    $mancanti = collect($this->actingAs($utente)->get(route('import.riconoscimento', $soloTabelle->uuid))->viewData('page')['props']['mancanti']);
    expect($mancanti->firstWhere('cosa', 'l\'Elenco unità')['bloccante'])->toBeFalse();

    // Con il riparto, sì: i suoi saldi sono intestati a persone che arrivano dall'Elenco unità.
    $conRiparto = b14ccCarica($utente, [b14ccFixture('riparto_consuntivo.xls'), b14ccFixture('millesimi_tabelle_miste.xls')]);
    $mancanti = collect($this->actingAs($utente)->get(route('import.riconoscimento', $conRiparto->uuid))->viewData('page')['props']['mancanti']);
    expect($mancanti->firstWhere('cosa', 'l\'Elenco unità')['bloccante'])->toBeTrue();
});
