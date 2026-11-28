<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La voce a € 0 messa a rate con un piano delle fatture: la riga che il piano ha preso resta presa finché il piano c'è, qualunque cifra prenda poi la voce in
 * un altro piano (il preventivo «con tutte le voci», il «Ricalcola» con le voci da includere). E i rilievi rimasti del ripianamento del debito ereditato.
 *
 * I casi: «Manutenzione tetto» € 2.000,00, la fattura ZM0003 da € 1.400,00 approvata, la voce scesa a € 0; il piano delle fatture «Tetto a rate» ne chiede
 * € 1.000,00; «Pulizie» € 1.000,00. Le tabelle: «Proprietà» 600/400, «Scale» 500/500. Il debito ereditato verso Idraulica Bianchi (€ 2.000,00), i pregressi
 * di Ugo (€ 500,00) e Bice (€ 300,00).
 */

use App\Events\Gestionale\FatturaRegistrata;
use App\Listeners\Gestionale\SyncScadenziarioWithFattura;
use App\Models\Evento;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PianoRate;
use App\Models\Saldo;
use App\Services\CondominioService;
use App\Services\Gestionale\FatturaPassivaService;
use App\Services\Gestionale\RipianamentoDelDeficit;
use App\Services\Gestionale\VoceFuoriDalPreventivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/Beta15VoceAZeroScenari.php';

uses(RefreshDatabase::class);

// === La riga presa resta presa finché il suo piano c'è (Coda 742) ==========================================================================================

it('V1c il preventivo «con tutte le voci» chiede il residuo del tetto; la riga resta del piano delle fatture, la fattura non diventa mista, il «Ricalcola» dà 600/400', function () {
    $r = b15vzTettoARate($this);
    $s = $r['s'];
    b15vzPreventivo($this, $r)->assertSessionHasNoErrors();
    $preventivo = PianoRate::query()->where('nome', 'Preventivo 2026')->sole();
    // Il preventivo chiede il residuo: € 1.400,00 di spesa, € 1.000,00 già nel piano delle fatture.
    expect(b15vzCopie($preventivo))->toBe([['Manutenzione tetto', 40000], ['Pulizie', 100000]])
        ->and(VoceFuoriDalPreventivo::idDellaFattura((int) $r['f']->id))->not->toBe([]);
    b15vzStato($this, $r, $r['piano'], false)->assertSessionHasNoErrors();
    b15vzModificaFattura($this, $r, $r['f'], [['Manutenzione tetto', 1400.00, null], ['Pulizie', 100.00, 'A']]);
    expect(b15vzCiSonoErrori())->toBeTrue()
        ->and(DB::table('righe_fattura')->where('fattura_passiva_id', $r['f']->id)->count())->toBe(1);
    b15vzRicalcola($this, $r, $r['piano'])->assertSessionHasNoErrors();
    // A mano: € 1.000,00 sulla «Proprietà» 600/400.
    expect(b15vzPerPersona($s, $r['piano']))->toBe(['ugo' => 60000, 'bice' => 40000]);
});

it('V1d dopo il preventivo «con tutte le voci» il «Ricalcola» del piano delle fatture non si ferma', function () {
    $r = b15vzTettoARate($this);
    $s = $r['s'];
    b15vzPreventivo($this, $r)->assertSessionHasNoErrors();
    b15vzRicalcola($this, $r, $r['piano']);
    expect(b15vzFlash()['type'] ?? null)->not->toBe('error')
        ->and(b15vzPerPersona($s, $r['piano']))->toBe(['ugo' => 60000, 'bice' => 40000]);
});

it('V1g la fattura modificata senza cambiare composizione resta nel piano: la riga tiene il segno, il «Ricalcola» dà ancora 600/400', function () {
    $r = b15vzTettoARate($this);
    $s = $r['s'];
    b15vzPreventivo($this, $r)->assertSessionHasNoErrors();
    b15vzStato($this, $r, $r['piano'], false)->assertSessionHasNoErrors();
    b15vzModificaFattura($this, $r, $r['f'], [['Manutenzione tetto', 1400.00, null]])->assertSessionHasNoErrors();
    expect(b15vzRateizzata($r['f']))->toBe([true])
        ->and(VoceFuoriDalPreventivo::idDellaFattura((int) $r['f']->id))->not->toBe([]);
    b15vzRicalcola($this, $r, $r['piano'])->assertSessionHasNoErrors();
    expect(b15vzPerPersona($s, $r['piano']))->toBe(['ugo' => 60000, 'bice' => 40000]);
});

it('V1e la voce non sale a € 1.400,00 finché il piano c\'è; i due piani chiedono € 2.400,00 per € 2.400,00, anche dopo l\'emissione e l\'incasso della prima rata', function () {
    $r = b15vzTettoARate($this);
    $s = $r['s'];
    b15vzPreventivo($this, $r)->assertSessionHasNoErrors();
    $preventivo = PianoRate::query()->where('nome', 'Preventivo 2026')->sole();
    b15vzModificaVoce($this, $r, $s->voci['Manutenzione tetto']['conto'], ['importo' => '1.400,00']);
    expect(b15vzCiSonoErrori())->toBeTrue()
        ->and((int) $s->voci['Manutenzione tetto']['conto']->fresh()->importo)->toBe(0);
    // A mano: pulizie € 1.000,00 + tetto € 1.400,00 = € 2.400,00 di spesa; il piano delle fatture ne chiede € 1.000,00, il preventivo € 400,00.
    $chiesto = fn () => array_sum(b15vzPerPersona($s, $preventivo)) + array_sum(b15vzPerPersona($s, $r['piano']->fresh()));
    expect($chiesto())->toBe(240000);

    // La catena fino all'incasso: il piano delle fatture approvato, la prima rata emessa e pagata da Bice.
    $prima = DB::table('rate')->where('piano_rate_id', $r['piano']->id)->orderBy('numero_rata')->first();
    $this->actingAs($s->admin)->post(route('admin.gestionale.piani-rate.emetti', [$s->c, $r['piano']]), [
        'rate_ids' => [$prima->id], 'data_emissione' => '2026-11-30', 'invia_notifiche' => false,
    ])->assertSessionHasNoErrors();
    $quota = DB::table('rate_quote')->where('rata_id', $prima->id)->where('anagrafica_id', $s->persona('bice')->id)->first();
    $this->actingAs($s->admin)->post(route('admin.gestionale.movimenti-rate.store', [$s->c]), [
        'pagante_id' => $s->persona('bice')->id, 'cassa_id' => $s->cassa()->id, 'data_pagamento' => '2026-12-01',
        'importo_totale' => number_format($quota->importo / 100, 2, '.', ''), 'descrizione' => 'Bonifico', 'eccedenza' => '0.00',
        'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => number_format($quota->importo / 100, 2, '.', '')]],
    ])->assertSessionHasNoErrors();
    expect((int) DB::table('rate_quote')->where('id', $quota->id)->value('importo_pagato'))->toBe((int) $quota->importo);
    b15vzModificaVoce($this, $r, $s->voci['Manutenzione tetto']['conto'], ['importo' => '1.400,00']);
    expect(b15vzCiSonoErrori())->toBeTrue()
        ->and(VoceFuoriDalPreventivo::idDellaFattura((int) $r['f']->id))->not->toBe([])
        ->and($chiesto())->toBe(240000);
});

it('V1f due fatture sul tetto: la ZM0003 resta presa dal piano delle fatture, la ZM0004 la chiede il preventivo', function () {
    $r = b15vzTetto($this, 200000);
    $s = $r['s'];
    $f = b15vzFattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0003');
    b15vzFattura($this, $r, [['Manutenzione tetto', 500.00, null]], 'ZM0004');
    b15vzModificaVoce($this, $r, $s->voci['Manutenzione tetto']['conto'], ['importo' => '0,00'])->assertSessionHasNoErrors();
    b15vzPianoFatture($this, $r, $f, '1.400,00')->assertSessionHasNoErrors();
    $piano = PianoRate::query()->where('nome', 'Tetto a rate')->sole();
    b15vzPreventivo($this, $r)->assertSessionHasNoErrors();
    $preventivo = PianoRate::query()->where('nome', 'Preventivo 2026')->sole();
    expect(b15vzCopie($preventivo))->toBe([['Manutenzione tetto', 50000], ['Pulizie', 100000]])
        ->and(VoceFuoriDalPreventivo::idDellaFattura((int) $f->id))->not->toBe([]);
    b15vzRicalcola($this, $r, $piano)->assertSessionHasNoErrors();
    expect(b15vzFlash()['type'] ?? null)->not->toBe('error')
        ->and(b15vzPerPersona($s, $piano))->toBe(['ugo' => 84000, 'bice' => 56000]);
});

it('V2 il «Ricalcola» del preventivo con il capitolo del tetto fra le voci da includere non gli dà una cifra; la riga resta presa e la voce ferma', function () {
    $r = b15vzCapitolo($this);
    $s = $r['s'];
    b15vzPianoFatture($this, $r, $r['fattura'], '1.400,00')->assertSessionHasNoErrors();
    b15vzPreventivo($this, $r, 'Preventivo 2026', ['capitoli_ids' => [$s->voci['Pulizie']['conto']->id],
        'capitoli_config' => [['id' => $s->voci['Pulizie']['conto']->id, 'importo' => '']]])->assertSessionHasNoErrors();
    $preventivo = PianoRate::query()->where('nome', 'Preventivo 2026')->sole();
    b15vzRicalcola($this, $r, $preventivo, ['orphan_ids' => [$r['capitolo']->id]]);
    expect(b15vzCopie($preventivo))->toBe([['Pulizie', null]])
        ->and(VoceFuoriDalPreventivo::idDellaFattura((int) $r['fattura']->id))->not->toBe([]);
    b15vzModificaVoce($this, $r, $s->voci['Manutenzione tetto']['conto'], ['importo' => '1.400,00']);
    expect(b15vzCiSonoErrori())->toBeTrue()
        ->and((int) $s->voci['Manutenzione tetto']['conto']->fresh()->importo)->toBe(0);
});

it('V2b il «Ricalcola» fermato dal capitolo del tetto dice la frase di «Dalle voci» da sola, senza «Errore durante il ricalcolo»', function () {
    $r = b15vzCapitolo($this);
    $s = $r['s'];
    b15vzPianoFatture($this, $r, $r['fattura'], '1.400,00')->assertSessionHasNoErrors();
    b15vzPreventivo($this, $r, 'Preventivo 2026', ['capitoli_ids' => [$s->voci['Pulizie']['conto']->id],
        'capitoli_config' => [['id' => $s->voci['Pulizie']['conto']->id, 'importo' => '']]])->assertSessionHasNoErrors();
    $preventivo = PianoRate::query()->where('nome', 'Preventivo 2026')->sole();
    b15vzRicalcola($this, $r, $preventivo, ['orphan_ids' => [$r['capitolo']->id]])->assertRedirect();
    expect(b15vzFlash()['type'] ?? null)->toBe('error')
        ->and((string) (b15vzFlash()['message'] ?? ''))->toStartWith('Il capitolo «' . $r['capitolo']->nome . '» contiene la voce «Manutenzione tetto»')
        ->and((string) (b15vzFlash()['message'] ?? ''))->not->toContain('Errore durante il ricalcolo');
});

it('V2c la stessa con la fattura approvata e la voce scesa a € 0: la fattura non diventa mista e il «Ricalcola» del piano delle fatture dà 840/560', function () {
    $r = b15vzCapitolo($this, true, '2.000,00');
    $s = $r['s'];
    b15vzPianoFatture($this, $r, $r['fattura'], '1.400,00')->assertSessionHasNoErrors();
    $tetto = PianoRate::query()->where('nome', 'Tetto a rate')->sole();
    b15vzPreventivo($this, $r, 'Preventivo 2026', ['capitoli_ids' => [$s->voci['Pulizie']['conto']->id],
        'capitoli_config' => [['id' => $s->voci['Pulizie']['conto']->id, 'importo' => '']]])->assertSessionHasNoErrors();
    $preventivo = PianoRate::query()->where('nome', 'Preventivo 2026')->sole();
    b15vzRicalcola($this, $r, $preventivo, ['orphan_ids' => [$r['capitolo']->id]]);
    b15vzStato($this, $r, $tetto, false)->assertSessionHasNoErrors();
    b15vzModificaFattura($this, $r, $r['fattura'], [['Manutenzione tetto', 1400.00, null], ['Pulizie', 100.00, 'A']]);
    expect(b15vzCiSonoErrori())->toBeTrue();
    b15vzRicalcola($this, $r, $tetto)->assertSessionHasNoErrors();
    expect(b15vzPerPersona($s, $tetto))->toBe(['ugo' => 84000, 'bice' => 56000]);
});

it('B1 la controprova: eliminato il piano delle fatture, il segno si spegne, lo spostamento passa e il carrello non offre più niente', function () {
    $r = b15vzTetto($this);
    $s = $r['s'];
    $f = b15vzFattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0001', 140000);
    b15vzPianoFatture($this, $r, $f, '1.400,00')->assertSessionHasNoErrors();
    $tetto = PianoRate::query()->where('nome', 'Tetto a rate')->sole();
    expect(b15vzRateizzata($f))->toBe([true]);
    b15vzPreventivo($this, $r)->assertSessionHasNoErrors();
    $preventivo = PianoRate::query()->where('nome', 'Preventivo 2026')->sole();
    b15vzStato($this, $r, $tetto, false)->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s->c, $s->e, $tetto]))->assertRedirect();
    expect(PianoRate::query()->whereKey($tetto->id)->exists())->toBeFalse()
        ->and(b15vzRateizzata($f))->toBe([false]);
    b15vzSposta($this, $r, $preventivo, $s->voci['Pulizie']['conto']->id, $s->voci['Manutenzione tetto']['conto']->id)->assertSessionHasNoErrors();
    $carrello = collect($this->actingAs($s->admin)->getJson(route('admin.gestionale.fetch-fatture-straordinarie', ['condominio' => $s->c->id,
        'gestione_id' => $r['g']->id, 'esercizio_id' => $s->e->id]))->assertOk()->json())->pluck('numero_documento')->all();
    expect($carrello)->not->toContain('ZM0001')
        ->and(VoceFuoriDalPreventivo::idDellaFattura((int) $f->id))->toBe([]);
});

it('B1b eliminato il piano delle fatture dopo che il preventivo ha dato una cifra al tetto, il segno si spegne lo stesso', function () {
    $r = b15vzTettoARate($this);
    $s = $r['s'];
    b15vzPreventivo($this, $r)->assertSessionHasNoErrors();
    expect(b15vzRateizzata($r['f']))->toBe([true]);
    b15vzStato($this, $r, $r['piano'], false)->assertSessionHasNoErrors();
    $this->actingAs($s->admin)->delete(route('admin.gestionale.esercizi.piani-rate.destroy', [$s->c, $s->e, $r['piano']]))->assertRedirect();
    expect(b15vzRateizzata($r['f']))->toBe([false])
        ->and(VoceFuoriDalPreventivo::idDellaFattura((int) $r['f']->id))->toBe([]);
});

// === Il ripianamento del debito ereditato: i rilievi rimasti (Coda 743) ===================================================================================

it('M1a i pregressi scritti dal Wallet dopo la pregressa: la descrizione del compito non porta più la cifra della registrazione', function () {
    $r = b15vzDebito($this, false, 200000, 'Idraulica Bianchi', null, false);
    // Il compito nasce senza la cifra (la dice la riga sotto il titolo); quello scritto dalle versioni di prima la portava, e la perde al primo allineamento.
    expect((string) $r['compito']->fresh()->description)->not->toContain('€ 2.000,00')->toContain('ha una parte scoperta');
    $r['compito']->fresh()->update(['description' => 'Il debito ereditato (Idraulica Bianchi) è scoperto per € 2.000,00: è la parte che i pregressi chiesti ai condòmini non coprono, e nessuna rata la chiede.']);
    b15vzSaldo($this, $r, 'ugo', 'A', 50000);
    b15vzSaldo($this, $r, 'bice', 'B', 30000);
    $compito = $r['compito']->fresh();
    expect(b15vzRiga($compito)['sotto'])->toBe('Scoperto di € 1.200,00')
        ->and((string) b15vzRiga($compito)['description'])->not->toContain('€ 2.000,00');
});

it('M2a con il debito a rate oltre lo scoperto il punto dell\'ordine del giorno dice la correzione', function () {
    $r = b15vzDebito($this, true, 200000, 'Idraulica Bianchi', null, false);
    $s = $r['s'];
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), b15vzCorpo($r, [], ['importo' => '2.000,00']))->assertSessionHasNoErrors();
    b15vzSaldo($this, $r, 'ugo', 'A', 50000);
    b15vzSaldo($this, $r, 'bice', 'B', 30000);
    $compito = $r['compito']->fresh();
    $punto = \App\Services\Assemblee\PropostaDelPunto::perLaScheda($compito);
    expect((bool) $compito->is_completed)->toBeFalse()
        ->and($punto['testo'] ?? '')->toContain('Correzione del ripianamento')
        ->and($punto['testo'] ?? '')->toContain('€ 800,00');
});

it('M2b un pregresso tolto dal Wallet dopo il ripianamento riapre il compito e lo dice', function () {
    $r = b15vzDebito($this, true, 200000, 'Idraulica Bianchi', null, false);
    $s = $r['s'];
    b15vzSaldo($this, $r, 'ugo', 'A', 50000);
    b15vzSaldo($this, $r, 'bice', 'B', 30000);
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), b15vzCorpo($r))->assertSessionHasNoErrors();
    expect((bool) $r['compito']->fresh()->is_completed)->toBeTrue();
    $bice = Saldo::query()->where('condominio_id', $s->c->id)->where('anagrafica_id', $s->persona('bice')->id)->whereNull('fornitore_id')->sole();
    session()->forget(['message', 'errors']);
    $this->actingAs($s->admin)->from('/saldi')->delete(route('admin.gestionale.saldi.destroy', [$s->c, $bice]))->assertRedirect();
    $compito = $r['compito']->fresh();
    // A mano: debito € 2.000,00 − pregresso di Ugo € 500,00 − ripianati € 1.200,00 = € 300,00 che nessuno chiede.
    expect((bool) $compito->is_completed)->toBeFalse()
        ->and(b15vzRiga($compito)['sotto'])->toBe('Scoperto di € 300,00')
        ->and((string) (b15vzFlash()['message'] ?? ''))->toContain('€ 300,00 non li chiede nessuno')
        ->and(b15vzTesoreria($s))->toBe(['quanti' => 1, 'cifra' => 30000]);
});

it('M2d con il compito segnato come fatto dall\'amministratore, un pregresso scritto dopo non ripete l\'avviso', function () {
    $r = b15vzDebito($this, true, 200000, 'Idraulica Bianchi', null, false);
    $s = $r['s'];
    $this->actingAs($s->admin)->post(route('admin.gestionale.esercizi.piani-rate.store', [$s->c, $s->e]), b15vzCorpo($r, [], ['importo' => '2.000,00']))->assertSessionHasNoErrors();
    b15vzSaldo($this, $r, 'ugo', 'A', 50000);
    app(\App\Services\Gestionale\RispostePromemoria::class)->fattoDelCompito($r['compito']->fresh(), 'L\'assemblea ha deciso così', $s->admin);
    $dopo = b15vzSaldo($this, $r, 'bice', 'B', 30000);
    expect((bool) $r['compito']->fresh()->is_completed)->toBeTrue()
        ->and((string) ($dopo['message'] ?? ''))->not->toContain('segna il compito come fatto');
});

it('S1 lo storno verso il capitolo sopra la voce messa a rate si rifiuta con la frase dello storno', function () {
    $r = b15vzCapitolo($this, false);
    $s = $r['s'];
    b15vzPreventivo($this, $r, 'Preventivo 2026', ['capitoli_ids' => [$r['capitolo']->id, $s->voci['Pulizie']['conto']->id],
        'capitoli_config' => [['id' => $r['capitolo']->id, 'importo' => '500,00'], ['id' => $s->voci['Pulizie']['conto']->id, 'importo' => '']]])->assertSessionHasNoErrors();
    $preventivo = PianoRate::query()->where('nome', 'Preventivo 2026')->sole();
    b15vzSposta($this, $r, $preventivo, $r['capitolo']->id, $s->voci['Pulizie']['conto']->id, '500')->assertSessionHasNoErrors();
    $via = \App\Models\Gestionale\BudgetMovement::query()->where('source_conto_id', $r['capitolo']->id)->sole();
    $f = b15vzFattura($this, $r, [['Manutenzione tetto', 1400.00, null]], 'ZM0001', 140000);
    b15vzPianoFatture($this, $r, $f, '1.400,00')->assertSessionHasNoErrors();
    session()->forget('errors');
    $this->actingAs($s->admin)->from('/piano')->post(route('admin.gestionale.piani-rate.budget-movements.reverse', [$s->c, $preventivo, $via]))->assertRedirect();
    expect(b15vzErrori())->toHaveKey('movement')
        ->and(implode(' ', b15vzErrori()['movement']))->toContain('Togli prima la fattura da quel piano, poi annulla lo spostamento')
        ->and(implode(' ', b15vzErrori()['movement']))->not->toContain('Sposta il budget su un\'altra voce');
});
