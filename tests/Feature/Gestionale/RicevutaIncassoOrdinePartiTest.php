<?php

use App\Models\Anagrafica;
use App\Models\Gestionale\RataQuote;
use App\Models\Immobile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * L'ordine scelto per la stampa in blocco (per persona, per unità, per data del versamento) vale per tutte le parti del PDF, dalla prima
 * all'ultima: quando le ricevute sono più del tetto di un PDF le persone si dividono nell'ordine scelto, e la prima parte porta le prime.
 *
 * Che cosa NON copre: l'ordine dentro una parte (nei test della stampa) e la pagina (nei test JavaScript).
 */
function ordinePartiIncassi(object $s, int $quanti, Anagrafica $persona, string $dal, string $data, RataQuote $quota, int $primoNumero): array
{
    $righe = [];
    for ($i = $primoNumero; $i < $primoNumero + $quanti; $i++) {
        $righe[] = [
            'condominio_id' => $s->condominio->id, 'esercizio_id' => $s->esercizio->id, 'gestione_id' => $s->gestione->id,
            'data_registrazione' => $data, 'data_competenza' => $data, 'numero_protocollo' => sprintf('INC-2026-%05d', $i),
            'causale' => 'Incasso', 'tipo_movimento' => 'incasso_rata', 'stato' => 'registrata', 'created_at' => now(), 'updated_at' => now(),
        ];
    }
    DB::table('scritture_contabili')->insert($righe);
    $ids = DB::table('scritture_contabili')->where('condominio_id', $s->condominio->id)->whereIn('numero_protocollo', array_column($righe, 'numero_protocollo'))->pluck('id')->all();
    DB::table('righe_scritture')->insert(array_merge(...array_map(fn ($id) => [
        ['scrittura_id' => $id, 'conto_contabile_id' => $s->banca->conto_contabile_id, 'cassa_id' => $s->banca->id, 'tipo_riga' => 'dare', 'importo' => 1000, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $id, 'conto_contabile_id' => $s->banca->conto_contabile_id, 'tipo_riga' => 'avere', 'anagrafica_id' => $persona->id, 'importo' => 1000, 'created_at' => now(), 'updated_at' => now()],
    ], $ids)));
    DB::table('quota_scrittura')->insert(array_map(fn ($id) => [
        'scrittura_contabile_id' => $id, 'rate_quota_id' => $quota->id, 'importo_pagato' => 1000, 'data_pagamento' => $data, 'created_at' => now(), 'updated_at' => now(),
    ], $ids));

    return $ids;
}

function ordinePartiPartiDelPdf($test, object $s, string $ordine): array
{
    return $test->actingAs(amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.anteprima', ['condominio' => $s->condominio->id]), ['tutte' => 1, 'filtri' => ['ricevuta' => 'tutte'], 'ordine' => $ordine])
        ->assertOk()->json('parti_pdf');
}

test('più ricevute del tetto: le parti del PDF seguono l\'ordine scelto, per persona, per unità o per data, dalla prima all\'ultima', function () {
    $s = scenarioRicevutaIncasso();
    // Anna Bruni (Int. 4): 150 ricevute di febbraio. Carlo Bruni (Int. 1): 55 ricevute di gennaio. In ordine di nome viene prima Anna, di unità e di data prima Carlo.
    $unitaDiCarlo = Immobile::create(['condominio_id' => $s->condominio->id, 'nome' => 'Appartamento 1', 'descrizione' => 'Appartamento', 'interno' => '1']);
    $unitaDiCarlo->anagrafiche()->attach($s->carlo->id, ['tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => '2020-01-01']);
    $quotaDiCarlo = RataQuote::create(['rata_id' => $s->rate[1]->id, 'anagrafica_id' => $s->carlo->id, 'immobile_id' => $unitaDiCarlo->id,
        'importo' => 1000, 'importo_pagato' => 0, 'stato' => 'da_pagare', 'data_scadenza' => $s->rate[1]->data_scadenza]);
    $anna = ordinePartiIncassi($s, 150, $s->anna, '2026-02-01', '2026-02-01', $s->anna1, 1);
    $carlo = ordinePartiIncassi($s, 55, $s->carlo, '2026-01-15', '2026-01-15', $quotaDiCarlo, 151);
    $insieme = fn (array $ids) => collect($ids)->sort()->values()->all();

    foreach (['persona' => [$anna, $carlo], 'unita' => [$carlo, $anna], 'data' => [$carlo, $anna]] as $ordine => [$prima, $seconda]) {
        $parti = ordinePartiPartiDelPdf($this, $s, $ordine);
        expect(array_map('count', $parti))->toBe([count($prima), count($seconda)], "ordine {$ordine}: le parti")
            ->and($insieme($parti[0]))->toBe($insieme($prima), "ordine {$ordine}: la prima parte")
            ->and($insieme($parti[1]))->toBe($insieme($seconda), "ordine {$ordine}: l'ultima parte");
    }

    // Senza l'ordine in richiesta vale «per persona», come la stampa.
    $senza = $this->actingAs(amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.anteprima', ['condominio' => $s->condominio->id]), ['tutte' => 1, 'filtri' => ['ricevuta' => 'tutte']])->assertOk()->json('parti_pdf');
    expect(array_map('count', $senza))->toBe([150, 55]);
});
