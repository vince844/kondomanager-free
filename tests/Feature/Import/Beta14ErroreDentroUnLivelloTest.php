<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 598, 1.12.0-beta.14: un errore dentro un livello dell'importazione arrivava come pagina «Server Error», e il lotto restava «in corso» per sempre.
 *
 * `ImportRunner::esegui()` faceva girare il livello dentro la sua transazione senza fermare l'eccezione: il livello si annullava (giusto), ma l'eccezione
 * risaliva fino a `ImportController::conferma()`, che non scriveva il rapporto. Ora il livello si ferma come un livello bloccato: il rapporto dice quale
 * livello non è entrato, con il codice di sei caratteri dell'errore, lo stesso del registro degli errori (tappa 6); il lotto è «parziale» e si riprende.
 *
 * Che cosa non copre: un errore **fuori** dai livelli (la lettura dei file in `conferma()`), che resta la pagina d'errore con il codice; i lotti rimasti
 * «in corso» per un errore di prima (l'ingresso li mostra già fra quelli da riprendere).
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Errori\VoltaDellErrore;
use App\Models\Gestionale\Conto;
use App\Models\Esercizio;
use App\Models\Immobile;
use App\Models\ImportBatch;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use App\Support\CodiceDellErrore;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->withoutVite();
    test()->seed(TipologieImmobiliSeeder::class);
    Storage::fake('local');
    config(['app.debug' => false]);
    $this->guasto = true;
});

function b14eUtente(): User
{
    foreach (['Crea condomini', 'Accesso pannello amministratore', 'Importa dati'] as $nome) {
        Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']);
    }
    $u = User::factory()->create();
    $u->givePermissionTo('Crea condomini');
    $u->givePermissionTo('Importa dati');
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

    return $u;
}

/** I tre file di Danea fino all'anteprima, con le decisioni prese: da qui la conferma è aperta. */
function b14eLottoPronto(User $utente, array $altri = []): ImportBatch
{
    $files = [];
    foreach (['elenco_unita.xls', 'riparto_consuntivo.xls', 'millesimi_tabelle_miste.xls', ...$altri] as $n) {
        $files[] = new UploadedFile(base_path('tests/Fixtures/import/danea/'.$n), $n, 'application/vnd.ms-excel', test: true);
    }
    test()->actingAs($utente)->post(route('import.store'), ['file' => $files]);
    $batch = ImportBatch::query()->orderByDesc('id')->first();

    $props = test()->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props'];
    foreach ($props['anteprima']['persone']['da_dividere'] as $d) {
        test()->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => 'soggetto:'.$d['chiave'], 'scelta' => 'lascia']);
    }
    foreach ($props['anteprima']['collisioni']['voci'] ?? [] as $c) {
        test()->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => $c['chiave_decisione'], 'scelta' => 'salta']);
    }

    return $batch->fresh();
}

/** La riga del rapporto di un livello. */
function b14eRiga(ImportBatch $batch, string $livello): ?array
{
    return collect($batch->fresh()->rapporto['livelli'] ?? [])->firstWhere('livello', $livello);
}

/** Il codice dell'errore scritto nel motivo del livello fermato. */
function b14eCodice(array $riga): ?string
{
    $messaggio = $riga['rilievi'][0]['messaggio'] ?? '';

    return preg_match('/\(codice (['.CodiceDellErrore::ALFABETO.']{6})\)/u', $messaggio, $m) === 1 ? $m[1] : null;
}

it('un errore dentro «Unità immobiliari» apre l\'esito: il livello fermato con il motivo e il codice, il lotto «parziale», nessuna unità scritta', function () {
    $utente = b14eUtente();
    $batch = b14eLottoPronto($utente);
    Immobile::creating(function () {
        if (test()->guasto) {
            throw new RuntimeException('guasto simulato nel livello delle unità');
        }
    });

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))
        ->assertRedirect(route('import.esito', $batch->uuid));

    $batch->refresh();
    expect($batch->stato)->toBe(ImportBatch::STATO_PARZIALE)
        ->and($batch->livello_corrente)->toBe('unita')
        ->and(Immobile::count())->toBe(0)
        // I livelli di prima sono entrati: il condominio, l'esercizio, le persone.
        ->and(Condominio::count())->toBe(1)
        ->and(Anagrafica::count())->toBeGreaterThan(0);

    $riga = b14eRiga($batch, 'unita');
    expect($riga)->not->toBeNull()
        ->and($riga['riuscito'])->toBeFalse()
        ->and($riga['saltato'])->toBeFalse()
        ->and($riga['rilievi'])->toHaveCount(1)
        ->and($riga['rilievi'][0]['codice'])->toBe('import.errore_inatteso')
        ->and($riga['rilievi'][0]['severita'])->toBe('errore')
        ->and($riga['rilievi'][0]['messaggio'])->toMatch('/^«Unità immobiliari» non è entrato: il programma ha incontrato un errore \(codice [2-9A-Z]{6}\)\.$/u')
        ->and($riga['rilievi'][0]['rimedio'])->toBe('Quello che c\'era prima è entrato. Da «Importa dati» premi «Riprendi»: l\'anteprima ti mostra quello '
            .'che è già in archivio; lascialo com\'è e conferma di nuovo. Se l\'errore si ripete, cerca il codice nel registro degli errori, o scrivici con '
            .'il codice.')
        // Il testo dell'eccezione non arriva a chi importa: resta nel registro degli errori.
        ->and(json_encode($batch->rapporto))->not->toContain('guasto simulato');

    // I livelli dopo non sono stati tentati.
    expect(b14eRiga($batch, 'titolarita'))->toBeNull()
        ->and(b14eRiga($batch, 'saldi'))->toBeNull();

    // Lo stesso codice nel registro degli errori, con il messaggio vero.
    $codice = b14eCodice($riga);
    $volta = VoltaDellErrore::query()->where('codice', $codice)->first();
    expect($volta)->not->toBeNull()
        ->and($volta->classe)->toBe(RuntimeException::class)
        ->and($volta->messaggio)->toContain('guasto simulato nel livello delle unità');

    // L'esito si apre e dice che cosa è successo.
    $this->actingAs($utente)->get(route('import.esito', $batch->uuid))
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('import/ImportEsito')
            ->where('lotto.stato', 'parziale')
            ->where('rapporto.livelli', fn ($livelli) => collect($livelli)->contains(fn ($l) => $l['livello'] === 'unita'
                && str_contains($l['rilievi'][0]['messaggio'], '(codice '.$codice.')'))));
});

it('la catena: il codice dell\'esito si trova nella scheda «Errori» dei registri, e il lotto è fra quelli da riprendere', function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $utente = b14eUtente();
    $batch = b14eLottoPronto($utente);
    Immobile::creating(function () {
        if (test()->guasto) {
            throw new RuntimeException('guasto simulato nel livello delle unità');
        }
    });

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));
    $codice = b14eCodice(b14eRiga($batch, 'unita'));
    expect($codice)->not->toBeNull();

    // La scheda «Errori» di chi gestisce le impostazioni: il codice intero apre quell'errore.
    $admin = User::factory()->create(['email_verified_at' => now()]);
    $admin->assignRole(Role::findByName('amministratore', 'web'));
    $dettaglio = $this->actingAs($admin)->get(route('logs.index', ['scheda' => 'errori', 'cerca' => $codice]))
        ->assertOk()->viewData('page')['props']['dettaglio'];
    expect($dettaglio['volta']['codice'])->toBe($codice);

    // L'ingresso dell'importazione lo mostra fra le importazioni ferme a metà, arrivata alle unità, con qualcosa già scritto.
    $interrotta = collect($this->actingAs($utente)->get(route('import.index'))->viewData('page')['props']['interrotte'])
        ->firstWhere('uuid', $batch->uuid);
    expect($interrotta)->not->toBeNull()
        ->and($interrotta['livello_corrente'])->toBe('unita')
        ->and($interrotta['ha_scritto'])->toBeTrue();
});

it('il rimedio dice il vero: «Riprendi», l\'anteprima mostra quello che è già in archivio, «Lascia com\'è» e la conferma portano a «completato» senza un secondo condominio', function () {
    $utente = b14eUtente();
    $batch = b14eLottoPronto($utente);
    Immobile::creating(function () {
        if (test()->guasto) {
            throw new RuntimeException('guasto simulato nel livello delle unità');
        }
    });

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));
    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_PARZIALE);
    $this->guasto = false;

    // «Riprendi» porta al riconoscimento; l'anteprima elenca il condominio, l'esercizio e le persone già entrati, con «Lascia com'è» («salta»).
    $this->actingAs($utente)->get(route('import.riconoscimento', $batch->uuid))->assertOk();
    $voci = $this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props']['anteprima']['collisioni']['voci'];
    expect(collect($voci)->pluck('cosa')->unique()->sort()->values()->all())->toBe(['condominio', 'esercizio', 'persona'])
        ->and(collect($voci)->every(fn ($v) => in_array('salta', $v['scelte'], true)))->toBeTrue();
    foreach ($voci as $v) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => $v['chiave_decisione'], 'scelta' => 'salta'])->assertRedirect();
    }

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));

    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(Condominio::count())->toBe(1)
        ->and(Immobile::count())->toBeGreaterThan(0)
        ->and(DB::table('anagrafica_immobile')->count())->toBeGreaterThan(0)
        ->and(collect($batch->fresh()->rapporto['livelli'])->every(fn ($l) => $l['rilievi'] === []))->toBeTrue();
});

it('un errore nel primo livello: il rapporto dice che non è entrato niente', function () {
    $utente = b14eUtente();
    $batch = b14eLottoPronto($utente);
    Condominio::creating(function () {
        throw new RuntimeException('guasto simulato nel livello del condominio');
    });

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));

    $batch->refresh();
    $riga = b14eRiga($batch, 'condominio');
    expect($batch->stato)->toBe(ImportBatch::STATO_PARZIALE)
        ->and($batch->livello_corrente)->toBe('condominio')
        ->and(Condominio::count())->toBe(0)
        ->and($riga['rilievi'][0]['messaggio'])->toMatch('/^«Condominio» non è entrato: il programma ha incontrato un errore \(codice [2-9A-Z]{6}\)\.$/u')
        ->and($riga['rilievi'][0]['rimedio'])->toBe('Non è entrato niente. Da «Importa dati» premi «Riprendi» e conferma di nuovo. Se l\'errore si ripete, '
            .'cerca il codice nel registro degli errori, o scrivici con il codice.');
});

it('un errore in ogni livello che scrive si ferma allo stesso modo, e quel livello non lascia niente', function (string $livello, string $etichetta, Closure $guasta, Closure $quanti) {
    $utente = b14eUtente();
    $batch = b14eLottoPronto($utente);
    $guasta();

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));

    $batch->refresh();
    $riga = b14eRiga($batch, $livello);
    expect([$batch->stato, $batch->livello_corrente])->toBe([ImportBatch::STATO_PARZIALE, $livello])
        ->and($riga['rilievi'][0]['codice'])->toBe('import.errore_inatteso')
        ->and($riga['rilievi'][0]['messaggio'])->toStartWith('«'.$etichetta.'» non è entrato: il programma ha incontrato un errore (codice ')
        ->and($quanti())->toBe(0)
        ->and(VoltaDellErrore::query()->where('codice', b14eCodice($riga))->exists())->toBeTrue();
})->with([
    'esercizi' => ['esercizi', 'Esercizi', fn () => Esercizio::creating(fn () => throw new RuntimeException('guasto')), fn () => Esercizio::count()],
    'persone' => ['soggetti', 'Persone', fn () => Anagrafica::creating(fn () => throw new RuntimeException('guasto')), fn () => Anagrafica::count()],
    'chi possiede cosa' => ['titolarita', 'Chi possiede cosa', fn () => DB::listen(function (QueryExecuted $q) {
        if (str_starts_with(strtolower(ltrim($q->sql)), 'insert into "anagrafica_immobile"')) {
            throw new RuntimeException('guasto');
        }
    }), fn () => DB::table('anagrafica_immobile')->count()],
    'tabelle millesimali' => ['tabelle', 'Tabelle millesimali', fn () => Tabella::creating(fn () => throw new RuntimeException('guasto')), fn () => Tabella::count()],
    'saldi di apertura' => ['saldi', 'Saldi di apertura', fn () => Saldo::creating(fn () => throw new RuntimeException('guasto')), fn () => Saldo::count()],
]);

it('un errore nei capitoli di spesa (con il bilancio consuntivo) si ferma allo stesso modo, senza un capitolo scritto', function () {
    $utente = b14eUtente();
    $batch = b14eLottoPronto($utente, ['bilancio_consuntivo.xls']);
    Conto::creating(fn () => throw new RuntimeException('guasto'));

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));

    $batch->refresh();
    $riga = b14eRiga($batch, 'capitoli');
    expect([$batch->stato, $batch->livello_corrente])->toBe([ImportBatch::STATO_PARZIALE, 'capitoli'])
        ->and($riga['rilievi'][0]['messaggio'])->toStartWith('«Capitoli di spesa» non è entrato: il programma ha incontrato un errore (codice ')
        ->and(Conto::count())->toBe(0)
        ->and(Esercizio::count())->toBe(1);
});

it('la ripresa funziona anche dopo un errore negli ultimi livelli: quello che era entrato si riusa, niente due volte', function (string $livello, Closure $guasta) {
    $utente = b14eUtente();
    $batch = b14eLottoPronto($utente);
    $guasta();

    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));
    expect([$batch->fresh()->stato, $batch->fresh()->livello_corrente])->toBe([ImportBatch::STATO_PARZIALE, $livello]);

    $this->guasto = false;
    foreach ($this->actingAs($utente)->get(route('import.anteprima', $batch->uuid))->viewData('page')['props']['anteprima']['collisioni']['voci'] as $v) {
        $this->actingAs($utente)->put(route('import.decisione', $batch->uuid), ['chiave' => $v['chiave_decisione'], 'scelta' => 'salta']);
    }
    $this->actingAs($utente)->post(route('import.conferma', $batch->uuid))->assertRedirect(route('import.esito', $batch->uuid));

    // Con il file di prova entrano sei tabelle e cinque saldi: dopo la ripresa sono ancora sei e cinque, non il doppio.
    expect($batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO)
        ->and(Condominio::count())->toBe(1)
        ->and(Tabella::count())->toBe(6)
        ->and(Saldo::count())->toBe(5);
})->with([
    'chi possiede cosa' => ['titolarita', fn () => DB::listen(function (QueryExecuted $q) {
        if (test()->guasto && str_starts_with(strtolower(ltrim($q->sql)), 'insert into "anagrafica_immobile"')) {
            throw new RuntimeException('guasto');
        }
    })],
    'tabelle millesimali' => ['tabelle', fn () => Tabella::creating(function () {
        if (test()->guasto) {
            throw new RuntimeException('guasto');
        }
    })],
    'saldi di apertura' => ['saldi', fn () => Saldo::creating(function () {
        if (test()->guasto) {
            throw new RuntimeException('guasto');
        }
    })],
]);
