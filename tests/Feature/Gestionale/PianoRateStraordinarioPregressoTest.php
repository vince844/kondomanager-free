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

    $stats = app(GeneratePianoRateAction::class)->execute($piano, accettaScoperti: false);

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
    app(GeneratePianoRateAction::class)->execute($piano);

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
    app(GeneratePianoRateAction::class)->execute($piano);

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
