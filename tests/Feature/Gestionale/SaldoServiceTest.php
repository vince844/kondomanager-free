<?php

use App\Models\Condominio;
use App\Models\Gestione;
use App\Services\Gestionale\SaldoEsercizioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service    = new SaldoEsercizioService();
    $this->condominio = Condominio::factory()->create();
});

// ─── TEST 1 ──────────────────────────────────────────────────────────────────
// Verifica che il saldo matematico venga calcolato correttamente
// dalla tabella `saldi` (wallet v1.9) quando la gestione non è bloccata.
// ─────────────────────────────────────────────────────────────────────────────
test('calcola correttamente il saldo matematico dalla tabella saldi v1.9', function () {
    $gestione = Gestione::factory()->create([
        'condominio_id'   => $this->condominio->id,
        'saldo_applicato' => false,
    ]);

    $esercizio = \App\Models\Esercizio::factory()->create([
        'condominio_id' => $this->condominio->id,
    ]);
    $immobile = \App\Models\Immobile::create([
        'condominio_id' => $this->condominio->id,
        'nome'          => 'Int 1',
        'descrizione'   => 'Test',
        'interno'       => '1',
        'foglio'        => '1', 'particella' => '1', 'subalterno' => '1',
    ]);

    DB::table('saldi')->insert([
        'gestione_id'    => $gestione->id,
        'esercizio_id'   => $esercizio->id,
        'condominio_id'  => $this->condominio->id,
        'immobile_id'    => $immobile->id,
        'anagrafica_id'  => null,
        'saldo_iniziale' => 10000, // 100€ in centesimi
        'is_applicato'   => false,
        'created_at'     => now(),
        'updated_at'     => now(),
    ]);

    $result = $this->service->calcolaSaldoApplicabile($gestione);

    expect($result['saldo'])->toBe(10000)
        ->and($result['applicabile'])->toBeTrue();
});

// ─── TEST 2 ──────────────────────────────────────────────────────────────────
// Il service deve restituire applicabile=false se la gestione ha già
// il lock `saldo_applicato = true`.
// ─────────────────────────────────────────────────────────────────────────────
test('blocca l\'applicazione se la gestione ha già il lock saldo_applicato', function () {
    $ordinaria = Gestione::factory()->create([
        'condominio_id'   => $this->condominio->id,
        'nome'            => 'Ordinaria',
        'saldo_applicato' => true,
    ]);

    $result = $this->service->calcolaSaldoApplicabile($ordinaria);

    expect($result['applicabile'])->toBeFalse()
        ->and(strtolower($result['motivo']))->toContain('ordinaria');
});

// ─── TEST 3 ──────────────────────────────────────────────────────────────────
// Se la gestione NON ha il lock, il service deve restituire applicabile=true.
// ─────────────────────────────────────────────────────────────────────────────
test('permette l\'applicazione se la gestione non ha il lock', function () {
    $gestione = Gestione::factory()->create([
        'condominio_id'   => $this->condominio->id,
        'saldo_applicato' => false,
    ]);

    $result = $this->service->calcolaSaldoApplicabile($gestione);

    expect($result['applicabile'])->toBeTrue();
});