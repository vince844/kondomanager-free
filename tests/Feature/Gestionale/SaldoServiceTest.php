<?php

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote; // Corretto in RataQuote
use App\Models\User;
use App\Services\Gestionale\SaldoEsercizioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new SaldoEsercizioService();
    $this->condominio = Condominio::factory()->create();
});

test('calcola correttamente il saldo matematico dall\'esercizio precedente chiuso', function () {
    // 1. Creiamo l'Anagrafica reale per evitare il Foreign Key constraint failed
    $anagrafica = \App\Models\Anagrafica::factory()->create();

    // 2. Setup Esercizio 2025 (CHIUSO)
    $esercizio2025 = Esercizio::factory()->create([
        'condominio_id' => $this->condominio->id,
        'data_inizio' => '2025-01-01',
        'data_fine' => '2025-12-31',
        'stato' => 'chiuso'
    ]);

    // 3. Setup Gestione e Piano Rate nel 2025
    $gestione2025 = Gestione::factory()->create(['condominio_id' => $this->condominio->id]);
    $gestione2025->esercizi()->attach($esercizio2025->id);
    
    $pianoRate2025 = PianoRate::factory()->create(['gestione_id' => $gestione2025->id]);
    $rata = Rata::factory()->create(['piano_rate_id' => $pianoRate2025->id]);

    // 4. Simula un debito: Importo 100€, Pagato 0€
    // Usiamo l'ID dell'anagrafica appena creata
    RataQuote::factory()->create([
        'rata_id' => $rata->id,
        'anagrafica_id' => $anagrafica->id,
        'importo' => 10000, 
        'importo_pagato' => 0,
        'stato' => 'da_pagare'
    ]);

    // 5. Setup Esercizio 2026 (CORRENTE)
    $esercizio2026 = Esercizio::factory()->create([
        'condominio_id' => $this->condominio->id,
        'data_inizio' => '2026-01-01',
        'data_fine' => '2026-12-31',
        'stato' => 'aperto'
    ]);

    // 6. Esegui il calcolo
    $result = $this->service->calcolaSaldoApplicabile($this->condominio, $esercizio2026, $anagrafica->id);

    // 7. Asserzioni
    expect($result['saldo'])->toBe(10000)
        ->and($result['applicabile'])->toBeTrue();
});

test('mantiene saldo_applicato a 0 se la creazione del piano rate fallisce', function () {
    // Setup con saldo a 0
    $ordinaria = Gestione::factory()->create(['saldo_applicato' => 0]);
    
    // Forziamo il CreatorService a lanciare un'eccezione (simulando un errore DB improvviso)
    $this->mock(PianoRateCreatorService::class, function (MockInterface $mock) {
        $mock->shouldReceive('creaPianoRate')->andThrow(new \Exception("Errore imprevisto"));
    });

    // Eseguiamo la chiamata
    // OPZIONE A — se non servono parametri
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store'));

    // OPZIONE B — se servono i parametri del condominio/esercizio
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.store', [
        'condominio' => $condominio->id,
        'esercizio'  => $esercizio->id,
    ]));

    // Verifichiamo che il valore sia rimasto 0 (Rollback della transazione)
    $valoreDb = DB::table('gestioni')->where('id', $ordinaria->id)->value('saldo_applicato');
    expect((int)$valoreDb)->toBe(0);
});

test('blocca l\'applicazione se un\'altra gestione ha già il lock', function () {
    $esercizio = Esercizio::factory()->create([
        'condominio_id' => $this->condominio->id,
        'stato' => 'aperto'
    ]);

    $ordinaria = Gestione::factory()->create([
        'condominio_id' => $this->condominio->id,
        'nome' => 'Ordinaria',
        'saldo_applicato' => true 
    ]);
    $ordinaria->esercizi()->attach($esercizio->id);

    $result = $this->service->calcolaSaldoApplicabile($this->condominio, $esercizio, 1);

    // Usiamo un controllo più flessibile sul messaggio per evitare errori di case-sensitivity
    expect($result['applicabile'])->toBeFalse();
    expect(strtolower($result['motivo']))->toContain('ordinaria'); 
});

test('permette l\'applicazione se nessuna gestione ha il lock', function () {
    $esercizio = Esercizio::factory()->create([
        'condominio_id' => $this->condominio->id,
        'stato' => 'aperto'
    ]);

    $vecchiaStraordinaria = Gestione::factory()->create([
        'condominio_id' => $this->condominio->id,
        'saldo_applicato' => false 
    ]);
    $vecchiaStraordinaria->esercizi()->attach($esercizio->id);

    $result = $this->service->calcolaSaldoApplicabile($this->condominio, $esercizio, 1);

    expect($result['applicabile'])->toBeTrue();
});