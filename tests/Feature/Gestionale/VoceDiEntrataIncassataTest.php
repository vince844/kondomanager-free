<?php

/**
 * La voce di entrata con un incasso da terzi, nelle pagine che leggono lo speso per voce: il piano dei conti, il cruscotto e i capitoli della
 * creazione del piano rate.
 *
 * L'incasso scrive AVERE sul mastro della voce, con la voce: lo speso della voce di entrata (dare meno avere) è −€ 240,00. Le pagine non lo
 * mostrano come uno speso negativo e non lo sommano alle spese: la voce di entrata dice «incassato € 240,00», il consuntivo del piano dei conti
 * resta la somma delle sole voci di spesa (€ 1.200,00) e l'incassato ha il suo totale; il cruscotto dice uscite per € 1.200,00, non € 960,00; i
 * capitoli non dicono «sforo» e non portano uno speso negativo.
 *
 * Scenario: spese € 1.200,00 pagate con una regolazione, entrata € 240,00 incassata il 15/02. Le cifre vengono dalla regola (spese e entrate
 * separate), non dal codice.
 */

use App\Models\Anagrafica;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

beforeEach(function () {
    $this->user = be52Utente();
    // Le pagine si leggono dalla loro rotta: il test non dipende da una compilazione di Vite.
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    $this->travelTo(Carbon::parse('2026-11-07 10:00', 'Europe/Rome'));
});

/** Spese € 1.200,00 pagate con una regolazione il 10/03 e l'antenna incassata il 15/02 (€ 240,00). */
function veiScenario($test, bool $conIncasso = true): array
{
    $s = be52Scenario($test);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $test->actingAs($test->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), [
        'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $s['spesa']->id, 'cassa_id' => $s['cassa']->id, 'fornitore_id' => null,
        'data_operazione' => '2026-03-10', 'causale' => 'Spese generali pagate', 'importo' => 1200.00,
    ])->assertSessionHasNoErrors();
    if ($conIncasso) {
        $gestore = Anagrafica::forceCreate(['nome' => 'Torri Spa', 'email' => "vei{$s['seq']}@test.it", 'indirizzo' => 'Via Po 1', 'codice_fiscale' => 'VEITORRI'.str_pad((string) $s['seq'], 8, '0', STR_PAD_LEFT)]);
        $test->actingAs($test->user)->post(route('admin.gestionale.incassi-da-terzi.store', [$s['c']]), [
            'esercizio_id' => $s['e']->id, 'conto_id' => $s['entrata']->id, 'cassa_id' => $s['cassa']->id, 'data_operazione' => '2026-02-15',
            'importo' => 240.00, 'anagrafica_id' => $gestore->id, 'causale' => 'Affitto antenna 2026',
        ])->assertSessionHasNoErrors();
    }

    return $s;
}

function veiProps($test, string $rotta, array $parametri): array
{
    return $test->actingAs($test->user)->get(route($rotta, $parametri))->assertOk()->viewData('page')['props'];
}

it('il piano dei conti: la voce di entrata dice incassato € 240,00 e speso zero, mai −€ 240,00', function () {
    $s = veiScenario($this);
    $p = veiProps($this, 'admin.gestionale.esercizi.piani-conti.show', [$s['c'], $s['e'], $s['pc']]);
    $voci = collect($p['conti'])->keyBy('nome');

    expect($voci['Affitto antenna']['speso_raw'])->toBe(0)
        ->and($voci['Affitto antenna']['incassato_raw'])->toBe(24000)
        ->and($voci['Spese generali']['speso_raw'])->toBe(120000)
        ->and($voci['Spese generali']['incassato_raw'])->toBe(0);
});

it('il piano dei conti: il consuntivo somma le sole spese (€ 1.200,00) e l\'incassato ha il suo totale (€ 240,00)', function () {
    $s = veiScenario($this);
    $p = veiProps($this, 'admin.gestionale.esercizi.piani-conti.show', [$s['c'], $s['e'], $s['pc']]);

    expect($p['totaleConsuntivo'])->toBe(120000)
        ->and($p['totaleIncassato'])->toBe(24000);
});

it('controprova: senza l\'incasso il consuntivo è € 1.200,00 e l\'incassato zero', function () {
    $s = veiScenario($this, conIncasso: false);
    $p = veiProps($this, 'admin.gestionale.esercizi.piani-conti.show', [$s['c'], $s['e'], $s['pc']]);

    expect($p['totaleConsuntivo'])->toBe(120000)
        ->and($p['totaleIncassato'])->toBe(0);
});

it('il cruscotto: le uscite sono € 1.200,00, l\'incasso dell\'entrata non si sottrae come se fosse una spesa', function () {
    $s = veiScenario($this);
    $copertura = veiProps($this, 'admin.gestionale.index', [$s['c']->id])['copertura'];

    expect($copertura['uscite_totali'])->toBe(120000);
});

it('i capitoli della creazione del piano: l\'entrata non è uno sforo e non porta uno speso negativo', function () {
    $s = veiScenario($this);
    $righe = collect($this->actingAs($this->user)->getJson(route('admin.gestionale.fetch-capitoli-gestione', [$s['c']]).'?gestione_id='.$s['g']->id.'&esercizio_id='.$s['e']->id)
        ->assertOk()->json())->keyBy('id');

    expect($righe[$s['entrata']->id]['is_sforo'])->toBeFalse()
        ->and((float) $righe[$s['entrata']->id]['speso_reale'])->toBe(0.0)
        ->and((float) $righe[$s['spesa']->id]['speso_reale'])->toBe(1200.0);
});

/** I dati di prima su una voce di entrata: una fattura da € 500,00 registrata sull'antenna (com'era possibile prima che la registrazione le rifiutasse). */
function veiFatturaVecchiaSullEntrata(array $s): void
{
    be52FatturaDalServizio($s, be52Fornitore(), $s['entrata']->id, 500.00, 'FT-VECCHIA-ENTRATA');
}

it('i dati di prima su una voce di entrata si leggono come prima: consuntivo € 1.700,00, uscite € 1.700,00, nessun incassato', function () {
    $s = veiScenario($this, conIncasso: false);
    veiFatturaVecchiaSullEntrata($s);
    $p = veiProps($this, 'admin.gestionale.esercizi.piani-conti.show', [$s['c'], $s['e'], $s['pc']]);
    $voci = collect($p['conti'])->keyBy('nome');
    $copertura = veiProps($this, 'admin.gestionale.index', [$s['c']->id])['copertura'];

    expect($p['totaleConsuntivo'])->toBe(170000)
        ->and($p['totaleIncassato'])->toBe(0)
        ->and($voci['Affitto antenna']['speso_raw'])->toBe(50000)
        ->and($voci['Affitto antenna']['incassato_raw'])->toBe(0)
        ->and($copertura['uscite_totali'])->toBe(170000);
});

it('con i dati di prima e l\'incasso dell\'antenna: incassato € 240,00, e il consuntivo resta € 1.700,00', function () {
    $s = veiScenario($this);
    veiFatturaVecchiaSullEntrata($s);
    $p = veiProps($this, 'admin.gestionale.esercizi.piani-conti.show', [$s['c'], $s['e'], $s['pc']]);
    $voci = collect($p['conti'])->keyBy('nome');
    $righe = collect($this->actingAs($this->user)->getJson(route('admin.gestionale.fetch-capitoli-gestione', [$s['c']]).'?gestione_id='.$s['g']->id.'&esercizio_id='.$s['e']->id)->json())->keyBy('id');

    expect($p['totaleConsuntivo'])->toBe(170000)
        ->and($p['totaleIncassato'])->toBe(24000)
        ->and($voci['Affitto antenna']['speso_raw'])->toBe(50000)
        ->and($voci['Affitto antenna']['incassato_raw'])->toBe(24000)
        ->and(veiProps($this, 'admin.gestionale.index', [$s['c']->id])['copertura']['uscite_totali'])->toBe(170000)
        ->and((float) $righe[$s['entrata']->id]['speso_reale'])->toBe(500.0);
});

it('lo storno dell\'incasso toglie l\'incassato: la voce torna a zero', function () {
    $s = veiScenario($this);
    $sc = \App\Models\Gestionale\ScritturaContabile::where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_diverso')->firstOrFail();
    $this->actingAs($this->user)->post(route('admin.gestionale.incassi-da-terzi.storno', [$s['c'], $sc]), ['natura_storno' => 'versamento_tornato', 'motivo' => 'Bonifico richiamato dal gestore'])
        ->assertSessionHasNoErrors();
    $p = veiProps($this, 'admin.gestionale.esercizi.piani-conti.show', [$s['c'], $s['e'], $s['pc']]);

    expect(collect($p['conti'])->keyBy('nome')['Affitto antenna']['incassato_raw'])->toBe(0)
        ->and($p['totaleIncassato'])->toBe(0);
});
