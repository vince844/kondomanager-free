<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Un addebito a una persona che muore passa ai suoi eredi con le loro quote: ogni erede ne ha la sua parte (il resto di un centesimo a uno solo, nessun
 * centesimo perso o doppio), il suo B nel conguaglio resta quello delle sole quote, il suo saldo del conguaglio e il suo estratto conto crescono della sua
 * parte, i controlli del rendiconto non cambiano. Se poi un erede vende la sua parte, il compratore è come senza l'addebito, e l'addebito resta a chi l'ha
 * ereditato.
 *
 * Il caso: Via Roma (`RendicontoViaRomaScenario.php`), Bice titolare di C con un addebito di € 50,00 (o € 50,01) del 20/11/2026, morta con la decorrenza del
 * 01/12/2026; due eredi al 70 e 30 %, o tre al 34, 33 e 33 %.
 */

use App\Models\Gestionale\RataQuote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/RendicontoViaRomaScenario.php';
require_once __DIR__.'/LetturaDebitiProva.php';
require_once __DIR__.'/AddebitiInOgniCombinazioneScenario.php';

beforeEach(function () {
    $this->user = be52Utente();
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

function aeqPersona(array &$s, string $nome): \App\Models\Anagrafica
{
    $p = \App\Models\Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome) . '-aeq' . $s['c']->id . '@test.it', 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => 'SSS' . strtoupper(substr($nome, 0, 3)) . str_pad((string) $s['c']->id, 10, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);
    $s['persone'][strtolower($nome)] = $p;

    return $p;
}

function aeqRiga(array $s, string $chi): int
{
    return (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['persone'][$chi]->id)->where('immobile_id', $s['unita']['C']->id)
        ->whereNull('data_fine')->value('id');
}

/** @param array<string,int> $eredi nome => quota */
function aeqSuccessione($test, ?string $euro, array $eredi): array
{
    $s = vrmScenario($test);
    $persone = [];
    foreach ($eredi as $nome => $q) {
        $persone[] = ['anagrafica_id' => aeqPersona($s, $nome)->id, 'quota' => $q];
    }
    if ($euro !== null) {
        vrmGiorno($test, '2026-11-20');
        $s['addebito'] = aocAddebita($test, $s, [['chi' => 'bice', 'unita' => 'C', 'euro' => $euro, 'scadenza' => '2026-11-20']])->assertCreated()->json('rate.0');
    }
    vrmGiorno($test, '2026-12-05');
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['C']]), [
        'tipo' => 'successione', 'riga_uscente_id' => aeqRiga($s, 'bice'), 'decorrenza' => '2026-12-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => $persone, 'arretrato' => 'eredi', 'erede_di_riferimento' => $persone[0]['anagrafica_id'],
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 1', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Dichiarazione letta',
    ])->assertSessionHasNoErrors()->assertRedirect();

    return $s;
}

function aeqSaldi(array $s, ?string $descrizione = null): array
{
    $nomi = collect($s['persone'])->mapWithKeys(fn ($p, $k) => [(int) $p->id => $k])->all();

    return DB::table('saldi')->where('condominio_id', $s['c']->id)->whereNotNull('subentro_id')
        ->when($descrizione !== null, fn ($q) => $q->where('descrizione', 'like', $descrizione . '%'))
        ->groupBy('anagrafica_id')->selectRaw('anagrafica_id, SUM(saldo_iniziale) as s')
        ->pluck('s', 'anagrafica_id')->mapWithKeys(fn ($v, $id) => [$nomi[(int) $id] => (int) $v])->sortKeys()->all();
}

it('due eredi al 70 e 30 %, tre eredi al 34/33/33 con € 50,01: le parti, il resto, il conguaglio, gli estratti', function (array $eredi, string $euro, int $cent) {
    $senza = aeqSuccessione($this, null, $eredi);
    $vSenza = aeqLeggi($this, $senza);
    $con = aeqSuccessione($this, $euro, $eredi);
    $vCon = aeqLeggi($this, $con);
    $riga = fn (array $v, string $chi) => array_values($v['conguaglio'][$chi])[0];

    $tutti = aeqSaldi($con);
    $addebiti = aeqSaldi($con, 'Addebiti agli eredi');
    $estratti = [];
    foreach (array_keys($eredi) as $n) {
        $k = strtolower($n);
        $estratti[$k] = aocEstratto($this, $con, $k)['saldo'];
    }

    expect(array_sum($tutti))->toBe(0)
        ->and(array_sum(array_diff_key($addebiti, ['bice' => 1])))->toBe($cent)
        ->and($addebiti['bice'])->toBe(-$cent)
        ->and(array_sum($estratti))->toBe(41000 + $cent)
        ->and($vCon['controlli'])->toBe($vSenza['controlli']);
    foreach (array_keys($eredi) as $n) {
        $k = strtolower($n);
        // B dell'erede: le sole quote, come senza l'addebito; il saldo del conguaglio: quello senza più la sua parte dell'addebito.
        expect($riga($vCon, $k)[2])->toBe($riga($vSenza, $k)[2])
            ->and($riga($vCon, $k)[5] - $riga($vSenza, $k)[5])->toBe($addebiti[$k]);
    }
    expect($riga($vCon, 'bice')[3])->toBe($riga($vSenza, 'bice')[3]);
})->with([
    'due eredi 70/30' => [['Elena' => 70, 'Fabio' => 30], '50,00', 5000],
    'tre eredi 34/33/33' => [['Elena' => 34, 'Fabio' => 33, 'Gino' => 33], '50,01', 5001],
]);

it('la vendita dopo la successione: Elena vende la sua metà a Dario; Dario come senza l\'addebito, l\'addebito resta di Elena', function () {
    $run = function (bool $conAddebito) {
        $s = aeqSuccessione($this, $conAddebito ? '50,00' : null, ['Elena' => 50, 'Fabio' => 50]);
        $dario = aeqPersona($s, 'Dario');
        vrmGiorno($this, '2026-12-20');
        $r = $this->actingAs($this->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['C']]), [
            'tipo' => 'vendita', 'riga_uscente_id' => aeqRiga($s, 'elena'), 'anagrafica_entrante_id' => $dario->id, 'decorrenza' => '2026-12-15',
            'quota' => 50, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-12-16',
            'estremi_titolo' => 'atto notaio Bruni, rep. 9', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Rogito letto',
        ]);
        $r->assertSessionHasNoErrors();

        return $s;
    };
    $senza = $run(false);
    $con = $run(true);
    $vSenza = aeqLeggi($this, $senza);
    $vCon = aeqLeggi($this, $con);
    $riga = fn (array $v, string $chi) => array_values($v['conguaglio'][$chi] ?? [[]])[0];
    expect($riga($vCon, 'dario'))->toBe($riga($vSenza, 'dario'))
        ->and(aocEstratto($this, $con, 'dario'))->toBe(aocEstratto($this, $senza, 'dario'))
        // La metà di Elena dell'addebito di € 50,00 resta sua.
        ->and(aocEstratto($this, $con, 'elena')['saldo'] - aocEstratto($this, $senza, 'elena')['saldo'])->toBe(2500)
        ->and($vCon['controlli'])->toBe($vSenza['controlli']);
});

function aeqLeggi($test, array $s): array
{
    vrmGiorno($test, '2027-01-15');

    return aocRendiconto($test, $s, $s['e']);
}
