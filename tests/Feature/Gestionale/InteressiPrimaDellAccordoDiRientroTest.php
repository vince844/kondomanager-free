<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Gli interessi dei giorni prima di un accordo di rientro restano nella proposta (decisione 438, che precisa la 433). Per le rate coperte da un accordo
 * la proposta lascia fuori solo i giorni dalla data dell'accordo in poi, finché l'accordo è in corso o è rispettato fino in fondo; i giorni dalla scadenza
 * al giorno prima dell'accordo restano. Se l'accordo decade, o il calendario finisce con rate non versate, tornano anche i giorni dell'accordo. Gli
 * interessi già registrati per quei giorni non si propongono due volte.
 *
 * Il caso del laboratorio (rilievo A1, `riferimenti/beta10_1_12_lavori/laboratorio/rapporto.md` § 8): Neri, rata 1 di € 300,00 scaduta il 31/03/2026
 * (`AccessoriScenario.php`), € 100,00 versati il 30/04, nessun interesse registrato; accordo del 15/07/2026 sui € 200,00 che restano, calendario
 * € 100,00 il 31/07 e € 100,00 il 31/08, decadenza con 2 rate non versate o 15 giorni dopo una rata non versata. Tasso legale 2026: 1,60 %.
 *
 * Le cifre, rifatte a mano (in centesimi: capitale × 16.000 × giorni / (1.000.000 × 365); un versamento conta dal giorno dopo):
 * - i giorni prima dell'accordo, dal 01/04 al 14/07: 30.000 × 16.000 × 30 / 365.000.000 = 39,452055 (dal 01/04 al 30/04) + 20.000 × 16.000 × 75 /
 *   365.000.000 = 65,753425 (dal 01/05 al 14/07: 31 + 30 + 14 giorni) = 105,205480 → € 1,05. Il laboratorio scriveva «circa € 1,06» con 76 giorni,
 *   cioè contando anche il 15/07: il giorno dell'accordo, che la decisione 438 lascia fuori («dalla data dell'accordo in poi»).
 */

use App\Models\Gestionale\AccordoRientro;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__ . '/AccordoCalendarioScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Il caso del laboratorio fino all'accordo del 15/07 registrato: [scenario, accordo, quota della rata 1]. */
function ipaNeri($test): array
{
    $s = accViaVerdi($test);
    $test->travelTo('2026-04-30 10:00:00');
    lcdIncassa($test, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 10000], '2026-04-30');
    aclRegistra($test, $s, [['rata_id' => lcdRata($s, 1), 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => '200,00']],
        aclCalendario(['100,00', '100,00'], ['2026-07-31', '2026-08-31']))->assertCreated();

    return [$s, AccordoRientro::latest('id')->firstOrFail(), lcdQuota($s, 'Neri', 1)];
}

/** La riga della rata 1 di Neri nella proposta alla data: [interessi, tratti come dal-al-giorni-capitale]; null se la proposta non la ha. */
function ipaRata1($test, array $s, string $alla): ?array
{
    $neri = accDi(accProposta($test, $s, ['Neri'], $alla)->assertOk()->json(), $s, 'Neri');
    $riga = collect($neri['righe'] ?? [])->firstWhere('rata_id', lcdRata($s, 1));

    return $riga === null ? null : [$riga['interessi'], array_map(fn ($t) => [$t['dal'], $t['al'], $t['giorni'], $t['capitale']], $riga['tratti'])];
}

/** I due tratti dei giorni prima dell'accordo: € 300,00 per 30 giorni, € 200,00 per 75. */
function ipaPrima(): array
{
    return [['2026-04-01', '2026-04-30', 30, 30000], ['2026-05-01', '2026-07-14', 75, 20000]];
}

it('accordo in corso: la proposta del 20/07 tiene i giorni dal 01/04 al 14/07, € 1,05, e lascia fuori quelli dal 15/07; un versamento dell\'accordo non li cambia', function () {
    [$s, , $q1] = ipaNeri($this);

    $this->travelTo('2026-07-20 10:00:00');
    expect(ipaRata1($this, $s, '2026-07-20'))->toBe([105, ipaPrima()]);
    // La riga dice fin dove arriva e perché: il giorno dell'accordo.
    $riga = collect(accDi(accProposta($this, $s, ['Neri'], '2026-07-20')->json(), $s, 'Neri')['righe'])->firstWhere('rata_id', lcdRata($s, 1));
    expect([$riga['dal'], $riga['al'], $riga['accordo_dal']])->toBe(['2026-04-01', '2026-07-14', '2026-07-15']);

    // La prima rata del calendario versata il 31/07: i giorni prima dell'accordo restano gli stessi.
    aclVersa($this, $s, [$q1 => 10000], '2026-07-31');
    $this->travelTo('2026-08-10 10:00:00');
    expect(ipaRata1($this, $s, '2026-08-10'))->toBe([105, ipaPrima()]);
});

it('accordo rispettato fino in fondo: dopo «fino al» la proposta tiene i giorni prima dell\'accordo, € 1,05, e non quelli dell\'accordo', function () {
    [$s, , $q1] = ipaNeri($this);
    aclVersa($this, $s, [$q1 => 10000], '2026-07-31');
    aclVersa($this, $s, [$q1 => 10000], '2026-08-31');

    // Al 01/10, il calendario è tutto versato: senza l'accordo sarebbero anche i giorni dal 15/07 al 31/08.
    $this->travelTo('2026-10-01 10:00:00');
    expect(ipaRata1($this, $s, '2026-10-01'))->toBe([105, ipaPrima()]);
});

it('accordo decaduto: la proposta torna con tutti i giorni, anche quelli dell\'accordo', function () {
    [$s, $accordo] = ipaNeri($this);

    // Il 19/08 l'accordo è in corso, prima di «fino al» (31/08): la rata del 31/07 non è versata, la decadenza non è registrata. I soli giorni prima.
    $this->travelTo('2026-08-19 10:00:00');
    expect(ipaRata1($this, $s, '2026-08-19'))->toBe([105, ipaPrima()]);

    $this->travelTo('2026-08-20 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.decadenza', ['condominio' => $s['c']->id, 'accordo' => $accordo->id]),
        ['data' => '2026-08-20', 'nota' => 'Comunicata con la raccomandata del 20/08'])->assertOk();
    // Al 20/08: 39,452055 (dal 01/04 al 30/04) + 20.000 × 16.000 × 112 / 365.000.000 = 98,191781 (dal 01/05 al 20/08: 31 + 30 + 31 + 20 giorni)
    // = 137,643836 → € 1,38.
    expect(ipaRata1($this, $s, '2026-08-20'))->toBe([138, [['2026-04-01', '2026-04-30', 30, 30000], ['2026-05-01', '2026-08-20', 112, 20000]]]);
});

it('calendario finito con una rata non versata, senza decadenza: dopo «fino al» tornano anche i giorni dell\'accordo', function () {
    [$s, , $q1] = ipaNeri($this);
    aclVersa($this, $s, [$q1 => 10000], '2026-07-31');

    // Il 31/08, ultimo giorno del calendario, l'accordo è in corso: i soli giorni prima.
    $this->travelTo('2026-08-31 10:00:00');
    expect(ipaRata1($this, $s, '2026-08-31'))->toBe([105, ipaPrima()]);

    // Al 10/09: 39,452055 (dal 01/04 al 30/04) + 20.000 × 16.000 × 92 / 365.000.000 = 80,657534 (dal 01/05 al 31/07: 31 + 30 + 31 giorni)
    // + 10.000 × 16.000 × 41 / 365.000.000 = 17,972603 (dal 01/08 al 10/09: 31 + 10 giorni) = 138,082192 → € 1,38.
    $this->travelTo('2026-09-10 10:00:00');
    expect(ipaRata1($this, $s, '2026-09-10'))->toBe([138, [['2026-04-01', '2026-04-30', 30, 30000], ['2026-05-01', '2026-07-31', 92, 20000], ['2026-08-01', '2026-09-10', 41, 10000]]]);
});

it('i giorni prima dell\'accordo registrati non si propongono di nuovo; con la decadenza la proposta riparte dal giorno dell\'accordo, non dal giorno della registrazione', function () {
    [$s, $accordo] = ipaNeri($this);

    // Il 20/07 l'amministratore registra la proposta: € 1,05, i giorni fino al 14/07.
    $this->travelTo('2026-07-20 10:00:00');
    $rata = (int) accRegistra($this, $s, accProposta($this, $s, ['Neri'], '2026-07-20')->json())->assertCreated()->json('rate.0');
    expect((int) \App\Models\Gestionale\RataQuote::where('rata_id', $rata)->value('importo'))->toBe(105);

    // Il 10/08, con l'accordo in corso: niente di nuovo sulla rata 1.
    $this->travelTo('2026-08-10 10:00:00');
    expect(ipaRata1($this, $s, '2026-08-10'))->toBeNull();

    // Decaduto il 20/08: tornano i giorni dell'accordo, dal 15/07 (il giorno dopo l'ultimo registrato, il 14/07), non dal 21/07.
    // 20.000 × 16.000 × 37 / 365.000.000 = 32,438356 (dal 15/07 al 20/08: 17 + 20 giorni) → € 0,32.
    $this->travelTo('2026-08-20 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.decadenza', ['condominio' => $s['c']->id, 'accordo' => $accordo->id]),
        ['data' => '2026-08-20', 'nota' => 'Comunicata con la raccomandata del 20/08'])->assertOk();
    expect(ipaRata1($this, $s, '2026-08-20'))->toBe([32, [['2026-07-15', '2026-08-20', 37, 20000]]]);
});

/** Registra alla data le sole righe date (per rata) della proposta di Neri. */
function ipaRegistraRighe($test, array $s, string $alla, array $rataIds)
{
    $p = accProposta($test, $s, ['Neri'], $alla)->assertOk()->json();
    $righe = collect(accDi($p, $s, 'Neri')['righe'])->filter(fn ($r) => in_array($r['rata_id'], $rataIds, true));

    return $test->actingAs($test->user)->postJson(route('admin.gestionale.addebiti.interessi.store', [$s['c']]), [
        'alla' => $alla, 'righe' => $righe->pluck('chiave')->values()->all(), 'atteso' => (int) $righe->sum('interessi'),
        'voci' => collect($p['gestioni'])->mapWithKeys(fn ($g) => [$g['gestione_id'] => $s['interessi']->id])->all(),
    ]);
}

it('un credito dei giorni prima dell\'accordo copre una volta sola, anche dopo che i giorni prima dell\'accordo della rata coperta sono registrati', function () {
    // Rata 1 (A): € 300,00 al 31/03, € 100,00 versati il 30/04, accordo del 15/07 sui € 200,00. Rata 2 (B): € 300,00 al 30/06, emessa il 15/06, non
    // versata. Il 10/07 € 100,00 versati sulla rata 3, non ancora emessa: un credito della persona, dal 11/07.
    $s = accViaVerdi($this);
    $this->travelTo('2026-04-30 10:00:00');
    lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 1) => 10000], '2026-04-30');
    $this->travelTo('2026-06-15 10:00:00');
    lcdEmetti($this, $s, [2], '2026-06-15');
    $this->travelTo('2026-07-10 10:00:00');
    lcdIncassa($this, $s, 'Neri', [lcdQuota($s, 'Neri', 3) => 10000], '2026-07-10');
    aclRegistra($this, $s, [['rata_id' => lcdRata($s, 1), 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => '200,00']],
        aclCalendario(['100,00', '100,00'], ['2026-07-31', '2026-08-31']))->assertCreated();
    [$a, $b] = [lcdRata($s, 1), lcdRata($s, 2)];
    $interessiDi = fn (string $alla) => collect(accDi(accProposta($this, $s, ['Neri'], $alla)->assertOk()->json(), $s, 'Neri')['righe'])
        ->mapWithKeys(fn ($r) => [(int) $r['rata_id'] => (int) $r['interessi']])->all();

    // Niente registrato, al 10/08. A: 39,452055 + 20.000 × 16.000 × 71 / 365.000.000 = 62,246575 (dal 01/05 al 10/07) + 10.000 × 16.000 × 4 /
    // 365.000.000 = 1,753425 (dal 11/07 al 14/07: il credito copre A, la più vecchia) = 103,452055 → € 1,03. B: 30.000 × 16.000 × 14 / 365.000.000 =
    // 18,410959 (dal 01/07 al 14/07) + 20.000 × 16.000 × 27 / 365.000.000 = 23,671233 (dal 15/07 al 10/08: A è fuori, il credito passa a B) = 42,082192
    // → € 0,42.
    $this->travelTo('2026-08-10 10:00:00');
    expect($interessiDi('2026-08-10'))->toBe([$a => 103, $b => 42]);

    // Registrata alla data del 20/07 la sola riga di A (€ 1,03, con il credito su A dal 11/07 al 14/07), la proposta del 10/08 non ha A e per B dice
    // ancora € 0,42: il credito dei giorni dal 11/07 al 14/07 ha già abbassato la riga registrata di A, e non copre anche B in quei giorni. (Prima della
    // correzione A usciva dalla base del tutto e B era € 0,40: 30.000 × 10 giorni, 13,150685, + 20.000 × 31 giorni, 27,178082.)
    $this->travelTo('2026-07-20 10:00:00');
    ipaRegistraRighe($this, $s, '2026-07-20', [$a])->assertCreated();
    $this->travelTo('2026-08-10 10:00:00');
    expect($interessiDi('2026-08-10'))->toBe([$b => 42]);
});

it('il riallineo dei giorni prima dell\'accordo dice il giorno dell\'addebito registrato, lo stesso che ne porta il nome', function () {
    [$s, $accordo, $q1] = ipaNeri($this);
    // Il 20/07 si registrano i giorni prima dell'accordo: € 1,05, l'addebito «Interessi di mora al 20/07/2026».
    $this->travelTo('2026-07-20 10:00:00');
    $rata = (int) ipaRegistraRighe($this, $s, '2026-07-20', [lcdRata($s, 1)])->assertCreated()->json('rate.0');
    $causale = (string) \Illuminate\Support\Facades\DB::table('rate')->where('id', $rata)->value('descrizione');
    expect($causale)->toBe('Interessi di mora al 20/07/2026');

    // Il 25/07 si registra un bonifico del 10/06 di € 50,00 sulla rata 1, arrivato prima. I giorni registrati darebbero ora 39,452055 + 20.000 × 16.000 ×
    // 41 / 365.000.000 = 35,945205 (dal 01/05 al 10/06) + 15.000 × 16.000 × 34 / 365.000.000 = 22,356164 (dal 11/06 al 14/07) = 97,753424 → € 0,98.
    $this->travelTo('2026-07-25 10:00:00');
    lcdIncassa($this, $s, 'Neri', [$q1 => 5000], '2026-06-10');

    // Con la decadenza del 20/08 la riga torna (15.000 × 16.000 × 37 / 365.000.000 = 24,328767 → € 0,24, dal 15/07), e con lei la frase del riallineo: dice
    // il 20/07, il giorno dell'addebito da annullare, non il 14/07, l'ultimo giorno che la riga registrata conta.
    $this->travelTo('2026-08-20 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.decadenza', ['condominio' => $s['c']->id, 'accordo' => $accordo->id]),
        ['data' => '2026-08-20', 'nota' => 'Raccomandata'])->assertOk();
    $riga = collect(accDi(accProposta($this, $s, ['Neri'], '2026-08-20')->assertOk()->json(), $s, 'Neri')['righe'])->firstWhere('rata_id', lcdRata($s, 1));
    expect([$riga['interessi'], $riga['dal']])->toBe([24, '2026-07-15'])
        ->and($riga['riallineo'])->toBe('Gli interessi registrati al 20/07/2026 erano € 1,05; con il giornale di oggi quei giorni darebbero € 0,98: per cambiarli, '
            . 'annulla l\'addebito e rifai la proposta.');
});
