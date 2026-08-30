<?php

use App\Models\ImportBatch;
use App\Models\Saldo;
use App\Services\Import\Canonical\CanonicalSaldiApertura;
use App\Services\Import\Canonical\CanonicalSaldo;
use App\Services\Import\ImportContext;
use App\Services\Import\ImportRunner;
use App\Services\Import\Livelli\LivelloCondominio;
use App\Services\Import\Livelli\LivelloEsercizi;
use App\Services\Import\Livelli\LivelloSaldi;
use App\Services\Import\Livelli\LivelloSoggetti;
use App\Services\Import\Livelli\LivelloTabelle;
use App\Services\Import\Livelli\LivelloTitolarita;
use App\Services\Import\Livelli\LivelloUnita;
use App\Services\Import\Parser\AnagraficaMillesimiParser;
use App\Services\Import\Parser\BannerParser;
use App\Services\Import\Parser\ElencoUnitaParser;
use App\Services\Import\Parser\RipartoConsuntivoParser;
use App\Services\Import\ReportRecognizer;
use App\Services\Import\SpreadsheetReader;
use Database\Seeders\TipologieImmobiliSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => test()->seed(TipologieImmobiliSeeder::class));

/**
 * I saldi di apertura — livello 10, e il momento di fiducia della schermata di conferma.
 *
 * È l'unico dato importato con conseguenze legali: da qui nascono morosità, solleciti e decreti
 * ingiuntivi. La quadratura sul totale scritto nel riparto è **bloccante**, e questi test
 * verificano soprattutto che blocchi davvero.
 *
 * ## Cosa questi test NON coprono
 *
 * - **Il saldo per gestione multipla**: il livello aggancia la prima gestione dell'esercizio.
 *   Su un condominio con ordinaria e straordinaria servirà scegliere, e la scelta non c'è.
 * - **La colonna `Saldi di fine Es. prec.`** come fonte alternativa: si legge solo `Saldo
 *   finale`, che è quella più precisa (§17.5).
 * - **L'unità ambigua risolta dall'utente**: il rilievo si produce, la schermata che lo risolve
 *   non esiste.
 */
function bundleCompleto(): ImportContext
{
    $reader = new SpreadsheetReader;
    $ric = new ReportRecognizer;

    $riparto = $reader->leggi(base_path('tests/Fixtures/import/danea/riparto_consuntivo.xls'))[0];
    $banner = (new BannerParser)->estrai($riparto);
    $saldi = (new RipartoConsuntivoParser)->estrai($riparto, $ric->riconosci($riparto)->rigaIntestazione);

    $elenco = $reader->leggi(base_path('tests/Fixtures/import/danea/elenco_unita.xls'))[0];
    $letto = (new ElencoUnitaParser)->estrai($elenco, $ric->riconosci($elenco)->rigaIntestazione);

    $mill = $reader->leggi(base_path('tests/Fixtures/import/danea/millesimi_tabelle_miste.xls'))[0];
    $millesimi = (new AnagraficaMillesimiParser)->estrai($mill, $ric->riconosci($mill)->rigaIntestazione);

    return (new ImportContext(ImportBatch::create(['sorgente' => 'danea'])))
        ->conCanonico(LivelloCondominio::CHIAVE, $banner['condominio'])
        ->conCanonico(LivelloEsercizi::CHIAVE, $banner['esercizio'])
        ->conCanonico(LivelloSoggetti::CHIAVE, $letto['soggetti'])
        ->conCanonico(LivelloUnita::CHIAVE, [...$millesimi['immobili'], ...$letto['immobili']])
        ->conCanonico(LivelloTitolarita::CHIAVE, $letto['titolarita'])
        ->conCanonico(LivelloTabelle::CHIAVE, $millesimi['tabelle'])
        ->conCanonico(LivelloSaldi::CHIAVE, new CanonicalSaldiApertura(
            righe: $saldi['saldi'],
            totaleRiferimentoCents: $saldi['totale_riferimento_cents'],
            arrotondamentiCents: $saldi['arrotondamenti_cents'],
        ));
}

it('importa i saldi di apertura da tre file veri, dall\'inizio alla fine', function () {
    $ctx = bundleCompleto();

    $esiti = (new ImportRunner)->esegui($ctx);

    foreach (['condominio', 'esercizi', 'soggetti', 'unita', 'titolarita', 'tabelle', 'saldi'] as $livello) {
        expect($esiti[$livello]->riuscito())->toBeTrue("il livello «{$livello}» non è passato");
    }

    expect(Saldo::count())->toBeGreaterThan(0)
        ->and($ctx->batch->fresh()->stato)->toBe(ImportBatch::STATO_COMPLETATO);
});

it('scrive i saldi con il segno di Kondomanager e l\'origine giusta', function () {
    (new ImportRunner)->esegui(bundleCompleto());

    // Chi in Danea era a −1.202,84 qui è a debito, quindi positivo.
    $debitore = Saldo::whereHas('anagrafica', fn ($q) => $q->where('nome', 'GIALLI FRANCESCA'))->first();
    expect($debitore)->not->toBeNull()
        ->and($debitore->saldo_iniziale)->toBe(120284)
        ->and($debitore->origine)->toBe('importato');

    // Chi era a +169,57 aveva versato in più: qui è un credito, quindi negativo.
    $creditore = Saldo::whereHas('anagrafica', fn ($q) => $q->where('nome', 'ROSSI MARIA GRAZIA'))->first();
    expect($creditore->saldo_iniziale)->toBe(-16957);
});

it('la somma dei saldi scritti torna al totale della stampa', function () {
    // È la verifica che l'amministratore vede come «scarto € 0,00», fatta sui dati veri.
    $ctx = bundleCompleto();
    (new ImportRunner)->esegui($ctx);

    /** @var CanonicalSaldiApertura $dati */
    $dati = $ctx->canonico(LivelloSaldi::CHIAVE);

    expect(Saldo::sum('saldo_iniziale') + $dati->arrotondamentiCents)
        ->toBe($dati->totaleRiferimentoCents);
});

it('non scrive un solo saldo se non quadrano con il totale dichiarato', function () {
    // Il controllo che rende difendibile la migrazione. Scarto ≠ 0 significa che qualcosa non è
    // stato letto, o è stato letto due volte, o il file è stato modificato dopo l'esportazione.
    $ctx = bundleCompleto();

    /** @var CanonicalSaldiApertura $originali */
    $originali = $ctx->canonico(LivelloSaldi::CHIAVE);

    $ctx->conCanonico(LivelloSaldi::CHIAVE, new CanonicalSaldiApertura(
        righe: $originali->righe,
        // Il totale dichiarato viene alterato di un solo centesimo.
        totaleRiferimentoCents: $originali->totaleRiferimentoCents + 1,
        arrotondamentiCents: $originali->arrotondamentiCents,
    ));

    $esiti = (new ImportRunner)->esegui($ctx);

    expect($esiti['saldi']->riuscito())->toBeFalse()
        ->and($esiti['saldi']->rilievi[0]->codice)->toBe('saldi.non_quadrano')
        ->and($esiti['saldi']->rilievi[0]->messaggio)->toContain('€ 0,01')
        ->and(Saldo::count())->toBe(0);
});

it('un centesimo di scarto basta: nessuna tolleranza', function () {
    // Una tolleranza qui sarebbe un centesimo che si perde a ogni migrazione, cioè la cosa che
    // nessuno ritrova più. È la stessa scelta già fatta per il riparto manuale del pregresso.
    $ctx = bundleCompleto();
    $originali = $ctx->canonico(LivelloSaldi::CHIAVE);

    $ctx->conCanonico(LivelloSaldi::CHIAVE, new CanonicalSaldiApertura(
        righe: $originali->righe,
        totaleRiferimentoCents: $originali->totaleRiferimentoCents,
        arrotondamentiCents: $originali->arrotondamentiCents - 1,
    ));

    $esiti = (new ImportRunner)->esegui($ctx);

    expect($esiti['saldi']->riuscito())->toBeFalse()
        ->and(Saldo::count())->toBe(0);
});

it('porta il saldo del titolare cessato sull\'unità, non su una persona', function () {
    // `anagrafica_id` a NULL è il saldo **solidale**: la forma con cui Kondomanager rappresenta
    // un debito che segue la casa e non la persona (art. 63 disp. att. c.c.).
    (new ImportRunner)->esegui(bundleCompleto());

    $solidali = Saldo::whereNull('anagrafica_id')->get();

    // **Uno solo**, e sommato: l'unità ha un saldo solidale per esercizio e gestione, perché
    // è il debito che segue la casa — non uno per ciascuno dei titolari che si sono succeduti.
    // Nel riparto l'unità 01 ha un ex proprietario a −74,28 e un ex conduttore a +43,75, che
    // nella nostra convenzione fanno un credito di 30,53.
    expect($solidali)->toHaveCount(1)
        ->and($solidali->first()->immobile_id)->not->toBeNull()
        ->and($solidali->first()->saldo_iniziale)->toBe(-3053)
        ->and($solidali->first()->descrizione)->toContain('art. 63');
});

it('aggancia i saldi alla gestione dell\'esercizio, non li lascia orfani', function () {
    // Il saldo vive in un «cassetto» (art. 1130-bis): senza gestione il wallet non saprebbe a
    // cosa riferire il pregresso.
    (new ImportRunner)->esegui(bundleCompleto());

    expect(Saldo::whereNull('gestione_id')->count())->toBe(0);

    $gestione = Saldo::first()->gestione;
    expect($gestione)->not->toBeNull()
        ->and($gestione->tipo)->toBe('ordinaria');
});

it('rifiuta il saldo di una persona che non è fra quelle importate', function () {
    $ctx = bundleCompleto();

    $ctx->conCanonico(LivelloSaldi::CHIAVE, new CanonicalSaldiApertura(
        righe: [new CanonicalSaldo('01', 'SCONOSCIUTO ILLUSTRE', 10000, rigaSorgente: 7)],
        totaleRiferimentoCents: 10000,
    ));

    $esiti = (new ImportRunner)->esegui($ctx);

    expect($esiti['saldi']->riuscito())->toBeFalse()
        ->and($esiti['saldi']->rilievi[0]->codice)->toBe('saldi.soggetto_non_trovato')
        ->and($esiti['saldi']->rilievi[0]->riga)->toBe(7)
        ->and(Saldo::count())->toBe(0);
});

it('non indovina quando il numero di unità è ambiguo fra due palazzine', function () {
    // Il riparto stampa solo il progressivo: su un condominio con più palazzine quel numero non
    // basta, e l'ambiguità non si risolve tirando a indovinare su denaro altrui.
    $ctx = bundleCompleto();

    $unita = $ctx->canonico(LivelloUnita::CHIAVE);
    $primo = reset($unita);

    // Una seconda unità con lo stesso progressivo, su un'altra palazzina.
    $gemella = new App\Services\Import\Canonical\CanonicalImmobile(
        palazzina: '2',
        gruppo: '0',
        progressivo: $primo->progressivo,
        interno: $primo->progressivo,
    );

    $ctx->conCanonico(LivelloUnita::CHIAVE, [...$unita, $gemella->chiave() => $gemella]);

    $esiti = (new ImportRunner)->esegui($ctx);

    expect($esiti['saldi']->riuscito())->toBeFalse();

    $codici = array_map(fn ($r) => $r->codice, $esiti['saldi']->rilievi);
    expect($codici)->toContain('saldi.unita_ambigua');

    $ambiguo = collect($esiti['saldi']->rilievi)->firstWhere('codice', 'saldi.unita_ambigua');
    expect($ambiguo->rimedio)->toContain('palazzina alla volta')
        ->and(Saldo::count())->toBe(0);
});

it('avvisa quando ha dovuto accostare un nome a una cella che ne contiene due', function () {
    // Il riparto elenca le persone una per una; l'elenco unità può averle in una cella sola.
    // È un accostamento che facciamo noi, quindi va detto.
    $ctx = bundleCompleto();
    $esiti = (new ImportRunner)->esegui($ctx);

    $codici = array_map(fn ($r) => $r->codice, $esiti['saldi']->avvisi);

    expect($codici)->toContain('saldi.soggetto_da_nome_composto');
});

it('non duplica i saldi quando lo stesso riparto viene caricato due volte', function () {
    (new ImportRunner)->esegui(bundleCompleto());
    $primo = Saldo::count();

    $ctx2 = bundleCompleto();
    $decisioni = ['condominio:00000000001' => 'salta', 'esercizi:2024/2025' => 'salta'];

    foreach (App\Models\Anagrafica::pluck('codice_fiscale')->filter() as $cf) {
        $decisioni['soggetti:'.$cf] = 'salta';
    }
    foreach (App\Models\Anagrafica::whereNull('codice_fiscale')->pluck('nome') as $nome) {
        $decisioni['soggetti:nome:'.mb_strtolower($nome)] = 'salta';
    }

    (new ImportRunner)->esegui($ctx2, $decisioni);

    expect(Saldo::count())->toBe($primo);
});

it('segnala il saldo intestato a chi su quell\'unità non è titolare', function () {
    // È l'incrocio che nessuno dei due file può fare da solo: il riparto dice «il saldo di
    // Tizio», l'elenco unità dice «l'unità di Caio». Il caso vero è il consuntivo chiuso più
    // l'elenco di oggi, con una compravendita in mezzo — e allora il saldo va riletto prima di
    // trasformarlo in una rata. Non blocca: l'art. 63 lega comunque il debito all'unità.
    (new ImportRunner)->esegui(bundleCompleto());

    // Si stacca la titolarità di un condòmino che ha un saldo, lasciando intatto tutto il resto,
    // e si reimporta: è la forma minima della compravendita non riportata sul riparto.
    $saldo = Saldo::whereNotNull('anagrafica_id')->firstOrFail();
    DB::table('anagrafica_immobile')
        ->where('anagrafica_id', $saldo->anagrafica_id)
        ->where('immobile_id', $saldo->immobile_id)
        ->delete();
    Saldo::query()->delete();

    $decisioni = ['condominio:00000000001' => 'salta', 'esercizi:2024/2025' => 'salta'];

    foreach (App\Models\Anagrafica::pluck('codice_fiscale')->filter() as $cf) {
        $decisioni['soggetti:'.$cf] = 'salta';
    }
    foreach (App\Models\Anagrafica::whereNull('codice_fiscale')->pluck('nome') as $nome) {
        $decisioni['soggetti:nome:'.mb_strtolower($nome)] = 'salta';
    }

    $esiti = (new ImportRunner)->esegui(bundleCompleto(), $decisioni);
    $codici = array_map(fn ($a) => $a->codice, $esiti['saldi']->avvisi);

    expect($codici)->toContain('saldi.titolarita_non_corrispondente')
        ->and($esiti['saldi']->riuscito())->toBeTrue();
});

it('non perde il flag «nome diviso» quando due saldi solidali si fondono sulla stessa unità', function () {
    // Trovato dalla revisione avversariale: accorpaPerPosizione() costruiva il CanonicalSaldo unito
    // senza inoltrare daNomeDiviso, che il costruttore lascia a `false` di default. Un saldo
    // solidale nato da «dividi nome doppio» perdeva quel flag in silenzio non appena si fondeva
    // con un altro saldo solidale sulla stessa unità (es. un residuo di titolare cessato) — e
    // con lui l'avviso `saldi.da_nome_diviso` che dovrebbe segnalarlo.
    //
    // Test diretto sul metodo, via reflection: costruire l'intero contesto (condominio,
    // esercizio, gestione, unità) solo per arrivare a due righe che si fondono sarebbe più
    // impianto che verifica — accorpaPerPosizione() è una trasformazione pura sui dati canonici.
    $cessato = new CanonicalSaldo(
        immobileRef: 'u1', soggettoNome: null, importoCents: 3000,
        daTitolareCessato: true, daNomeDiviso: false,
    );
    $diviso = new CanonicalSaldo(
        immobileRef: 'u1', soggettoNome: null, importoCents: 2000,
        daTitolareCessato: false, daNomeDiviso: true,
    );

    $metodo = new ReflectionMethod(LivelloSaldi::class, 'accorpaPerPosizione');
    $metodo->setAccessible(true);

    $stub = new class
    {
        public function getKey(): int
        {
            return 1;
        }
    };

    $avvisi = [];

    $risultato = $metodo->invokeArgs(new LivelloSaldi, [[
        [$cessato, $stub, null],
        [$diviso, $stub, null],
    ], &$avvisi]);

    expect($risultato)->toHaveCount(1);

    /** @var CanonicalSaldo $fuso */
    $fuso = $risultato[0][0];

    expect($fuso->importoCents)->toBe(5000)
        ->and($fuso->daTitolareCessato)->toBeTrue()
        ->and($fuso->daNomeDiviso)->toBeTrue()
        // La fusione non è più muta: dalla beta.5 dice che è avvenuta, perché il modello
        // compilabile a mano invita a scrivere due righe sulla stessa posizione.
        ->and($avvisi[0]->codice)->toBe('saldi.righe_fuse_su_una_posizione');
});

it('somma due righe intestate alla stessa persona invece di perderne una', function () {
    // ⚠️ **Il difetto che la revisione avversariale della beta.5 ha misurato.** L'accorpamento
    // valeva solo per i saldi solidali; due righe con la **stessa persona** sulla stessa unità
    // arrivavano intere al ciclo di scrittura, la prima creava il `Saldo` e la seconda finiva in
    // `saltati` — un contatore che ovunque nel prodotto significa «era già in archivio».
    // € 300 sparivano, il lotto si chiudeva «completato», e sul modello manuale la quadratura non
    // c'è per costruzione, quindi niente lo annunciava.
    $conguaglio = new CanonicalSaldo(
        immobileRef: 'u1', soggettoNome: 'ROSSI MARIO', importoCents: 12050,
        causale: 'conguaglio 2024/2025',
    );
    $morosita = new CanonicalSaldo(
        immobileRef: 'u1', soggettoNome: 'ROSSI MARIO', importoCents: 30000,
        causale: 'rate non versate 2025',
    );

    $metodo = new ReflectionMethod(LivelloSaldi::class, 'accorpaPerPosizione');
    $metodo->setAccessible(true);

    $immobile = new class
    {
        public function getKey(): int
        {
            return 1;
        }
    };
    $persona = new class
    {
        public function getKey(): int
        {
            return 7;
        }
    };

    $avvisi = [];

    $risultato = $metodo->invokeArgs(new LivelloSaldi, [[
        [$conguaglio, $immobile, $persona],
        [$morosita, $immobile, $persona],
    ], &$avvisi]);

    expect($risultato)->toHaveCount(1)
        ->and($risultato[0][0]->importoCents)->toBe(42050)
        // Il nome resta, ed è ciò che distingue questa fusione da quella dei solidali.
        ->and($risultato[0][0]->soggettoNome)->toBe('ROSSI MARIO')
        // Le due causali si uniscono: il perché di una posizione aperta è l'unica cosa che
        // nessuna stampa porta, e perderne metà sarebbe scegliere a caso quale metà.
        ->and($risultato[0][0]->causale)->toBe('conguaglio 2024/2025 · rate non versate 2025')
        ->and($avvisi[0]->codice)->toBe('saldi.righe_fuse_su_una_posizione');
});

it('tiene distinte due persone diverse sulla stessa unità', function () {
    // La controprova: l'accorpamento è per **posizione**, non per unità. Fondere i comproprietari
    // trasformerebbe due morosità in una, intestata a uno solo.
    $uno = new CanonicalSaldo(immobileRef: 'u1', soggettoNome: 'BIANCHI ANNA', importoCents: 10000);
    $due = new CanonicalSaldo(immobileRef: 'u1', soggettoNome: 'VERDI LUCA', importoCents: 20000);

    $metodo = new ReflectionMethod(LivelloSaldi::class, 'accorpaPerPosizione');
    $metodo->setAccessible(true);

    $immobile = new class
    {
        public function getKey(): int
        {
            return 1;
        }
    };
    $anna = new class
    {
        public function getKey(): int
        {
            return 7;
        }
    };
    $luca = new class
    {
        public function getKey(): int
        {
            return 8;
        }
    };

    $avvisi = [];

    $risultato = $metodo->invokeArgs(new LivelloSaldi, [[
        [$uno, $immobile, $anna],
        [$due, $immobile, $luca],
    ], &$avvisi]);

    expect($risultato)->toHaveCount(2)
        ->and($avvisi)->toBe([]);
});
