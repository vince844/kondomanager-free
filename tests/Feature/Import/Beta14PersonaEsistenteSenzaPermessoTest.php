<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\Import\ImportVerificaService;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

/**
 * «Unisci» su una persona che c'è già chiede «Modifica anagrafiche» (1.12.0-beta.14, tappa 5).
 *
 * Il caso. Dopo il condominio che c'è già (`condominio.senza_permesso`), lo stesso dal lato delle persone: l'anteprima offriva «Unisci»
 * a chiunque potesse importare, e la conferma riscriveva indirizzo, recapiti e codice fiscale della persona in archivio
 * (`LivelloSoggetti::commit()`, `fill(...)->save()`) anche a chi ha solo «Importa dati» e non può modificare le anagrafiche.
 *
 * Adesso, senza il permesso: la verifica lo dice (un avviso: «Lascia com'è» resta, e collega le unità alla persona senza toccarla),
 * l'anteprima offre solo «Lascia com'è» e dice che cosa serve per unire, la decisione «Unisci» e la conferma con «Unisci» già sul lotto
 * scritte a mano ricevono un 403 e la persona resta com'era. Con il permesso, «Unisci» c'è e scrive. La persona nata dallo stesso lotto,
 * nella ripresa di un'importazione fermata a metà, non chiede niente: è sua. I file sono quelli della prova di Danea.
 */
function b14ppUtente(bool $puoModificare): User
{
    foreach (['Crea condomini', 'Accesso pannello amministratore', 'Importa dati', 'Modifica anagrafiche'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = User::factory()->create();
    $u->givePermissionTo(['Crea condomini', 'Importa dati', ...($puoModificare ? ['Modifica anagrafiche'] : [])]);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

function b14ppCarica(User $chi): ImportBatch
{
    $file = [];
    foreach (['elenco_unita.xls', 'riparto_consuntivo.xls', 'millesimi_tabelle_miste.xls'] as $nome) {
        $file[] = new UploadedFile(base_path('tests/Fixtures/import/danea/'.$nome), $nome, 'application/vnd.ms-excel', test: true);
    }
    test()->actingAs($chi)->post(route('import.store'), ['file' => $file])->assertSessionHasNoErrors();

    return ImportBatch::latest('id')->first();
}

/** In archivio, una persona del file (con il suo indirizzo nel file), con lo stesso codice fiscale e un altro indirizzo. */
function b14ppPersonaDelFile(ImportBatch $batch): Anagrafica
{
    $s = collect(app(ImportVerificaService::class)->verifica($batch)['canonici']['soggetti'])
        ->first(fn ($s) => $s->haCodiceFiscale() && trim((string) $s->indirizzo) !== '');

    return Anagrafica::create(['nome' => $s->nome, 'codice_fiscale' => $s->codiceFiscale, 'indirizzo' => 'Via Altrove 1']);
}

/** I nomi doppi del file lasciati come sono: non sono la domanda di questa prova. */
function b14ppLasciaINomiDoppi($test, User $utente, ImportBatch $batch): void
{
    $props = $test->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
        $test->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => 'soggetto:'.$d['chiave'], 'scelta' => 'lascia']);
    }
}

beforeEach(function () {
    $this->withoutVite();
    test()->seed(TipologieImmobiliSeeder::class);
    Storage::fake('local');
});

it('senza «Modifica anagrafiche» la persona che c\'è già non si riscrive: la verifica lo dice, l\'anteprima offre solo «Lascia com\'è», «Unisci» scritto a mano è rifiutato', function () {
    $utente = b14ppUtente(puoModificare: false);
    $batch = b14ppCarica($utente);
    $persona = b14ppPersonaDelFile($batch);
    b14ppLasciaINomiDoppi($this, $utente, $batch);

    // La verifica: un avviso sulle persone, con il permesso che manca; si arriva comunque alla conferma.
    $verifica = $this->actingAs($utente)->get(route('import.verifica', $batch->uuid))->viewData('page')['props'];
    $persone = collect($verifica['livelli'])->firstWhere('chiave', 'soggetti');
    expect($verifica['senza_errori'])->toBeTrue()
        ->and(collect($persone['avvisi'])->firstWhere('codice', 'soggetto.senza_permesso')['messaggio'] ?? null)
        ->toBe('«'.$persona->nome.'» c\'è già in archivio: per aggiornare la sua scheda con i dati del file («Unisci») serve il permesso «Modifica anagrafiche».');

    // L'anteprima: per quella persona solo «Lascia com'è», con il permesso che serve per unire.
    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    $voce = collect($props['anteprima']['collisioni']['voci'])->firstWhere('cosa', 'persona');
    expect($voce['senza_permesso'])->toBeTrue()
        ->and($voce['scelte'])->toBe(['salta']);

    // «Unisci» scritto a mano: rifiutato, e non resta sul lotto.
    $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => $voce['chiave_decisione'], 'scelta' => 'unisci'])
        ->assertForbidden();
    expect($batch->fresh()->decisioni[$voce['chiave_decisione']] ?? null)->toBeNull();

    // La conferma con «Unisci» già sul lotto (una pagina vecchia, un permesso tolto dopo la scelta): rifiutata, niente scritto.
    $batch->update(['decisioni' => [...($batch->fresh()->decisioni ?? []), $voce['chiave_decisione'] => 'unisci']]);
    expect($this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props']['confermabile'])->toBeFalse();
    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertForbidden();
    expect($persona->fresh()->indirizzo)->toBe('Via Altrove 1')
        ->and(Condominio::count())->toBe(0)
        ->and($batch->fresh()->stato)->toBe(ImportBatch::STATO_IN_CORSO);

    // «Lascia com'è»: si prosegue fino in fondo, la persona resta com'era e le unità le vengono collegate.
    $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => $voce['chiave_decisione'], 'scelta' => 'salta'])
        ->assertRedirect();
    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));
    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and($persona->fresh()->indirizzo)->toBe('Via Altrove 1')
        ->and($persona->fresh()->condomini()->count())->toBe(1)
        ->and(Immobile::count())->toBe(11);
});

it('con «Modifica anagrafiche» la scelta c\'è, e «Unisci» aggiorna la persona con i dati del file', function () {
    $utente = b14ppUtente(puoModificare: true);
    $batch = b14ppCarica($utente);
    $persona = b14ppPersonaDelFile($batch);
    b14ppLasciaINomiDoppi($this, $utente, $batch);

    $verifica = $this->actingAs($utente)->get(route('import.verifica', $batch->uuid))->viewData('page')['props'];
    expect(collect($verifica['livelli'])->flatMap(fn ($l) => collect($l['avvisi'])->pluck('codice'))->all())->not->toContain('soggetto.senza_permesso');

    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    $voce = collect($props['anteprima']['collisioni']['voci'])->firstWhere('cosa', 'persona');
    expect($voce['senza_permesso'])->toBeFalse()
        ->and($voce['scelte'])->toBe(['unisci', 'salta']);

    $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => $voce['chiave_decisione'], 'scelta' => 'unisci'])
        ->assertRedirect();
    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));

    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and($persona->fresh()->indirizzo)->not->toBe('Via Altrove 1');
});

it('la ripresa di un\'importazione fermata a metà non chiede il permesso per le persone che lo stesso lotto ha creato', function () {
    $utente = b14ppUtente(puoModificare: false);
    $batch = b14ppCarica($utente);
    b14ppLasciaINomiDoppi($this, $utente, $batch);

    // Un guasto nel livello delle unità: le persone sono entrate, il lotto è «parziale».
    $guasto = true;
    Immobile::creating(function () use (&$guasto) {
        if ($guasto) {
            throw new RuntimeException('guasto simulato nel livello delle unità');
        }
    });
    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));
    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_PARZIALE)
        ->and(Anagrafica::count())->toBeGreaterThan(0);
    $guasto = false;

    // «Riprendi»: le persone sono del lotto, «Unisci» si offre e si accetta, e la conferma arriva in fondo.
    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    $persone = collect($props['anteprima']['collisioni']['voci'])->where('cosa', 'persona');
    expect($persone)->not->toBeEmpty()
        ->and($persone->pluck('senza_permesso')->unique()->all())->toBe([false]);
    foreach ($props['anteprima']['collisioni']['voci'] as $v) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => $v['chiave_decisione'], 'scelta' => $v['cosa'] === 'persona' ? 'unisci' : 'salta'])
            ->assertRedirect();
    }

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));
    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(Immobile::count())->toBe(11);
});
