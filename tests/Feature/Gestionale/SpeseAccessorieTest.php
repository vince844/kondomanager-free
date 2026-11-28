<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le spese per il ritardo addebitate a una persona: il tipo (sollecito, legali prima della causa e della causa, altro), la base dichiarata con il suo
 * riferimento, gli avvisi che non bloccano mai; da dove viene il denaro (la fattura dello studio con la riga sull'unità, o una spesa pagata dal
 * condominio senza fattura, che va su «Accessori da incassare» e alla sua voce quando è versata).
 */

use App\Models\Gestionale\RataQuote;
use App\Services\Gestionale\Accessori\BaseDegliAccessori;
use App\Services\Gestionale\Addebiti\AddebitiIndividuali;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AccessoriScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

it('il sollecito di € 25,00 fatturato dallo studio con la riga su U1, base «delibera del 14/03/2026, verbale n. 1»: le personali non chieste a zero, CA torna, il saldo di Neri resta € 425,00', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    cpcSpese($s);
    $riga = cpcSpesaPersonale($s, 'U1', 2500, '2026-05-15');
    $prima = cpcDi(cpcLeggi($this, $s), $s, 'Neri', 'U1');
    expect([$prima['personali']['non_chieste'], $prima['saldo']])->toBe([2500, 42500]);

    $this->travelTo('2026-06-10 10:00:00');
    $rataId = adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '25,00', 'causale' => 'Spese di sollecito', 'natura' => 'accessorio', 'riga_fattura_id' => $riga,
        'scadenza' => '2026-06-10', 'tipo_accessorio' => 'sollecito', 'base' => 'delibera', 'riferimento' => 'del 14/03/2026, verbale n. 1']])->assertCreated()->json('rate.0');

    $letto = cpcLeggi($this, $s);
    $dopo = cpcDi($letto, $s, 'Neri', 'U1');
    expect($dopo['personali']['non_chieste'])->toBe(0)->and($dopo['saldo'])->toBe(42500)->and($dopo['posizione'] - $prima['posizione'])->toBe(2500)
        ->and(\App\Services\Gestionale\ControlliDelConguaglio::personali($letto)['esito'])->toBe('quadra');

    // La riga dell'estratto conto: che cos'è e su che cosa si fonda; per un sollecito con una delibera nessun avviso. L'emissione è quella di sempre:
    // la riga del riparto chiude l'anticipazione, niente su «Accessori da incassare».
    $suo = collect(app(AddebitiIndividuali::class)->dellaPersona($s['c']->id, $s['persone']['Neri']->id))->firstWhere('rata_id', $rataId);
    expect([$suo['tipo'], $suo['base'], $suo['avvisi'], $suo['spesa_fatturata']])->toBe(['sollecito', 'una delibera dell\'assemblea, del 14/03/2026, verbale n. 1', [], true])
        ->and(DB::table('conti_contabili')->where('condominio_id', $s['c']->id)->where('ruolo', 'accessori_da_incassare')->exists())->toBeFalse();
});

it('la base si mostra e non blocca: gli avvisi delle spese legali e del sollecito senza base, e la spesa si registra lo stesso', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-06-10 10:00:00');
    $casi = [
        ['legali_stragiudiziali', 'delibera', [BaseDegliAccessori::AVVISO_STRAGIUDIZIALI_DELIBERA]],
        ['legali_stragiudiziali', 'regolamento', []],
        ['legali_giudiziali', 'nessuna', [BaseDegliAccessori::AVVISO_GIUDIZIALI]],
        ['legali_giudiziali', 'provvedimento', []],
        ['sollecito', 'nessuna', [BaseDegliAccessori::AVVISO_SOLLECITO]],
        ['altro', 'nessuna', []],
    ];
    foreach ($casi as [$tipo, $base, $avvisi]) {
        $rataId = adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '10,00', 'causale' => 'Spesa ' . $tipo . ' ' . $base, 'natura' => 'accessorio',
            'tipo_accessorio' => $tipo, 'base' => $base, 'conto_id' => $s['postali']->id, 'scadenza' => '2026-06-10']])->assertCreated()->json('rate.0');
        $suo = collect(app(AddebitiIndividuali::class)->dellaPersona($s['c']->id, $s['persone']['Neri']->id))->firstWhere('rata_id', $rataId);
        expect($suo['avvisi'])->toBe($avvisi, "{$tipo} con {$base}")->and(accEmissione($rataId)[1][0])->toBe('accessori_da_incassare');
    }
});

it('senza fattura servono il tipo, la base e la voce della gestione: la scelta è dell\'amministratore, nessuna già fatta', function () {
    $s = accViaVerdi($this);
    $voce = fn (array $extra) => [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '5,00', 'causale' => 'Raccomandata', 'natura' => 'accessorio'] + $extra];
    adbRegistra($this, $s, $voce([]))->assertStatus(422)
        ->assertJsonPath('errors.addebiti.0', 'Scegli che cos\'è la spesa: interessi di mora, spese di sollecito, spese legali o altro.');
    adbRegistra($this, $s, $voce(['tipo_accessorio' => 'sollecito']))->assertStatus(422)
        ->assertJsonPath('errors.addebiti.0', 'Scegli la base della spesa: la legge, il regolamento, una delibera, un provvedimento del giudice o nessuna.');
    adbRegistra($this, $s, $voce(['tipo_accessorio' => 'sollecito', 'base' => 'regolamento']))->assertStatus(422)
        ->assertJsonPath('errors.addebiti.0', 'Scegli la voce che riceve il denaro quando la persona lo versa: una voce della gestione «Ordinaria 2026». Se non c\'è, creala nel piano dei conti con il preventivo a zero e una tabella millesimale collegata.');
    // Una voce di un altro condominio è un 404.
    $altro = accViaVerdi($this);
    adbRegistra($this, $s, $voce(['tipo_accessorio' => 'sollecito', 'base' => 'regolamento', 'conto_id' => $altro['postali']->id]))->assertNotFound();

    $rataId = adbRegistra($this, $s, $voce(['tipo_accessorio' => 'sollecito', 'base' => 'regolamento', 'riferimento' => 'art. 20', 'conto_id' => $s['postali']->id]))
        ->assertCreated()->json('rate.0');
    $dati = AddebitiIndividuali::dati(RataQuote::where('rata_id', $rataId)->firstOrFail());
    expect([$dati['tipo'], $dati['base'], $dati['riferimento'], (int) $dati['conto_id']])->toBe(['sollecito', 'regolamento', 'art. 20', $s['postali']->id]);
});

it('le spese in blocco dall\'elenco: per ognuno l\'unità con più scaduto, le gestioni di oggi con le voci, gli avvisi per tipo e base; una rata per persona', function () {
    $s = accViaVerdi($this);
    $this->travelTo('2026-06-10 10:00:00');
    $r = $this->actingAs($this->user)->getJson(route('admin.gestionale.addebiti.spese.proposta', [$s['c']]) . '?' . http_build_query(['persone' => [$s['persone']['Neri']->id, $s['persone']['Bianchi']->id]]))
        ->assertOk()->json();
    expect(collect($r['persone'])->map(fn ($p) => [$p['nome'], $p['immobile_id']])->all())->toBe([['Neri', $s['unita']['U1']->id], ['Bianchi', $s['unita']['U2']->id]])
        ->and($r['dove']['proposta'])->toBe($s['g']->id)
        ->and(collect($r['dove']['scelte'][0]['voci'])->pluck('nome')->all())->toContain('Spese postali', 'Interessi di mora')
        ->and(collect($r['tipi'])->pluck('valore')->all())->not->toContain('interessi')
        ->and($r['avvisi']['legali_giudiziali']['nessuna'])->toBe([BaseDegliAccessori::AVVISO_GIUDIZIALI]);

    $rate = adbRegistra($this, $s, array_map(fn ($chi) => ['chi' => $chi, 'unita' => $chi === 'Neri' ? 'U1' : 'U2', 'euro' => '25,00', 'causale' => 'Spese di sollecito',
        'natura' => 'accessorio', 'tipo_accessorio' => 'sollecito', 'base' => 'delibera', 'riferimento' => 'del 14/03/2026', 'conto_id' => $s['postali']->id,
        'scadenza' => '2026-06-10'], ['Neri', 'Bianchi']))->assertCreated()->json('rate');
    expect($rate)->toHaveCount(2);
});

it('versato nell\'anno dopo, la voce è la gemella dell\'esercizio del versamento, con lo stesso nome e lo stesso tipo', function () {
    $s = accViaVerdi($this);
    $servizio = app(\App\Services\Gestionale\Accessori\AccessoriDaIncassare::class);
    [$e27, $g27] = adbAnnoDopo($s);
    expect($servizio->voceNellEsercizio($s['interessi']->id, $s['e'])->id)->toBe($s['interessi']->id);

    $pc27 = \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g27->id, 'nome' => 'PC 2027']);
    $gemella = accVoce(['c' => $s['c'], 'pianoConto' => $pc27, 'generali' => $s['generali']], 'Interessi di mora', 'entrata');
    accVoce(['c' => $s['c'], 'pianoConto' => $pc27, 'generali' => $s['generali']], 'Interessi di mora', 'spesa');
    expect($servizio->voceNellEsercizio($s['interessi']->id, $e27)->id)->toBe($gemella->id);
});

/** Neri con gli interessi al 29/06/2026 registrati (€ 1,18), il 2026 nello stato dato e il 2027 con la sua gestione ordinaria. */
function saInteressiEAnnoDopo($test, string $stato2026, bool $conPiano = true): array
{
    $s = accViaVerdi($test);
    $test->travelTo('2026-06-29 10:00:00');
    $rataId = accRegistra($test, $s, accProposta($test, $s, ['Neri'])->json())->assertCreated()->json('rate.0');
    [$e27, $g27] = adbAnnoDopo($s, $stato2026);
    $pc27 = $conPiano ? \App\Models\Gestionale\PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g27->id, 'nome' => 'PC 2027']) : null;

    return [$s, RataQuote::where('rata_id', $rataId)->firstOrFail(), $e27, $pc27];
}

function saVersaNel2027($test, array $s, RataQuote $quota)
{
    $test->travelTo('2027-01-20 10:00:00');

    return $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['Neri']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2027-01-20', 'importo_totale' => '1.18',
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => '1.18']],
    ]);
}

it('versato nel 2027 con il 2026 chiuso e senza la gemella: nasce la voce con lo stesso nome nel piano dei conti del 2027, con le sue tabelle; CR torna e il versato va nel conguaglio del 2027', function () {
    [$s, $quota, $e27, $pc27] = saInteressiEAnnoDopo($this, 'chiuso');
    $cifre = function () use ($s, $e27) {
        $r = app(\App\Services\Gestionale\RiepilogoPerCompetenza::class)->perEsercizio($s['c'], $e27->fresh());
        $c = app(\App\Services\Gestionale\ControlliDelRendiconto::class)->perEsercizio($s['c'], $e27->fresh());

        return ['differenza' => $r['riconciliazione']['differenza'], 'versato' => array_map(fn ($b) => array_sum(array_column($b['versato_accessori'], 'importo')), $r['blocchi']),
            'diviso' => \App\Services\Gestionale\ConguaglioDelRendiconto::totaliDelCondominio((new \App\Services\Gestionale\ConsuntivoPerPersona())->perEsercizio($e27->fresh()))['a'] ?? 0,
            'CR' => collect($c['controlli'])->firstWhere('id', 'CR')['esito']];
    };
    $prima = $cifre();
    expect($prima['CR'])->toBe('quadra');

    saVersaNel2027($this, $s, $quota)->assertSessionHasNoErrors();
    $gemella = \App\Models\Gestionale\Conto::where('piano_conto_id', $pc27->id)->where('nome', 'Interessi di mora')->sole();
    expect([$gemella->tipo, (int) $gemella->conto_contabile_id, (int) $gemella->importo])->toBe([$s['interessi']->tipo, (int) $s['interessi']->conto_contabile_id, 0])
        ->and(DB::table('conto_tabella_millesimale')->where('conto_id', $gemella->id)->pluck('tabella_id')->all())
        ->toBe(DB::table('conto_tabella_millesimale')->where('conto_id', $s['interessi']->id)->pluck('tabella_id')->all());
    $id = (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
    expect((int) DB::table('scritture_contabili')->where('id', $id)->value('esercizio_id'))->toBe((int) $e27->id)
        ->and(DB::table('righe_scritture')->where('scrittura_id', $id)->whereNotNull('voce_spesa_id')->pluck('voce_spesa_id')->all())->toBe([$gemella->id]);

    $dopo = $cifre();
    expect($dopo['CR'])->toBe('quadra')->and($dopo['differenza'])->toBe($prima['differenza'])
        ->and(array_sum($dopo['versato']))->toBe(118)->and($dopo['diviso'] - $prima['diviso'])->toBe(-118);

    // Un secondo versamento trova la voce nata: non ne nasce un'altra.
    expect(app(\App\Services\Gestionale\Accessori\AccessoriDaIncassare::class)->voceNellEsercizio($s['interessi']->id, $e27)->id)->toBe($gemella->id);
});

it('versato nel 2027 con il 2026 chiuso e senza il piano dei conti della gestione ordinaria del 2027: l\'incasso non si registra, e la frase dice che cosa fare', function () {
    [$s, $quota] = saInteressiEAnnoDopo($this, 'chiuso', false);
    $incassi = DB::table('scritture_contabili')->where('tipo_movimento', 'incasso_rata')->count();
    saVersaNel2027($this, $s, $quota)->assertSessionHasErrors(['dettaglio_pagamenti' => 'I € 1,18 di «Interessi di mora al 29/06/2026» vanno alla voce «Interessi di mora» '
        . 'di una gestione di tipo «ordinaria» dell\'esercizio «Esercizio 2027», che non ha ancora il piano dei conti: crealo, poi registra l\'incasso.']);
    expect(DB::table('scritture_contabili')->where('tipo_movimento', 'incasso_rata')->count())->toBe($incassi)
        ->and((int) $quota->fresh()->importo_pagato)->toBe(0);
});

it('versato nel 2027 con il 2026 aperto: l\'incasso sta nel 2026 e cambia le sue spese; la riga dell\'elenco oltre il confine lo dice', function () {
    [$s, $quota, $e27] = saInteressiEAnnoDopo($this, 'aperto');
    // Il 2027 non è ancora aperto: con due esercizi aperti l'incasso andrebbe nel più recente (Coda 646, decisione 707 (4)), e non starebbe nel 2026.
    $e27->update(['stato' => 'chiuso']);
    saVersaNel2027($this, $s, $quota)->assertSessionHasNoErrors();
    $id = (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
    $riga = collect(app(\App\Services\Gestionale\AnnoACavallo::class)->perEsercizio($s['c'], $s['e']->fresh())['righe'])->firstWhere('scrittura_id', $id);
    expect($riga['nota'])->toBe('un incasso di interessi o spese cambia le spese dell\'esercizio in cui sta');
});
