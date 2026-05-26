<?php

use App\Services\Treasury\TreasuryGuardianService;
use App\Services\Treasury\TreasuryTimelineBuilder;
use App\Support\Treasury\TreasuryStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use App\Models\Condominio;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->condominio = Condominio::factory()->create();
    
    // Inseriamo un conto contabile di tipo liquidità
    $this->contoLiquiditaId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'nome' => 'Banca',
        'categoria' => 'liquidita',
        'tipo' => 'attivo',
        'codice' => '1001',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Aggiungiamo un fornitore finto per le query
    $this->fornitoreId = DB::table('fornitori')->insertGetId([
        'ragione_sociale' => 'Enel',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // Gestione ed esercizio finti per poter creare piani rate
    $this->esercizioId = DB::table('esercizi')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'nome' => '2024',
        'data_inizio' => now()->startOfYear(),
        'data_fine' => now()->endOfYear(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->gestioneId = DB::table('gestioni')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'nome' => 'Ordinaria',
        'attiva' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->pianoRateId = DB::table('piani_rate')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'gestione_id' => $this->gestioneId,
        'nome' => 'Piano Base',
        'tipo' => 'ordinario',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->service = new TreasuryGuardianService(new TreasuryTimelineBuilder());
});

/**
 * Helper per inserire liquidità iniziale in modo veloce
 */
function assegnaLiquidita($condominioId, $contoId, $importoCents, $gestioneId) {
    global $testEsercizioId;
    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $condominioId,
        'gestione_id' => $gestioneId,
        'esercizio_id' => 1, // hardcoded from setup
        'data_registrazione' => now(),
        'data_competenza' => now(),
        'numero_protocollo' => 'PROT-' . rand(1000, 9999),
        'causale' => 'Versamento iniziale test',
        'tipo_movimento' => 'versamento',
        'stato' => 'registrata'
    ]);
    
    DB::table('righe_scritture')->insert([
        'scrittura_id' => $scritturaId,
        'conto_contabile_id' => $contoId,
        'tipo_riga' => 'dare', // Dare su un conto liquidità aumenta il saldo
        'importo' => $importoCents,
    ]);
}

/**
 * Helper per creare una fattura passiva
 */
function creaFattura(
    $condominioId, $fornitoreId, $esercizioId, $importoCents, $pagatoCents = 0, $statoPag = 'aperta', 
    $statoAppr = 'approvata', $scadenza = null, $isPregresso = false
) {
    $fatturaId = DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $condominioId,
        'esercizio_id' => $esercizioId,
        'fornitore_id' => $fornitoreId,
        'tipo_documento' => 'fattura',
        'numero_documento' => 'FATT-' . rand(1000, 9999),
        'netto_a_pagare' => $importoCents,
        'totale_documento' => $importoCents,
        'importo_imponibile' => $importoCents,
        'importo_iva' => 0,
        'data_scadenza' => $scadenza ?? now(),
        'stato_pagamento' => $statoPag,
        'stato_approvazione' => $statoAppr,
        'is_pregresso' => $isPregresso,
        'data_documento' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($pagatoCents > 0) {
        $scritturaId = DB::table('scritture_contabili')->insertGetId([
            'condominio_id' => $condominioId,
            'gestione_id' => 1,
            'esercizio_id' => 1,
            'data_registrazione' => now(),
            'data_competenza' => now(),
            'numero_protocollo' => 'PROT-' . rand(1000, 9999),
            'causale' => 'Pagamento fornitore',
            'tipo_movimento' => 'pagamento_fornitore',
            'stato' => 'registrata'
        ]);
        
        DB::table('fattura_scrittura')->insert([
            'fattura_passiva_id' => $fatturaId,
            'scrittura_contabile_id' => $scritturaId,
            'importo_allocato' => $pagatoCents,
            'tipo' => 'pagamento'
        ]);
    }

    return $fatturaId;
}

/**
 * Helper per creare una rata emessa
 */
function creaRata($pianoRateId, $importoCents, $pagatoCents = 0, $stato = 'emessa', $scadenza = null) {
    $rataId = DB::table('rate')->insertGetId([
        'piano_rate_id' => $pianoRateId,
        'importo_totale' => $importoCents,
        'numero_rata' => rand(1, 10),
        'stato' => $stato,
        'data_scadenza' => $scadenza,
        'data_emissione' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    if ($pagatoCents > 0) {
        $scritturaId = DB::table('scritture_contabili')->insertGetId([
            'condominio_id' => 1,
            'gestione_id' => 1,
            'esercizio_id' => 1,
            'data_registrazione' => now(),
            'data_competenza' => now(),
            'numero_protocollo' => 'PROT-' . rand(1000, 9999),
            'causale' => 'Incasso rata',
            'tipo_movimento' => 'incasso_rata',
            'stato' => 'registrata'
        ]);
        
        DB::table('righe_scritture')->insert([
            'rata_id' => $rataId,
            'scrittura_id' => $scritturaId,
            'conto_contabile_id' => 1, // Assume existence of conto 1 for test setup
            'tipo_riga' => 'dare',
            'importo' => $pagatoCents,
        ]);
    }

    return $rataId;
}


test('Scenario verde: cassa abbondante, niente fatture o uscite sotto controllo', function () {
    assegnaLiquidita($this->condominio->id, $this->contoLiquiditaId, 500000, $this->gestioneId); // 5.000€
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 0, 'aperta', 'approvata', now()->addDays(10)); // Uscita 1.000€

    $status = $this->service->perCondominio($this->condominio->id);

    expect($status->livello)->toBe('verde')
        ->and($status->liquiditaTotaleCents)->toBe(500000)
        ->and($status->uscitePredittiveCents)->toBe(100000)
        ->and($status->giornoScopertoPrevisto)->toBeNull()
        ->and($status->scenarioPessimisticoCents)->toBe(400000);
});

test('Scenario rosso: liquidità insufficiente per fatture imminenti senza incassi', function () {
    assegnaLiquidita($this->condominio->id, $this->contoLiquiditaId, 50000, $this->gestioneId); // 500€
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 0, 'aperta', 'approvata', now()->addDays(5)); // Uscita 1.000€

    $status = $this->service->perCondominio($this->condominio->id);

    expect($status->livello)->toBe('rosso')
        ->and($status->liquiditaTotaleCents)->toBe(50000)
        ->and($status->uscitePredittiveCents)->toBe(100000)
        ->and($status->giornoScopertoPrevisto)->not->toBeNull()
        ->and($status->scenarioPessimisticoCents)->toBe(-50000);
});

test('Scenario giallo: cassa non basta ma incassi attesi la salvano', function () {
    assegnaLiquidita($this->condominio->id, $this->contoLiquiditaId, 50000, $this->gestioneId); // 500€
    
    creaRata($this->pianoRateId, 100000, 0, 'emessa', now()->addDays(2)); // +1.000€
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 0, 'aperta', 'approvata', now()->addDays(5)); // -1.000€

    $status = $this->service->perCondominio($this->condominio->id);

    // Scenario pessimistico (no incassi) = 500 - 1000 = -500 (negativo)
    // Scenario ottimistico (incassi pagati) = 500 + 1000 - 1000 = +500 (positivo)
    // Un pessimistico negativo e un ottimistico positivo generano il livello giallo!
    expect($status->livello)->toBe('giallo')
        ->and($status->scenarioPessimisticoCents)->toBe(-50000)
        ->and($status->scenarioOttimisticoCents)->toBe(50000);
});

test('Residuo corretto: la timeline calcola il netto ancora da pagare o da incassare', function () {
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 40000, 'parziale', 'approvata', now()->addDays(5)); // Residuo: 60.000
    creaRata($this->pianoRateId, 50000, 20000, 'emessa', now()->addDays(5)); // Residuo: 30.000

    $status = $this->service->perCondominio($this->condominio->id);

    expect($status->uscitePredittiveCents)->toBe(60000)
        ->and($status->incassiAttesiCents)->toBe(30000);
});

test('Esclusione di fatture fuori dalla finestra dei 30 giorni', function () {
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 0, 'aperta', 'approvata', now()->addDays(40));

    $status = $this->service->perCondominio($this->condominio->id);

    expect($status->uscitePredittiveCents)->toBe(0);
});

test('Debiti pregressi scaduti: evitano l\'alert fatigue e si contano a parte', function () {
    // Fattura di 1 anno fa
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 150000, 0, 'aperta', 'approvata', now()->subDays(300), true);

    $status = $this->service->perCondominio($this->condominio->id);

    expect($status->debitiPregressiScadutiCents)->toBe(150000)
        ->and($status->uscitePredittiveCents)->toBe(0) // Non entra nella previsione a 30gg!
        ->and($status->scenarioPessimisticoCents)->toBe(0); // Non toglie cassa corrente nella previsione!
});

test('Fatture scadute ma non pregresse vengono clampate ad "oggi" (Giorno 0)', function () {
    // Fattura ordinaria appena scaduta (es. scaduta ieri)
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 0, 'aperta', 'approvata', now()->subDays(2), false);

    $status = $this->service->perCondominio($this->condominio->id);

    // Essendo non pregresso, va nelle uscite predittive
    expect($status->uscitePredittiveCents)->toBe(100000)
        ->and($status->giornoScopertoPrevisto)->toBe(now()->toDateString()); // Lo scoperto si calcola da "oggi"
});


test('Fatture stornate non partecipano alla prediction', function () {
    creaFattura($this->condominio->id, $this->fornitoreId, $this->esercizioId, 100000, 0, 'stornata', 'approvata', now()->addDays(5));

    $status = $this->service->perCondominio($this->condominio->id);

    expect($status->uscitePredittiveCents)->toBe(0);
});

test('Filtro per gestione_id aggrega liquidita e rate per quella gestione', function () {
    assegnaLiquidita($this->condominio->id, $this->contoLiquiditaId, 50000, $this->gestioneId); // 500€
    // Dobbiamo aggiornare la scrittura per averla su quella gestione:
    DB::table('scritture_contabili')->update(['gestione_id' => $this->gestioneId]);

    // Rata emessa sulla gestione_id
    creaRata($this->pianoRateId, 100000, 0, 'emessa', now()->addDays(5));

    $status = $this->service->perCondominio($this->condominio->id, $this->gestioneId);

    expect($status->liquiditaTotaleCents)->toBe(50000)
        ->and($status->incassiAttesiCents)->toBe(100000);
});

test('Fattura senza scadenza: segnalata come anomalia, non conteggiata nelle uscite (§6.2)', function () {
    // Fattura aperta con data_scadenza = NULL
    $fatturaId = DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'esercizio_id' => $this->esercizioId,
        'fornitore_id' => $this->fornitoreId,
        'tipo_documento' => 'fattura',
        'numero_documento' => 'FATT-NULL',
        'netto_a_pagare' => 75000,
        'totale_documento' => 75000,
        'importo_imponibile' => 75000,
        'importo_iva' => 0,
        'data_scadenza' => null,  // NULL esplicito!
        'stato_pagamento' => 'aperta',
        'stato_approvazione' => 'approvata',
        'is_pregresso' => false,
        'data_documento' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $status = $this->service->perCondominio($this->condominio->id);

    // Non deve entrare nelle uscite predittive
    expect($status->uscitePredittiveCents)->toBe(0)
        // Ma deve essere segnalata come anomalia
        ->and($status->fattureSenzaScadenza)->toHaveCount(1)
        ->and($status->fattureSenzaScadenza[0]['numero'])->toBe('FATT-NULL')
        ->and($status->fattureSenzaScadenza[0]['importoCents'])->toBe(75000);
});

test('Storno di pagamento: la fattura torna come uscita con residuo corretto (§6.8)', function () {
    // 1. Crea fattura da 100.000 cents con pagamento di 100.000 (pagata)
    $fatturaId = DB::table('fatture_passive')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'esercizio_id' => $this->esercizioId,
        'fornitore_id' => $this->fornitoreId,
        'tipo_documento' => 'fattura',
        'numero_documento' => 'FATT-STORNO',
        'netto_a_pagare' => 100000,
        'totale_documento' => 100000,
        'importo_imponibile' => 100000,
        'importo_iva' => 0,
        'data_scadenza' => now()->addDays(10),
        'stato_pagamento' => 'aperta',  // Lo storno la riapre
        'stato_approvazione' => 'approvata',
        'is_pregresso' => false,
        'data_documento' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // 2. Simula il pagamento (pivot positivo)
    $scritturaIdPag = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'gestione_id' => $this->gestioneId,
        'esercizio_id' => $this->esercizioId,
        'data_registrazione' => now(),
        'data_competenza' => now(),
        'numero_protocollo' => 'PROT-PAG',
        'causale' => 'Pagamento fornitore',
        'tipo_movimento' => 'pagamento_fornitore',
        'stato' => 'registrata',
    ]);

    DB::table('fattura_scrittura')->insert([
        'fattura_passiva_id' => $fatturaId,
        'scrittura_contabile_id' => $scritturaIdPag,
        'importo_allocato' => 100000,  // +100.000
        'tipo' => 'pagamento',
    ]);

    // 3. Simula lo storno (pivot negativo, stesso tipo — pattern del codebase)
    $scritturaIdStorno = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $this->condominio->id,
        'gestione_id' => $this->gestioneId,
        'esercizio_id' => $this->esercizioId,
        'data_registrazione' => now(),
        'data_competenza' => now(),
        'numero_protocollo' => 'PROT-STO',
        'causale' => 'Storno pagamento',
        'tipo_movimento' => 'storno_pagamento_fornitore',
        'stato' => 'registrata',
    ]);

    DB::table('fattura_scrittura')->insert([
        'fattura_passiva_id' => $fatturaId,
        'scrittura_contabile_id' => $scritturaIdStorno,
        'importo_allocato' => -100000,  // -100.000 (lo storno è importo negativo, stesso tipo)
        'tipo' => 'pagamento',
    ]);

    // Il residuo netto è: 100.000 - (100.000 + -100.000) = 100.000
    // La fattura torna completamente scoperta
    $status = $this->service->perCondominio($this->condominio->id);

    expect($status->uscitePredittiveCents)->toBe(100000)
        ->and($status->fattureInScadenza)->toHaveCount(1)
        ->and($status->fattureInScadenza[0]['importoCents'])->toBe(100000);
});
