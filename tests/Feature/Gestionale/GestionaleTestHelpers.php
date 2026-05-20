<?php

/**
 * Helper condivisi per i test del modulo Gestionale.
 *
 * Incluso da:
 *   - tests/Feature/Gestionale/FatturaPassivaServiceTest.php
 *   - tests/Feature/Gestionale/PagamentoFornitoreServiceTest.php
 *
 * NON contiene nessun it() o test — solo funzioni di supporto.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

function setupContabile(): array
{
    // Disabilita listener che dipendono da tabelle non rilevanti per questi test
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);

    $condominio = \App\Models\Condominio::factory()->create();

    $esercizioId = DB::table('esercizi')->insertGetId([
        'condominio_id' => $condominio->id,
        'nome'          => 'Esercizio 2026',
        'stato'         => 'aperto',
        'data_inizio'   => '2026-01-01',
        'data_fine'     => '2026-12-31',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $gestioneId = DB::table('gestioni')->insertGetId([
        'condominio_id' => $condominio->id,
        'nome'          => 'Gestione Ordinaria',
        'tipo'          => 'ordinaria',
        'attiva'        => true,
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $fornitoreId = DB::table('fornitori')->insertGetId([
        'ragione_sociale'            => 'Fornitore Test Srl',
        'soggetto_ritenuta'          => false,
        'perc_imponibile_ritenuta'   => 100,
        'perc_ritenuta'              => 4,
        'giorni_scadenza'            => 30,
        'modalita_pagamento_default' => 'bonifico',
        'created_at'                 => now(),
        'updated_at'                 => now(),
    ]);

    foreach (['debiti_fornitori','crediti_condomini','debiti_erario_ritenute','passate_gestioni','sopravvenienze_passive'] as $ruolo) {
        DB::table('conti_contabili')->insert([
            'condominio_id' => $condominio->id,
            'ruolo'         => $ruolo,
            'codice'        => strtoupper($ruolo),
            'nome'          => ucfirst(str_replace('_', ' ', $ruolo)),
            'tipo'          => 'passivo',
            'categoria'     => 'debiti',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    $contoFondoId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominio->id,
        'ruolo'         => 'fondo_riserva',
        'codice'        => 'FONDO',
        'nome'          => 'Fondo Riserva',
        'tipo'          => 'passivo',
        'categoria'     => 'fondi',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $contoContabileId = DB::table('conti_contabili')->insertGetId([
        'condominio_id' => $condominio->id,
        'ruolo'         => null,
        'codice'        => 'COSTO-TEST',
        'nome'          => 'Conto Costo Test',
        'tipo'          => 'attivo',
        'categoria'     => 'crediti',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $pianoContoId = DB::table('piani_conti')->insertGetId([
        'gestione_id'   => $gestioneId,
        'condominio_id' => $condominio->id,
        'nome'          => 'Piano dei Conti Test',
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $capitoloId = DB::table('conti')->insertGetId([
        'piano_conto_id'     => $pianoContoId,
        'conto_contabile_id' => $contoContabileId,
        'nome'               => 'Manutenzione Test',
        'tipo'               => 'spesa',
        'importo'            => 500000,
        'is_tecnico'         => false,
        'created_at'         => now(),
        'updated_at'         => now(),
    ]);

    $immobileId = DB::table('immobili')->insertGetId([
        'condominio_id'   => $condominio->id,
        'interno'         => '1',
        'nome'            => 'Appartamento Test',
        'descrizione'     => '',
        'codice_immobile' => 'APP-TEST-1',
        'attivo'          => true,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    $esercizio = \App\Models\Esercizio::find($esercizioId);
    $gestione  = \App\Models\Gestione::find($gestioneId);

    return [
        $condominio,
        $esercizio,
        $gestione,
        \App\Models\Fornitore::find($fornitoreId),
        Conto::find($capitoloId),
        ContoContabile::find($contoFondoId),
        $immobileId,
    ];
}

function assertQuadraturaPerfetta(int $scritturaId): void
{
    $dare  = DB::table('righe_scritture')->where('scrittura_id', $scritturaId)->where('tipo_riga', 'dare')->sum('importo');
    $avere = DB::table('righe_scritture')->where('scrittura_id', $scritturaId)->where('tipo_riga', 'avere')->sum('importo');

    expect((int) $dare)->toBeGreaterThan(0, 'Nessun movimento DARE')
        ->and((int) $avere)->toBeGreaterThan(0, 'Nessun movimento AVERE')
        ->and((int) $dare)->toEqual((int) $avere, "Sbilancio! DARE: $dare | AVERE: $avere");
}

function datiBase(array $ctx, array $extra = []): array
{
    [$condominio, $esercizio, $gestione, $fornitore] = $ctx;
    return array_merge([
        'fornitore_id'       => $fornitore->id,
        'esercizio_id'       => $esercizio->id,
        'gestione_id'        => $gestione->id,
        'tipo_documento'     => 'fattura',
        'numero_documento'   => 'FT-' . uniqid(),
        'data_documento'     => now()->format('Y-m-d'),
        'data_scadenza'      => now()->addDays(30)->format('Y-m-d'),
        'modalita_pagamento' => 'bonifico',
        'dati_extra'         => ['fiscal' => [], 'competenza' => null, 'override_budget' => null],
    ], $extra);
}