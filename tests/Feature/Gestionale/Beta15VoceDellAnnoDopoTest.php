<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Gli interessi addebitati nel 2026 e versati nel 2027 vanno alla voce dell'anno dopo con lo stesso nome e lo stesso tipo. Se nel piano dei conti del
 * 2027 «Interessi di mora» è un capitolo con delle voci sotto, il versamento non va sul capitolo (che non ha tabelle, e il conguaglio lo direbbe «non
 * attribuito»): va a una voce con le tabelle; se l'unica con quel nome è il capitolo, nasce la voce nuova con le tabelle della voce d'origine.
 * E la frase della Sintesi, con le spese non attribuite sotto zero, dice «entrate», con la cifra senza il meno.
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\RataQuote;
use App\Services\Gestionale\Accessori\AccessoriDaIncassare;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\ConsuntivoPerPersona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/AccessoriScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Neri con gli interessi al 29/06/2026 (€ 1,18), il 2026 chiuso, il 2027 con la sua gestione ordinaria e il piano dei conti. */
function b15vadScenario($test): array
{
    $s = accViaVerdi($test);
    $test->travelTo('2026-06-29 10:00:00');
    $rataId = accRegistra($test, $s, accProposta($test, $s, ['Neri'])->json())->assertCreated()->json('rate.0');
    [$e27, $g27] = adbAnnoDopo($s, 'chiuso');
    $pc27 = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g27->id, 'nome' => 'PC 2027']);

    return [$s, RataQuote::where('rata_id', $rataId)->firstOrFail(), $e27, $pc27];
}

/** Il capitolo «Interessi di mora» nel piano dei conti del 2027, con una voce sotto: nessuna tabella sul capitolo. */
function b15vadCapitolo(array $s, $pc27, string $sotto = 'Interessi sulle rate'): Conto
{
    $capitolo = Conto::create(['piano_conto_id' => $pc27->id, 'nome' => 'Interessi di mora', 'tipo' => $s['interessi']->tipo, 'importo' => 0, 'is_capitolo' => true,
        'conto_contabile_id' => $s['interessi']->conto_contabile_id]);
    Conto::create(['piano_conto_id' => $pc27->id, 'parent_id' => $capitolo->id, 'nome' => $sotto, 'tipo' => $s['interessi']->tipo, 'importo' => 0,
        'conto_contabile_id' => $s['interessi']->conto_contabile_id]);

    return $capitolo;
}

function b15vadVersa($test, array $s, RataQuote $quota)
{
    $test->travelTo('2027-01-20 10:00:00');

    return $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['Neri']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2027-01-20', 'importo_totale' => '1.18',
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => '1.18']],
    ]);
}

it('con il capitolo vuoto «Interessi di mora» nel 2027 il versamento va alla voce nuova con le tabelle, e il conguaglio del 2027 lo divide', function () {
    [$s, $quota, $e27, $pc27] = b15vadScenario($this);
    $capitolo = b15vadCapitolo($s, $pc27);
    $diviso = fn () => ConguaglioDelRendiconto::totaliDelCondominio((new ConsuntivoPerPersona())->perEsercizio($e27->fresh()));
    $prima = $diviso();

    b15vadVersa($this, $s, $quota)->assertSessionHasNoErrors()->assertRedirect();

    $id = (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
    $voci = DB::table('righe_scritture')->where('scrittura_id', $id)->whereNotNull('voce_spesa_id')->pluck('voce_spesa_id')->all();
    expect($voci)->toHaveCount(1)->and($voci[0])->not->toBe($capitolo->id);
    $voce = Conto::find($voci[0]);
    expect([$voce->nome, (int) $voce->piano_conto_id, $voce->parent_id])->toBe(['Interessi di mora', (int) $pc27->id, null])
        ->and(DB::table('conto_tabella_millesimale')->where('conto_id', $voce->id)->pluck('tabella_id')->all())
        ->toBe(DB::table('conto_tabella_millesimale')->where('conto_id', $s['interessi']->id)->pluck('tabella_id')->all());

    // La catena: il conguaglio del 2027 divide il versato fra le persone e non lo dice «non attribuito».
    $dopo = $diviso();
    expect($dopo['a'] - $prima['a'])->toBe(-118)
        ->and($dopo['non_attribuite'])->toBe($prima['non_attribuite']);
});

it('fra due voci con lo stesso nome la scelta cade su quella con le tabelle; mai sul capitolo con le voci sotto', function () {
    [$s, , $e27, $pc27] = b15vadScenario($this);
    $servizio = app(AccessoriDaIncassare::class);
    b15vadCapitolo($s, $pc27);
    $senzaTabelle = Conto::create(['piano_conto_id' => $pc27->id, 'nome' => 'Interessi di mora', 'tipo' => $s['interessi']->tipo, 'importo' => 0,
        'conto_contabile_id' => $s['interessi']->conto_contabile_id]);
    $conTabelle = accVoce(['c' => $s['c'], 'pianoConto' => $pc27, 'generali' => $s['generali']], 'Interessi di mora', $s['interessi']->tipo);

    expect($servizio->voceNellEsercizio($s['interessi']->id, $e27)->id)->toBe($conTabelle->id);

    DB::table('conto_tabella_ripartizioni')->whereIn('conto_tabella_millesimale_id', DB::table('conto_tabella_millesimale')->where('conto_id', $conTabelle->id)->pluck('id'))->delete();
    DB::table('conto_tabella_millesimale')->where('conto_id', $conTabelle->id)->delete();
    // Senza tabelle tutte e due: la prima creata, come prima; il capitolo no.
    expect($servizio->voceNellEsercizio($s['interessi']->id, $e27)->id)->toBe($senzaTabelle->id);
});

it('la frase della Sintesi con le spese non attribuite sotto zero dice le entrate, senza il meno', function () {
    expect(ConguaglioDelRendiconto::fraseNonAttribuite(-2066))->toBe('€ 20,66 di entrate non attribuite a nessuno: le dice la scheda Conguaglio, con il perché.')
        ->and(ConguaglioDelRendiconto::fraseNonAttribuite(12345))->toBe('€ 123,45 di spese non attribuite a nessuno: le dice la scheda Conguaglio, con il perché.')
        ->and(ConguaglioDelRendiconto::fraseNonAttribuite(0))->toBeNull();
});
