<?php

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Tabella;
use App\Services\CalcoloQuoteService;
use App\Services\Gestionale\FatturaPassivaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

require_once __DIR__ . '/GestionaleTestHelpers.php';

// =============================================================================
// HELPER DI SCENARIO
// =============================================================================

/**
 * Condominio contabile completo + un immobile con proprietario attivo + una
 * tabella millesimale collegata al capitolo "Manutenzione Test".
 *
 * @return array{condominio: \App\Models\Condominio, esercizio: mixed, gestione: mixed,
 *   fornitore: mixed, capitolo: \App\Models\Gestionale\Conto, immobileId: int,
 *   tabella: Tabella, anagraficaId: int}
 */
function baseStraordinario(): array
{
    [$condominio, $esercizio, $gestione, $fornitore, $capitolo, , $immobileId] = setupContabile();

    $tabella = Tabella::create([
        'condominio_id' => $condominio->id,
        'nome'          => 'Tabella Generale',
        'tipo'          => 'standard',
        'quota'         => 'millesimi',
        'attiva'        => true,
    ]);

    DB::table('quote_tabella')->insert([
        'tabella_id'  => $tabella->id,
        'immobile_id' => $immobileId,
        'valore'      => 1000.0,
        'created_at'  => now(),
        'updated_at'  => now(),
    ]);

    $anagrafica = Anagrafica::factory()->create();
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $anagrafica->id,
        'immobile_id'   => $immobileId,
        'tipologia'     => 'proprietario',
        'quota'         => 100,
        'attivo'        => true,
        'data_inizio'   => now(),
    ]);

    // Collega il capitolo esistente alla tabella (coeff 100%, ripartizione proprietario 100%)
    $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
        'conto_id'     => $capitolo->id,
        'tabella_id'   => $tabella->id,
        'coefficiente' => 100,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
    DB::table('conto_tabella_ripartizioni')->insert([
        'conto_tabella_millesimale_id' => $pivotId,
        'soggetto'                     => 'proprietario',
        'percentuale'                  => 100,
        'created_at'                   => now(),
        'updated_at'                   => now(),
    ]);

    return [
        'condominio'   => $condominio,
        'esercizio'    => $esercizio,
        'gestione'     => $gestione,
        'fornitore'    => $fornitore,
        'capitolo'     => $capitolo,
        'immobileId'   => $immobileId,
        'tabella'      => $tabella,
        'anagraficaId' => $anagrafica->id,
    ];
}

/** Fattura PREGRESSA senza coperture esplicite → 1.000,00 € di sopravvenienza su conto dinamico con tabella. */
function registraPregresso(array $base): FatturaPassiva
{
    return (new FatturaPassivaService())->registraFattura(
        datiBase([$base['condominio'], $base['esercizio'], $base['gestione'], $base['fornitore']], [
            'is_pregresso'           => true,
            'imponibile_pregresso'   => 1000.00,
            'aliquota_iva_pregressa' => 0,
            'coperture'              => [],
            'dati_extra'             => [
                'fiscal' => [], 'competenza' => null, 'override_budget' => null,
                'log_legale_sopravvenienza' => [
                    'origine_decisionale'      => 'gestione_corrente',
                    'motivazione_sforo'        => 'Debito pregresso fornitore',
                    'tipo_ripartizione'        => 'millesimale',
                    'nome_voce'                => 'Debito Pregresso Straordinario',
                    'tabella_millesimale_id'   => $base['tabella']->id,
                    'percentuale_proprietario' => 100,
                ],
            ],
        ]),
        $base['condominio']->id
    );
}

/** Fattura corrente minimale (header valido, is_pregresso=false), righe inserite a parte. */
function fatturaCorrente(array $base): FatturaPassiva
{
    return FatturaPassiva::create([
        'condominio_id'      => $base['condominio']->id,
        'fornitore_id'       => $base['fornitore']->id,
        'esercizio_id'       => $base['esercizio']->id,
        'tipo_documento'     => 'fattura',
        'numero_documento'   => 'FT-' . uniqid(),
        'data_documento'     => now()->format('Y-m-d'),
        'data_scadenza'      => now()->addDays(30)->format('Y-m-d'),
        'is_pregresso'       => false,
        'importo_imponibile' => 0,
        'importo_iva'        => 0,
        'importo_ritenuta'   => 0,
        'totale_documento'   => 0,
        'netto_a_pagare'     => 0,
        'stato_pagamento'    => 'aperta',
        'stato_approvazione' => 'approvata',
        'modalita_pagamento' => 'bonifico',
    ]);
}

/** Inserisce righe controllate (importi in centesimi). */
function inserisciRighe(int $fatturaId, array $righe): void
{
    foreach ($righe as $r) {
        DB::table('righe_fattura')->insert([
            'fattura_passiva_id' => $fatturaId,
            'conto_id'           => $r['conto_id'] ?? null,
            'immobile_id'        => $r['immobile_id'] ?? null,
            'descrizione'        => $r['descrizione'] ?? 'Riga test',
            'aliquota_iva'       => 0,
            'importo_imponibile' => $r['importo'],
            'importo_iva'        => 0,
            'is_sopravvenienza'  => $r['is_sopravvenienza'] ?? false,
            'is_rateizzata'      => false,
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }
}

/** Crea un piano straordinario e vi collega la fattura con l'importo indicato. */
function pianoStraordinario(array $base, FatturaPassiva $fattura, int $importoCollegato): PianoRate
{
    $piano = PianoRate::create([
        'gestione_id'   => $base['gestione']->id,
        'condominio_id' => $base['condominio']->id,
        'nome'          => 'Piano Straordinario Test',
        'stato'         => 'bozza',
        'tipo'          => 'straordinario',
    ]);

    $piano->fatture()->attach($fattura->id, ['importo_collegato' => $importoCollegato]);

    return $piano;
}

/** Somma tutte le quote calcolate (centesimi). */
function sommaTotali(array $totali): int
{
    $tot = 0;
    foreach ($totali as $perImmobile) {
        foreach ($perImmobile as $importo) {
            $tot += $importo;
        }
    }
    return $tot;
}

// =============================================================================
// FATTURE PREGRESSE (gap originale)
// =============================================================================

test('pregressa: ripartisce la sopravvenienza (nessuna riga_fattura) sul proprietario', function () {
    $base    = baseStraordinario();
    $fattura = registraPregresso($base);

    expect($fattura->righe()->count())->toBe(0);
    $naturale = (int) $fattura->coperture()->where('tipo_copertura', 'sopravvenienza')->sum('importo');
    expect($naturale)->toBe(100000);

    $piano  = pianoStraordinario($base, $fattura, $naturale);
    $totali = app(CalcoloQuoteService::class)->calcolaDaFattureStraordinarie($piano);

    expect($totali)->not->toBeEmpty()
        ->and(sommaTotali($totali))->toBe(100000)
        ->and($totali)->toHaveKey($base['anagraficaId']);
});

test('pregressa: la generazione del piano non lancia più la RuntimeException', function () {
    $base   = baseStraordinario();
    $piano  = pianoStraordinario($base, registraPregresso($base), 100000);

    // Dalla beta.35 (decisione 26) una pregressa senza periodo chiede la presa d'atto al cancello (2): il piano non sa
    // se nell'anno in cui il costo è maturato l'unità era di qualcun altro. `registraPregresso` passa dal servizio,
    // senza periodo, come le pregresse registrate prima della beta.35.
    $stats = app(GeneratePianoRateAction::class)->execute($piano, accettaScoperti: false, accettaDestinatari: true, notaDestinatari: 'Pregressa registrata senza periodo, letto il cancello');

    expect($stats)->toHaveKey('piano_rate_id')
        ->and($stats['piano_rate_id'])->toBe($piano->id);
});

// =============================================================================
// PUNTO 2 — importo_collegato rispettato (finanziamento parziale + fallback)
// =============================================================================

test('finanziamento parziale: distribuisce esattamente importo_collegato', function () {
    $base  = baseStraordinario();
    $piano = pianoStraordinario($base, registraPregresso($base), 40000); // finanzio 400 di 1000

    $totali = app(CalcoloQuoteService::class)->calcolaDaFattureStraordinarie($piano);

    expect(sommaTotali($totali))->toBe(40000);
});

test('fallback difensivo: importo_collegato=0 distribuisce il totale naturale', function () {
    $base  = baseStraordinario();
    $piano = pianoStraordinario($base, registraPregresso($base), 0); // dato storico/mancante

    $totali = app(CalcoloQuoteService::class)->calcolaDaFattureStraordinarie($piano);

    expect(sommaTotali($totali))->toBe(100000);
});

// =============================================================================
// FATTURE CORRENTI
// =============================================================================

test('corrente pura: distribuisce la riga sopravvenienza sul proprietario', function () {
    $base    = baseStraordinario();
    $fattura = fatturaCorrente($base);
    inserisciRighe($fattura->id, [
        ['conto_id' => $base['capitolo']->id, 'importo' => 50000, 'is_sopravvenienza' => true],
    ]);

    $piano  = pianoStraordinario($base, $fattura, 50000);
    $totali = app(CalcoloQuoteService::class)->calcolaDaFattureStraordinarie($piano);

    expect(sommaTotali($totali))->toBe(50000);
});

test('PUNTO 1 — corrente mista: le righe ordinarie NON entrano nello straordinario', function () {
    $base    = baseStraordinario();
    $fattura = fatturaCorrente($base);
    inserisciRighe($fattura->id, [
        // Riga ORDINARIA (capitolo a preventivo, non sopravvenienza, no immobile) → da ESCLUDERE
        ['conto_id' => $base['capitolo']->id, 'importo' => 30000, 'is_sopravvenienza' => false],
        // Riga STRAORDINARIA (sopravvenienza) → da includere
        ['conto_id' => $base['capitolo']->id, 'importo' => 50000, 'is_sopravvenienza' => true],
    ]);

    // importo_collegato=0 → fallback al naturale FILTRATO: isola l'effetto del filtro.
    // Col vecchio comportamento (tutte le righe) sarebbe 80000; col filtro corretto è 50000.
    $piano  = pianoStraordinario($base, $fattura, 0);
    $totali = app(CalcoloQuoteService::class)->calcolaDaFattureStraordinarie($piano);

    expect(sommaTotali($totali))->toBe(50000);
});

test('corrente ad personam: la riga con immobile_id viene addebitata diretta', function () {
    $base    = baseStraordinario();
    $fattura = fatturaCorrente($base);
    inserisciRighe($fattura->id, [
        ['immobile_id' => $base['immobileId'], 'importo' => 70000, 'is_sopravvenienza' => false],
    ]);

    $piano  = pianoStraordinario($base, $fattura, 70000);
    $totali = app(CalcoloQuoteService::class)->calcolaDaFattureStraordinarie($piano);

    expect(sommaTotali($totali))->toBe(70000)
        ->and($totali)->toHaveKey($base['anagraficaId']);
});

// =============================================================================
// REGRESSIONE — revisione avversariale beta.26 (netting del già-versato)
// =============================================================================

/**
 * `nettingGiaVersato()` rileggeva l'INTERA copertura storica ad ogni chiamata di
 * distribuisciSuTabelle(): se lo stesso conto era raggiunto da PIÙ componenti
 * nella STESSA esecuzione di calcolaDaFattureStraordinarie() (due fatture con una
 * riga sopravvenienza ciascuna sul medesimo capitolo, qui), la copertura veniva
 * sottratta una volta per fattura invece che una volta sola per conto.
 */
test('BUCO B RISOLTO: due fatture straordinarie sullo stesso conto non duplicano la copertura nella stessa chiamata', function () {
    $base = baseStraordinario();
    $base['capitolo']->forceFill(['importo' => 100_000])->save(); // budget nominale noto

    \App\Models\Gestionale\ContributoVersato::create([
        'condominio_id' => $base['condominio']->id,
        'target_type'   => \App\Models\Gestionale\Conto::class,
        'target_id'     => $base['capitolo']->id,
        'immobile_id'   => $base['immobileId'],
        'importo_cents' => 30_000, // €300 già versati
        'natura'        => 'fondo_vincolato',
    ]);

    // DUE fatture correnti, ciascuna con una riga sopravvenienza da €500 sullo
    // STESSO capitolo, entrambe finanziate al 100% dallo stesso piano.
    $fattura1 = fatturaCorrente($base);
    inserisciRighe($fattura1->id, [
        ['conto_id' => $base['capitolo']->id, 'importo' => 50_000, 'is_sopravvenienza' => true],
    ]);
    $fattura2 = fatturaCorrente($base);
    inserisciRighe($fattura2->id, [
        ['conto_id' => $base['capitolo']->id, 'importo' => 50_000, 'is_sopravvenienza' => true],
    ]);

    $piano = PianoRate::create([
        'gestione_id'   => $base['gestione']->id,
        'condominio_id' => $base['condominio']->id,
        'nome'          => 'Piano Straordinario Due Fatture',
        'stato'         => 'bozza',
        'tipo'          => 'straordinario',
    ]);
    $piano->fatture()->attach($fattura1->id, ['importo_collegato' => 50_000]);
    $piano->fatture()->attach($fattura2->id, ['importo_collegato' => 50_000]);

    $totali = app(CalcoloQuoteService::class)->calcolaDaFattureStraordinarie($piano);

    // Lordo totale €1.000, copertura €300: il dovuto vero è €700. Prima della
    // correzione ogni fattura sottraeva l'intera copertura dal proprio lordo
    // (€500 − €300 = €200 ciascuna, totale €400 invece di €700).
    expect(sommaTotali($totali))->toBe(70_000);
});

test('E2E — finanziamento parziale di fattura corrente: le rate generate sommano importo_collegato', function () {
    $base    = baseStraordinario();
    $fattura = fatturaCorrente($base);
    // Riga straordinaria da 1.000,00 €, ma il piano ne finanzia solo 400,00 €
    inserisciRighe($fattura->id, [
        ['conto_id' => $base['capitolo']->id, 'importo' => 100000, 'is_sopravvenienza' => true],
    ]);

    $piano = pianoStraordinario($base, $fattura, 40000);

    $stats = app(GeneratePianoRateAction::class)->execute($piano, accettaScoperti: false);
    expect($stats)->toHaveKey('piano_rate_id');

    // La somma delle quote effettivamente generate (rate reali, esclusa rata 0 saldi)
    // deve corrispondere ESATTAMENTE all'importo finanziato dal piano.
    $totaleQuote = (int) DB::table('rate_quote')
        ->join('rate', 'rate_quote.rata_id', '=', 'rate.id')
        ->where('rate.piano_rate_id', $piano->id)
        ->sum('rate_quote.importo');

    expect($totaleQuote)->toBe(40000);
});

// =============================================================================
// CONCORDANZA CON LA STAMPA — coda ⑩, beta.49
// =============================================================================

/**
 * Il riparto **stampato** di un piano straordinario coincide con quello **addebitato**.
 *
 * ## Perché questi test stanno qui
 *
 * Le quattro scene che servono sono già montate in questo file — pregressa, finanziamento
 * parziale, fattura mista, addebito ad personam — e sono le stesse quattro su cui la stampa
 * sbaglia. Mancava solo puntarle sull'altra metà: **ogni test qui sopra interroga il motore e
 * nessuno ha mai aperto il PDF.** È esattamente per questo che le divergenze sono sopravvissute.
 *
 * ## Cosa sbaglia la stampa, e perché è la stessa causa quattro volte
 *
 * `RipartoTabelleService` non chiede al motore quanto ha distribuito: se lo ricostruisce da sé,
 * con venti righe che leggono `righe_fattura`. Quelle venti righe sono un rimpiazzo ingenuo di
 * `calcolaDaFattureStraordinarie()`, e ne sbagliano quattro cose:
 *
 * - prendono **tutte** le righe con un conto, comprese le ordinarie che dello straordinario non
 *   fanno parte;
 * - sommano il totale naturale della fattura, ignorando `importo_collegato`, cioè la parte che
 *   *questo* piano finanzia;
 * - non conoscono `fattura_coperture`, quindi su una **pregressa** — che non ha righe — il
 *   documento esce bianco;
 * - scartano le righe ad personam, oppure, se hanno anche un conto, le **spalmano su tutti**.
 *
 * L'invariante che li accomuna è uno solo, ed è quello che asseriscono: *il gran totale del
 * documento è quanto il condominio deve davvero*.
 */
function granTotaleStampa(PianoRate $piano): int
{
    return (new \App\Services\RipartoTabelleService())->buildMatrice($piano)['gran_totale'];
}

test('concordanza pregressa: il riparto stampato non è un foglio bianco', function () {
    $base  = baseStraordinario();
    $piano = pianoStraordinario($base, registraPregresso($base), 100000);
    app(GeneratePianoRateAction::class)->execute($piano, accettaDestinatari: true, notaDestinatari: 'Pregressa registrata senza periodo, letto il cancello'); // decisione 26: pregressa senza periodo

    // Una pregressa non ha `righe_fattura`: la stampa restituiva `empty()` e il PDF usciva
    // completamente vuoto, mentre le rate erano state emesse correttamente. È lo scenario della
    // migrazione dello storico, cioè la funzione su cui si gioca l'importatore.
    expect(granTotaleStampa($piano))->toBe(100000);

    // E il denaro sta nella colonna della tabella, non in una pseudo-colonna: la pregressa è una
    // spesa comune ripartita a millesimi, non un addebito personale.
    $matrice  = (new \App\Services\RipartoTabelleService())->buildMatrice($piano);
    $soggetto = array_values($matrice['righe'][$base['immobileId']]['soggetti'])[0];

    expect($soggetto['per_tabella'][$base['tabella']->id]['importo'] ?? null)->toBe(100000);
});

test('concordanza finanziamento parziale: si stampa la parte finanziata, non l\'intera fattura', function () {
    $base  = baseStraordinario();
    $piano = pianoStraordinario($base, registraPregresso($base), 40000);
    app(GeneratePianoRateAction::class)->execute($piano, accettaDestinatari: true, notaDestinatari: 'Pregressa registrata senza periodo, letto il cancello'); // decisione 26: pregressa senza periodo

    // La stampa sommava `imponibile + iva` senza guardare `importo_collegato`: su una fattura da
    // € 1.000,00 finanziata per € 400,00 mostrava il riparto di tutti e mille.
    expect(granTotaleStampa($piano))->toBe(40000);
});

test('concordanza fattura mista: le righe ordinarie restano fuori dallo straordinario', function () {
    $base    = baseStraordinario();
    $fattura = fatturaCorrente($base);
    inserisciRighe($fattura->id, [
        ['conto_id' => $base['capitolo']->id, 'is_sopravvenienza' => true,  'immobile_id' => null, 'importo' => 100000],
        ['conto_id' => $base['capitolo']->id, 'is_sopravvenienza' => false, 'immobile_id' => null, 'importo' => 50000],
    ]);

    $piano = pianoStraordinario($base, $fattura, 100000);
    app(GeneratePianoRateAction::class)->execute($piano);

    // Il motore filtra `is_sopravvenienza = true OR immobile_id NOT NULL`; la stampa prendeva
    // tutte le righe con un conto, e i € 500,00 ordinari gonfiavano il documento.
    expect(granTotaleStampa($piano))->toBe(100000);
});

test('concordanza ad personam: la spesa personale non finisce addosso a tutti', function () {
    $base    = baseStraordinario();
    $fattura = fatturaCorrente($base);
    inserisciRighe($fattura->id, [
        ['conto_id' => $base['capitolo']->id, 'is_sopravvenienza' => false, 'immobile_id' => $base['immobileId'], 'importo' => 100000],
    ]);

    $piano = pianoStraordinario($base, $fattura, 100000);
    app(GeneratePianoRateAction::class)->execute($piano);

    // Il motore manda la riga ad `addebitaDiretto()`: la paga chi ha quell'unità. La stampa la
    // trattava come importo di capitolo e la spalmava sulla tabella millesimale — su un
    // condominio vero, la riparazione del balcone dell'interno 4 la vedevano ripartita tutti.
    expect(granTotaleStampa($piano))->toBe(100000);

    // ⚠️ Il gran totale da solo non basterebbe: viene da `rate_quote` e sarebbe giusto anche con
    // le celle vuote. Questa dice che il documento **mostra** l'addebito nella sua colonna, e che
    // non è finito sulla tabella millesimale.
    $matrice  = (new \App\Services\RipartoTabelleService())->buildMatrice($piano);
    $soggetto = array_values($matrice['righe'][$base['immobileId']]['soggetti'])[0];

    expect($soggetto['per_tabella'][\App\Services\RipartoTabelleService::COLONNA_DIRETTO]['importo'] ?? null)
        ->toBe(100000)
        ->and($soggetto['per_tabella'][$base['tabella']->id]['importo'] ?? 0)->toBe(0);
});

// =============================================================================
// FASE 1-bis DELLA BETA.18 — il segno delle righe, fin qui buttato via
// =============================================================================

/**
 * Rilievo 5 — qui si generano le quote VERE che i condòmini pagano.
 *
 * `calcolaDaFattureStraordinarie()` prendeva il valore assoluto di ogni riga prima di sommarla,
 * quindi una rettifica in diminuzione veniva ADDEBITATA invece che accreditata. Con righe di
 * sopravvenienza +€ 1.200,00 e −€ 200,00 sullo stesso capitolo il naturale risultava € 1.400,00
 * invece di € 1.000,00: i condòmini pagavano € 400,00 più del documento, cioè due volte lo
 * storno — la stessa firma aritmetica del difetto corretto nel motore contabile.
 *
 * ⚠️ Il difetto era **latente** fino alla beta.17, perché una fattura con una riga negativa non
 * si registrava affatto. È la correzione di questa beta ad averlo reso raggiungibile.
 */
test('una rettifica in diminuzione riduce le quote invece di aumentarle', function () {
    $base    = baseStraordinario();
    $fattura = fatturaCorrente($base);

    inserisciRighe($fattura->id, [
        ['conto_id' => $base['capitolo']->id, 'importo' => 120000, 'is_sopravvenienza' => true,
            'descrizione' => 'Intervento straordinario'],
        ['conto_id' => $base['capitolo']->id, 'importo' => -20000, 'is_sopravvenienza' => true,
            'descrizione' => 'Rettifica in diminuzione'],
    ]);

    // importo_collegato = 0 → il fallback distribuisce il totale naturale, che è il ramo in cui
    // il difetto si vedeva per intero.
    $piano  = pianoStraordinario($base, $fattura, 0);
    $totali = app(CalcoloQuoteService::class)->calcolaDaFattureStraordinarie($piano);

    expect(sommaTotali($totali))->toBe(100000, 'Le quote devono valere il netto del documento, non la somma dei valori assoluti');
});

/**
 * Rilievo 4 — lo storno di una fattura PREGRESSA non toccava il giornale.
 *
 * `registraFattura()` scriveva `imponibile_pregresso` e `aliquota_iva_pregressa` in `create()`,
 * ma non sono colonne di `fatture_passive` e il modello ha `$guarded = ['id']`: Eloquent le
 * scartava in silenzio, quindi rileggerle dava sempre `null`. `StornoFatturaController` leggeva
 * proprio quelle, otteneva zero, e generava una nota di credito VUOTA — la fattura risultava
 * stornata e il debito restava a bilancio, senza che niente lo segnalasse.
 *
 * ⚠️ Questo difetto è **indipendente dalla beta.18** e la precede: vale per ogni pregressa,
 * righe negative o no. Chiuso qui su decisione di Vincenzo perché è una perdita di dati
 * silenziosa e stava a una riga dal codice già in mano.
 */
test('lo storno di una fattura pregressa genera una nota di credito del suo importo, non vuota', function () {
    $permesso = Spatie\Permission\Models\Permission::firstOrCreate(
        ['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']
    );
    $ruolo = Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $utente = App\Models\User::factory()->create();
    $utente->assignRole($ruolo);

    $base     = baseStraordinario();
    $pregressa = registraPregresso($base);

    expect((int) $pregressa->netto_a_pagare)->toBe(100000);

    $risposta = test()->actingAs($utente)->post(
        route('admin.gestionale.fatture.storno', [$base['condominio']->id, $pregressa->id])
    );
    $risposta->assertSessionHasNoErrors();

    $nc = FatturaPassiva::where('condominio_id', $base['condominio']->id)
        ->where('tipo_documento', 'nota_credito')
        ->latest('id')->first();

    expect($nc)->not->toBeNull()
        ->and((int) $nc->netto_a_pagare)->toBe(-100000, 'La nota di credito deve valere quanto la pregressa che annulla');

    // E deve aver toccato il giornale: una NC senza scrittura è il difetto stesso.
    $righeNc = DB::table('fattura_scrittura')
        ->where('fattura_passiva_id', $nc->id)
        ->pluck('scrittura_contabile_id');

    expect($righeNc)->not->toBeEmpty();
    expect((int) DB::table('righe_scritture')->whereIn('scrittura_id', $righeNc)->sum('importo'))
        ->toBeGreaterThan(0, 'Lo storno non ha scritto nulla a giornale');
});

/**
 * ⚠️ **Il carrello dello straordinario continuava a offrire una fattura stornata.**
 *
 * Nessuna delle due query di `FetchFattureStraordinarieController` escludeva le stornate.
 * Misurato sulla rotta vera, prima della correzione: stornata una pregressa da € 610,00, il
 * carrello la elencava identica — `residuo_da_finanziare: 610`, `importo_suggerito: 610` — e
 * generando il piano su quella riga i soldi venivano **addebitati ai proprietari per un
 * documento annullato** (€ 1.000,00 su un proprietario, nella misura del revisore).
 *
 * ⚠️ **Il ramo `stato_pagamento` era già lì e non veniva letto**: lo storno imposta
 * `stato_pagamento = stornata`, quindi il dato per escluderla esisteva da sempre.
 *
 * ⚠️ **Il controllo PRIMA non è decorazione.** Senza di esso il test sarebbe verde anche con un
 * carrello rotto che non offre nulla a nessuno — cioè proverebbe la correzione con lo stesso
 * silenzio che un difetto peggiore produrrebbe.
 *
 * Difetto preesistente alla beta.22, chiuso lì su decisione di Vincenzo: la beta rende lo storno
 * di una pregressa capace di azzerare il capitolo che aveva inventato, e annunciarlo lasciando il
 * carrello a chiedere quei soldi sarebbe stato vero a metà proprio sul denaro.
 */
test('una pregressa stornata sparisce dal carrello dello straordinario', function () {
    $permesso = Spatie\Permission\Models\Permission::firstOrCreate(
        ['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']
    );
    $ruolo = Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $utente = App\Models\User::factory()->create();
    $utente->assignRole($ruolo);

    $base      = baseStraordinario();
    $pregressa = registraPregresso($base);

    $url = route('admin.gestionale.fetch-fatture-straordinarie', $base['condominio']->id)
        .'?esercizio_id='.$base['esercizio']->id
        .'&gestione_id='.$base['gestione']->id;

    // PRIMA: il carrello la offre davvero. Senza questa riga il test non prova niente.
    $prima = test()->actingAs($utente)->getJson($url)->assertOk()->json();
    // ⚠️ `toContain()` è variadico: passargli un messaggio lo trasforma in un secondo elemento
    // da cercare, e il test fallisce dicendo il falso. Qui serve una forma che il messaggio lo
    // accetti davvero.
    $idPrima = collect($prima)->pluck('id')->all();
    expect(in_array($pregressa->id, $idPrima, true))
        ->toBeTrue('il carrello non offriva la fattura nemmeno prima: lo scenario non è quello che credo');

    test()->actingAs($utente)->post(
        route('admin.gestionale.fatture.storno', [$base['condominio']->id, $pregressa->id])
    )->assertSessionHasNoErrors();

    expect($pregressa->fresh()->stato_pagamento->value)->toBe('stornata', 'lo storno non è avvenuto');

    // DOPO: non deve più comparire, a nessun importo.
    $dopo = test()->actingAs($utente)->getJson($url)->assertOk()->json();
    $idDopo = collect($dopo)->pluck('id')->all();
    expect(in_array($pregressa->id, $idDopo, true))
        ->toBeFalse('il carrello offre ancora una fattura annullata: generando il piano quei soldi finiscono addosso ai proprietari');
});

/*
|--------------------------------------------------------------------------
| Decisione 26, punti 2 e 3 (1.11.0-beta.35) — il periodo della pregressa
|--------------------------------------------------------------------------
|
| Una pregressa non si modifica dopo la registrazione (si storna), e il periodo in cui il costo è maturato lo conosce solo
| chi registra: la «data di origine del debito» è un'altra cosa. Quindi, quando la pregressa ha una parte non coperta dai
| saldi iniziali — la sola che finisce in un piano — il periodo è obbligatorio e si chiude prima dell'esercizio in cui la
| fattura si registra. Le pregresse già registrate senza periodo il carrello le segnala.
|
| Passano dalla ROTTA: la regola sta nella richiesta, non nel servizio, perché lo storno e questi test chiamano il servizio
| direttamente. Gli asserti guardano solo le chiavi in prova (`competenza_dal`, `competenza_al`), per non legarsi a ogni
| altro campo che la richiesta chiederà in futuro.
*/

function utenteAdminPregressa(): App\Models\User
{
    $permesso = Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $utente = App\Models\User::factory()->create();
    $utente->assignRole($ruolo);

    return $utente;
}

/**
 * Il corpo della richiesta per una pregressa da € 1.000,00 scoperta (nessuna copertura): tutta sopravvenienza.
 *
 * ⚠️ Con `applica_ritenuta` a false la richiesta pretende il motivo dell'esclusione: senza, i casi «va bene» qui sotto
 * venivano respinti per quel campo e passavano lo stesso, perché guardavano solo gli errori sulla competenza — nessuna
 * fattura registrata e test verde (R14 della Fase 1-bis). I casi validi ora controllano che la fattura esista.
 */
function corpoPregressaScoperta(array $base, array $extra = []): array
{
    return array_replace_recursive(datiBase([$base['condominio'], $base['esercizio'], $base['gestione'], $base['fornitore']], [
        'data_documento' => '2025-11-20', 'data_scadenza' => '2025-12-20', 'stato_approvazione' => 'approvata', 'applica_ritenuta' => false,
        'is_pregresso' => true, 'imponibile_pregresso' => 1000.00, 'aliquota_iva_pregressa' => 0, 'coperture' => [], 'righe' => [],
        'dati_extra' => ['fiscal' => ['motivo_esclusione_ritenuta' => 'fuori_campo'], 'competenza' => null, 'override_budget' => null, 'log_legale_sopravvenienza' => [
            'nome_voce' => 'Debito pregresso manutenzioni', 'origine_decisionale' => 'gestione_corrente', 'tipo_ripartizione' => 'millesimale',
            'is_ordinario' => true, 'richiede_copertura' => true, 'motivazione_sforo' => 'Fattura del 2025 arrivata dopo la chiusura',
            'tabella_millesimale_id' => $base['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        ]],
    ]), $extra);
}

test('decisione 26 [beta.35] — una pregressa con una parte non coperta non si registra senza il periodo in cui il costo è maturato', function () {
    $base = baseStraordinario();
    $this->actingAs(utenteAdminPregressa())
        ->post(route('admin.gestionale.fatture.store', $base['condominio']->id), corpoPregressaScoperta($base))
        ->assertSessionHasErrors(['competenza_dal']);
});

test('decisione 26 [beta.35] — il periodo della pregressa si chiude prima dell\'esercizio in cui si registra: fino al 31/12/2025 va bene, fino al 15/01/2026 no', function () {
    $base = baseStraordinario();
    $utente = utenteAdminPregressa();
    $url = route('admin.gestionale.fatture.store', $base['condominio']->id);

    $this->actingAs($utente)->post($url, corpoPregressaScoperta($base, ['competenza_dal' => '2025-01-01', 'competenza_al' => '2026-01-15']))
        ->assertSessionHasErrors(['competenza_al']);
    $this->actingAs($utente)->post($url, corpoPregressaScoperta($base, ['competenza_dal' => '2025-01-01', 'competenza_al' => '2025-12-31']))
        ->assertSessionHasNoErrors();

    // Registrata davvero, con il periodo salvato e la parte scoperta come sopravvenienza.
    $fattura = FatturaPassiva::where('condominio_id', $base['condominio']->id)->where('is_pregresso', true)->sole();
    expect($fattura->competenza_dal?->format('Y-m-d') ?? $fattura->competenza_dal)->toBe('2025-01-01')
        ->and($fattura->competenza_al?->format('Y-m-d') ?? $fattura->competenza_al)->toBe('2025-12-31')
        ->and($fattura->coperture()->where('tipo_copertura', 'sopravvenienza')->sum('importo'))->toEqual(100000);
});

test('decisione 26 [beta.35] — una pregressa TUTTA coperta dai saldi iniziali non va in nessun piano: il periodo resta facoltativo; e una fattura corrente non lo chiede', function () {
    $base = baseStraordinario();
    $utente = utenteAdminPregressa();
    $url = route('admin.gestionale.fatture.store', $base['condominio']->id);

    $coperta = corpoPregressaScoperta($base, ['coperture' => [['tipo_copertura' => 'rata_0', 'importo' => 1000.00, 'fonte_id' => null]]]);
    unset($coperta['dati_extra']['log_legale_sopravvenienza']);
    $this->actingAs($utente)->post($url, $coperta)->assertSessionHasNoErrors();
    expect(FatturaPassiva::where('condominio_id', $base['condominio']->id)->where('is_pregresso', true)->count())->toBe(1, 'la pregressa tutta coperta non è stata registrata');

    $corrente = datiBase([$base['condominio'], $base['esercizio'], $base['gestione'], $base['fornitore']], [
        'stato_approvazione' => 'approvata', 'applica_ritenuta' => false, 'dati_extra' => ['fiscal' => ['motivo_esclusione_ritenuta' => 'fuori_campo']],
        'righe' => [['descrizione' => 'Manutenzione', 'importo_imponibile' => 100, 'aliquota_iva' => 22, 'conto_id' => $base['capitolo']->id, 'is_sopravvenienza' => false]],
    ]);
    $this->actingAs($utente)->post($url, $corrente)->assertSessionHasNoErrors();
    expect(FatturaPassiva::where('condominio_id', $base['condominio']->id)->where('is_pregresso', false)->count())->toBe(1, 'la fattura corrente non è stata registrata');
});

test('R7 [beta.35] — la regola confronta date, non stringhe: un periodo scritto «gg-mm-aaaa» si giudica come quello ISO, in tutti e due i versi', function () {
    $base = baseStraordinario();
    $utente = utenteAdminPregressa();
    $url = route('admin.gestionale.fatture.store', $base['condominio']->id);

    // Dentro l'esercizio (inizia il 01/01/2026): va respinto anche se scritto all'europea.
    $this->actingAs($utente)->post($url, corpoPregressaScoperta($base, ['competenza_dal' => '01-02-2026', 'competenza_al' => '15-06-2026']))
        ->assertSessionHasErrors(['competenza_al']);
    // Prima dell'esercizio: va accettato anche se scritto all'europea.
    $this->actingAs($utente)->post($url, corpoPregressaScoperta($base, ['competenza_dal' => '01-01-2025', 'competenza_al' => '31-12-2025']))
        ->assertSessionDoesntHaveErrors(['competenza_dal', 'competenza_al']);
    // Il primo giorno dell'esercizio con un fuso orario positivo: è il 01/01, non il 31/12 in UTC (verifica delle correzioni).
    $this->actingAs($utente)->post($url, corpoPregressaScoperta($base, ['competenza_dal' => '2025-01-01', 'competenza_al' => '2026-01-01T00:00:00+01:00']))
        ->assertSessionHasErrors(['competenza_al']);
});

test('R8 [beta.35] — una pregressa con «coperture» che non è una lista riceve un errore di validazione, non un 500', function () {
    $base = baseStraordinario();

    $this->actingAs(utenteAdminPregressa())
        ->post(route('admin.gestionale.fatture.store', $base['condominio']->id), corpoPregressaScoperta($base, ['coperture' => 'x']))
        ->assertStatus(302)
        ->assertSessionHasErrors(['coperture']);
});

test('decisione 26 [beta.35] — anche una copertura «sopravvenienza» messa a mano nella richiesta (la seconda porta) vuole il periodo', function () {
    $base = baseStraordinario();
    $corpo = corpoPregressaScoperta($base, ['coperture' => [['tipo_copertura' => 'sopravvenienza', 'importo' => 1000.00, 'fonte_id' => $base['capitolo']->id]]]);
    unset($corpo['dati_extra']['log_legale_sopravvenienza']);
    $this->actingAs(utenteAdminPregressa())
        ->post(route('admin.gestionale.fatture.store', $base['condominio']->id), $corpo)
        ->assertSessionHasErrors(['competenza_dal']);
});

test('decisione 26 [beta.35] — il carrello segnala la pregressa già registrata senza periodo, e non quella con il periodo', function () {
    $utente = utenteAdminPregressa();
    $base = baseStraordinario();
    $senza = registraPregresso($base);
    $con = registraPregresso($base);
    $con->update(['competenza_dal' => '2025-01-01', 'competenza_al' => '2025-12-31']);

    $url = route('admin.gestionale.fetch-fatture-straordinarie', $base['condominio']->id)
        .'?esercizio_id='.$base['esercizio']->id.'&gestione_id='.$base['gestione']->id;
    $voci = collect($this->actingAs($utente)->getJson($url)->assertOk()->json())->keyBy('id');

    expect($voci[$senza->id]['is_pregresso'] ?? null)->toBeTrue()
        ->and($voci[$senza->id]['senza_periodo'] ?? null)->toBeTrue()
        ->and($voci[$con->id]['senza_periodo'] ?? null)->toBeFalse();
});

test('R2 [beta.35] — il carrello segnala la pregressa registrata con un periodo dentro l\'esercizio (la data dell\'assemblea che la finestra precompilava), e non quella chiusa prima', function () {
    $utente = utenteAdminPregressa();
    $base = baseStraordinario();
    $dentro = registraPregresso($base);
    $dentro->update(['competenza_dal' => '2026-03-10', 'competenza_al' => '2026-03-10']);
    $prima = registraPregresso($base);
    $prima->update(['competenza_dal' => '2025-01-01', 'competenza_al' => '2025-12-31']);
    $senza = registraPregresso($base);

    $url = route('admin.gestionale.fetch-fatture-straordinarie', $base['condominio']->id)
        .'?esercizio_id='.$base['esercizio']->id.'&gestione_id='.$base['gestione']->id;
    $voci = collect($this->actingAs($utente)->getJson($url)->assertOk()->json())->keyBy('id');

    expect($voci[$dentro->id]['periodo_nell_esercizio'] ?? null)->toBeTrue()
        ->and($voci[$prima->id]['periodo_nell_esercizio'] ?? null)->toBeFalse()
        ->and($voci[$senza->id]['periodo_nell_esercizio'] ?? null)->toBeFalse();
});

/*
| Trovato il 27/09/2026 da una sonda per l'articolo del sito sullo storno, dopo il commit della beta.35: con DUE fatture
| correnti fuori preventivo (o ad personam) il carrello rispondeva «Errore interno». La marcatura
| `->each(fn ($f) => $f->is_pregresso = false)` restituisce `false`, e `Collection::each()` si ferma al primo `false`: solo
| la prima fattura corrente aveva il campo, la seconda cadeva su «Undefined property». Le pregresse (`= true`) passavano,
| ed è per questo che i test del carrello, tutti con pregresse o con una sola corrente, erano verdi.
*/
test('carrello [beta.35] — con più fatture correnti fuori preventivo e ad personam il carrello le offre tutte, accanto alle pregresse', function () {
    $utente = utenteAdminPregressa();
    $base = baseStraordinario();
    $prima = fatturaCorrente($base);
    inserisciRighe($prima->id, [['conto_id' => $base['capitolo']->id, 'importo' => 50000, 'is_sopravvenienza' => true]]);
    $seconda = fatturaCorrente($base);
    inserisciRighe($seconda->id, [['conto_id' => $base['capitolo']->id, 'importo' => 30000, 'is_sopravvenienza' => true]]);
    $adPersonam = fatturaCorrente($base);
    inserisciRighe($adPersonam->id, [['conto_id' => $base['capitolo']->id, 'immobile_id' => $base['immobileId'], 'importo' => 12000]]);
    $pregressa = registraPregresso($base);

    $url = route('admin.gestionale.fetch-fatture-straordinarie', $base['condominio']->id)
        .'?esercizio_id='.$base['esercizio']->id.'&gestione_id='.$base['gestione']->id;
    $voci = collect($this->actingAs($utente)->getJson($url)->assertOk()->json())->keyBy('id');

    expect($voci->keys()->sort()->values()->all())->toBe(collect([$prima->id, $seconda->id, $adPersonam->id, $pregressa->id])->sort()->values()->all())
        ->and($voci[$seconda->id]['is_pregresso'])->toBeFalse()
        ->and($voci[$seconda->id]['senza_periodo'])->toBeFalse()
        ->and($voci[$adPersonam->id]['is_pregresso'])->toBeFalse()
        ->and($voci[$pregressa->id]['is_pregresso'])->toBeTrue()
        ->and($voci[$seconda->id]['residuo_da_finanziare'])->toEqual(300);
});

/*
| Coda 156 (1.11.0-beta.35) — «dentro un piano approvato la fattura non si modifica più» deve valere per OGNI piano che la
| contiene: una fattura si può dividere fra più piani (il carrello offre il residuo), e la guardia leggeva solo la prima
| riga della tabella ponte. La regola gemella dell'eliminazione (`FatturaPassiva::motivoBloccoEliminazione()`) li scorreva
| già tutti.
*/
test('Coda 156 [beta.35] — fattura divisa fra un piano in bozza (collegato per primo) e uno approvato: la modifica è bloccata e il motivo nomina il piano approvato', function () {
    $base = baseStraordinario();
    $fattura = fatturaCorrente($base);
    $nuovoPiano = fn (string $nome, string $stato) => PianoRate::create([
        'gestione_id' => $base['gestione']->id, 'condominio_id' => $base['condominio']->id, 'nome' => $nome, 'stato' => $stato,
        'tipo' => 'straordinario', 'numero_rate' => 2, 'metodo_distribuzione' => 'prima_rata',
    ]);
    $inBozza = $nuovoPiano('Facciata — prima parte', 'bozza');
    $approvato = $nuovoPiano('Facciata — seconda parte', 'approvato');
    $inBozza->fatture()->attach($fattura->id, ['importo_collegato' => 50000]);
    $approvato->fatture()->attach($fattura->id, ['importo_collegato' => 50000]);

    $motivo = (new FatturaPassivaService())->motivoBloccoModifica($fattura->fresh());
    expect($motivo)->not->toBeNull()->and($motivo)->toContain('Facciata — seconda parte');

    // Controllo: con tutti e due i piani in bozza la fattura resta modificabile.
    $approvato->update(['stato' => 'bozza']);
    expect((new FatturaPassivaService())->motivoBloccoModifica($fattura->fresh()))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| R1 della Fase 1-bis (1.11.0-beta.35) — lo storno di una fattura che sta in un piano
|--------------------------------------------------------------------------
|
| Lo storno non guardava i piani: la fattura annullata restava in `piano_rate_fatture`, il motore la leggeva senza
| escludere le stornate e le rate la chiedevano ancora. Registrata di nuovo e messa in un piano nuovo, i condòmini la
| pagavano due volte. La decisione 26 ci mandava l'amministratore col suo testo («storna e registra di nuovo»).
|
| Decisione di Vincenzo del 27/09/2026: lo storno segue la scala dell'eliminazione, con UNA regola per modifica,
| eliminazione e storno. Finché il piano non ha incassato niente lo storno si rifiuta e dice la via; con incassi è
| permesso, e la conferma avvisa che le rate restano. Un piano che contiene già una fattura stornata non si ricalcola.
*/

/**
 * Una pregressa da € 1.000,00 in un piano da fatture già generato, portato al grado chiesto: `bozza`, `approvato`,
 * `emesso` (una quota a giornale), `incassato` (una quota con un incasso).
 *
 * @return array{0: array, 1: FatturaPassiva, 2: PianoRate}
 */
function r1PianoConPregressa(string $grado): array
{
    $base = baseStraordinario();
    $pregressa = registraPregresso($base);
    $piano = pianoStraordinario($base, $pregressa, 100000);
    $piano->update(['nome' => 'Facciata 2026']);
    app(GeneratePianoRateAction::class)->execute($piano, accettaDestinatari: true, notaDestinatari: 'Pregressa registrata senza periodo, letto il cancello');

    $quotaId = DB::table('rate_quote')->join('rate', 'rate_quote.rata_id', '=', 'rate.id')
        ->where('rate.piano_rate_id', $piano->id)->orderBy('rate_quote.id')->value('rate_quote.id');
    expect($quotaId)->not->toBeNull('il piano non ha generato quote: lo scenario non è quello che credo');
    $scritturaId = DB::table('scritture_contabili')->orderBy('id')->value('id');

    match ($grado) {
        'bozza' => null,
        'approvato' => $piano->update(['stato' => 'approvato']),
        'emesso' => DB::table('rate_quote')->where('id', $quotaId)->update(['scrittura_contabile_id' => $scritturaId]),
        'incassato' => DB::table('rate_quote')->where('id', $quotaId)->update(['scrittura_contabile_id' => $scritturaId, 'importo_pagato' => 10000]),
    };
    if (in_array($grado, ['emesso', 'incassato'], true)) {
        $piano->update(['stato' => 'approvato']);
    }

    return [$base, $pregressa->fresh(), $piano->fresh()];
}

function r1TotaleQuote(PianoRate $piano): int
{
    return (int) DB::table('rate_quote')->join('rate', 'rate_quote.rata_id', '=', 'rate.id')
        ->where('rate.piano_rate_id', $piano->id)->sum('rate_quote.importo');
}

test('R1 [beta.35] — una fattura in un piano che non ha ancora incassato niente non si storna: il motivo nomina il piano e dice la via, per ogni grado', function (string $grado, string $via) {
    [$base, $pregressa, $piano] = r1PianoConPregressa($grado);
    $utente = utenteAdminPregressa();

    $risposta = $this->actingAs($utente)->post(route('admin.gestionale.fatture.storno', [$base['condominio']->id, $pregressa->id]));
    $risposta->assertSessionHasErrors('storno_vietato');
    $motivo = session('errors')->first('storno_vietato');

    expect($motivo)->toContain('«Facciata 2026»')->toContain($via)
        ->and($pregressa->fresh()->stato_pagamento->value)->toBe('aperta', 'lo storno è avvenuto lo stesso')
        ->and(FatturaPassiva::where('tipo_documento', 'nota_credito')->count())->toBe(0)
        // Il menu dell'elenco mostra lo stesso motivo che applica il server: è la stessa funzione.
        ->and($pregressa->fresh()->motivoBloccoStorno())->toBe($motivo);
})->with([
    'in bozza' => ['bozza', 'Elimina prima il piano'],
    'approvato, senza emissioni' => ['approvato', 'Riporta il piano in bozza ed eliminalo'],
    'con rate emesse ma senza incassi' => ['emesso', 'Annulla le emissioni'],
]);

test('R1 [beta.35] — seguita la via (il piano eliminato), la fattura si storna', function () {
    [$base, $pregressa, $piano] = r1PianoConPregressa('bozza');

    $piano->fattureStraordinarie()->detach();
    $piano->delete();

    $this->actingAs(utenteAdminPregressa())
        ->post(route('admin.gestionale.fatture.storno', [$base['condominio']->id, $pregressa->id]))
        ->assertSessionHasNoErrors();
    expect($pregressa->fresh()->stato_pagamento->value)->toBe('stornata');
});

test('R1 [beta.35] — con rate già incassate lo storno resta possibile, e l\'elenco porta l\'avviso che le rate del piano restano', function () {
    [$base, $pregressa, $piano] = r1PianoConPregressa('incassato');
    $utente = utenteAdminPregressa();

    $props = $this->actingAs($utente)->get(route('admin.gestionale.fatture.index', $base['condominio']->id))
        ->assertOk()->viewData('page')['props'];
    $riga = collect($props['fatture']['data'])->firstWhere('id', $pregressa->id);
    expect($riga['motivo_blocco_storno'])->toBeNull()
        ->and($riga['avviso_storno'])->toContain('«Facciata 2026»')->toContain('restano');

    $this->actingAs($utente)
        ->post(route('admin.gestionale.fatture.storno', [$base['condominio']->id, $pregressa->id]))
        ->assertSessionHasNoErrors();
    expect($pregressa->fresh()->stato_pagamento->value)->toBe('stornata');
});

test('R1 [beta.35] — modifica, eliminazione e storno non si contraddicono: dove una dice «usa lo storno», lo storno è permesso', function (string $grado) {
    [$base, $pregressa, $piano] = r1PianoConPregressa($grado);
    // Una fattura corrente nello stesso piano: la pregressa non si modifica mai, e qui serve la regola del piano.
    $corrente = fatturaCorrente($base);
    $piano->fatture()->attach($corrente->id, ['importo_collegato' => 0]);
    $corrente = $corrente->fresh();

    $modifica = (new FatturaPassivaService())->motivoBloccoModifica($corrente);
    $eliminazione = $corrente->motivoBloccoEliminazione();
    $storno = $corrente->motivoBloccoStorno();

    foreach (['modifica' => $modifica, 'eliminazione' => $eliminazione] as $azione => $motivo) {
        if ($motivo !== null && str_contains(mb_strtolower($motivo), 'storno')) {
            expect($storno)->toBeNull("la {$azione} manda allo storno ({$motivo}) e lo storno è rifiutato ({$storno})");
        }
    }
    match ($grado) {
        'bozza' => expect($modifica)->toBeNull()->and($eliminazione)->toBeNull()->and($storno)->toContain('Elimina prima il piano'),
        'approvato' => expect($modifica)->toContain('riportalo in bozza')->and($eliminazione)->toContain('Riporta il piano in bozza'),
        'emesso' => expect($modifica)->toContain('annulla le emissioni')->and($eliminazione)->toContain('Annulla le emissioni'),
        'incassato' => expect($modifica)->toContain('usa lo storno')->and($eliminazione)->toContain('usa lo storno')->and($storno)->toBeNull(),
    };
})->with(['bozza', 'approvato', 'emesso', 'incassato']);

test('R1 [beta.35] — un piano che contiene già una fattura stornata non si ricalcola: si ferma e la nomina, e le quote restano quelle di prima', function () {
    [$base, $pregressa, $piano] = r1PianoConPregressa('bozza');
    $prima = r1TotaleQuote($piano);
    expect($prima)->toBe(100000);

    // I dati di prima della beta: la fattura stornata quando lo storno non guardava i piani (StornoFatturaController).
    $pregressa->update(['stato_pagamento' => 'stornata', 'dati_extra' => array_merge($pregressa->dati_extra ?? [], ['is_stornata' => true])]);

    $this->actingAs(utenteAdminPregressa())
        ->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$base['condominio']->id, $base['esercizio']->id, $piano->id]), [
            'accetta_destinatari' => true, 'nota_destinatari' => 'Pregressa registrata senza periodo, letto il cancello',
        ])
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'error'
            && str_contains($m['message'], (string) $pregressa->numero_documento)
            && str_contains($m['message'], 'stornata'));

    expect(r1TotaleQuote($piano))->toBe($prima);
});

test('R1 [beta.35] — con la fattura in due piani conta il più avanzato: approvato (il primo) e uno che ha già incassato, la modifica manda allo storno e dice cosa fare prima', function () {
    $base = baseStraordinario();
    $corrente = fatturaCorrente($base);
    // Il piano approvato nasce per primo: la regola non deve fermarsi al primo piano che trova.
    $approvato = PianoRate::create([
        'gestione_id' => $base['gestione']->id, 'condominio_id' => $base['condominio']->id, 'nome' => 'Tetto — prima parte',
        'stato' => 'approvato', 'tipo' => 'straordinario', 'numero_rate' => 2, 'metodo_distribuzione' => 'prima_rata',
    ]);
    $approvato->fatture()->attach($corrente->id, ['importo_collegato' => 0]);

    $pregressa = registraPregresso($base);
    $incassato = pianoStraordinario($base, $pregressa, 100000);
    $incassato->update(['nome' => 'Tetto — seconda parte']);
    app(GeneratePianoRateAction::class)->execute($incassato, accettaDestinatari: true, notaDestinatari: 'Pregressa registrata senza periodo, letto il cancello');
    $quotaId = DB::table('rate_quote')->join('rate', 'rate_quote.rata_id', '=', 'rate.id')->where('rate.piano_rate_id', $incassato->id)->value('rate_quote.id');
    DB::table('rate_quote')->where('id', $quotaId)->update(['importo_pagato' => 10000]);
    $incassato->fatture()->attach($corrente->id, ['importo_collegato' => 0]);

    $modifica = (new FatturaPassivaService())->motivoBloccoModifica($corrente->fresh());

    // Il piano che ha incassato decide che la strada è lo storno; quello approvato va eliminato prima.
    expect($modifica)->toContain('«Tetto — prima parte»')->toContain('poi storna la fattura');
});

test('R4 [beta.35] — una pregressa che nel piano non ripartisce niente (copertura senza conto) non ferma il cancello con una frase falsa', function () {
    $base = baseStraordinario();
    $pregressa = registraPregresso($base);
    // La copertura «sopravvenienza» senza conto: il motore la scarta (whereNotNull), e la fattura contribuisce € 0,00.
    DB::table('fattura_coperture')->where('fattura_passiva_id', $pregressa->id)->update(['conto_id' => null]);
    $corrente = fatturaCorrente($base);
    inserisciRighe($corrente->id, [['conto_id' => $base['capitolo']->id, 'importo' => 50000, 'is_sopravvenienza' => true]]);

    $piano = pianoStraordinario($base, $corrente, 50000);
    $piano->fatture()->attach($pregressa->id, ['importo_collegato' => 100000]);

    // Il cancello diceva «il piano la ripartisce sui giorni di …» per una fattura che nel piano non c'è.
    $stats = app(GeneratePianoRateAction::class)->execute($piano, accettaScoperti: false);
    expect($stats)->toHaveKey('piano_rate_id');
});

test('R9 [beta.35] — sul flusso vero: aprire in modifica una fattura in un piano con rate emesse rimanda indietro e dice di annullare le emissioni', function () {
    [$base, , $piano] = r1PianoConPregressa('emesso');
    $corrente = fatturaCorrente($base);
    $piano->fatture()->attach($corrente->id, ['importo_collegato' => 0]);

    $this->actingAs(utenteAdminPregressa())
        ->get(route('admin.gestionale.fatture.edit', [$base['condominio']->id, $corrente->id]))
        ->assertRedirect()
        ->assertSessionHas('message', fn ($m) => $m['type'] === 'error'
            && str_contains($m['message'], '«Facciata 2026»')
            && str_contains($m['message'], 'annulla le emissioni'));
});

test('R10 [beta.35] — la nota del cancello è obbligatoria anche quando la presa d\'atto arriva come «1» e non come true', function () {
    [$base, , $piano] = r1PianoConPregressa('bozza');

    $this->actingAs(utenteAdminPregressa())
        ->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$base['condominio']->id, $base['esercizio']->id, $piano->id]), [
            'accetta_destinatari' => '1',
        ])
        ->assertSessionHasErrors(['nota_destinatari']);
    expect($piano->fresh()->titolarita_alla['nota_cancello'] ?? null)->toBeNull();
});

test('verifica delle correzioni [beta.35] — creare un piano con una fattura stornata nel frattempo (un\'altra scheda, una richiesta a mano) si rifiuta e la nomina', function () {
    $base = baseStraordinario();
    $pregressa = registraPregresso($base);
    $pregressa->update(['stato_pagamento' => 'stornata', 'dati_extra' => array_merge($pregressa->dati_extra ?? [], ['is_stornata' => true])]);

    $this->actingAs(utenteAdminPregressa())
        ->post(route('admin.gestionale.esercizi.piani-rate.store', [$base['condominio']->id, $base['esercizio']->id]), [
            'gestione_id' => $base['gestione']->id, 'nome' => 'Facciata 2026', 'tipo' => 'straordinario',
            'tipo_autorizzazione' => 'delibera', 'motivazione_autorizzazione' => 'Delibera di prova',
            'fatture_config' => [['id' => $pregressa->id, 'importo' => '1000,00']],
            'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2, 'giorno_scadenza' => 10, 'capitoli_ids' => [], 'genera_subito' => true,
        ])
        ->assertSessionHasErrors(['fatture_config.0.id']);
    expect(session('errors')->first('fatture_config.0.id'))->toContain((string) $pregressa->numero_documento)->toContain('stornata')
        ->and(PianoRate::where('nome', 'Facciata 2026')->exists())->toBeFalse();
});

test('verifica delle correzioni [beta.35] — R10: una presa d\'atto «TRUE» o «On» senza nota non passa più', function (string $valore) {
    [$base, , $piano] = r1PianoConPregressa('bozza');

    $this->actingAs(utenteAdminPregressa())
        ->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$base['condominio']->id, $base['esercizio']->id, $piano->id]), [
            'accetta_destinatari' => $valore,
        ])
        ->assertSessionHasErrors();
    expect($piano->fresh()->titolarita_alla['nota_cancello'] ?? null)->toBeNull();
})->with(['TRUE', 'On', 'YES']);

test('verifica delle correzioni [beta.35] — anche per la pregressa del piano la modifica che manda allo storno dice perché lo storno ora è rifiutato, e la via', function (string $grado, string $via) {
    [, $pregressa] = r1PianoConPregressa($grado);

    $modifica = (new FatturaPassivaService())->motivoBloccoModifica($pregressa);

    // Prima: «Le fatture pregresse non sono modificabili direttamente: usa lo storno.», e lo storno la rifiutava.
    expect($pregressa->motivoBloccoStorno())->not->toBeNull()
        ->and($modifica)->toContain('non sono modificabili direttamente')->toContain('ora non è possibile')->toContain($via);
})->with([
    'in bozza' => ['bozza', 'Elimina prima il piano'],
    'approvato' => ['approvato', 'Riporta il piano in bozza ed eliminalo'],
    'con rate emesse' => ['emesso', 'Annulla le emissioni'],
]);

test('richiesta di Vincenzo del 27/09 [beta.35] — il cancello porta i dati della pregressa, non solo il numero: fornitore, data, importi, voce e il collegamento alla fattura', function () {
    $base = baseStraordinario();
    $pregressa = registraPregresso($base);
    $piano = pianoStraordinario($base, $pregressa, 100000);

    $voce = null;
    try {
        app(GeneratePianoRateAction::class)->execute($piano);
    } catch (\App\Exceptions\Gestionale\DestinatariCambiatiException $e) {
        $voce = collect($e->getCambiamenti())->firstWhere('motivo', 'pregressa_senza_periodo');
    }

    expect($voce)->not->toBeNull('il cancello non si è fermato sulla pregressa senza periodo')
        ->and($voce['fattura'])->toMatchArray([
            'numero'               => $pregressa->numero_documento,
            'fornitore'            => $base['fornitore']->ragione_sociale,
            'data_documento'       => $pregressa->data_documento->format('d/m/Y'),
            'totale_formattato'    => \App\Helpers\MoneyHelper::format(100000),
            'nel_piano_formattato' => \App\Helpers\MoneyHelper::format(100000),
            'voce'                 => 'Debito Pregresso Straordinario',
        ])
        ->and($voce['fattura']['url'])->toContain('/fatture/' . $pregressa->id);
});
