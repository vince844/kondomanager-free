<?php

/**
 * Coda 438 — il piano straordinario (da fatture) toglie il già versato che vale nel suo esercizio, come il piano ordinario (decisione 183.1).
 *
 * Il motore ha due entrate, `calcolaPerGestione` e `calcolaDaFattureStraordinarie`: tutte e due fissano l'esercizio del piano per il netting. Lo scenario:
 * una fattura del 2026 con € 1.000,00 sulla voce da € 1.000,00, ripartita su un'unità sola (1.000 millesimi) del proprietario, e un suo già versato di € 300,00
 * sulla voce.
 *
 * Fonte dell'atteso: la regola; € 1.000,00 − € 300,00 = € 700,00 se il già versato vale nel 2026, € 1.000,00 se vale in un altro esercizio.
 */

use App\Models\Anagrafica;
use App\Models\Esercizio;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Tabella;
use App\Services\CalcoloQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

/** Il piano straordinario del 2026 e il proprietario dell'unità; il già versato vale per `$valePer` (l'esercizio del piano, un altro, o null per tutti). */
function g112sScenario(string $valePer): array
{
    [$condominio, $esercizio, $gestione, $fornitore, $voce, , $immobileId] = setupContabile();
    $tabella = Tabella::create(['condominio_id' => $condominio->id, 'nome' => 'GEN', 'quota' => 'millesimi']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $immobileId, 'valore' => 1000, 'created_at' => now(), 'updated_at' => now()]);
    $proprietario = Anagrafica::factory()->create();
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $proprietario->id, 'immobile_id' => $immobileId, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true, 'data_inizio' => now()]);
    // La voce vale la fattura: la chiamata è l'intera voce, e il netting toglie tutto il già versato che vale (quota 1).
    \App\Models\Gestionale\Conto::whereKey($voce->id)->update(['importo' => 100000]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $voce->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);

    $fattura = FatturaPassiva::create(['condominio_id' => $condominio->id, 'fornitore_id' => $fornitore->id, 'esercizio_id' => $esercizio->id, 'tipo_documento' => 'fattura',
        'numero_documento' => 'FT-438', 'data_documento' => '2026-03-10', 'data_scadenza' => '2026-04-10', 'is_pregresso' => false, 'importo_imponibile' => 100000, 'importo_iva' => 0,
        'importo_ritenuta' => 0, 'totale_documento' => 100000, 'netto_a_pagare' => 100000, 'stato_pagamento' => 'aperta', 'stato_approvazione' => 'approvata', 'modalita_pagamento' => 'bonifico']);
    DB::table('righe_fattura')->insert(['fattura_passiva_id' => $fattura->id, 'conto_id' => $voce->id, 'immobile_id' => null, 'descrizione' => 'Facciata', 'aliquota_iva' => 0,
        'importo_imponibile' => 100000, 'importo_iva' => 0, 'is_sopravvenienza' => true, 'is_rateizzata' => false, 'created_at' => now(), 'updated_at' => now()]);
    $piano = PianoRate::create(['gestione_id' => $gestione->id, 'condominio_id' => $condominio->id, 'esercizio_id' => $esercizio->id, 'nome' => 'Straordinario', 'stato' => 'bozza', 'tipo' => 'straordinario']);
    $piano->fatture()->attach($fattura->id, ['importo_collegato' => 100000]);

    $altro = Esercizio::create(['condominio_id' => $condominio->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    ContributoVersato::create(['condominio_id' => $condominio->id, 'target_type' => \App\Models\Gestionale\Conto::class, 'target_id' => $voce->id, 'immobile_id' => $immobileId,
        'anagrafica_id' => $proprietario->id, 'importo_cents' => 30000, 'natura' => 'fondo_vincolato', 'origine' => 'migrazione',
        'esercizio_id' => match ($valePer) { 'piano' => $esercizio->id, 'altro' => $altro->id, default => null }]);

    return [$piano, $proprietario->id, $immobileId];
}

it('Coda 438 — il piano straordinario del 2026 toglie il già versato del 2026 e quello senza esercizio, non quello del 2027', function (string $valePer, int $atteso) {
    [$piano, $chi, $unita] = g112sScenario($valePer);

    $totali = app(CalcoloQuoteService::class)->calcolaDaFattureStraordinarie($piano);

    expect($totali[$chi][$unita] ?? null)->toBe($atteso);
})->with([
    'vale per il 2026, l\'esercizio del piano' => ['piano', 70000],
    'vale per tutti gli esercizi' => ['tutti', 70000],
    'vale per il 2027' => ['altro', 100000],
]);
