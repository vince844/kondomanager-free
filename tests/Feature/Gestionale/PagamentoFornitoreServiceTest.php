<?php

require_once __DIR__.'/GestionaleTestHelpers.php';

/**
 * Test suite: PagamentoFornitoreService v1.9.1
 *
 * Flusso end-to-end: registrazione fattura (FatturaPassivaService)
 * → pagamento → storno → ricalcolo stato.
 *
 * Struttura dei test:
 *   - setupPagamentiService()  helper che estende setupContabile() con conto bancario + cassa
 *   - datiPagamento()   helper per costruire l'array input del service
 *   - Gruppo 1: pagamenti fondamentali (totale, parziale, cumulativo, netting)
 *   - Gruppo 2: storno (normale, cross-esercizio, già stornato)
 *   - Gruppo 3: commissioni bancarie
 *   - Gruppo 4: eccezioni di dominio (overpayment, esercizio chiuso, antiriciclaggio)
 *   - Gruppo 5: invarianti di sistema (quadratura, pivot cash = uscita cassa)
 *
 * Nota concorrenza: lockForUpdate() è silenziosamente ignorato da SQLite.
 * I test di concorrenza reale richiedono MySQL — vedi guida sez. 13.7.
 */

use App\Enums\MetodoPagamento;
use App\Enums\StatoPagamentoFattura;
use App\Enums\StatoPagamentoFornitore;
use App\Enums\TipoAllocazioneFattura;
use App\Enums\TipoDetrazione;
use App\Exceptions\Pagamenti\FiscalYearClosedException;
use App\Exceptions\Pagamenti\IllegalCashAmountException;
use App\Exceptions\Pagamenti\InsufficientFundsException;
use App\Exceptions\Pagamenti\OverpaymentException;
use App\Exceptions\Pagamenti\PagamentoGiaStornatoException;
use App\Exceptions\Pagamenti\PagamentoModificaVietataException;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\PagamentoFornitoreService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ════════════════════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ════════════════════════════════════════════════════════════════════════════

/**
 * Estende setupContabile() aggiungendo conto bancario + cassa + spese_bancarie.
 * Tutti gli eventi sono fakeati per isolare la logica contabile pura.
 *
 * Restituisce: [condominio, esercizio, gestione, fornitore, contoCorrenteId]
 */
/**
 * Verifica che la somma degli importi pivot di tipo 'pagamento' per una scrittura
 * sia uguale all'uscita di cassa (AVERE sul conto corrente).
 * Questa è l'INVARIANTE D'ORO del modulo: Σ(pivot tipo='pagamento') = uscita_cassa.
 */
function assertInvarianteCash(int $scritturaId, int $contoCorrenteId): void
{
    $totalePivotPagamento = (int) DB::table('fattura_scrittura')
        ->where('scrittura_contabile_id', $scritturaId)
        ->where('tipo', TipoAllocazioneFattura::PAGAMENTO->value)
        ->sum('importo_allocato');

    $averkBanca = (int) DB::table('righe_scritture')
        ->where('scrittura_id', $scritturaId)
        ->where('tipo_riga', 'avere')
        ->where('conto_contabile_id', $contoCorrenteId)
        ->sum('importo');

    // La differenza è le commissioni (che escono dalla banca ma non vanno nella pivot)
    // Quindi: uscita_banca = totalePivotPagamento + commissioni
    // Test: uscita_banca >= totalePivotPagamento (commissioni sono >= 0)
    expect($averkBanca)->toBeGreaterThanOrEqual(
        $totalePivotPagamento,
        "INVARIANTE VIOLATA: uscita_cassa({$averkBanca}) < Σ(pivot pagamento)({$totalePivotPagamento})"
    );
}

// ════════════════════════════════════════════════════════════════════════════
// GRUPPO 1 — PAGAMENTI FONDAMENTALI
// ════════════════════════════════════════════════════════════════════════════

it('pagamento totale: stato fattura diventa PAGATA e scrittura quadra', function () {
    $ctx = setupPagamentiService();
    $fattura = registraFatturaServiceTest($ctx);
    $service = new PagamentoFornitoreService;

    expect($fattura->stato_pagamento)->toEqual(StatoPagamentoFattura::APERTA);
    expect($fattura->netto_a_pagare)->toEqual(122000); // 1000 + 22% IVA

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura));

    $fattura->refresh();
    expect($fattura->stato_pagamento)->toEqual(StatoPagamentoFattura::PAGATA);
    expect($pagamento->stato)->toEqual(StatoPagamentoFornitore::CONFERMATO);
    expect($pagamento->uuid)->not->toBeEmpty();

    assertQuadraturaPerfetta($pagamento->scrittura->id);
});

it('pagamento totale: la pivot ha esattamente 1 record di tipo pagamento', function () {
    $ctx = setupPagamentiService();
    $fattura = registraFatturaServiceTest($ctx);
    $service = new PagamentoFornitoreService;

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura));

    $pivotRecords = DB::table('fattura_scrittura')
        ->where('fattura_passiva_id', $fattura->id)
        ->where('scrittura_contabile_id', $pagamento->scrittura_contabile_id)
        ->get();

    expect($pivotRecords)->toHaveCount(1);
    expect($pivotRecords->first()->tipo)->toEqual(TipoAllocazioneFattura::PAGAMENTO->value);
    expect((int) $pivotRecords->first()->importo_allocato)->toEqual(122000);
});

it('pagamento parziale: stato diventa PARZIALE', function () {
    $ctx = setupPagamentiService();
    $fattura = registraFatturaServiceTest($ctx);
    $service = new PagamentoFornitoreService;

    $service->registraPagamento(datiPagamento($ctx, $fattura, [
        'allocazioni' => [[
            'fattura_id' => $fattura->id,
            'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => 50000, // pago 500€ su 1220€
        ]],
    ]));

    $fattura->refresh();
    expect($fattura->stato_pagamento)->toEqual(StatoPagamentoFattura::PARZIALE);

    $residuo = $fattura->residuo;
    expect($residuo)->toEqual(72000); // 1220 - 500 = 720€
});

it('due pagamenti parziali consecutivi chiudono la fattura', function () {
    $ctx = setupPagamentiService();
    $fattura = registraFatturaServiceTest($ctx);
    $service = new PagamentoFornitoreService;

    // Primo pagamento: 700€
    $service->registraPagamento(datiPagamento($ctx, $fattura, [
        'allocazioni' => [[
            'fattura_id' => $fattura->id,
            'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => 70000,
        ]],
    ]));

    $fattura->refresh();
    expect($fattura->stato_pagamento)->toEqual(StatoPagamentoFattura::PARZIALE);

    // Secondo pagamento: rimanenti 520€
    $service->registraPagamento(datiPagamento($ctx, $fattura, [
        'conferma_duplicato_verificato' => true,
        'allocazioni' => [[
            'fattura_id' => $fattura->id,
            'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => 52000,
        ]],
    ]));

    $fattura->refresh();
    expect($fattura->stato_pagamento)->toEqual(StatoPagamentoFattura::PAGATA);

    // Totale pivot deve essere esattamente netto_a_pagare
    $totalePivot = (int) DB::table('fattura_scrittura')
        ->where('fattura_passiva_id', $fattura->id)
        ->whereIn('tipo', [
            TipoAllocazioneFattura::PAGAMENTO->value,
            TipoAllocazioneFattura::COMPENSAZIONE->value,
        ])
        ->sum('importo_allocato');

    expect($totalePivot)->toEqual($fattura->netto_a_pagare);
});

it('bonifico cumulativo: paga 2 fatture con 1 scrittura e tutto quadra', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    [$condominio, $esercizio, , $fornitore, $contoCorrenteId] = $ctx;

    $fattura1 = registraFatturaServiceTest($ctx); // 1220€
    $fattura2 = registraFatturaServiceTest($ctx); // 1220€

    $pagamento = $service->registraPagamento([
        'fornitore_id' => $fornitore->id,
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->format('Y-m-d'),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'iban_beneficiario' => 'IT60X0542811101000000123456',
        'importo_commissioni_cents' => 0,
        'allow_overdraft' => true,
        'iban_confermato_manualmente' => true,
        'conferma_duplicato_verificato' => true,
        'allocazioni' => [
            [
                'fattura_id' => $fattura1->id,
                'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                'importo_allocato_cents' => $fattura1->netto_a_pagare,
            ],
            [
                'fattura_id' => $fattura2->id,
                'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                'importo_allocato_cents' => $fattura2->netto_a_pagare,
            ],
        ],
    ]);

    $fattura1->refresh();
    $fattura2->refresh();

    expect($fattura1->stato_pagamento)->toEqual(StatoPagamentoFattura::PAGATA);
    expect($fattura2->stato_pagamento)->toEqual(StatoPagamentoFattura::PAGATA);

    assertQuadraturaPerfetta($pagamento->scrittura_contabile_id);

    // Un'unica scrittura ha toccato entrambe le fatture
    $pivotCount = DB::table('fattura_scrittura')
        ->where('scrittura_contabile_id', $pagamento->scrittura_contabile_id)
        ->count();
    expect($pivotCount)->toEqual(2);
});

it('pagamento con bonifico parlante genera causale fiscale corretta', function () {
    $ctx = setupPagamentiService();
    $fattura = registraFatturaServiceTest($ctx);
    $service = new PagamentoFornitoreService;

    // Assicuriamoci che il fornitore abbia la P.IVA per la causale
    $ctx[3]->update(['partita_iva' => '12345678901']);

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura, [
        'bonifico_parlante' => true,
        'tipo_detrazione' => TipoDetrazione::RISTRUTTURAZIONE->value,
        'beneficiari_detrazione' => [
            ['codice_fiscale' => 'RSSMRA80A01H501U', 'nome' => 'Mario Rossi'],
        ],
    ]));

    $pagamento->refresh();

    expect($pagamento->bonifico_parlante)->toBeTrue();
    expect($pagamento->tipo_detrazione)->toEqual(TipoDetrazione::RISTRUTTURAZIONE);
    expect($pagamento->beneficiari_detrazione)->toHaveCount(1);

    $base = sprintf(
        'Pagamento %s del %s',
        $fattura->numero_documento ?? "FT#{$fattura->id}",
        $fattura->data_documento?->format('d/m/Y') ?? now()->format('d/m/Y')
    );

    $expectedCausale = "Bonifico Ristrutturazione edilizia - art. 16-bis DPR 917/1986 - Benef. CF: RSSMRA80A01H501U - P.IVA: 12345678901 - {$base}";
    if (mb_strlen($expectedCausale) > 140) {
        $expectedCausale = mb_substr($expectedCausale, 0, 137).'...';
    }

    expect($pagamento->causale_bonifico)->toEqual($expectedCausale);
});

// ════════════════════════════════════════════════════════════════════════════
// GRUPPO 2 — NETTING (Fattura + Nota di Credito)
// ════════════════════════════════════════════════════════════════════════════

it('netting FT+NC: quadratura DARE=AVERE corretta', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = $ctx;

    // Fattura 1000€ + 22% = 1220€
    $fattura = registraFatturaServiceTest($ctx);

    // Nota credito 200€ + 22% = 244€ (no ritenuta)
    $nc = (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'tipo_documento' => 'nota_credito',
            'applica_ritenuta' => false,
            'righe' => [[
                'descrizione' => 'Storno parziale',
                'importo_imponibile' => 200,
                'aliquota_iva' => 22,
                'conto_id' => $capitolo->id,
                'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    // Netto NC = 244€ (abs del netto_a_pagare che è negativo)
    $importoNC = abs($nc->netto_a_pagare); // 24400 cents

    // Bonifico = 1220 - 244 = 976€
    $bonifico = $fattura->netto_a_pagare - $importoNC; // 97600 cents

    $pagamento = $service->registraPagamento([
        'fornitore_id' => $fornitore->id,
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->format('Y-m-d'),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'iban_beneficiario' => 'IT60X0542811101000000123456',
        'importo_commissioni_cents' => 0,
        'allow_overdraft' => true,
        'iban_confermato_manualmente' => true,
        'conferma_duplicato_verificato' => true,
        'allocazioni' => [
            // Fattura: 976 pagamento + 244 compensazione = 1220 totale chiuso
            [
                'fattura_id' => $fattura->id,
                'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                'importo_allocato_cents' => $bonifico,
            ],
            [
                'fattura_id' => $fattura->id,
                'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value,
                'importo_allocato_cents' => $importoNC,
            ],
            // NC: 244 compensazione → interamente utilizzata
            [
                'fattura_id' => $nc->id,
                'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value,
                'importo_allocato_cents' => $importoNC,
            ],
        ],
    ]);

    $fattura->refresh();
    $nc->refresh();

    // Entrambi chiusi
    expect($fattura->stato_pagamento)->toEqual(StatoPagamentoFattura::PAGATA);
    expect($nc->stato_pagamento)->toEqual(StatoPagamentoFattura::PAGATA);

    // Quadratura partita doppia
    assertQuadraturaPerfetta($pagamento->scrittura_contabile_id);
});

it('netting: invariante GOLD — Σ(pivot pagamento) = uscita cassa banca', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = $ctx;

    $fattura = registraFatturaServiceTest($ctx); // 1220€

    $nc = (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'tipo_documento' => 'nota_credito',
            'applica_ritenuta' => false,
            'righe' => [[
                'descrizione' => 'NC test',
                'importo_imponibile' => 200,
                'aliquota_iva' => 22,
                'conto_id' => $capitolo->id,
                'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    $importoNC = abs($nc->netto_a_pagare); // 24400
    $bonifico = $fattura->netto_a_pagare - $importoNC; // 97600

    $pagamento = $service->registraPagamento([
        'fornitore_id' => $fornitore->id,
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->format('Y-m-d'),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'allow_overdraft' => true,
        'iban_confermato_manualmente' => true,
        'conferma_duplicato_verificato' => true,
        'allocazioni' => [
            ['fattura_id' => $fattura->id, 'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,     'importo_allocato_cents' => $bonifico],
            ['fattura_id' => $fattura->id, 'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value, 'importo_allocato_cents' => $importoNC],
            ['fattura_id' => $nc->id,      'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value, 'importo_allocato_cents' => $importoNC],
        ],
    ]);

    // INVARIANTE GOLD: Σ(pivot tipo=pagamento) = uscita_cassa_banca
    $totalePivotPagamento = (int) DB::table('fattura_scrittura')
        ->where('scrittura_contabile_id', $pagamento->scrittura_contabile_id)
        ->where('tipo', TipoAllocazioneFattura::PAGAMENTO->value)
        ->sum('importo_allocato');

    $uscitaBanca = (int) DB::table('righe_scritture')
        ->where('scrittura_id', $pagamento->scrittura_contabile_id)
        ->where('tipo_riga', 'avere')
        ->where('conto_contabile_id', $contoCorrenteId)
        ->sum('importo');

    expect($totalePivotPagamento)->toEqual($bonifico, 'Σ(pivot pagamento) deve essere il bonifico effettivo');
    expect($uscitaBanca)->toEqual($bonifico, 'AVERE banca deve essere il bonifico effettivo');
    expect($totalePivotPagamento)->toEqual($uscitaBanca, 'INVARIANTE GOLD violata');
});

// ════════════════════════════════════════════════════════════════════════════
// GRUPPO 3 — STORNO
// ════════════════════════════════════════════════════════════════════════════

it('storno pagamento: fattura torna APERTA e scrittura storno quadra', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    $fattura = registraFatturaServiceTest($ctx);

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura));
    $fattura->refresh();
    expect($fattura->stato_pagamento)->toEqual(StatoPagamentoFattura::PAGATA);

    $storno = $service->stornaPagamento($pagamento, 'Insoluto bancario');

    $fattura->refresh();
    $pagamento->refresh();

    // Fattura ritorna aperta
    expect($fattura->stato_pagamento)->toEqual(StatoPagamentoFattura::APERTA);

    // Pagamento originale marcato stornato
    expect($pagamento->stato)->toEqual(StatoPagamentoFornitore::STORNATO);
    expect($pagamento->motivo_storno)->toEqual('Insoluto bancario');

    // Record storno creato con self-ref al padre
    expect($storno->stato)->toEqual(StatoPagamentoFornitore::CONFERMATO);
    expect($storno->pagamento_padre_id)->toEqual($pagamento->id);

    // Scrittura storno quadra
    assertQuadraturaPerfetta($storno->scrittura_contabile_id);

    // Totale pivot su fattura = 0 (pagamento +122000 + storno -122000)
    $totalePivot = (int) DB::table('fattura_scrittura')
        ->where('fattura_passiva_id', $fattura->id)
        ->whereIn('tipo', [
            TipoAllocazioneFattura::PAGAMENTO->value,
            TipoAllocazioneFattura::COMPENSAZIONE->value,
        ])
        ->sum('importo_allocato');

    expect($totalePivot)->toEqual(0, 'SUM(pivot) deve essere 0 dopo storno completo');
});

it('storno: pivot negativi mirror del pagamento originale', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    $fattura = registraFatturaServiceTest($ctx);

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura));
    $storno = $service->stornaPagamento($pagamento, 'Test pivot negativi');

    // La scrittura storno deve avere pivot con importo_allocato NEGATIVO
    $pivotStorno = DB::table('fattura_scrittura')
        ->where('scrittura_contabile_id', $storno->scrittura_contabile_id)
        ->first();

    expect((int) $pivotStorno->importo_allocato)->toEqual(-122000);
    expect($pivotStorno->tipo)->toEqual(TipoAllocazioneFattura::PAGAMENTO->value);
});

it('storno di pagamento già stornato lancia PagamentoGiaStornatoException', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    $fattura = registraFatturaServiceTest($ctx);

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura));
    $service->stornaPagamento($pagamento, 'Primo storno');

    // Tenta di stornare di nuovo il pagamento originale (ora già stornato)
    expect(fn () => $service->stornaPagamento($pagamento->fresh(), 'Secondo storno'))
        ->toThrow(PagamentoGiaStornatoException::class);
});

it('storno cross-esercizio (Variante B1): usa esercizio corrente aperto', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    [$condominio, $esercizio] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura));

    // Chiude l'esercizio originale DOPO il pagamento
    DB::table('esercizi')->where('id', $esercizio->id)->update(['stato' => 'chiuso']);

    // Crea un nuovo esercizio aperto per ricevere lo storno
    $nuovoEsercizioId = DB::table('esercizi')->insertGetId([
        'condominio_id' => $condominio->id,
        'nome' => 'Esercizio 2027',
        'stato' => 'aperto',
        'data_inizio' => '2027-01-01',
        'data_fine' => '2027-12-31',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $storno = $service->stornaPagamento($pagamento, 'Storno cross-esercizio test');

    // Storno registrato nel nuovo esercizio aperto
    expect($storno->storno_cross_esercizio)->toBeTrue();
    expect($storno->esercizio_storno_id)->toEqual($nuovoEsercizioId);

    $scritturaStorno = ScritturaContabile::find($storno->scrittura_contabile_id);
    expect($scritturaStorno->esercizio_id)->toEqual($nuovoEsercizioId);

    // Fattura torna aperta
    $fattura->refresh();
    expect($fattura->stato_pagamento)->toEqual(StatoPagamentoFattura::APERTA);

    assertQuadraturaPerfetta($storno->scrittura_contabile_id);
});

it('storno pagamento cumulativo multi-fattura: riapre entrambe', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    [$condominio, $esercizio, , $fornitore, $contoCorrenteId] = $ctx;

    $fattura1 = registraFatturaServiceTest($ctx);
    $fattura2 = registraFatturaServiceTest($ctx);

    $pagamento = $service->registraPagamento([
        'fornitore_id' => $fornitore->id,
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->format('Y-m-d'),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'allow_overdraft' => true,
        'iban_confermato_manualmente' => true,
        'conferma_duplicato_verificato' => true,
        'allocazioni' => [
            ['fattura_id' => $fattura1->id, 'tipo' => TipoAllocazioneFattura::PAGAMENTO->value, 'importo_allocato_cents' => $fattura1->netto_a_pagare],
            ['fattura_id' => $fattura2->id, 'tipo' => TipoAllocazioneFattura::PAGAMENTO->value, 'importo_allocato_cents' => $fattura2->netto_a_pagare],
        ],
    ]);

    $service->stornaPagamento($pagamento, 'Storno cumulativo');

    $fattura1->refresh();
    $fattura2->refresh();

    expect($fattura1->stato_pagamento)->toEqual(StatoPagamentoFattura::APERTA);
    expect($fattura2->stato_pagamento)->toEqual(StatoPagamentoFattura::APERTA);
});

it('storno pagamento con netting NC: riapre fattura e nota di credito', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = $ctx;

    $fattura = registraFatturaServiceTest($ctx);

    $nc = (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'tipo_documento' => 'nota_credito',
            'applica_ritenuta' => false,
            'righe' => [[
                'descrizione' => 'NC test storno',
                'importo_imponibile' => 200,
                'aliquota_iva' => 22,
                'conto_id' => $capitolo->id,
                'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    $importoNC = abs($nc->netto_a_pagare);
    $bonifico = $fattura->netto_a_pagare - $importoNC;

    $pagamento = $service->registraPagamento([
        'fornitore_id' => $fornitore->id,
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->format('Y-m-d'),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'allow_overdraft' => true,
        'iban_confermato_manualmente' => true,
        'conferma_duplicato_verificato' => true,
        'allocazioni' => [
            ['fattura_id' => $fattura->id, 'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,     'importo_allocato_cents' => $bonifico],
            ['fattura_id' => $fattura->id, 'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value, 'importo_allocato_cents' => $importoNC],
            ['fattura_id' => $nc->id,      'tipo' => TipoAllocazioneFattura::COMPENSAZIONE->value, 'importo_allocato_cents' => $importoNC],
        ],
    ]);

    $storno = $service->stornaPagamento($pagamento, 'Storno netting');

    $fattura->refresh();
    $nc->refresh();

    expect($fattura->stato_pagamento)->toEqual(StatoPagamentoFattura::APERTA);
    expect($nc->stato_pagamento)->toEqual(StatoPagamentoFattura::APERTA);
    assertQuadraturaPerfetta($storno->scrittura_contabile_id);
});

// ════════════════════════════════════════════════════════════════════════════
// GRUPPO 4 — COMMISSIONI BANCARIE
// ════════════════════════════════════════════════════════════════════════════

it('pagamento con commissioni: DARE spese bancarie + quadratura corretta', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    [$condominio, , , , $contoCorrenteId] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);

    $commissioni = 500; // 5€

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura, [
        'importo_commissioni_cents' => $commissioni,
    ]));

    assertQuadraturaPerfetta($pagamento->scrittura_contabile_id);

    // DARE Spese Bancarie deve essere 500 cents
    $contoSpese = ContoContabile::where('condominio_id', $condominio->id)
        ->where('ruolo', 'spese_bancarie')
        ->first();

    $dareSpese = (int) DB::table('righe_scritture')
        ->where('scrittura_id', $pagamento->scrittura_contabile_id)
        ->where('tipo_riga', 'dare')
        ->where('conto_contabile_id', $contoSpese->id)
        ->value('importo');

    expect($dareSpese)->toEqual($commissioni);

    // AVERE Banca = netto_a_pagare + commissioni
    $averkBanca = (int) DB::table('righe_scritture')
        ->where('scrittura_id', $pagamento->scrittura_contabile_id)
        ->where('tipo_riga', 'avere')
        ->where('conto_contabile_id', $contoCorrenteId)
        ->value('importo');

    expect($averkBanca)->toEqual($fattura->netto_a_pagare + $commissioni);

    // Invariante: Σ(pivot pagamento) = uscita_banca - commissioni
    $totalePivot = (int) DB::table('fattura_scrittura')
        ->where('scrittura_contabile_id', $pagamento->scrittura_contabile_id)
        ->where('tipo', TipoAllocazioneFattura::PAGAMENTO->value)
        ->sum('importo_allocato');

    expect($totalePivot)->toEqual($averkBanca - $commissioni);
});

// ════════════════════════════════════════════════════════════════════════════
// GRUPPO 5 — ECCEZIONI DI DOMINIO
// ════════════════════════════════════════════════════════════════════════════

it('overpayment bloccato: alloco più del residuo → OverpaymentException', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    $fattura = registraFatturaServiceTest($ctx);

    expect(fn () => $service->registraPagamento(datiPagamento($ctx, $fattura, [
        'allow_overpayment' => false,
        'allocazioni' => [[
            'fattura_id' => $fattura->id,
            'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => $fattura->netto_a_pagare + 1, // 1 centesimo in più
        ]],
    ])))->toThrow(OverpaymentException::class);

    // Fattura deve rimanere APERTA (rollback)
    $fattura->refresh();
    expect($fattura->stato_pagamento)->toEqual(StatoPagamentoFattura::APERTA);
});

it('esercizio chiuso: blocca registrazione → FiscalYearClosedException', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    [$condominio, $esercizio] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);

    // Chiude l'esercizio
    DB::table('esercizi')->where('id', $esercizio->id)->update(['stato' => 'chiuso']);

    expect(fn () => $service->registraPagamento(datiPagamento($ctx, $fattura)))
        ->toThrow(FiscalYearClosedException::class);
});

it('antiriciclaggio: contanti >= 5000€ → IllegalCashAmountException', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;

    // Fattura grande per superare il limite (6000€ + 22%)
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = $ctx;
    $fattura = (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Fattura Grande',
                'importo_imponibile' => 6000,
                'aliquota_iva' => 22,
                'conto_id' => $capitolo->id,
                'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    expect(fn () => $service->registraPagamento(datiPagamento($ctx, $fattura, [
        'metodo_pagamento' => MetodoPagamento::CONTANTI->value,
        'allocazioni' => [[
            'fattura_id' => $fattura->id,
            'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => $fattura->netto_a_pagare,
        ]],
    ])))->toThrow(IllegalCashAmountException::class);
});

it('antiriciclaggio: contanti 4999€ passano (sotto soglia)', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    [$condominio, $esercizio, $gestione, $fornitore, , $capitolo] = $ctx;

    $fattura = (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'righe' => [[
                'descrizione' => 'Fattura Piccola',
                'importo_imponibile' => 4000, // 4000 + 22% = 4880 < 5000
                'aliquota_iva' => 22,
                'conto_id' => $capitolo->id,
                'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    // Deve passare senza eccezione
    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura, [
        'metodo_pagamento' => MetodoPagamento::CONTANTI->value,
        'allocazioni' => [[
            'fattura_id' => $fattura->id,
            'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => $fattura->netto_a_pagare,
        ]],
    ]));

    expect($pagamento)->toBeInstanceOf(PagamentoFornitore::class);
});

// ════════════════════════════════════════════════════════════════════════════
// GRUPPO 6 — INVARIANTI DI SISTEMA
// ════════════════════════════════════════════════════════════════════════════

it('ricalcolaStatoFattura: corregge stato inconsistente (overpayment dirty data)', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    $fattura = registraFatturaServiceTest($ctx);

    // Inserisce manualmente un pivot con overpayment (simula dirty data)
    $scrittura = $fattura->scritture()->first();
    DB::table('fattura_scrittura')->insert([
        'fattura_passiva_id' => $fattura->id,
        'scrittura_contabile_id' => $scrittura->id,
        'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
        'importo_allocato' => $fattura->netto_a_pagare + 100000, // overpayment
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // ricalcolaStatoFattura non deve lanciare eccezione
    expect(fn () => $service->ricalcolaStatoFattura($fattura))->not->toThrow(Throwable::class);

    $fattura->refresh();

    // Deve marcare l'inconsistenza e usare PARZIALE come fallback
    expect($fattura->inconsistenza_pagamento)->toBeTrue();
    expect($fattura->ultimo_errore_ricalcolo)->not->toBeNull();
    expect($fattura->ultimo_ricalcolo_pagamento_at)->not->toBeNull();
});

it('ricalcolaStatoFattura: non include tipo=competenza nel calcolo', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    $fattura = registraFatturaServiceTest($ctx);

    // La fattura appena registrata ha già 1 pivot tipo=competenza da FatturaPassivaService.
    // Il totale allocato (ignorando la competenza) deve essere 0 → APERTA.
    $service->ricalcolaStatoFattura($fattura);

    $fattura->refresh();
    expect($fattura->stato_pagamento)->toEqual(StatoPagamentoFattura::APERTA);
    expect($fattura->inconsistenza_pagamento)->toBeFalse();
});

it('versione_allocazioni incrementa ad ogni operazione sulla pivot', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    $fattura = registraFatturaServiceTest($ctx);

    $versioneIniziale = $fattura->versione_allocazioni;

    // Pagamento parziale → versione +1
    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura, [
        'allocazioni' => [[
            'fattura_id' => $fattura->id,
            'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => 50000,
        ]],
    ]));

    $fattura->refresh();
    expect($fattura->versione_allocazioni)->toEqual($versioneIniziale + 1);

    // Storno → versione +1 ancora
    $service->stornaPagamento($pagamento, 'Test versione');

    $fattura->refresh();
    expect($fattura->versione_allocazioni)->toEqual($versioneIniziale + 2);
});

it('idempotency key: seconda chiamata con stessa key restituisce pagamento originale', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    $fattura = registraFatturaServiceTest($ctx);

    $idempotencyKey = (string) Str::uuid();
    $input = datiPagamento($ctx, $fattura, ['idempotency_key' => $idempotencyKey]);

    $pagamento1 = $service->registraPagamento($input);
    $pagamento2 = $service->registraPagamento($input); // replay con stessa key

    // Deve restituire lo stesso pagamento
    expect($pagamento1->id)->toEqual($pagamento2->id);

    // Deve essere stata creata 1 sola scrittura
    $scrittureCount = ScritturaContabile::where('idempotency_key', $idempotencyKey)->count();
    expect($scrittureCount)->toEqual(1);

    // Fattura deve essere PAGATA (non doppiamente pagata)
    $fattura->refresh();
    expect($fattura->stato_pagamento)->toEqual(StatoPagamentoFattura::PAGATA);
});

it('snapshot fornitore: salvato al momento del pagamento e immutabile', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    [$condominio, , , $fornitore] = $ctx;
    $fattura = registraFatturaServiceTest($ctx);

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura));

    // Snapshot creato
    expect($pagamento->fornitore_snapshot)->not->toBeNull();
    expect($pagamento->fornitore_snapshot['schema_version'])->toEqual(1);
    expect($pagamento->fornitore_snapshot['ragione_sociale'])->toEqual($fornitore->ragione_sociale);

    // Modifica anagrafica fornitore DOPO il pagamento
    $fornitore->update(['ragione_sociale' => 'Ragione Sociale Modificata Srl']);

    // Snapshot deve restare immutato
    $pagamento->refresh();
    expect($pagamento->fornitore_snapshot['ragione_sociale'])->toEqual('Fornitore Test Srl');
});

it('numero_protocollo generato con prefisso PAG per pagamento_fornitore', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    $fattura = registraFatturaServiceTest($ctx);

    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura));

    $scritturaProtocollo = ScritturaContabile::find($pagamento->scrittura_contabile_id)
        ->numero_protocollo;

    expect($scritturaProtocollo)->toStartWith('PAG-');
});

it('ritenute dacconto: calcolo pro-quota su pagamento parziale', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = $ctx;

    // Fattura da 1000€ netto a pagare. Ha 200€ di ritenuta.
    // In db i cents sono 100000 e 20000.
    $fattura = (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], [
            'applica_ritenuta' => true,
            'righe' => [[
                'descrizione' => 'Fattura Ritenuta',
                'importo_imponibile' => 1000,
                'aliquota_iva' => 0, // No IVA per calcoli più chiari
                'conto_id' => $capitolo->id,
                'is_sopravvenienza' => false,
            ]],
        ]),
        $condominio->id
    );

    // Forza la ritenuta a 20000 cents (200€) per il test
    $fattura->update([
        'netto_a_pagare' => 100000,
        'importo_ritenuta' => 20000,
    ]);

    // Paghiamo il 50% della fattura (50000 cents)
    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura, [
        'allocazioni' => [[
            'fattura_id' => $fattura->id,
            'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => 50000,
        ]],
    ]));

    $pagamento->refresh();

    // Il 50% di 20000 è 10000 cents (100€)
    expect($pagamento->importo_ritenuta)->toEqual(10000);

    // Il secondo pagamento del restante 50%
    $pagamento2 = $service->registraPagamento(datiPagamento($ctx, $fattura, [
        'conferma_duplicato_verificato' => true,
        'allocazioni' => [[
            'fattura_id' => $fattura->id,
            'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
            'importo_allocato_cents' => 50000,
        ]],
    ]));

    $pagamento2->refresh();

    // Anche il secondo pagamento deve avere 100€ di ritenuta
    expect($pagamento2->importo_ritenuta)->toEqual(10000);
});
it('aggiorna pagamento riscrivendo record bancario', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiService();

    $fattura = (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], ['righe' => [['descrizione' => 'Test', 'importo_imponibile' => 1000, 'aliquota_iva' => 22, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false]]]),
        $condominio->id
    );

    $pagamentoData = [
        'fornitore_id' => $fornitore->id,
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->format('Y-m-d'),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'importo_lordo_cents' => 122000,
        'importo_netto_cents' => 122000,
        'allocazioni' => [
            [
                'fattura_id' => $fattura->id,
                'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                'importo_allocato_cents' => 122000,
            ],
        ],
        'allow_overdraft' => true,
    ];

    $service = new PagamentoFornitoreService;
    $pagamento = $service->registraPagamento($pagamentoData);

    $newData = array_merge($pagamentoData, [
        'causale_bonifico' => 'Modificata',
        'note_override' => 'Nota modificata',
        'data_pagamento' => now()->addDay()->format('Y-m-d'),
    ]);

    $pagamentoAggiornato = $service->aggiornaPagamento($pagamento, $newData);

    expect($pagamentoAggiornato->causale_bonifico)->toBe('Modificata');
    expect($pagamentoAggiornato->note_override)->toBe('Nota modificata');
    expect($pagamentoAggiornato->data_pagamento->toDateString())->toBe(now()->addDay()->toDateString());

    $scrittura = $pagamentoAggiornato->scrittura;
    expect($scrittura->data_registrazione->toDateString())->toBe(now()->addDay()->toDateString());
});

it('aggiorna pagamento con commissioni bancarie: ricrea riga spese bancarie e mantiene la quadratura', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiService();

    $fattura = (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], ['righe' => [['descrizione' => 'Test', 'importo_imponibile' => 1000, 'aliquota_iva' => 22, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false]]]),
        $condominio->id
    );

    $pagamentoData = [
        'fornitore_id' => $fornitore->id,
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->format('Y-m-d'),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'importo_lordo_cents' => 122000,
        'importo_netto_cents' => 122000,
        'allocazioni' => [
            [
                'fattura_id' => $fattura->id,
                'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                'importo_allocato_cents' => 122000,
            ],
        ],
        'allow_overdraft' => true,
    ];

    $service = new PagamentoFornitoreService;
    // Creato SENZA commissioni
    $pagamento = $service->registraPagamento($pagamentoData);

    // Modificato AGGIUNGENDO 5€ di commissioni
    $commissioni = 500;
    $pagamentoAggiornato = $service->aggiornaPagamento($pagamento, array_merge($pagamentoData, [
        'importo_commissioni_cents' => $commissioni,
    ]));

    assertQuadraturaPerfetta($pagamentoAggiornato->scrittura_contabile_id);

    expect($pagamentoAggiornato->importo_commissione)->toEqual($commissioni);

    $contoSpese = ContoContabile::where('condominio_id', $condominio->id)
        ->where('ruolo', 'spese_bancarie')
        ->first();

    $dareSpese = (int) DB::table('righe_scritture')
        ->where('scrittura_id', $pagamentoAggiornato->scrittura_contabile_id)
        ->where('tipo_riga', 'dare')
        ->where('conto_contabile_id', $contoSpese->id)
        ->value('importo');

    expect($dareSpese)->toEqual($commissioni);

    // AVERE Banca deve includere le commissioni (netto + commissioni)
    $averkBanca = (int) DB::table('righe_scritture')
        ->where('scrittura_id', $pagamentoAggiornato->scrittura_contabile_id)
        ->where('tipo_riga', 'avere')
        ->where('conto_contabile_id', $contoCorrenteId)
        ->value('importo');

    expect($averkBanca)->toEqual(122000 + $commissioni);

    // Modificato di nuovo AZZERANDO le commissioni: la riga spese bancarie non deve ricomparire
    $pagamentoSenzaCommissioni = $service->aggiornaPagamento($pagamentoAggiornato, array_merge($pagamentoData, [
        'importo_commissioni_cents' => 0,
    ]));

    assertQuadraturaPerfetta($pagamentoSenzaCommissioni->scrittura_contabile_id);
    expect($pagamentoSenzaCommissioni->importo_commissione)->toEqual(0);

    $dareSpeseDopo = (int) DB::table('righe_scritture')
        ->where('scrittura_id', $pagamentoSenzaCommissioni->scrittura_contabile_id)
        ->where('tipo_riga', 'dare')
        ->where('conto_contabile_id', $contoSpese->id)
        ->value('importo');

    expect($dareSpeseDopo)->toEqual(0);
});

it('aggiorna pagamento persiste bonifico_parlante e tipo_detrazione', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiService();

    $fattura = (new FatturaPassivaService)->registraFattura(
        datiBase([$condominio, $esercizio, $gestione, $fornitore], ['righe' => [['descrizione' => 'Test', 'importo_imponibile' => 1000, 'aliquota_iva' => 22, 'conto_id' => $capitolo->id, 'is_sopravvenienza' => false]]]),
        $condominio->id
    );

    $pagamentoData = [
        'fornitore_id' => $fornitore->id,
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'gestione_id' => $gestione->id,
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->format('Y-m-d'),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'importo_lordo_cents' => 122000,
        'importo_netto_cents' => 122000,
        'allocazioni' => [
            [
                'fattura_id' => $fattura->id,
                'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                'importo_allocato_cents' => 122000,
            ],
        ],
        'allow_overdraft' => true,
    ];

    $service = new PagamentoFornitoreService;
    // Creato senza bonifico parlante
    $pagamento = $service->registraPagamento($pagamentoData);

    expect($pagamento->bonifico_parlante)->toBeFalse();
    expect($pagamento->tipo_detrazione)->toBeNull();

    $pagamentoAggiornato = $service->aggiornaPagamento($pagamento, array_merge($pagamentoData, [
        'bonifico_parlante' => true,
        'tipo_detrazione' => TipoDetrazione::RISTRUTTURAZIONE->value,
    ]));

    expect($pagamentoAggiornato->bonifico_parlante)->toBeTrue();
    expect($pagamentoAggiornato->tipo_detrazione)->toBe(TipoDetrazione::RISTRUTTURAZIONE);
});

it('blocca la modifica di un pagamento cumulativo su più fatture', function () {
    $ctx = setupPagamentiService();
    $service = new PagamentoFornitoreService;
    [$condominio, $esercizio, , $fornitore, $contoCorrenteId] = $ctx;

    $fattura1 = registraFatturaServiceTest($ctx);
    $fattura2 = registraFatturaServiceTest($ctx);

    $pagamento = $service->registraPagamento([
        'fornitore_id' => $fornitore->id,
        'condominio_id' => $condominio->id,
        'esercizio_id' => $esercizio->id,
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->format('Y-m-d'),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'allow_overdraft' => true,
        'allocazioni' => [
            [
                'fattura_id' => $fattura1->id,
                'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                'importo_allocato_cents' => $fattura1->netto_a_pagare,
            ],
            [
                'fattura_id' => $fattura2->id,
                'tipo' => TipoAllocazioneFattura::PAGAMENTO->value,
                'importo_allocato_cents' => $fattura2->netto_a_pagare,
            ],
        ],
    ]);

    $service->aggiornaPagamento($pagamento, [
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->addDay()->format('Y-m-d'),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'importo_lordo_cents' => $pagamento->importo_lordo,
        'importo_netto_cents' => $pagamento->importo_netto,
    ]);
})->throws(PagamentoModificaVietataException::class);

it('blocca in modifica un aumento di importo che sfora la capienza del conto, ma permette un edit invariato', function () {
    [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo] = setupPagamentiService();
    $fattura = registraFatturaServiceTest($ctx = [$condominio, $esercizio, $gestione, $fornitore, $contoCorrenteId, $capitolo]);

    $service = new PagamentoFornitoreService;

    // Registrato in overdraft esplicito: il conto parte da saldo 0, quindi qualunque
    // pagamento lo porta in negativo (nessun saldo iniziale configurato in setup).
    $pagamento = $service->registraPagamento(datiPagamento($ctx, $fattura, [
        'allow_overdraft' => true,
    ]));

    // Edit che NON aumenta l'uscita di cassa (stessa importo, cambia solo la data):
    // deve passare anche SENZA allow_overdraft, perché non peggiora l'esposizione.
    $pagamentoInvariato = $service->aggiornaPagamento($pagamento, [
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->addDay()->format('Y-m-d'),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'importo_lordo_cents' => $pagamento->importo_lordo,
        'importo_netto_cents' => $pagamento->importo_netto,
    ]);
    expect($pagamentoInvariato->data_pagamento->toDateString())->toBe(now()->addDay()->toDateString());

    // Edit che AUMENTA l'uscita di cassa senza allow_overdraft: deve essere bloccato
    // (allow_overpayment serve solo a superare il controllo residuo fattura, non c'entra
    // con la capienza conto: qui vogliamo isolare il blocco sulla capienza).
    expect(fn () => $service->aggiornaPagamento($pagamentoInvariato, [
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->format('Y-m-d'),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'importo_lordo_cents' => $pagamento->importo_lordo * 2,
        'importo_netto_cents' => $pagamento->importo_netto * 2,
        'allow_overpayment' => true,
    ]))->toThrow(InsufficientFundsException::class);

    // Lo stesso aumento CON allow_overdraft deve invece passare.
    $pagamentoAumentato = $service->aggiornaPagamento($pagamentoInvariato, [
        'conto_corrente_id' => $contoCorrenteId,
        'data_pagamento' => now()->format('Y-m-d'),
        'metodo_pagamento' => MetodoPagamento::BONIFICO->value,
        'importo_lordo_cents' => $pagamento->importo_lordo * 2,
        'importo_netto_cents' => $pagamento->importo_netto * 2,
        'allow_overpayment' => true,
        'allow_overdraft' => true,
    ]);
    expect($pagamentoAumentato->importo_netto)->toEqual($pagamento->importo_netto * 2);
});
