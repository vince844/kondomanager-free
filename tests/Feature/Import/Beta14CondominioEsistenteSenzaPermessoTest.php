<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
 * Importare dentro un condominio che c'è già chiede «Modifica condomini», da qualunque strada ci si arrivi (1.12.0-beta.14, tappa 5).
 *
 * Il caso. La destinazione scelta a mano («importa dentro questo condominio») ricontrollava il permesso di modificare il condominio, al
 * momento della scelta e alla conferma. Quando invece è il file a dichiarare un condominio che c'è già (stesso nome o stesso codice
 * fiscale), l'anteprima offriva «Unisci» e «Lascia com'è» a chiunque potesse importare, e la conferma scriveva dentro quel condominio
 * senza controllo: con «Unisci» l'indirizzo riscritto, con l'una o l'altra le 11 unità aggiunte, da chi ha solo «Crea condomini» e
 * «Importa dati».
 *
 * Adesso: la verifica lo dice come un errore, l'anteprima al posto dei pulsanti dice che serve il permesso, la decisione e la conferma
 * scritte a mano sono rifiutate (403, come la destinazione) e il condominio resta com'era. Il condominio nato dallo stesso lotto, nella
 * ripresa di un'importazione fermata a metà, non chiede niente: è suo.
 */
function b14cpUtente(bool $puoModificare): User
{
    foreach (['Crea condomini', 'Modifica condomini', 'Accesso pannello amministratore', 'Importa dati'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }

    $u = User::factory()->create();
    $u->givePermissionTo(['Crea condomini', 'Importa dati', ...($puoModificare ? ['Modifica condomini'] : [])]);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

function b14cpCarica(User $chi): ImportBatch
{
    $file = [];
    foreach (['elenco_unita.xls', 'riparto_consuntivo.xls', 'millesimi_tabelle_miste.xls'] as $nome) {
        $file[] = new UploadedFile(base_path('tests/Fixtures/import/danea/'.$nome), $nome, 'application/vnd.ms-excel', test: true);
    }
    test()->actingAs($chi)->post(route('import.store'), ['file' => $file])->assertSessionHasNoErrors();

    return ImportBatch::latest('id')->first();
}

/** In archivio, lo stesso condominio che dichiara la testata del riparto, con un altro indirizzo. */
function b14cpCondominioDelFile(ImportBatch $batch): Condominio
{
    $c = app(ImportVerificaService::class)->verifica($batch)['canonici']['condominio'];

    return Condominio::factory()->create(['nome' => $c->nome, 'codice_fiscale' => $c->codiceFiscale, 'indirizzo' => 'Via di Prima 1']);
}

beforeEach(function () {
    $this->withoutVite();
    test()->seed(TipologieImmobiliSeeder::class);
    Storage::fake('local');
});

it('senza «Modifica condomini» non si scrive dentro il condominio che c\'è già: la verifica lo dice, l\'anteprima non offre la scelta, la conferma è rifiutata', function () {
    $utente = b14cpUtente(puoModificare: false);
    $batch = b14cpCarica($utente);
    $esistente = b14cpCondominioDelFile($batch);

    // La verifica: un errore sul condominio, con il permesso che manca.
    $verifica = $this->actingAs($utente)->get(route('import.verifica', $batch->uuid))->viewData('page')['props'];
    $condominio = collect($verifica['livelli'])->firstWhere('chiave', 'condominio');
    expect($verifica['senza_errori'])->toBeFalse()
        ->and(collect($condominio['errori'])->pluck('codice')->all())->toContain('condominio.senza_permesso')
        ->and(collect($condominio['errori'])->firstWhere('codice', 'condominio.senza_permesso')['messaggio'])
        ->toBe('«'.$esistente->nome.'» c\'è già in archivio, e per importarci dentro serve il permesso «Modifica condomini».');

    // L'anteprima: la voce del condominio dice che serve il permesso, e la conferma è chiusa.
    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    $voce = collect($props['anteprima']['collisioni']['voci'])->firstWhere('cosa', 'condominio');
    expect($voce['senza_permesso'])->toBeTrue()
        ->and($props['confermabile'])->toBeFalse();

    // La decisione scritta a mano: rifiutata, e non resta sul lotto.
    $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => $voce['chiave_decisione'], 'scelta' => 'unisci'])
        ->assertForbidden();
    expect($batch->fresh()->decisioni[$voce['chiave_decisione']] ?? null)->toBeNull();

    // La conferma con tutte le decisioni già sul lotto (una pagina vecchia, o una richiesta scritta a mano): rifiutata.
    $decisioni = [];
    foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
        $decisioni['soggetto:'.$d['chiave']] = 'lascia';
    }
    foreach ($props['anteprima']['collisioni']['voci'] as $v) {
        $decisioni[$v['chiave_decisione']] = $v['cosa'] === 'condominio' ? 'unisci' : 'salta';
    }
    $batch->update(['decisioni' => $decisioni]);

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertForbidden();

    expect($esistente->fresh()->indirizzo)->toBe('Via di Prima 1')
        ->and(Immobile::where('condominio_id', $esistente->id)->count())->toBe(0)
        ->and(Condominio::count())->toBe(1)
        ->and($batch->fresh()->stato)->toBe(ImportBatch::STATO_IN_CORSO);
});

it('con «Modifica condomini» la scelta c\'è, e «Unisci» scrive dentro il condominio che c\'è già', function () {
    $utente = b14cpUtente(puoModificare: true);
    $batch = b14cpCarica($utente);
    $esistente = b14cpCondominioDelFile($batch);

    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    $voce = collect($props['anteprima']['collisioni']['voci'])->firstWhere('cosa', 'condominio');
    expect($voce['senza_permesso'])->toBeFalse();

    foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => 'soggetto:'.$d['chiave'], 'scelta' => 'lascia']);
    }
    foreach ($props['anteprima']['collisioni']['voci'] as $v) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => $v['chiave_decisione'], 'scelta' => $v['cosa'] === 'condominio' ? 'unisci' : 'salta'])
            ->assertRedirect();
    }

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));

    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and($esistente->fresh()->indirizzo)->not->toBe('Via di Prima 1')
        ->and(Immobile::where('condominio_id', $esistente->id)->count())->toBe(11);
});

it('la ripresa di un\'importazione fermata a metà non chiede il permesso per il condominio che lo stesso lotto ha creato', function () {
    $utente = b14cpUtente(puoModificare: false);
    $batch = b14cpCarica($utente);
    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => 'soggetto:'.$d['chiave'], 'scelta' => 'lascia']);
    }

    // Un guasto nel livello delle unità: il condominio è entrato, il lotto è «parziale».
    $guasto = true;
    Immobile::creating(function () use (&$guasto) {
        if ($guasto) {
            throw new RuntimeException('guasto simulato nel livello delle unità');
        }
    });
    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));
    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_PARZIALE)
        ->and(Condominio::count())->toBe(1);
    $guasto = false;

    // «Riprendi»: l'anteprima mostra quello che è già entrato, «Lascia com'è», e la conferma arriva in fondo.
    $props = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    $voce = collect($props['anteprima']['collisioni']['voci'])->firstWhere('cosa', 'condominio');
    expect($voce)->not->toBeNull()
        ->and($voce['senza_permesso'])->toBeFalse();
    foreach ($props['anteprima']['collisioni']['voci'] as $v) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => $v['chiave_decisione'], 'scelta' => 'salta'])->assertRedirect();
    }

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));
    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(Condominio::count())->toBe(1)
        ->and(Immobile::count())->toBe(11);
});
