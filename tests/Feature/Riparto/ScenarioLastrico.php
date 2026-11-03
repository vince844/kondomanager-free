<?php

/**
 * Lo scenario del capitolo del lastrico solare (art. 1126 c.c.), condiviso dai test del riparto che lo usano:
 *
 *   - tests/Feature/Riparto/CoefficientiSottoIlCentoTest.php
 *   - tests/Feature/Riparto/GiaVersatoSottoDecurtazioneTest.php
 *
 * NON contiene nessun it() o test, solo l'aiutante: ogni file che lo usa lo carica con `require_once`, così gira da solo e insieme agli altri.
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\Immobile;
use App\Models\Tabella;
use Illuminate\Support\Facades\DB;

/**
 * Il capitolo del lastrico: € 9.000, una sola tabella collegata con il coefficiente indicato.
 *
 * Prende l'**elenco** dei coefficienti: una tabella collegata per ciascuno, tutte con la stessa
 * unità dentro. `[33.33]` è lo scenario del difetto; `[100]` è la controprova; `[33.33, 33.33,
 * 33.33]` è il caso in cui la somma fa 99,99 per la sola precisione della colonna.
 */
function scenarioLastrico(array $coefficienti): array
{
    $condominio = Condominio::factory()->create();

    $esercizio = Esercizio::create([
        'condominio_id' => $condominio->id,
        'nome'          => '2026',
        'data_inizio'   => '2026-01-01',
        'data_fine'     => '2026-12-31',
        'stato'         => 'aperto',
    ]);

    $gestione = Gestione::create([
        'condominio_id' => $condominio->id,
        'esercizio_id'  => $esercizio->id,
        'nome'          => 'Ordinaria 2026',
        'tipo'          => 'ordinaria',
        'descrizione'   => 'Gestione con il capitolo del lastrico',
    ]);

    $pianoConto = PianoConto::create([
        'condominio_id' => $condominio->id,
        'gestione_id'   => $gestione->id,
        'nome'          => 'PC 2026',
    ]);

    // € 9.000,00 in centesimi interi, come vuole la convenzione del progetto.
    $conto = Conto::create([
        'piano_conto_id' => $pianoConto->id,
        'nome'           => 'Rifacimento lastrico solare',
        'tipo'           => 'spesa',
        'natura_spesa'   => 'ordinaria',
        'importo'        => 900000,
    ]);

    // Il titolare dell'uso esclusivo: l'unica unità dentro queste tabelle.
    $immobile = Immobile::create([
        'condominio_id'   => $condominio->id,
        'tipo'            => 'appartamento',
        'codice_immobile' => 'C1-ATT',
        'nome'            => 'Attico',
        'descrizione'     => 'Con lastrico a uso esclusivo',
        'interno'         => '10',
    ]);

    foreach ($coefficienti as $n => $coefficiente) {
        $tabella = Tabella::create([
            'condominio_id' => $condominio->id,
            'nome'          => 'Lastrico — platea '.($n + 1),
            'tipo'          => 'lastrico',
            'quota'         => 'millesimi',
            'attiva'        => true,
        ]);

        $pivotId = DB::table('conto_tabella_millesimale')->insertGetId([
            'conto_id'     => $conto->id,
            'tabella_id'   => $tabella->id,
            'coefficiente' => $coefficiente,
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

        DB::table('quote_tabella')->insert([
            'tabella_id'  => $tabella->id,
            'immobile_id' => $immobile->id,
            'valore'      => 1000.0,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    $anagrafica = Anagrafica::factory()->create();
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $anagrafica->id,
        'immobile_id'   => $immobile->id,
        'tipologia'     => 'proprietario',
        'quota'         => 100,
        'attivo'        => true,
        'data_inizio'   => now(),
        'created_at'    => now(),
        'updated_at'    => now(),
    ]);

    $pianoRate = PianoRate::create([
        'gestione_id'   => $gestione->id,
        'condominio_id' => $condominio->id,
        'nome'          => 'Piano lastrico',
        'stato'         => 'bozza',
        'tipo'          => 'ordinario',
    ]);

    return ['piano' => $pianoRate, 'immobile' => $immobile, 'conto' => $conto];
}
