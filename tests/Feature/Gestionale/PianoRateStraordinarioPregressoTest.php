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
