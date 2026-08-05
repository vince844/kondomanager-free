<?php

/**
 * beta.43 — Il saldo solidale dell'unità cade sul soggetto giusto.
 *
 * `GenerateSaldiAction` ripartisce i pregressi intestati all'**unità** (art. 63 disp. att.
 * c.c., `anagrafica_id = NULL`) fra chi sta su quell'unità. Il ramo automatico prendeva
 * **tutti** gli occupanti attivi, senza guardare il ruolo:
 *
 *     ->where('immobile_id', $saldo->immobile_id)->where('attivo', true)->get()
 *
 * Da lì un danno doppio: l'inquilino — che verso il condominio non è debitore, il suo
 * rapporto è con il locatore — riceveva una quota del pregresso, e la quota del proprietario
 * vero **si diluiva**, perché il denominatore sommava anche i millesimi dell'inquilino.
 *
 * Il comportamento corretto era già scritto nell'ADR-001 (`architettura_saldi_iniziali.md`,
 * sezione «GENERAZIONE PIANO RATE»): *cerca i proprietari attivi*, *applica Adjust Remainder*,
 * *proprietari assenti → ALERT BLOCCANTE*. Le rettifiche del 31/07/2026 in coda a quel
 * documento certificano che nessuno dei tre esisteva nel codice. Questa suite li fissa tutti
 * e tre.
 *
 * ## La catena, e perché non è un semplice filtro sui «proprietari»
 *
 * Filtrare `tipologia = 'proprietario'` avrebbe curato l'inquilino e lasciato scoperta
 * l'unità con nuda proprietà e usufrutto, dove nessuno è registrato come `proprietario`: il
 * saldo sarebbe sparito. La regola è la cascata del motore di riparto **meno l'inquilino**,
 * con l'asse deciso dalla natura della gestione da cui il pregresso proviene:
 *
 * - gestione **ordinaria** → `usufruttuario → proprietario` (art. 1004 c.c.)
 * - gestione **straordinaria** → `nuda_proprietario → proprietario` (art. 1005 c.c.)
 *
 * Verso il condominio i due rispondono comunque in solido (art. 67 co. 8 disp. att. c.c.):
 * questa è la ripartizione *interna*, cioè un default. Chi ha pattuito diversamente usa il
 * riparto manuale, che è il ramo B1 della stessa funzione e non è toccato da qui.
 */

use App\Actions\PianoRate\GenerateSaldiAction;
use App\Enums\RuoloAnagraficaImmobile;
use App\Exceptions\Gestionale\SaldoSolidaleSenzaTitolareException;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Saldo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Costruisce un'unità con gli occupanti indicati e un saldo solidale da ripartire.
 *
 * @param  array  $occupanti  [['ruolo' => 'proprietario', 'quota' => 100.0], …]
 */
function scenarioSolidale(array $occupanti, int $saldoCents = 100000, string $tipoGestione = 'ordinaria'): object
{
    static $seq = 0;
    $seq++;

    $condominio = Condominio::create([
        'nome' => "Condominio Solidale {$seq}", 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id, 'nome' => '2026',
        'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id, 'nome' => "Gestione {$seq}",
        'data_inizio' => '2026-01-01', 'tipo' => $tipoGestione, 'saldo_applicato' => false,
    ]);
    $gestione->esercizi()->attach($esercizio->id, ['attiva' => true]);

    $immobile = Immobile::forceCreate([
        'condominio_id' => $condominio->id, 'nome' => "Int {$seq}",
        'descrizione' => 'Appartamento', 'interno' => (string) $seq,
    ]);

    $persone = [];
    foreach ($occupanti as $occupante) {
        $seq++;
        // `anagrafiche` non ha né `condominio_id` né `cognome`: il legame col condominio
        // passa da un pivot. Il contatore statico tiene univoci email e codice fiscale, che
        // sono UNIQUE — è la stessa precauzione presa in `CascataRuoloRipartoTest` dopo che
        // dei valori casuali avevano reso la suite flaky.
        $anagrafica = Anagrafica::forceCreate([
            'nome' => "Tizio Numero {$seq}",
            'email' => "solidale{$seq}@test.it",
            'indirizzo' => 'Via Verdi 1',
            'codice_fiscale' => 'SLDTST' . str_pad((string) $seq, 10, '0', STR_PAD_LEFT),
        ]);

        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $anagrafica->id,
            'immobile_id' => $immobile->id,
            'tipologia' => $occupante['ruolo'],
            'quota' => $occupante['quota'] ?? 100.0,
            'attivo' => $occupante['attivo'] ?? true,
            'data_inizio' => '2026-01-01',
        ]);

        // La chiave è esplicita quando serve distinguere due persone con lo stesso ruolo:
        // senza, la seconda sovrascriveva la prima e il test guardava l'anagrafica sbagliata.
        $persone[$occupante['chiave'] ?? $occupante['ruolo']] = $anagrafica;
    }

    $saldo = Saldo::forceCreate([
        'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id, 'immobile_id' => $immobile->id,
        'anagrafica_id' => null, 'saldo_iniziale' => $saldoCents,
        'descrizione' => 'Pregresso solidale', 'is_applicato' => false,
    ]);

    $piano = PianoRate::create([
        'condominio_id' => $condominio->id, 'gestione_id' => $gestione->id,
        'nome' => "Piano {$seq}", 'numero_rate' => 2, 'metodo_distribuzione' => 'rata_zero',
        'stato' => 'bozza', 'tipo' => $tipoGestione === 'straordinaria' ? 'straordinario' : 'ordinario',
    ]);

    return (object) [
        'condominio' => $condominio, 'esercizio' => $esercizio, 'gestione' => $gestione,
        'immobile' => $immobile, 'saldo' => $saldo, 'piano' => $piano, 'persone' => $persone,
    ];
}

function ripartisci(object $s): array
{
    return app(GenerateSaldiAction::class)->execute($s->piano, $s->gestione);
}

/** Quanto è stato addebitato a quella persona su quell'unità, in centesimi. */
function quotaDi(array $distribuzione, Anagrafica $persona, Immobile $immobile): int
{
    return $distribuzione[$persona->id][$immobile->id]['importo'] ?? 0;
}

test('l\'inquilino non paga il pregresso dell\'unità, e il proprietario non si diluisce', function () {
    // Il caso della segnalazione: proprietario e inquilino entrambi attivi, entrambi con
    // millesimi sul pivot. Prima: 500,00 € a testa. Ora: 1.000,00 € al proprietario.
    $s = scenarioSolidale([
        ['ruolo' => 'proprietario', 'quota' => 100.0],
        ['ruolo' => 'inquilino', 'quota' => 100.0],
    ], saldoCents: 100000);

    $d = ripartisci($s);

    expect(quotaDi($d, $s->persone['proprietario'], $s->immobile))->toBe(100000)
        ->and(quotaDi($d, $s->persone['inquilino'], $s->immobile))->toBe(0)
        ->and($d)->not->toHaveKey($s->persone['inquilino']->id);
});

test('su gestione ordinaria il pregresso è dell\'usufruttuario, non del nudo proprietario', function () {
    // Art. 1004 c.c.: le spese di ordinaria amministrazione sono a carico dell'usufruttuario.
    $s = scenarioSolidale([
        ['ruolo' => 'nuda_proprietario', 'quota' => 100.0],
        ['ruolo' => 'usufruttuario', 'quota' => 100.0],
    ], saldoCents: 80000, tipoGestione: 'ordinaria');

    $d = ripartisci($s);

    expect(quotaDi($d, $s->persone['usufruttuario'], $s->immobile))->toBe(80000)
        ->and(quotaDi($d, $s->persone['nuda_proprietario'], $s->immobile))->toBe(0);
});

test('su gestione straordinaria il pregresso è del nudo proprietario', function () {
    // Art. 1005 c.c.: le riparazioni straordinarie restano del proprietario.
    $s = scenarioSolidale([
        ['ruolo' => 'nuda_proprietario', 'quota' => 100.0],
        ['ruolo' => 'usufruttuario', 'quota' => 100.0],
    ], saldoCents: 80000, tipoGestione: 'straordinaria');

    $d = ripartisci($s);

    expect(quotaDi($d, $s->persone['nuda_proprietario'], $s->immobile))->toBe(80000)
        ->and(quotaDi($d, $s->persone['usufruttuario'], $s->immobile))->toBe(0);
});

test('in piena proprietà la catena non cambia niente, in entrambe le nature', function (string $tipo) {
    // Controprova: il caso normale — un solo proprietario — deve rispondere come sempre.
    // Se questa si accende, la correzione ha spostato qualcosa che andava lasciato fermo.
    $s = scenarioSolidale([
        ['ruolo' => 'proprietario', 'quota' => 100.0],
    ], saldoCents: 45000, tipoGestione: $tipo);

    expect(quotaDi(ripartisci($s), $s->persone['proprietario'], $s->immobile))->toBe(45000);
})->with(['ordinaria', 'straordinaria']);

test('fra comproprietari la somma ripartita è esattamente il saldo, al centesimo', function () {
    // 100,01 € fra tre comproprietari in parti uguali: 3333,67 centesimi a testa, che non
    // esiste. Con un `round()` indipendente per ciascuno escono 3334 × 3 = 10002, cioè **un
    // centesimo creato dal nulla** — su un piano rate è una quadratura che non torna, e nel
    // verso opposto un centesimo che sparisce.
    $s = scenarioSolidale([
        ['ruolo' => 'proprietario', 'quota' => 100.0, 'chiave' => 'a'],
        ['ruolo' => 'proprietario', 'quota' => 100.0, 'chiave' => 'b'],
        ['ruolo' => 'proprietario', 'quota' => 100.0, 'chiave' => 'c'],
    ], saldoCents: 10001);

    $d = ripartisci($s);

    $quote = collect(['a', 'b', 'c'])
        ->map(fn (string $k) => quotaDi($d, $s->persone[$k], $s->immobile));

    expect($quote->sum())->toBe(10001)
        // Il resto va a uno solo: nessuno deve pagare due centesimi in più degli altri.
        ->and($quote->max() - $quote->min())->toBe(1);
});

test('l\'inquilino non diluisce la quota di chi paga davvero', function () {
    // Il secondo danno del difetto, distinto dal primo: anche volendo ignorare che
    // l'inquilino riceveva un addebito, la sua presenza gonfiava il denominatore e riduceva
    // la quota del proprietario. Con 100 e 100 di millesimi il proprietario ne pagava metà.
    $s = scenarioSolidale([
        ['ruolo' => 'proprietario', 'quota' => 100.0, 'chiave' => 'prop'],
        ['ruolo' => 'inquilino', 'quota' => 300.0, 'chiave' => 'inq'],
    ], saldoCents: 60000);

    $d = ripartisci($s);

    expect(quotaDi($d, $s->persone['prop'], $s->immobile))->toBe(60000)
        ->and(quotaDi($d, $s->persone['inq'], $s->immobile))->toBe(0);
});

test('senza nessun titolare di diritto reale il saldo non sparisce: blocca', function () {
    // Unità con il solo inquilino censito. Prima: la collection usciva vuota, il foreach non
    // girava, il saldo svaniva senza eccezione e senza log — e il lucchetto si chiudeva lo
    // stesso, scrivendo un importo «processato» che non esisteva in nessuna quota.
    $s = scenarioSolidale([
        ['ruolo' => 'inquilino', 'quota' => 100.0],
    ], saldoCents: 50000);

    expect(fn () => ripartisci($s))
        ->toThrow(SaldoSolidaleSenzaTitolareException::class);
});

test('con le quote tutte a zero si ripartisce per teste invece di perdere il saldo', function () {
    // `$totaleQuote = sum(quota) ?: 100` metteva 100 al denominatore e ogni importo usciva 0;
    // la guardia `if ($importo !== 0)` li scartava tutti e il saldo spariva per intero.
    // L'ADR-001 prometteva il riparto in parti uguali con un warning: non esisteva.
    $s = scenarioSolidale([
        ['ruolo' => 'proprietario', 'quota' => 0.0],
        ['ruolo' => 'usufruttuario', 'quota' => 0.0],
    ], saldoCents: 30000, tipoGestione: 'straordinaria');

    $d = ripartisci($s);

    // Straordinaria senza nudo proprietario: la catena scende al proprietario, che è solo.
    expect(quotaDi($d, $s->persone['proprietario'], $s->immobile))->toBe(30000);
});

test('un occupante non attivo non partecipa', function () {
    $s = scenarioSolidale([
        ['ruolo' => 'proprietario', 'quota' => 50.0, 'chiave' => 'attivo'],
        ['ruolo' => 'proprietario', 'quota' => 50.0, 'attivo' => false, 'chiave' => 'uscito'],
    ], saldoCents: 20000);

    $d = ripartisci($s);

    expect(quotaDi($d, $s->persone['attivo'], $s->immobile))->toBe(20000)
        ->and(quotaDi($d, $s->persone['uscito'], $s->immobile))->toBe(0);
});

test('il meta dice con che ruolo e con che catena è stato risolto', function () {
    // La risoluzione va congelata nel riparto, non ricalcolata a runtime: è il requisito di
    // trasparenza del §4 di `cascata_risoluzione_ruolo_coerenza_ruoli.md`.
    $s = scenarioSolidale([
        ['ruolo' => 'nuda_proprietario', 'quota' => 100.0],
        ['ruolo' => 'usufruttuario', 'quota' => 100.0],
    ], saldoCents: 10000, tipoGestione: 'ordinaria');

    $meta = ripartisci($s)[$s->persone['usufruttuario']->id][$s->immobile->id]['meta_storico'][0];

    expect($meta['tipo_riparto'])->toBe('solidale_automatico')
        ->and($meta['ruolo_risolto'])->toBe(RuoloAnagraficaImmobile::USUFRUTTUARIO->value);
});

/**
 * L'anteprima del riparto automatico (beta.43).
 *
 * Fino a ieri, in modalità automatica, la creazione del piano non mostrava **niente**: chi
 * fosse stato addebitato si scopriva dopo aver generato. Il §4 di
 * `cascata_risoluzione_ruolo_coerenza_ruoli.md` lo dichiara vincolante da prima che il codice
 * esistesse — *quello che l'amministratore legge è quello che viene addebitato* — ed è anche
 * ciò che rende il riparto manuale una scelta invece del ripiego di chi ha già sbagliato.
 */
test('l\'anteprima dice chi paga e con che ruolo', function () {
    $s = scenarioSolidale([
        ['ruolo' => 'nuda_proprietario', 'quota' => 100.0],
        ['ruolo' => 'usufruttuario', 'quota' => 100.0],
    ], saldoCents: 80000, tipoGestione: 'ordinaria');

    $anteprima = app(GenerateSaldiAction::class)->anteprimaSolidale($s->saldo, $s->gestione);

    expect($anteprima['risolvibile'])->toBeTrue()
        ->and($anteprima['ruolo'])->toBe('usufruttuario')
        ->and($anteprima['ruolo_label'])->toBe('Usufruttuario')
        ->and($anteprima['quote'])->toHaveCount(1)
        ->and($anteprima['quote'][0]['importo'])->toBe(80000);
});

test('l\'anteprima mostra il problema invece di sollevare', function () {
    // In generazione la catena esaurita è un errore che ferma tutto, ed è giusto. Qui no: si
    // è ancora in tempo per censire il proprietario mancante, e dirlo adesso vale molto di
    // più che scoprirlo a piano emesso.
    $s = scenarioSolidale([
        ['ruolo' => 'inquilino', 'quota' => 100.0],
    ], saldoCents: 50000);

    $anteprima = app(GenerateSaldiAction::class)->anteprimaSolidale($s->saldo, $s->gestione);

    expect($anteprima['risolvibile'])->toBeFalse()
        ->and($anteprima['quote'])->toBeEmpty()
        ->and($anteprima['motivo'])->toContain('nessun proprietario');
});

test('l\'anteprima e la generazione dicono lo stesso identico numero', function () {
    // È la garanzia che conta: un'anteprima che ricalcola a modo suo diverge al primo cambio.
    // Qui le due strade passano dalle stesse funzioni, e questo test lo inchioda — su un
    // riparto con i resti, dove una seconda implementazione sbaglierebbe per prima.
    $s = scenarioSolidale([
        ['ruolo' => 'proprietario', 'quota' => 100.0, 'chiave' => 'a'],
        ['ruolo' => 'proprietario', 'quota' => 100.0, 'chiave' => 'b'],
        ['ruolo' => 'proprietario', 'quota' => 100.0, 'chiave' => 'c'],
    ], saldoCents: 10001);

    $anteprima = collect(app(GenerateSaldiAction::class)->anteprimaSolidale($s->saldo, $s->gestione)['quote'])
        ->pluck('importo', 'anagrafica_id');

    $generato = collect(ripartisci($s))->map(fn ($perImmobile) => reset($perImmobile)['importo']);

    expect($anteprima->sort()->values()->all())->toBe($generato->sort()->values()->all())
        ->and($anteprima->sum())->toBe(10001);
});

test('l\'endpoint della creazione piano porta l\'anteprima fino al frontend', function () {
    // Questo test nasce da un difetto vero, trovato **a video e non dalla suite**: la closure
    // che costruisce il payload non aveva l'azione nel suo `use`, e l'endpoint rispondeva 500.
    // La schermata di creazione del piano diceva «Nessun saldo pregresso per questa gestione»
    // — cioè il modo peggiore di fallire, perché sembra una risposta.
    //
    // `fetchSaldiAnalitici` non aveva alcun test: è il percorso che alimenta la modale dei
    // saldi, e da oggi porta anche l'anteprima del riparto. Ora ce l'ha.
    $s = scenarioSolidale([
        ['ruolo' => 'proprietario', 'quota' => 100.0],
        ['ruolo' => 'inquilino', 'quota' => 100.0],
    ], saldoCents: 120000);

    \Spatie\Permission\Models\Permission::firstOrCreate([
        'name' => 'Accesso pannello amministratore', 'guard_name' => 'web',
    ]);
    $utente = \App\Models\User::factory()->create();
    $utente->givePermissionTo('Accesso pannello amministratore');

    $risposta = $this->actingAs($utente)->getJson(route('admin.gestionale.fetch-saldi-analitici', [
        'condominio' => $s->condominio->id,
        'esercizio' => $s->esercizio->id,
        'gestione' => $s->gestione->id,
    ]));

    $risposta->assertOk();

    $solidale = collect($risposta->json())->firstWhere('tipo', 'solidale');

    expect($solidale)->not->toBeNull()
        ->and($solidale['riparto_previsto']['risolvibile'])->toBeTrue()
        ->and($solidale['riparto_previsto']['ruolo_label'])->toBe('Proprietario')
        ->and($solidale['riparto_previsto']['quote'])->toHaveCount(1)
        ->and($solidale['riparto_previsto']['quote'][0]['importo'])->toBe(120000)
        // L'inquilino non compare nell'anteprima, che è il punto di tutta la beta.
        ->and(collect($solidale['riparto_previsto']['quote'])->pluck('nome'))
            ->not->toContain($s->persone['inquilino']->nome);
});
