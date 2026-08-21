<?php

/**
 * Il già-versato si scomputa **per intero** anche quando la spesa viene decurtata da uno scoperto.
 *
 * ## Il difetto
 *
 * `nettingGiaVersato()` applica la copertura storica in proporzione a quanto **questa chiamata**
 * rappresenta del budget del capitolo:
 *
 *     $quota = $totaleQuestaChiamata / abs($conto->importo)
 *
 * Serve per un motivo giusto: un capitolo può essere finanziato da più chiamate indipendenti — un
 * acconto oggi e un saldo fra un mese — e applicare ogni volta l'intera copertura la
 * sottrarrebbe due volte.
 *
 * Ma `$totaleQuestaChiamata` è l'importo **già decurtato dagli scoperti**. E uno scoperto non è
 * una seconda tranche che arriverà: è una parte che **non verrà chiesta a nessuno, mai**. Il
 * risultato è che la copertura viene scomputata solo per la frazione ripartita, e la differenza
 * viene **richiesta di nuovo a chi l'aveva già versata**.
 *
 * ## Perché è la beta.63 a occuparsene
 *
 * Il difetto è **preesistente**: valeva già per le quote orfane e per le tabelle senza millesimi,
 * cioè da quando esistono gli scoperti. Ma questa beta ha aggiunto una terza causa di scoperto —
 * i coefficienti sotto il cento — che è di gran lunga la più facile da incontrare: basta collegare
 * una tabella al 33,33% e rimandare la seconda. Chi rende un difetto raggiungibile se ne fa
 * carico, ed è la regola che questo progetto si è dato.
 *
 * ## Lo scenario
 *
 * Lastrico da € 9.000, una sola tabella collegata al 33,33% (manca quella dei condòmini serviti).
 * Il piano chiede quindi € 2.999,70. Il titolare aveva già versato € 1.000,00 su quella voce.
 *
 * - Prima: scomputati € 333,00 su € 1.000,00 → gli si chiedevano **€ 2.666,70**, di cui € 667,00
 *   già in cassa del condominio.
 * - Ora: scomputati tutti e € 1.000,00 → gli si chiedono € 1.999,70.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Gestionale\ContributoVersato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('la copertura già versata si scomputa tutta, anche se la spesa è decurtata', function () {
    $s = scenarioLastrico([33.33]);

    ContributoVersato::create([
        'condominio_id'  => $s['immobile']->condominio_id,
        'target_type'    => \App\Models\Gestionale\Conto::class,
        'target_id'      => $s['conto']->id,
        'immobile_id'    => $s['immobile']->id,
        'anagrafica_id'  => null,
        'importo_cents'  => 100000,   // € 1.000,00 già versati
        'natura'         => 'fondo',
        'origine'        => 'manuale',
        'descrizione'    => 'Acconto versato prima del piano',
    ]);

    app(GeneratePianoRateAction::class)->execute($s['piano'], accettaScoperti: true, notaScoperti: 'Manca la tabella dei serviti');

    $addebitato = (int) DB::table('rate_quote')->where('immobile_id', $s['immobile']->id)->sum('importo');

    // € 2.999,70 di quota lorda meno i € 1.000,00 già versati.
    expect($addebitato)->toBe(199970);
});
