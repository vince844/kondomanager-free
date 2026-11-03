<?php

/**
 * Coda 414 — tre casi in più: la quota che nessuno ha accettato, le voci di entrata, il preventivo di una voce con il già versato.
 *
 * Cosa resta scoperto: il piano straordinario e il gruppo frazionato (Coda 415); MySQL.
 *
 * (1) «Rimuovi voce e ricalcola» rigenera accettando gli scoperti senza chiedere niente (`PianoRateController::detachCapitolo`): una quota che la
 * generazione di prima non aveva — la Cantina 1 ha perso il proprietario dopo la generazione — non l'ha accettata nessuno, e il piano non è in
 * «quota aperta» ma disallineato, come prima della 1.11.0-beta.59; «Ricalcola» chiede la presa d'atto, e dopo è quota aperta.
 * (2) La quota non assegnata su una voce di entrata è un credito che nessuno riceve: lo scarto ha il segno meno (atteso − generato), e le quote si
 * confrontano con il loro segno. Una spesa e un'entrata sulla stessa tabella della Cantina 1: € 75,00 − € 30,00 = € 45,00, ed è quota aperta.
 * (3) Il preventivo alzato dopo la generazione su una voce con del già versato sposta l'atteso: il piano toglie la parte del già versato che
 * corrisponde alla sua quota del preventivo (`QuotaDellaChiamata::perConto`), e la guida non può dire che il preventivo cambiato non accende il badge.
 *
 * Le cifre: tabella «Riscaldamento» A 500, C 470, Cantina 1 30 su 1000. Riscaldamento € 2.500,00 nel piano → Cantina 2.500,00 × 30 / 1000 = € 75,00.
 * Antenna (entrata) € 1.000,00 sulla stessa tabella → Cantina 1.000,00 × 30 / 1000 = € 30,00, che nessuno riceve. Pulizie € 1.000,00 su A e C
 * 500/500, già versato di Ugo € 100,00: alzato il preventivo a € 2.000,00 il piano ne chiede la metà, e toglie € 50,00 invece di € 100,00.
 */

use App\Models\Gestionale\PianoRate;
use App\Services\PianoRateQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta59PianoScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = b52tAdmin();
    b52tSqliteJson();
});

it('Coda 414 — «Rimuovi voce» che trova una quota nuova, che nessuno ha accettato: il piano è disallineato, e «Ricalcola» chiede la presa d\'atto', function () {
    $s = b59pScenarioV34($this, conCantinaSenzaProprietario: false);
    b59pPulizie($this, $s);
    $piano = b59pPianoV34($this, $s, procedi: false, extra: b59pConPulizie($s));
    expect(b59pPagina($this, $s, $piano)['allineamento']['stato'])->toBe('allineato');

    // la Cantina 1 perde il proprietario dopo la generazione, poi si toglie la voce delle pulizie
    DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']['Cantina']->id)->delete();
    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s['c'], $s['e'], $piano, $s['pulizie']->id]))->assertSessionHasNoErrors();

    expect(b59pMisura($piano))->toBe(['atteso' => 250000, 'generato' => 242500, 'disallineato' => true]);
    expect(b59pPagina($this, $s, $piano)['allineamento'])->toMatchArray(['stato' => 'disallineato', 'scarto_cents' => 7500])
        ->and(b59pPagina($this, $s, $piano)['allineamento']['scarto_registrato_cents'])->toBeNull();
    expect(collect(b59pCruscotto($this, $s)['pianiDisallineati'])->pluck('delta')->all())->toBe([7500]);
    // la quota c'è nella colonna, con la sua cifra, e il riquadro la mostra
    expect(collect(b59pColonna($piano)['voci'])->map(fn ($v) => [$v['immobile_nome'], $v['importo_cents']])->all())->toBe([['Cantina 1', 7500]]);

    b59pRicalcola($this, $s, $piano)->assertSessionHas('scoperti_warning');
    b59pRicalcola($this, $s, $piano, 'Cantina 1 senza proprietario dal rogito')->assertSessionHasNoErrors();
    expect(b59pPagina($this, $s, $piano)['allineamento']['stato'])->toBe('quota_aperta');
});

it('Coda 414 — controprova: «Rimuovi voce» due volte con la stessa quota accettata resta in quota aperta', function () {
    $s = b59pScenarioV34($this);
    b59pPulizie($this, $s);
    $ascensore = b52tVoce($this, $s, 'Ascensore', 'spesa', '500,00', $s['tabella']);
    $piano = b59pPianoV34($this, $s, extra: ['capitoli_ids' => [$s['riscaldamento']->id, $s['pulizie']->id, $ascensore->id], 'capitoli_config' => [
        ['id' => $s['riscaldamento']->id, 'importo' => '2.500,00', 'note' => ''], ['id' => $s['pulizie']->id, 'importo' => '1.000,00', 'note' => ''],
        ['id' => $ascensore->id, 'importo' => '500,00', 'note' => ''],
    ]]);

    foreach ([$s['pulizie']->id, $ascensore->id] as $voce) {
        $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.capitoli.detach', [$s['c'], $s['e'], $piano, $voce]))->assertSessionHasNoErrors();
        expect(b59pPagina($this, $s, $piano)['allineamento']['stato'])->toBe('quota_aperta');
    }
});

it('Coda 414 — una quota non assegnata su una voce di entrata: lo scarto è − € 30,00, la quota € 30,00 con il segno meno, ed è quota aperta', function () {
    $s = b59pScenarioV34($this);
    $antenna = b52tVoce($this, $s, 'Antenna', 'entrata', '1.000,00', $s['risc']);
    $piano = b59pPianoV34($this, $s, nota: 'Cantina 1 senza proprietario al rogito', extra: ['capitoli_ids' => [$antenna->id], 'capitoli_config' => [['id' => $antenna->id, 'importo' => '1.000,00', 'note' => '']]]);

    expect(b59pPagina($this, $s, $piano)['allineamento'])->toMatchArray(['stato' => 'quota_aperta', 'scarto_cents' => -3000, 'scarto_registrato_cents' => -3000]);
    expect(collect(b59pColonna($piano)['voci'])->map(fn ($v) => [$v['conto_nome'], $v['importo_cents'], $v['segno']])->all())->toBe([['Antenna', 3000, -1]]);
});

it('Coda 414 — una spesa e un\'entrata sulla tabella della Cantina 1: € 75,00 − € 30,00 = € 45,00 di scarto, spiegati dalle due quote con il loro segno: quota aperta', function () {
    $s = b59pScenarioV34($this);
    $antenna = b52tVoce($this, $s, 'Antenna', 'entrata', '1.000,00', $s['risc']);
    $piano = b59pPianoV34($this, $s, extra: ['capitoli_ids' => [$s['riscaldamento']->id, $antenna->id], 'capitoli_config' => [
        ['id' => $s['riscaldamento']->id, 'importo' => '2.500,00', 'note' => ''], ['id' => $antenna->id, 'importo' => '1.000,00', 'note' => ''],
    ]]);

    expect(b59pMisura($piano))->toBe(['atteso' => 150000, 'generato' => 145500, 'disallineato' => false]);
    expect(b59pPagina($this, $s, $piano)['allineamento'])->toMatchArray(['stato' => 'quota_aperta', 'scarto_cents' => 4500, 'scarto_registrato_cents' => 4500]);
    expect(b59pCruscotto($this, $s)['pianiDisallineati'])->toBe([]);
});

it('Coda 414 — controprova: il preventivo alzato dopo la generazione su una voce con del già versato accende «Disallineato», e «Ricalcola» lo spegne', function () {
    $s = b59pScenarioV34($this, conCantinaSenzaProprietario: false);
    b59pPulizie($this, $s);
    b52tGiaVersato($this, $s, $s['pulizie'], 'A', 10_000);
    $piano = b59pPianoV34($this, $s, procedi: false, extra: b59pConPulizie($s));
    expect(b59pMisura($piano))->toBe(['atteso' => 340000, 'generato' => 340000, 'disallineato' => false]);

    $this->actingAs($this->user)->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $s['pulizie']]), [
        'nome' => 'Pulizie', 'codice' => '', 'tipo' => 'spesa', 'importo' => '2.000,00', 'descrizione' => '', 'note' => '', 'parent_id' => null,
        'tabella_millesimale_id' => $s['tabella']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();

    // il piano chiede € 1.000,00 di € 2.000,00: toglie € 50,00 del già versato di Ugo, non € 100,00 — l'atteso sale di € 50,00, le rate no
    expect(b59pMisura($piano))->toBe(['atteso' => 345000, 'generato' => 340000, 'disallineato' => true]);

    b59pRicalcola($this, $s, $piano)->assertSessionHasNoErrors();
    expect(b59pMisura($piano))->toBe(['atteso' => 345000, 'generato' => 345000, 'disallineato' => false]);
});

it('Coda 414 — una voce portata a «Entrata» dopo la generazione, finché nessun piano approvato la tiene: il piano è disallineato, come dice la guida', function () {
    // «Riscaldamento» € 4.000,00 nel piano per € 2.500,00, Cantina 1 col proprietario censito: allineato, € 3.500,00 meno nulla. Il piano è in bozza e la voce si può portare a «Entrata» (il tipo
    // lo blocca il controller solo con un piano approvato): le rate hanno chiesto la spesa, e il piano adesso la legge come un'entrata, − € 2.500,00. Il segno di `Conto::segno` è quello di adesso.
    $s = b59pScenarioV34($this, conCantinaSenzaProprietario: false);
    $piano = b59pPianoV34($this, $s, procedi: false);
    expect(b59pMisura($piano))->toBe(['atteso' => 250000, 'generato' => 250000, 'disallineato' => false]);

    $this->actingAs($this->user)->put(route('admin.gestionale.esercizi.piani-conti.conti.update', [$s['c'], $s['e'], $s['pc'], $s['riscaldamento']]), [
        'nome' => 'Riscaldamento', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'entrata', 'importo' => '4.000,00', 'descrizione' => '', 'note' => '',
        'parent_id' => null, 'tabella_millesimale_id' => $s['risc']->id, 'percentuale_proprietario' => 100, 'percentuale_inquilino' => 0, 'percentuale_usufruttuario' => 0,
        'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
    ])->assertSessionHasNoErrors();

    expect($s['riscaldamento']->fresh()->tipo)->toBe('entrata')
        ->and(b59pMisura($piano)['disallineato'])->toBeTrue();
});

