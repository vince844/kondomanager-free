<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La voce che riceve gli interessi e le spese di sollecito addebitati a una persona deve avere una tabella millesimale: senza, una volta versati,
 * il denaro resterebbe fra le «spese non attribuite» del conguaglio.
 *
 * Un esercizio 2026, tre persone (Ugo 400, Carla 300, Giulia 300 millesimi), Pulizie € 1.200,00 in 12 rate emesse il 31/01. Il 10/06 a Giulia
 * € 12,16 di interessi di mora e € 8,50 di spese di sollecito, sulla voce di entrata «Interessi di mora». Tre forme della voce:
 * - P: creata dalla rotta del piano dei conti («Aggiungi voce», con la tabella), come la crea il prodotto;
 * - M: scritta a mano come nel database del sito (nessuna riga in `conto_tabella_millesimale`);
 * - K: creata dalla rotta come capitolo di entrata (nessuna tabella, nessun importo chiesto).
 */

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\RataQuote;
use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\ControlliDelRendiconto;
use App\Services\Gestionale\SintesiDelRendiconto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/RendicontoViaRomaScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
    $this->withoutVite();
});

function lnaBase($test): array
{
    vrmGiorno($test, '2026-01-02');
    $s = be52Condominio();
    be52Unita($s, 'A', 'ugo', $s['tabella'], 400.0);
    be52Unita($s, 'B', 'carla', $s['tabella'], 300.0);
    be52Unita($s, 'C', 'giulia', $s['tabella'], 300.0);
    $s['pc'] = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'nome' => 'Piano dei conti']);
    $s['pulizie'] = be52Voce($test, $s, $s['pc'], 'Pulizie', 'spesa', '1.200,00');
    $s['piano'] = be52PianoDallaRotta($test, $s);
    $test->actingAs($test->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), ['approvato' => true, 'data_delibera_assemblea' => '2026-01-02'])->assertSessionHasNoErrors();
    vrmGiorno($test, '2026-01-31');
    $ids = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->orderBy('numero_rata')->pluck('id')->all();
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), ['rate_ids' => $ids, 'data_emissione' => '2026-01-31', 'invia_notifiche' => false])->assertSessionHasNoErrors();

    return $s;
}

/** La voce «Interessi di mora» in una delle tre forme. */
function lnaVoce($test, array $s, string $forma): Conto
{
    if ($forma === 'P') {
        return be52Voce($test, $s, $s['pc'], 'Interessi di mora', 'entrata', '0,00');
    }
    if ($forma === 'K') {
        be52PostVoce($test, $s, ['nome' => 'Interessi di mora', 'tipo' => 'entrata', 'isCapitolo' => true, 'importo' => null,
            'tabella_millesimale_id' => null, 'percentuale_proprietario' => null, 'percentuale_inquilino' => null, 'percentuale_usufruttuario' => null])->assertSessionHasNoErrors();

        return Conto::where('piano_conto_id', $s['pc']->id)->where('nome', 'Interessi di mora')->firstOrFail();
    }
    // M: la riga del sito (conti id 10 di km_sito_b10), con il mastro che la rotta dà a una voce «standard».
    $mastro = (int) Conto::find($s['pulizie']->id)->conto_contabile_id;

    return Conto::create(['piano_conto_id' => $s['pc']->id, 'parent_id' => null, 'is_capitolo' => false, 'richiede_gia_versato' => false, 'conto_contabile_id' => $mastro,
        'nome' => 'Interessi di mora', 'tipo' => 'entrata', 'tipo_spesa' => 'standard', 'importo' => 0, 'attivo' => true]);
}

/** Interessi € 12,16 e sollecito € 8,50 a Giulia, il 10/06; le due quote. */
function lnaAddebita($test, array $s, Conto $voce): array
{
    vrmGiorno($test, '2026-06-10');
    $quote = [];
    foreach ([['interessi', 1216, 'legge'], ['sollecito', 850, 'regolamento']] as [$tipo, $importo, $base]) {
        $rata = app(AddebitiIndividuali::class)->registra($s['c'], $s['g'], $s['e'], [[
            'anagrafica_id' => $s['persone']['giulia']->id, 'immobile_id' => $s['unita']['C']->id, 'importo' => $importo, 'causale' => ucfirst($tipo),
            'scadenza' => '2026-06-10', 'natura' => 'accessorio', 'tipo_accessorio' => $tipo, 'base' => $base, 'riferimento' => 'prova', 'conto_id' => $voce->id,
        ]], $test->user->id, '2026-06-10')[0];
        $quote[(int) RataQuote::where('rata_id', $rata->id)->value('id')] = $importo;
    }

    return $quote;
}

/** Il riquadro del conguaglio della Sintesi, il controllo CG e le spese divise a Giulia sulla voce. */
function lnaLeggi(array $s, Conto $voce): array
{
    $e = $s['e']->fresh();
    $letto = app(ConguaglioDelRendiconto::class)->leggi($e);
    $c = app(ControlliDelRendiconto::class)->perEsercizio($s['c'], $e, null, [], null, null, [], $letto);
    $cg = collect($c['controlli'])->firstWhere('id', 'CG');
    $riquadro = app(SintesiDelRendiconto::class)->perEsercizio($s['c'], $e)['riquadri']['conguaglio'];
    $g = $letto['gestioni'][$s['g']->id];
    $persone = $letto['persone'][$s['g']->id] ?? [];
    $suVoce = fn (string $chi, string $u) => (int) ($persone[$s['persone'][$chi]->id][$s['unita'][$u]->id]['voci'][$voce->id] ?? 0);

    return [
        'riquadro' => $riquadro['non_attribuite'],
        'non_attribuite' => $g['non_attribuite'],
        'motivi' => $g['motivi'],
        'cg' => $cg['esito'],
        'cg_nota' => $cg['nota'],
        'voce' => [$suVoce('ugo', 'A'), $suVoce('carla', 'B'), $suVoce('giulia', 'C')],
    ];
}

it('P, la voce creata dal prodotto: nessuna spesa non attribuita, né prima né dopo il versamento; versati, gli accessori scendono le spese di tutti', function () {
    $s = lnaBase($this);
    $voce = lnaVoce($this, $s, 'P');
    $quote = lnaAddebita($this, $s, $voce);

    $prima = lnaLeggi($s, $voce);
    expect($prima['riquadro'])->toBeNull()->and($prima['non_attribuite'])->toBe([])->and($prima['cg'])->toBe('quadra')->and($prima['voce'])->toBe([0, 0, 0]);

    vrmGiorno($this, '2026-06-20');
    vrmIncassa($this, $s, 'giulia', $quote, '2026-06-20');
    $dopo = lnaLeggi($s, $voce);
    expect($dopo['riquadro'])->toBeNull()->and($dopo['non_attribuite'])->toBe([])->and($dopo['cg'])->toBe('quadra')
        ->and(array_sum($dopo['voce']))->toBe(-2066);
});

it('M, la voce che perde la tabella dopo l\'addebito (la forma del sito): versati, gli accessori sono € -20,66 di spese non attribuite, con il perché', function () {
    $s = lnaBase($this);
    $voce = lnaVoce($this, $s, 'P');
    $quote = lnaAddebita($this, $s, $voce);
    // La forma della voce 10 di km_sito_b10: nessuna riga in `conto_tabella_millesimale` (qui tolta dopo l'addebito, che ora la vuole).
    DB::table('conto_tabella_ripartizioni')->whereIn('conto_tabella_millesimale_id', DB::table('conto_tabella_millesimale')->where('conto_id', $voce->id)->pluck('id'))->delete();
    DB::table('conto_tabella_millesimale')->where('conto_id', $voce->id)->delete();

    $prima = lnaLeggi($s, $voce);
    expect($prima['riquadro'])->toBeNull()->and($prima['non_attribuite'])->toBe([]);

    vrmGiorno($this, '2026-06-20');
    vrmIncassa($this, $s, 'giulia', $quote, '2026-06-20');
    $dopo = lnaLeggi($s, $voce);
    expect($dopo['non_attribuite'])->toBe([$voce->id => -2066])->and($dopo['motivi'])->toBe([$voce->id => ['conto_senza_tabella' => 2066]])
        ->and($dopo['cg'])->toBe('quadra')->and($dopo['voce'])->toBe([0, 0, 0])
        ->and($dopo['riquadro'])->toBe('€ 20,66 di entrate non attribuite a nessuno: le dice la scheda Conguaglio, con il perché.');
});

it('una voce senza tabella millesimale non riceve il denaro: la finestra non la offre e l\'addebito si rifiuta con il perché', function () {
    $s = lnaBase($this);
    $capitolo = lnaVoce($this, $s, 'K');
    $buona = be52Voce($this, $s, $s['pc'], 'Spese di sollecito', 'entrata', '0,00');
    $aMano = Conto::create(['piano_conto_id' => $s['pc']->id, 'parent_id' => null, 'is_capitolo' => false, 'richiede_gia_versato' => false,
        'conto_contabile_id' => $buona->conto_contabile_id, 'nome' => 'Interessi scritti a mano', 'tipo' => 'entrata', 'tipo_spesa' => 'standard', 'importo' => 0, 'attivo' => true]);

    $voci = collect($this->actingAs($this->user)->getJson(route('admin.gestionale.addebiti.spese.proposta', [$s['c']]) . '?' . http_build_query(['persone' => [$s['persone']['giulia']->id]]))
        ->assertOk()->json('dove.scelte.0.voci'))->pluck('id')->all();
    expect($voci)->toContain($buona->id)->and($voci)->not->toContain($capitolo->id)->and($voci)->not->toContain($aMano->id);

    foreach ([$capitolo, $aMano] as $voce) {
        $rifiuto = null;
        try {
            lnaAddebita($this, $s, $voce);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $rifiuto = $e->errors()['addebiti'][0] ?? null;
        }
        expect($rifiuto)->toBe('La voce «' . $voce->nome . '» non ha una tabella millesimale: il denaro versato non si dividerebbe nel conguaglio. '
            . 'Collega una tabella alla voce nel piano dei conti, o scegli un\'altra voce.');
    }
    expect(RataQuote::where('tipo', RataQuote::TIPO_ACCESSORIO)->count())->toBe(0);
});
