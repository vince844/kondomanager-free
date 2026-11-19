<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Decisione 530 (D3 della lente del denaro della 1-bis della 1.12.0-beta.12): le successioni a catena. Sul caso di Ettore Fabbri
 * (`InteressiSulDebitoEreditatoScenario.php`): la rata di aprile da € 300,00, il 2 % l'anno, il decesso di Ettore il 17/11/2026, Elena e Marco al 50 %; Marco
 * muore il 01/12/2026 e lascia tutto a Luca, con la successione registrata il 05/12/2026 (arretrato agli eredi, «si fermano al decesso» di serie).
 *
 * - Marco smette di contare al 30/11 (14 giorni, 11,5 → € 0,12); Luca conta dal 01/12 (31 giorni, 25,5 → € 0,25), con la chiave della seconda successione.
 * - «Rate scadute»: la riga di Marco passa a Luca, «da Marco, successione del 01/12/2026, che l'aveva ereditato da Ettore Fabbri».
 * - La proposta chiesta per Ettore porta anche Luca.
 * - Gli interessi già registrati a Marco dopo la sua morte (registrati prima che la sua successione fosse scritta): niente si cancella da sé, la riga di Marco
 *   lo dice, e Luca parte dal giorno dopo l'ultimo registrato a Marco.
 * - `nonEmesse()` non conta due volte: per persona.
 *
 * - Q5 della quarta prova (decisione 536): Luca versa il debito che gli arriva da Marco a nome suo, dalla sua riga «Debito ereditato da Marco, che l'aveva
 *   ereditato da Ettore Fabbri»: la riga sulla rata di Ettore con il legame a Luca, la coppia legata alla seconda successione; «Versato da» Luca sulla rata
 *   di Ettore si rifiuta con la frase che porta alla sua riga; la domanda sulla rata di Ettore nomina Elena e Luca, e «per il debito degli eredi» divide fra
 *   loro.
 *
 * Che cosa NON copre: «continuano a suo nome» nella seconda successione (letto nel codice: la chiave di Marco continua, come le sue rate); un credito di Marco
 * al decesso che copriva la chiave ereditata; l'annullamento della prima successione sotto la seconda (si annulla solo l'ultimo passaggio dell'unità,
 * `AnnullaPassaggioAction`); lo storno del versamento di Luca (la coppia è nel registro della seconda successione, come ogni coppia); il versamento di Luca
 * dopo che un piano ha preso la catena; MySQL.
 */

use App\Models\Gestionale\Subentro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** La seconda successione: Marco muore il 01/12/2026 e lascia la sua parte di U1 a Luca, registrata il giorno dato. */
function sacSeconda($test, array &$s, string $registrata = '2026-12-05'): Subentro
{
    $s['persone']['Luca'] = ideErede($s, 'Luca');
    $riga = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $s['persone']['Marco']->id)->where('immobile_id', $s['unita']['U1']->id)->where('attivo', true)->value('id');
    $quota = (float) DB::table('anagrafica_immobile')->where('id', $riga)->value('quota');
    $test->travelTo($registrata . ' 10:00:00');
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), [
        'tipo' => 'successione', 'riga_uscente_id' => $riga, 'decorrenza' => '2026-12-01', 'quota' => $quota, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $s['persone']['Luca']->id, 'quota' => $quota]], 'arretrato' => Subentro::ARRETRATO_AGLI_EREDI,
        'erede_di_riferimento' => $s['persone']['Luca']->id, 'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 2', 'pertinenze' => [],
        'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta'])->assertSessionHasNoErrors();

    return Subentro::where('immobile_id', $s['unita']['U1']->id)->whereNull('subentro_padre_id')->latest('id')->firstOrFail();
}

/** «Rate scadute» alla data: persona => la riga. */
function sacElenco($test, array $s, string $alla): \Illuminate\Support\Collection
{
    $test->travelTo($alla . ' 12:00:00');
    $elenco = app(\App\Services\Gestionale\Debiti\ElencoRateScadute::class);

    return $elenco->righe($s['c'], $elenco->filtriNormalizzati(['alla' => $alla]))->keyBy('anagrafica_id');
}

function sacEreditato(\Illuminate\Support\Collection $elenco, int $persona): array
{
    return collect($elenco[$persona]['rate'] ?? [])->filter(fn ($r) => ($r['ereditato'] ?? null) !== null)->values()->all();
}

it('decisione 530: Marco, erede di Ettore, muore il 01/12/2026; la sua riga ereditata si ferma al 30/11 (€ 0,12), e da lì conta Luca (€ 0,25), con la seconda successione', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    $seconda = sacSeconda($this, $s);
    $p = ideProposta($this, $s, ['Marco', 'Luca', 'Elena'], '2026-12-31');
    $marco = ideEreditata($p, $s, 'Marco');
    $luca = ideEreditata($p, $s, 'Luca');
    // Con il codice di prima: Marco contava fino al 31/12 (€ 0,37) e Luca non aveva niente.
    expect([$marco['interessi'], $marco['dal'], $marco['al']])->toBe([12, '2026-11-17', '2026-11-30'])
        ->and($luca)->not->toBeNull()
        ->and([$luca['interessi'], $luca['dal'], $luca['al'], $luca['tratti'][0]['capitale']])->toBe([25, '2026-12-01', '2026-12-31', 15000])
        ->and($luca['chiave'])->toEndWith('|s' . $seconda->id)
        ->and($luca['frase_ereditata'])->toStartWith('Di Marco, che l\'aveva ereditato da Ettore Fabbri, passata a te per il 100 % il 01/12/2026')
        // Elena non cambia: € 0,37.
        ->and(ideEreditata($p, $s, 'Elena')['interessi'])->toBe(37);
});

it('decisione 530, «Rate scadute»: la riga di Marco passa a Luca, «da Marco, successione del 01/12/2026, che l\'aveva ereditato da Ettore Fabbri»; il totale lo conta una volta', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    sacSeconda($this, $s);
    $elenco = sacElenco($this, $s, '2026-12-31');
    $luca = sacEreditato($elenco, $s['persone']['Luca']->id);
    expect(sacEreditato($elenco, $s['persone']['Marco']->id))->toBe([])
        ->and(array_sum(array_column($luca, 'residuo')))->toBe(15000)
        ->and($luca[0]['ereditato']['frase'])->toBe('da Marco, successione del 01/12/2026, che l\'aveva ereditato da Ettore Fabbri, non ancora in una rata')
        ->and(array_sum(array_column(sacEreditato($elenco, $s['persone']['Elena']->id), 'residuo')))->toBe(15000)
        ->and($elenco->has($s['persone']['Neri']->id))->toBeFalse();
});

it('decisione 530: la proposta chiesta per Ettore porta Elena, Marco e Luca', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    sacSeconda($this, $s);
    $p = ideProposta($this, $s, ['Neri'], '2026-12-31');
    expect(collect($p['persone'])->pluck('anagrafica_id')->sort()->values()->all())
        ->toBe(collect(['Neri', 'Elena', 'Marco', 'Luca'])->map(fn ($n) => $s['persone'][$n]->id)->sort()->values()->all());
});

it('decisione 530: gli interessi registrati a Marco fino al 10/12, dopo la sua morte e prima della sua successione, restano; la riga di Marco lo dice, Luca parte dal 11/12', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    // Il 10/12 l'amministratore registra gli interessi di Marco: la sua successione non è ancora scritta (17/11–10/12, 24 giorni: 19,7 → € 0,20).
    $p = ideProposta($this, $s, ['Marco'], '2026-12-10');
    $r = ideEreditata($p, $s, 'Marco');
    expect($r['interessi'])->toBe(20);
    ideRegistraRigheCatena($this, $s, $p, [$r['chiave']])->assertCreated();
    sacSeconda($this, $s, '2026-12-15');
    $dopo = ideProposta($this, $s, ['Marco', 'Luca'], '2026-12-31');
    $marco = collect(accDi($dopo, $s, 'Marco')['righe'] ?? [])->first(fn ($x) => str_contains($x['chiave'], '|s'));
    $luca = ideEreditata($dopo, $s, 'Luca');
    // Niente cancellato da sé: la riga di Marco ha la frase del riallineo; Luca dal 11/12 al 31/12, 21 giorni: 17,26 → € 0,17.
    expect($marco['riallineo'] ?? '')->toContain('con la successione del 01/12/2026, che ferma la proposta al 30/11/2026, quei giorni darebbero € 0,12')
        ->and([$luca['dal'], $luca['interessi']])->toBe(['2026-12-11', 17]);
});

it('decisione 530, nonEmesse(): le righe della prima successione di Marco e quelle della seconda a Luca contano una volta, per persona', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    sacSeconda($this, $s);
    $this->travelTo('2026-12-31 12:00:00');
    $saldi = DB::table('saldi')->whereNull('piano_rate_id')->get(['anagrafica_id', 'gestione_id', 'immobile_id', 'saldo_iniziale'])
        ->groupBy(fn ($r) => $r->anagrafica_id . '|' . $r->gestione_id . '|' . $r->immobile_id)->map(fn ($g) => max(0, (int) $g->sum('saldo_iniziale')));
    $nonEmesse = app(\App\Services\Gestionale\Debiti\ElencoRateScadute::class)->nonEmesse($s['c'], '2026-12-31', null);
    // Marco: la sua parte della prima (+) e la contropartita della seconda (−): niente; Luca: la seconda, meno i € 150,00 già in «Rate scadute» come
    // «ereditato»; Elena la sua, che il conguaglio porta sotto zero.
    expect($saldi[$s['persone']['Marco']->id . '|' . $s['g']->id . '|' . $s['unita']['U1']->id] ?? 0)->toBe(0)
        ->and($nonEmesse['saldi'])->toBe((int) $saldi->sum() - 15000);
});

/** Registra le righe date della proposta. */
function ideRegistraRigheCatena($test, array $s, array $proposta, array $chiavi)
{
    $righe = collect($proposta['persone'])->flatMap(fn ($p) => $p['righe'])->whereIn('chiave', $chiavi);

    return $test->actingAs($test->user)->postJson(route('admin.gestionale.addebiti.interessi.store', [$s['c']]), [
        'alla' => $proposta['alla'], 'righe' => $righe->pluck('chiave')->values()->all(), 'atteso' => (int) $righe->sum('interessi'),
        'voci' => collect($proposta['gestioni'])->mapWithKeys(fn ($g) => [$g['gestione_id'] => $s['interessi']->id])->all(),
    ]);
}

/** Il saldo di una persona su «Crediti verso condomini» a giornale, più le sue righe del Wallet non ancora in un piano. */
function sacEstratto(array $s, string $chi): int
{
    $id = $s['persone'][$chi]->id;
    $giornale = (int) DB::table('righe_scritture as rs')->join('scritture_contabili as sc', 'sc.id', '=', 'rs.scrittura_id')->join('conti_contabili as cc', 'cc.id', '=', 'rs.conto_contabile_id')
        ->whereNull('sc.deleted_at')->where('cc.ruolo', 'crediti_condomini')->where('rs.anagrafica_id', $id)
        ->sum(DB::raw("CASE WHEN rs.tipo_riga = 'dare' THEN rs.importo ELSE -rs.importo END"));

    return $giornale + (int) DB::table('saldi')->where('anagrafica_id', $id)->where('is_applicato', false)->sum('saldo_iniziale');
}

it('Q5 della quarta prova: la pagina dell\'incasso offre a Luca il debito che gli arriva da Marco; il suo versamento chiude la rata di Ettore con il legame a lui, la coppia è della seconda successione, e la riga di Elena non cambia', function () {
    $s = ideCaso($this);
    $prima = ideRegistra($this, $s, ideSuccessione($s));
    $seconda = sacSeconda($this, $s);
    [$ettore, $elena, $luca] = [$s['persone']['Neri']->id, $s['persone']['Elena']->id, $s['persone']['Luca']->id];
    $this->travelTo('2026-12-10 10:00:00');
    // Con il codice di prima la pagina non offriva niente a Luca: le righe della catena si incassavano dalla rata di Ettore, con «Versato da».
    $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.create', [$s['c']]))
        ->assertInertia(fn ($x) => $x->where('debiti_ereditati.' . $luca . '.0.subentro_id', $seconda->id)
            ->where('debiti_ereditati.' . $luca . '.0.da', 'Marco, che l\'aveva ereditato da Ettore Fabbri')->where('debiti_ereditati.' . $luca . '.0.aperto', 15000)->etc());
    $estratti = fn () => ['Neri' => sacEstratto($s, 'Neri'), 'Elena' => sacEstratto($s, 'Elena'), 'Marco' => sacEstratto($s, 'Marco'), 'Luca' => sacEstratto($s, 'Luca')];
    $primaDi = $estratti();
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $luca, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-12-10', 'importo_totale' => '100.00', 'descrizione' => 'Bonifico', 'eccedenza' => '0.00',
        'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [], 'debito_ereditato' => [['subentro_id' => $seconda->id, 'importo' => '100.00']],
    ])->assertSessionHasNoErrors();
    $sc = \App\Models\Gestionale\ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();
    expect(DB::table('righe_scritture')->where('scrittura_id', $sc->id)->where('tipo_riga', 'avere')->get()->map(fn ($r) => [(int) $r->anagrafica_id, (int) $r->rata_id, (int) $r->riferimento_id, (int) $r->importo])->all())
        ->toBe([[$ettore, lcdRata($s, 1), $luca, 10000]])
        ->and(DB::table('saldi')->where('passaggio_id', $seconda->id)->orderBy('anagrafica_id')->get()->map(fn ($r) => [(int) $r->anagrafica_id, (int) $r->saldo_iniziale])->all())
        ->toBe(collect([[$ettore, 10000], [$luca, -10000]])->sortBy(fn ($x) => $x[0])->values()->all())
        ->and(DB::table('saldi')->where('passaggio_id', $prima->id)->exists())->toBeFalse();
    // Gli estratti: Luca − € 100,00, gli altri come prima (Ettore: la rata giù, il Wallet su).
    $dopoDi = $estratti();
    expect($dopoDi['Luca'] - $primaDi['Luca'])->toBe(-10000)->and($dopoDi['Elena'])->toBe($primaDi['Elena'])->and($dopoDi['Marco'])->toBe($primaDi['Marco'])
        ->and($dopoDi['Neri'])->toBe($primaDi['Neri']);
    // «Rate scadute»: Luca € 50,00 «ereditato», Elena € 150,00; la proposta di Luca vede il versamento.
    $elenco = sacElenco($this, $s, '2026-12-31');
    expect(array_sum(array_column(sacEreditato($elenco, $luca), 'residuo')))->toBe(5000)
        ->and(array_sum(array_column(sacEreditato($elenco, $elena), 'residuo')))->toBe(15000)
        ->and(ideEreditata(ideProposta($this, $s, ['Luca'], '2026-12-31'), $s, 'Luca')['frase_ereditata'])->toContain('€ 100,00 versati il 10/12/2026');
});

it('Q5 della quarta prova: «Versato da» Luca sulla rata di Ettore si rifiuta con la frase che porta alla sua riga, e niente si scrive', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    sacSeconda($this, $s);
    $this->travelTo('2026-12-10 10:00:00');
    $quota = \App\Models\Gestionale\RataQuote::where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $s['persone']['Neri']->id)->where('immobile_id', $s['unita']['U1']->id)->firstOrFail();
    $prima = DB::table('scritture_contabili')->count();
    // Con il codice di prima il versamento andava alla parte di Elena: il suo debito si chiudeva con i soldi di Luca.
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['Neri']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-12-10', 'importo_totale' => '150.00', 'descrizione' => 'Bonifico',
        'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => '150.00']],
        'versato_da_id' => $s['persone']['Luca']->id, 'agli_eredi' => '1',
    ])->assertSessionHasErrors(['versato_da_id' => 'Luca ha ereditato questo debito da Marco, che l\'aveva ereditato da Ettore Fabbri: il suo versamento si registra a nome suo. Scegli Luca come pagante e scrivi la cifra nella riga «Debito ereditato da Marco, che l\'aveva ereditato da Ettore Fabbri»: copre la sua parte, e il prossimo piano non gliela chiede di nuovo. Se questo versamento resta comunque a Ettore Fabbri, scegli «Resta a Ettore Fabbri».']);
    expect(DB::table('scritture_contabili')->count())->toBe($prima);
});

it('Q5 della quarta prova: la domanda sulla rata di Ettore nomina Elena e Luca, non Marco; «per il debito degli eredi» dal conto di Ettore divide fra Elena e Luca, la coppia di Luca è della seconda successione', function () {
    $s = ideCaso($this);
    $prima = ideRegistra($this, $s, ideSuccessione($s));
    $seconda = sacSeconda($this, $s);
    [$ettore, $elena, $luca] = [$s['persone']['Neri']->id, $s['persone']['Elena']->id, $s['persone']['Luca']->id];
    $this->travelTo('2026-12-10 10:00:00');
    $quota = \App\Models\Gestionale\RataQuote::where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $ettore)->where('immobile_id', $s['unita']['U1']->id)->firstOrFail();
    $dati = ['pagante_id' => $ettore, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-12-10', 'importo_totale' => '100.00', 'descrizione' => 'Bonifico',
        'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => '100.00']]];
    // Con il codice di prima la domanda nominava «Elena e Marco», e Marco è morto.
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), $dati)
        ->assertSessionHasErrors(['agli_eredi' => 'Il debito di Ettore Fabbri su questa unità è passato a Elena e Luca con la successione del 17/11/2026. Scegli che cosa copre il versamento: «Per il debito degli eredi»: il versamento copre il debito di Elena e Luca, per quota, e il prossimo piano non lo chiede di nuovo; «Resta a Ettore Fabbri»: il prossimo piano chiederà agli eredi l\'arretrato intero, e a Ettore Fabbri resterà un credito.']);
    $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.create', [$s['c']]))
        ->assertInertia(fn ($x) => $x->where('successioni_dei_defunti.' . $ettore . '.0.eredi', 'Elena e Luca')->where('successioni_dei_defunti.' . $ettore . '.0.eredi_ids', [$elena])->etc());
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), $dati + ['agli_eredi' => '1'])->assertSessionHasNoErrors();
    $sc = \App\Models\Gestionale\ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();
    expect(DB::table('righe_scritture')->where('scrittura_id', $sc->id)->where('tipo_riga', 'avere')->orderBy('riferimento_id')->get()->map(fn ($r) => [(int) $r->riferimento_id, (int) $r->importo])->all())
        ->toBe(collect([[$elena, 5000], [$luca, 5000]])->sortBy(fn ($x) => $x[0])->values()->all())
        ->and(DB::table('saldi')->where('passaggio_id', $prima->id)->orderBy('anagrafica_id')->get()->map(fn ($r) => [(int) $r->anagrafica_id, (int) $r->saldo_iniziale])->all())
        ->toBe(collect([[$ettore, 5000], [$elena, -5000]])->sortBy(fn ($x) => $x[0])->values()->all())
        ->and(DB::table('saldi')->where('passaggio_id', $seconda->id)->orderBy('anagrafica_id')->get()->map(fn ($r) => [(int) $r->anagrafica_id, (int) $r->saldo_iniziale])->all())
        ->toBe(collect([[$ettore, 5000], [$luca, -5000]])->sortBy(fn ($x) => $x[0])->values()->all());
    $elenco = sacElenco($this, $s, '2026-12-31');
    expect(array_sum(array_column(sacEreditato($elenco, $luca), 'residuo')))->toBe(10000)
        ->and(array_sum(array_column(sacEreditato($elenco, $elena), 'residuo')))->toBe(10000);
});
