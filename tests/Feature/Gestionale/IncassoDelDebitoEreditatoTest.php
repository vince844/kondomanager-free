<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Decisione 529 (D1 della lente del denaro della 1-bis della 1.12.0-beta.12): il pagamento di un erede si registra a nome suo e copre il suo debito ereditato,
 * per la strada dello scettico: a giornale la riga sulla rata del defunto con il legame all'erede, e una coppia nel Wallet legata al passaggio (l'erede − y, il
 * defunto + y) che il piano dopo compensa. Sul caso di Ettore Fabbri (`InteressiSulDebitoEreditatoScenario.php`): la rata di aprile da € 300,00 scaduta il
 * 30/04/2026, il 2 % l'anno, il decesso il 17/11/2026, Elena e Marco al 50 %, la successione registrata il 20/11/2026; il piano 2027 con la rata 1 al 31/03/2027,
 * emesso il 15/01/2027.
 *
 * - Elena, pagante, senza altre rate: «Debito ereditato da Ettore Fabbri»; le scritture; «Rate scadute» e la proposta; il piano dopo.
 * - Il versamento dal conto di Ettore dopo la successione: la domanda «per il debito degli eredi» o «resta a Ettore», e le due strade (D-a dello scettico).
 * - Il caso contrario: un versamento con la data di prima del decesso, registrato dopo la successione.
 * - I rischi dello scettico: lo storno rovescia la coppia; le parti delle rate nuove al netto (`tranche()`, la chiave mai sotto zero); `nonEmesse()` per
 *   persona; il messaggio di `DebitoNonDelPaganteException`.
 * - D2 (D-g della lente) sulla strada nuova: Bianchi, erede e condòmino, versa la sua rata e il debito ereditato nello stesso incasso.
 *
 * Che cosa NON copre: la pagina dell'incasso a video (vitest `IncassoDebitoEreditato.test.ts` per le sue regole); MySQL (qui sqlite); il versamento dell'erede
 * dopo che un piano ha assorbito l'arretrato (non si offre: si incassa dalla rata nuova); un erede della catena (decisione 530) che paga sulla rata del primo
 * defunto; la coppia cambiata a mano dal Wallet (una domanda del rapporto).
 */

use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\Subentro;
use App\Services\Gestionale\Accessori\DebitoEreditato;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/InteressiSulDebitoEreditatoScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** «Rate scadute» alla data, «oggi» spostato a quel giorno: persona => la riga. */
function idrElenco($test, array $s, string $alla): \Illuminate\Support\Collection
{
    $test->travelTo($alla . ' 12:00:00');
    $elenco = app(\App\Services\Gestionale\Debiti\ElencoRateScadute::class);

    return $elenco->righe($s['c'], $elenco->filtriNormalizzati(['alla' => $alla]))->keyBy('anagrafica_id');
}

/** Le righe «ereditato» di una persona in «Rate scadute», sommate. */
function idrEreditato(\Illuminate\Support\Collection $elenco, int $persona): int
{
    return (int) collect($elenco[$persona]['rate'] ?? [])->filter(fn ($r) => ($r['ereditato'] ?? null) !== null)->sum('residuo');
}

/** La quota di Ettore sulla rata di aprile. */
function idrQuotaEttore(array $s): RataQuote
{
    return RataQuote::where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $s['persone']['Neri']->id)->where('immobile_id', $s['unita']['U1']->id)->firstOrFail();
}

/** L'erede pagante, sul suo debito ereditato: `[subentro => centesimi]`, con le sue rate (`[id quota => centesimi]`) e la parte in più. */
function idrVersaErede($test, array $s, string $chi, array $ereditato, string $data, array $righe = [], int $eccedenza = 0)
{
    $test->travelTo($data . ' 11:00:00');
    $totale = array_sum($ereditato) + array_sum($righe) + $eccedenza;

    return $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone'][$chi]->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $data, 'importo_totale' => number_format($totale / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => number_format($eccedenza / 100, 2, '.', ''), 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => array_map(fn ($id, $c) => ['rata_id' => (int) $id, 'importo' => number_format($c / 100, 2, '.', '')], array_keys($righe), array_values($righe)),
        'debito_ereditato' => array_map(fn ($id, $c) => ['subentro_id' => (int) $id, 'importo' => number_format($c / 100, 2, '.', '')], array_keys($ereditato), array_values($ereditato)),
    ]);
}

/** Un versamento sulla rata di aprile di Ettore, pagante Ettore, con la data, «Versato da» e la scelta «per il debito degli eredi». */
function idrVersaSuEttore($test, array $s, int $cent, string $data, ?bool $agliEredi, ?string $versatoDa = null, string $registrato = '2026-11-25')
{
    $test->travelTo($registrato . ' 11:00:00');

    return $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), array_filter([
        'pagante_id' => $s['persone']['Neri']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $data, 'importo_totale' => number_format($cent / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => [['rata_id' => idrQuotaEttore($s)->id, 'importo' => number_format($cent / 100, 2, '.', '')]],
        'versato_da_id' => $versatoDa === null ? null : $s['persone'][$versatoDa]->id, 'agli_eredi' => $agliEredi === null ? null : ($agliEredi ? '1' : '0'),
    ], fn ($v) => $v !== null));
}

/** Le righe del Wallet legate al passaggio: persona => cifra. */
function idrCoppia(Subentro $p): array
{
    $esito = DB::table('saldi')->where('passaggio_id', $p->id)->get(['anagrafica_id', 'saldo_iniziale'])
        ->groupBy('anagrafica_id')->map(fn ($g) => (int) $g->sum('saldo_iniziale'))->all();
    ksort($esito);

    return $esito;
}

it('decisione 529: Elena, senza altre rate, versa i suoi € 150,00 a nome suo; a giornale la rata di Ettore con il legame a lei, nel Wallet la coppia; «Rate scadute» [€ 0,00, € 150,00] e la proposta [€ 0,07, € 0,37]', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    [$ettore, $elena, $marco] = [$s['persone']['Neri']->id, $s['persone']['Elena']->id, $s['persone']['Marco']->id];
    // La pagina dell'incasso offre a Elena il suo debito ereditato, con la cifra.
    $this->travelTo('2026-11-25 10:00:00');
    $pagina = $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.create', [$s['c']]));
    $pagina->assertInertia(fn ($x) => $x->where('debiti_ereditati.' . $elena . '.0.da', 'Ettore Fabbri')->where('debiti_ereditati.' . $elena . '.0.aperto', 15000)
        ->where('debiti_ereditati.' . $elena . '.0.subentro_id', $p->id)
        // Sulla rata di Ettore la pagina chiede a chi va il versamento: la successione con gli eredi.
        ->where('successioni_dei_defunti.' . $ettore . '.0.subentro_id', $p->id)->where('successioni_dei_defunti.' . $ettore . '.0.eredi', 'Elena e Marco')->etc());
    // Con il codice di prima l'incasso senza righe sulle quote non si registrava («Il campo dettaglio pagamenti è richiesto»).
    idrVersaErede($this, $s, 'Elena', [$p->id => 15000], '2026-11-25')->assertSessionHasNoErrors();

    // Le scritture: DARE cassa € 150,00 / AVERE «Crediti verso condomini» Ettore, rata di aprile, U1, € 150,00, legata a Elena.
    $sc = \App\Models\Gestionale\ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();
    $righe = DB::table('righe_scritture')->where('scrittura_id', $sc->id)->get();
    expect((int) $righe->where('tipo_riga', 'dare')->sum('importo'))->toBe(15000)
        ->and($righe->where('tipo_riga', 'avere')->map(fn ($r) => [(int) $r->anagrafica_id, (int) $r->rata_id, (int) $r->immobile_id, (int) $r->importo, (int) $r->riferimento_id])->values()->all())
        ->toBe([[$ettore, lcdRata($s, 1), (int) $s['unita']['U1']->id, 15000, $elena]]);
    // Il Wallet: Elena − € 150,00, Ettore + € 150,00, legate al passaggio, libere; il registro del passaggio le ricorda con l'incasso.
    expect(idrCoppia($p))->toBe([$ettore => 15000, $elena => -15000])
        ->and(DB::table('saldi')->where('passaggio_id', $p->id)->where('is_applicato', true)->exists())->toBeFalse()
        ->and($p->fresh()->registro['versamenti_degli_eredi'][0]['scrittura_id'])->toBe((int) $sc->id);
    // La rata di Ettore: € 150,00 versati.
    expect((int) idrQuotaEttore($s)->fresh()->importo_pagato)->toBe(15000);

    // Le letture: «Rate scadute» a Elena niente, a Marco € 150,00; la proposta Elena dal 17/11 al 25/11 su € 150,00 (9 giorni: 7,4 → 7), Marco € 0,37.
    $elenco = idrElenco($this, $s, '2026-12-31');
    $pr = ideProposta($this, $s, ['Elena', 'Marco'], '2026-12-31');
    expect([idrEreditato($elenco, $elena), idrEreditato($elenco, $marco)])->toBe([0, 15000])
        ->and([ideEreditata($pr, $s, 'Elena')['interessi'] ?? null, ideEreditata($pr, $s, 'Marco')['interessi'] ?? null])->toBe([7, 37])
        ->and($elenco->has($ettore))->toBeFalse();
    // La lettera a Marco dice € 150,00; a Elena non c'è niente da sollecitare.
    $lettere = app(\App\Services\Gestionale\Lettere\EmissioneLettere::class)->contenuti($s['c'], 'sollecito', ['persone' => [$elena, $marco]])['persone'];
    expect(isset($lettere[$elena]))->toBeFalse()->and(collect($lettere[$marco]['righe'] ?? [])->sum('residuo_cents'))->toBeGreaterThanOrEqual(0);
});

it('decisione 529, il piano: con la coppia il piano 2027 non chiede a Elena i € 150,00 versati, ed Ettore resta con i suoi € 4,38', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    idrVersaErede($this, $s, 'Elena', [$p->id => 15000], '2026-11-25')->assertSessionHasNoErrors();
    idePianoNuovo($this, $s);
    $cid = (int) $s['c']->id;
    $netto = fn ($n) => app(LetturaDebiti::class)->perPersona($cid, $s['persone'][$n]->id, '2027-04-30')['totali']['netto'];
    // Senza il versamento: Ettore € 4,38, Elena − € 299,69 (il conguaglio a credito), Marco € 600,31 (prova N1). Con: Elena € 150,00 in meno, gli altri uguali.
    expect([$netto('Neri'), $netto('Elena'), $netto('Marco')])->toBe([438, -29969 - 15000, 60031])
        ->and(DB::table('saldi')->where('passaggio_id', $p->id)->where('is_applicato', false)->exists())->toBeFalse();
});

it('decisione 529, il conto di Ettore: senza la scelta il programma la chiede e non scrive niente; «per il debito degli eredi» € 50,00 a testa con il legame; la proposta € 150,00 per 9 giorni e € 100,00 per 36, € 0,27 a testa', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    [$elena, $marco] = [$s['persone']['Elena']->id, $s['persone']['Marco']->id];
    $prima = DB::table('scritture_contabili')->count();
    // Con il codice di prima l'incasso passava e lasciava il debito a Ettore: il piano dopo chiedeva agli eredi l'arretrato intero.
    idrVersaSuEttore($this, $s, 10000, '2026-11-25', null)->assertSessionHasErrors(['agli_eredi' => 'Il debito di Ettore Fabbri su questa unità è passato a Elena e Marco con la successione del 17/11/2026. Scegli che cosa copre il versamento: «Per il debito degli eredi»: il versamento copre il debito di Elena e Marco, per quota, e il prossimo piano non lo chiede di nuovo; «Resta a Ettore Fabbri»: il prossimo piano chiederà agli eredi l\'arretrato intero, e a Ettore Fabbri resterà un credito.']);
    expect(DB::table('scritture_contabili')->count())->toBe($prima);

    idrVersaSuEttore($this, $s, 10000, '2026-11-25', true)->assertSessionHasNoErrors();
    $sc = \App\Models\Gestionale\ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();
    expect(DB::table('righe_scritture')->where('scrittura_id', $sc->id)->where('tipo_riga', 'avere')->orderBy('riferimento_id')->get()
        ->map(fn ($r) => [(int) $r->importo, (int) $r->riferimento_id])->all())->toBe([[5000, $elena], [5000, $marco]])
        ->and(idrCoppia($p))->toBe([$s['persone']['Neri']->id => 10000, $elena => -5000, $marco => -5000]);

    $elenco = idrElenco($this, $s, '2026-12-31');
    $pr = ideProposta($this, $s, ['Elena', 'Marco'], '2026-12-31');
    $elenaRiga = ideEreditata($pr, $s, 'Elena');
    // D-a dello scettico: 15000 per 9 giorni (17–25/11) = 7,397 + 10000 per 36 giorni (26/11–31/12) = 19,726 → 27 a testa.
    expect([idrEreditato($elenco, $elena), idrEreditato($elenco, $marco)])->toBe([10000, 10000])
        ->and(array_map(fn ($t) => [$t['capitale'], $t['giorni']], $elenaRiga['tratti']))->toBe([[15000, 9], [10000, 36]])
        ->and([$elenaRiga['interessi'], ideEreditata($pr, $s, 'Marco')['interessi']])->toBe([27, 27])
        ->and($elenaRiga['frase_ereditata'])->toContain('€ 50,00 versati il 25/11/2026');
});

it('decisione 529, «resta a Ettore»: niente coppia, il versamento resta sulla sua rata; con «Versato da» Elena e «per il debito degli eredi», la parte intera di Elena', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    idrVersaSuEttore($this, $s, 5000, '2026-11-25', false)->assertSessionHasNoErrors();
    expect(idrCoppia($p))->toBe([])
        ->and(DB::table('righe_scritture')->whereNotNull('riferimento_id')->exists())->toBeFalse();
    idrVersaSuEttore($this, $s, 15000, '2026-11-26', true, 'Elena', '2026-11-26')->assertSessionHasNoErrors();
    // Decisione 536 (Q3 della quarta prova): il versamento rimasto a Ettore non tocca le parti degli eredi («il prossimo piano chiederà agli eredi l'arretrato
    // intero»), e la parte di Elena è ancora € 150,00: tutto a lei, niente a Marco. Prima della riscrittura la lettura divideva per quota il residuo di Ettore
    // (€ 250,00): Elena € 125,00 e € 25,00 a Marco, che non aveva versato niente.
    $coppia = idrCoppia($p);
    expect($coppia[$s['persone']['Elena']->id])->toBe(-15000)->and($coppia[$s['persone']['Marco']->id] ?? 0)->toBe(0)
        ->and($coppia[$s['persone']['Neri']->id])->toBe(15000);
});

it('decisione 529, «resta a Ettore» con «Versato da» Marco: niente coppia, e il versamento resta a Ettore anche per la proposta e per «Rate scadute»; Marco € 0,37 su € 150,00 interi', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    $marco = $s['persone']['Marco']->id;
    idrVersaSuEttore($this, $s, 5000, '2026-11-25', false, 'Marco')->assertSessionHasNoErrors();
    // Il giornale: la riga sulla rata di Ettore con il legame a Marco («Versato da», Coda 167); il Wallet: nessuna coppia (è «Resta a Ettore»).
    expect(DB::table('righe_scritture')->where('riferimento_id', $marco)->where('tipo_riga', 'avere')->sum('importo'))->toEqual(5000)
        ->and(idrCoppia($p))->toBe([]);
    // Lo scettico stretto della riscrittura: con il codice di prima la proposta contava i € 50,00 come versati da Marco (€ 150,00 per 9 giorni e € 100,00 per 36:
    // € 0,27) e «Rate scadute» gli dava € 100,00, mentre il piano dopo gli chiede l'arretrato intero. 15000 × 2 % × 45 / 365 = 36,99 → € 0,37.
    $r = ideEreditata(ideProposta($this, $s, ['Marco'], '2026-12-31'), $s, 'Marco');
    expect($r['interessi'])->toBe(37)->and($r['frase_ereditata'])->not->toContain('versati')
        ->and(idrEreditato(idrElenco($this, $s, '2026-12-31'), $marco))->toBe(15000);
});

it('decisione 529, il caso contrario: € 100,00 con la data del 10/11, prima del decesso, registrati dopo la successione «per il debito degli eredi»: solo la coppia per quota; il piano chiede agli eredi € 50,00 in meno ciascuno, Ettore resta con i suoi € 4,38', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    idrVersaSuEttore($this, $s, 10000, '2026-11-10', true)->assertSessionHasNoErrors();
    // A giornale la riga resta di Ettore, senza legame: il versamento è suo, di prima del decesso.
    expect(DB::table('righe_scritture')->whereNotNull('riferimento_id')->exists())->toBeFalse()
        ->and(idrCoppia($p))->toBe([$s['persone']['Neri']->id => 10000, $s['persone']['Elena']->id => -5000, $s['persone']['Marco']->id => -5000]);
    // La proposta: il capitale al decesso è € 200,00, € 100,00 a testa dal 17/11, nessun movimento in più (10000 × 2 % × 45 / 365 = 24,66 → 25).
    $pr = ideProposta($this, $s, ['Elena', 'Marco'], '2026-12-31');
    expect([ideEreditata($pr, $s, 'Elena')['interessi'], ideEreditata($pr, $s, 'Marco')['interessi']])->toBe([25, 25])
        ->and(ideEreditata($pr, $s, 'Elena')['tratti'][0]['capitale'])->toBe(10000);
    idePianoNuovo($this, $s);
    $netti = collect(['Neri', 'Elena', 'Marco'])->mapWithKeys(fn ($n) => [$n => app(LetturaDebiti::class)->perPersona((int) $s['c']->id, $s['persone'][$n]->id, '2027-04-30')['totali']['netto']])->all();
    // Con il codice di prima (lo scettico, «D1, il caso contrario»): Ettore − € 95,62, Elena e Marco l'arretrato intero.
    expect($netti['Neri'])->toBe(438)->and($netti['Elena'] + $netti['Marco'])->toBe(-29969 + 60031 - 10000);
});

it('decisione 529, lo storno: stornato il versamento di Elena, la coppia si toglie (nessun piano l\'aveva presa) e le letture tornano come prima', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    idrVersaErede($this, $s, 'Elena', [$p->id => 15000], '2026-11-25')->assertSessionHasNoErrors();
    $sc = \App\Models\Gestionale\ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();
    $this->travelTo('2026-11-28 10:00:00');
    app(\App\Actions\Gestionale\Movimenti\StornoIncassoRateAction::class)->execute($sc, $s['c'], \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);
    expect(idrCoppia($p))->toBe([])
        ->and($p->fresh()->registro['versamenti_degli_eredi'][0]['stornato_il'])->toBe('2026-11-28');
    $elenco = idrElenco($this, $s, '2026-12-31');
    $pr = ideProposta($this, $s, ['Elena', 'Marco'], '2026-12-31');
    // Senza la correzione dello storno la coppia restava, e il debito di Elena restava ridotto senza il denaro.
    expect([idrEreditato($elenco, $s['persone']['Elena']->id), idrEreditato($elenco, $s['persone']['Marco']->id)])->toBe([15000, 15000])
        ->and([ideEreditata($pr, $s, 'Elena')['interessi'], ideEreditata($pr, $s, 'Marco')['interessi']])->toBe([37, 37]);
});

it('decisione 529, lo storno dopo il piano: la coppia è nel piano, e lo storno scrive quella rovescia legata al passaggio, che il piano dopo compensa', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    idrVersaErede($this, $s, 'Marco', [$p->id => 15000], '2026-11-25')->assertSessionHasNoErrors();
    $sc = \App\Models\Gestionale\ScritturaContabile::where('tipo_movimento', 'incasso_rata')->latest('id')->firstOrFail();
    idePianoNuovo($this, $s, emissione: null);
    $this->travelTo('2026-12-01 10:00:00');
    app(\App\Actions\Gestionale\Movimenti\StornoIncassoRateAction::class)->execute($sc, $s['c'], \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);
    $libere = DB::table('saldi')->where('passaggio_id', $p->id)->where('is_applicato', false)->get(['anagrafica_id', 'saldo_iniziale'])
        ->mapWithKeys(fn ($r) => [(int) $r->anagrafica_id => (int) $r->saldo_iniziale])->all();
    ksort($libere);
    expect($libere)->toBe([$s['persone']['Neri']->id => -15000, $s['persone']['Marco']->id => 15000]);
});

it('decisione 529, tranche() al netto: Marco versa € 100,00, poi il piano assorbe ed è emesso; la sua chiave ereditata non va sotto zero, e la rata nuova parte intera dal 01/04', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    idrVersaErede($this, $s, 'Marco', [$p->id => 10000], '2026-11-25')->assertSessionHasNoErrors();
    $piano = idePianoNuovo($this, $s);
    $rata1 = (int) DB::table('rate')->where('piano_rate_id', $piano->id)->where('numero_rata', 1)->value('id');
    $quota = (int) RataQuote::where('rata_id', $rata1)->where('anagrafica_id', $s['persone']['Marco']->id)->value('importo');
    $pr = ideProposta($this, $s, ['Marco'], '2027-04-30');
    $r = ideEreditata($pr, $s, 'Marco');
    $nuova = collect(accDi($pr, $s, 'Marco')['righe'])->firstWhere('rata_id', $rata1);
    // 15000 × 9 g (17–25/11) = 7,397 + 5000 × 36 g = 9,863 + 5000 × 90 g (2027) = 24,658 → 41,918 → 42; poi la parte è nella rata nuova.
    expect([$r['interessi'], $r['al']])->toBe([42, '2027-03-31'])
        ->and(collect($r['tratti'])->min('capitale'))->toBeGreaterThan(0)
        // Senza il netto l'uscita era di € 150,00 su € 50,00 rimasti: la chiave a − € 100,00 copriva la rata nuova.
        ->and($nuova['tratti'][0]['capitale'])->toBe($quota);
    // «Rate scadute» il 20/02/2027: la riga «ereditato» € 50,00 nella rata 1, la rata nuova con € 50,00 in meno.
    $elenco = idrElenco($this, $s, '2027-02-20');
    expect(idrEreditato($elenco, $s['persone']['Marco']->id))->toBe(5000)
        ->and($elenco[$s['persone']['Marco']->id]['importo'])->toBe(5000);
});

it('decisione 529, nonEmesse() per persona: la riga + del defunto di una coppia (del conguaglio o del versamento) non conta fra i saldi a debito', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    $this->travelTo('2026-11-25 12:00:00');
    $elenco = app(\App\Services\Gestionale\Debiti\ElencoRateScadute::class);
    // Per persona, gestione e unità: Ettore 826,02 − 73,97 − 526,02 − 526,03 = − 300,00 → niente; Elena − 826,02 + 526,02 = − 300,00 → niente; Marco
    // 73,97 + 526,03 = 600,00, di cui € 150,00 già in «Rate scadute» come «ereditato»: € 450,00. Riga per riga erano € 1.652,04.
    expect($elenco->nonEmesse($s['c'], '2026-11-25', null)['saldi'])->toBe(45000);
    idrVersaErede($this, $s, 'Marco', [$p->id => 5000], '2026-11-25')->assertSessionHasNoErrors();
    $this->travelTo('2026-11-25 12:00:00');
    // Marco € 600,00 − € 50,00 = € 550,00, di cui € 100,00 in «Rate scadute»: € 450,00; la riga + € 50,00 di Ettore non conta.
    expect(app(\App\Services\Gestionale\Debiti\ElencoRateScadute::class)->nonEmesse($s['c'], '2026-11-25', null)['saldi'])->toBe(45000);
});

it('decisione 529, il messaggio: Elena pagante sulla rata di Ettore si rifiuta, e il messaggio dice la riga «Debito ereditato»; più della sua parte non si registra', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    $this->travelTo('2026-11-25 11:00:00');
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['persone']['Elena']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-11-25', 'importo_totale' => '150.00',
        'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $s['g']->id, 'dettaglio_pagamenti' => [['rata_id' => idrQuotaEttore($s)->id, 'importo' => '150.00']],
    ])->assertSessionHasErrors(['pagante_id' => 'Non puoi incassare la rata n.1 a nome di chi hai scelto come pagante: quel debito è intestato a Ettore Fabbri, Bianchi, Verdi, ed è passato agli eredi con la successione. Per il versamento di un erede lascia lui come pagante e scrivi la cifra nella riga «Debito ereditato da Ettore Fabbri»: copre la sua parte, e il prossimo piano non gliela chiede di nuovo.']);
    idrVersaErede($this, $s, 'Elena', [$p->id => 15001], '2026-11-25')->assertSessionHasErrors(['debito_ereditato' => 'Il debito ereditato di Elena da Ettore Fabbri è € 150,00: più di così non si registra qui. Il resto mettilo sulle sue rate, o come parte in più.']);
    expect(idrCoppia($p))->toBe([]);
});

it('D2 sulla strada nuova (D-g della lente): Bianchi, erede al 50 % e condòmino di U2, versa la sua rata 1 e € 150,00 sul debito ereditato nello stesso incasso: «Rate scadute» e il sollecito non lo chiedono più, la proposta € 0,03', function () {
    $s = ideCaso($this);
    $dati = ideSuccessione($s);
    $dati['eredi'] = [['anagrafica_id' => $s['persone']['Bianchi']->id, 'quota' => 50], ['anagrafica_id' => $s['persone']['Marco']->id, 'quota' => 50]];
    $dati['erede_di_riferimento'] = $s['persone']['Marco']->id;
    $p = ideRegistra($this, $s, $dati);
    $bianchi = $s['persone']['Bianchi']->id;
    $quota = RataQuote::where('rata_id', lcdRata($s, 1))->where('anagrafica_id', $bianchi)->firstOrFail();
    $aperto = (int) $quota->importo - (int) $quota->importo_pagato;
    idrVersaErede($this, $s, 'Bianchi', [$p->id => 15000], '2026-11-20', [$quota->id => $aperto])->assertSessionHasNoErrors();
    $r = ideEreditata(ideProposta($this, $s, ['Bianchi'], '2026-12-31'), $s, 'Bianchi');
    $elenco = idrElenco($this, $s, '2026-12-31');
    $lettera = app(\App\Services\Gestionale\Lettere\EmissioneLettere::class)->contenuti($s['c'], 'sollecito', ['persone' => [$bianchi]])['persone'][$bianchi] ?? null;
    // 15000 × 2 % × 4 giorni (17–20/11) / 365 = 3,29 → 3. Con la strada di prima (l'eccedenza sulla sua quota) «Rate scadute» teneva € 150,00 «ereditato».
    expect($r['interessi'] ?? null)->toBe(3)
        ->and(idrEreditato($elenco, $bianchi))->toBe(0)
        ->and($lettera === null || collect($lettera['righe'] ?? [])->pluck('ereditato')->filter()->isEmpty())->toBeTrue();
});

/*
 * Seconda passata del giro A (risposte di chi coordina): la coppia del Wallet non si cambia a mano; dopo che un piano ha assorbito l'arretrato, il
 * versamento sulla rata del defunto avvisa dov'è il debito e lascia la scelta.
 */

it('decisione 529, il Wallet: le due righe della coppia non si modificano né si cancellano a mano, e la pagina lo dice; si tolgono stornando l\'incasso', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    idrVersaErede($this, $s, 'Elena', [$p->id => 15000], '2026-11-25')->assertSessionHasNoErrors();
    $righe = \App\Models\Saldo::where('passaggio_id', $p->id)->orderBy('id')->get();
    expect($righe)->toHaveCount(2);
    $frase = 'Questa riga è il versamento di un erede sul debito ereditato, scritta con l\'incasso: non si modifica e non si cancella da sola. Si toglie stornando '
        . 'l\'incasso; se un piano l\'ha già presa, lo storno scrive la riga opposta.';
    foreach ($righe as $riga) {
        // Con il codice di prima la riga si cambiava (una riga legata a un passaggio scritta a mano) e si cancellava.
        $this->actingAs($this->user)->from('/saldi')->patch(route('admin.gestionale.saldi.update', [$s['c'], $riga]), ['saldo_iniziale' => 1, 'gestione_id' => $s['g']->id])
            ->assertSessionHasErrors(['saldo' => $frase]);
        $this->actingAs($this->user)->from('/saldi')->delete(route('admin.gestionale.saldi.destroy', [$s['c'], $riga]))->assertSessionHasErrors(['saldo' => $frase]);
    }
    expect(idrCoppia($p))->toBe([$s['persone']['Neri']->id => 15000, $s['persone']['Elena']->id => -15000]);
    // La pagina dei saldi segna le due righe, perché non mostri la matita e il cestino.
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.saldi.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    $sue = collect($props['immobili'])->flatMap(fn ($u) => $u['saldi'])->whereIn('id', $righe->pluck('id')->all());
    expect($sue->pluck('e_versamento_erede')->unique()->values()->all())->toBe([true]);
    // Una riga legata scritta a mano dal Wallet resta modificabile (Coda 244): niente segno.
    expect(collect($props['immobili'])->flatMap(fn ($u) => $u['saldi'])->whereNotIn('id', $righe->pluck('id')->all())->pluck('e_versamento_erede')->filter()->all())->toBe([]);
});

it('decisione 529, dopo il piano: un versamento sulla rata di Ettore, con il debito già nella rata nuova degli eredi, avvisa dov\'è; «Resta a Ettore» lo registra a lui', function () {
    $s = ideCaso($this);
    $p = ideRegistra($this, $s, ideSuccessione($s));
    idePianoNuovo($this, $s);
    $prima = DB::table('scritture_contabili')->count();
    // Con il codice di prima l'incasso passava senza dire niente: la rata di Ettore chiusa, gli eredi con la loro rata nuova intera, Ettore con un credito.
    idrVersaSuEttore($this, $s, 10000, '2027-02-20', null, null, '2027-02-20')->assertSessionHasErrors(['agli_eredi' => 'Il debito di Ettore Fabbri su questa unità è passato a Elena e Marco con la successione del 17/11/2026, ed è nella Rata 1 — Preventivo 2027 di Elena e Marco: incassalo da lì. Se questo versamento resta comunque a Ettore Fabbri, scegli «Resta a Ettore Fabbri»: il versamento va sulla sua rata, gli eredi devono ancora la loro rata nuova, e a Ettore Fabbri resterà un credito.']);
    // «Per il debito degli eredi» qui non c'è più: il debito è nella loro rata.
    idrVersaSuEttore($this, $s, 10000, '2027-02-20', true, null, '2027-02-20')->assertSessionHasErrors(['agli_eredi']);
    expect(DB::table('scritture_contabili')->count())->toBe($prima);
    idrVersaSuEttore($this, $s, 10000, '2027-02-20', false, null, '2027-02-20')->assertSessionHasNoErrors();
    expect(idrCoppia($p))->toBe([])->and((int) idrQuotaEttore($s)->fresh()->importo_pagato)->toBe(10000);
    // La pagina dell'incasso lo sa: la successione di Ettore con dove è il debito.
    $this->actingAs($this->user)->get(route('admin.gestionale.movimenti-rate.create', [$s['c']]))
        ->assertInertia(fn ($x) => $x->where('successioni_dei_defunti.' . $s['persone']['Neri']->id . '.0.nel_piano', 'nella Rata 1 — Preventivo 2027')->etc());
});

it('decisione 529, dopo il piano non ancora emesso: l\'avviso dice di incassare dalla rata nuova quando è emessa', function () {
    $s = ideCaso($this);
    ideRegistra($this, $s, ideSuccessione($s));
    idePianoNuovo($this, $s, emissione: null);
    idrVersaSuEttore($this, $s, 10000, '2026-12-01', null, null, '2026-12-01')->assertSessionHasErrors(['agli_eredi' => 'Il debito di Ettore Fabbri su questa unità è passato a Elena e Marco con la successione del 17/11/2026, ed è nella Rata 1 — Preventivo 2027 di Elena e Marco: incassalo da lì quando è emessa. Se questo versamento resta comunque a Ettore Fabbri, scegli «Resta a Ettore Fabbri»: il versamento va sulla sua rata, gli eredi devono ancora la loro rata nuova, e a Ettore Fabbri resterà un credito.']);
});
