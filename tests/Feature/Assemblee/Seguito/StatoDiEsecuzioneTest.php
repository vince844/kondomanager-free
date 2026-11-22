<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\ProposteDellOrdineDelGiorno;
use App\Services\Assemblee\Seguito\StatoDiEsecuzione;
use App\Services\Assemblee\StatiDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * Alla prossima assemblea (R8, R10; decisioni 522 (2) e (6), 622 (7)): il punto «Stato di esecuzione delle delibere precedenti», proposto di serie e senza
 * voto, porta le delibere aperte di sempre (la straordinaria del 22/10/2026, il piano ancora da creare) e quelle eseguite dall'ultima ordinaria (il
 * 15/12/2026), con l'elenco fermo al giorno in cui si prepara; il sospeso rinviato torna proposto con «rinviato dal 15/12/2026».
 *
 * Che cosa non copre: la stampa dello stato di esecuzione; il testo nell'avviso e nel verbale (leggono i dati del punto).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Una riunione tenuta su carta il giorno dato, con gli esiti per numero del punto. */
function sdeSuCarta(Beta51Scenario $s, array $punti, string $giorno, array $esiti = [], array $piu = []): \App\Models\Assemblee\Assemblea
{
    ConvocazioneDiViaRoma12::oggi(\Illuminate\Support\Carbon::parse($giorno)->addDay()->format('Y-m-d') . ' 10:00');
    $a = ViaRoma12::ordinaria($s, $punti, $piu);
    $e = [];
    foreach ($a->punti()->get() as $p) {
        $e[(int) $p->id] = ['esito' => $esiti[$p->numero] ?? 'approvata'];
    }

    return StatiDellAssemblea::tenutaFuoriDalProgramma($a, $s->admin, $e, $giorno);
}

it('R10 · il punto porta le aperte di sempre e le eseguite dall\'ultima ordinaria, fermo al giorno in cui si prepara; senza voto, di serie', function () {
    $s = ViaRoma12::nuovo($this);
    $facciata = sdeSuCarta($s, [['testo' => 'Rifacimento della facciata', 'materia' => 'lavori', 'importo' => 9000000]], '2026-10-22', [],
        ['tipo' => 'straordinaria']);
    SeguitoDiViaRoma12::conferma($this, $s, $facciata, [SeguitoDiViaRoma12::delibera($facciata, 1)->id . ':cosa:crea_piano_straordinario'])->assertOk();
    $ordinaria = sdeSuCarta($s, [['testo' => 'Riscaldamento: orari', 'materia' => 'riscaldamento']], '2026-12-15');
    SeguitoDiViaRoma12::conferma($this, $s, $ordinaria, [])->assertOk();

    ConvocazioneDiViaRoma12::oggi('2027-04-28 10:00');
    $proposte = collect(ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'di_serie')['punti'];
    $punto = collect($proposte)->firstWhere('chiave_fonte', StatoDiEsecuzione::CHIAVE);
    expect($punto['testo'])->toBe('Stato di esecuzione delle delibere precedenti')
        ->and($punto['materia'])->toBe('comunicazione')
        ->and($punto['maggioranza'])->toBe('senza_voto')
        ->and($punto['dati']['stato_esecuzione']['al'])->toBe('2027-04-28')
        ->and($punto['dati']['stato_esecuzione']['ultima_ordinaria'])->toBe('2026-12-15')
        // Il punto va nell'avviso e nel verbale: senza la frase pubblica, la riga neutra della materia (mai il testo del punto).
        ->and(collect($punto['dati']['stato_esecuzione']['aperte'])->pluck('testo')->all())->toBe(['Lavori nelle parti comuni'])
        ->and(collect($punto['dati']['stato_esecuzione']['eseguite'])->pluck('testo')->all())->toBe(['Riscaldamento e condizionamento'])
        ->and($punto['dati']['stato_esecuzione']['eseguite'][0]['eseguita_il'])->toBe('2026-12-16');
});

it('R10 · senza delibere confermate il punto non si propone', function () {
    $s = ViaRoma12::nuovo($this);
    sdeSuCarta($s, [['testo' => 'Riscaldamento: orari', 'materia' => 'riscaldamento']], '2026-12-15');
    $proposte = collect(ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'di_serie')['punti'];
    expect(collect($proposte)->pluck('chiave_fonte')->all())->not->toContain(StatoDiEsecuzione::CHIAVE);
});

it('R8 · il sospeso rinviato torna proposto con «rinviato dal 15/12/2026»', function () {
    $s = ViaRoma12::nuovo($this);
    $f = ViaRoma12::fatturaInSforo($s);
    $p = \App\Services\Assemblee\PropostaDelPunto::perLaScheda($f);
    sdeSuCarta($s, [['testo' => $p['testo'], 'materia' => $p['materia'], 'oggetti' => $p['oggetti'], 'chiave_fonte' => $p['chiave_fonte'], 'fonte' => 'programma']],
        '2026-12-15', [1 => 'rinviata']);
    $proposte = collect(ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'dal_programma')['punti'];
    expect(collect($proposte)->firstWhere('chiave_fonte', $p['chiave_fonte'])['rinviato_dal'] ?? null)->toBe('2026-12-15');
});

it('R8 · il voto corretto dopo la conferma: da «Rinviata» a «Approvata» il punto non torna più; da «Approvata» a «Rinviata» torna (revisione del 22/11)', function () {
    $daTe = fn ($c) => collect(collect(ProposteDellOrdineDelGiorno::per($c, null))->firstWhere('chiave', 'da_te')['punti'] ?? [])->pluck('testo')->all();
    $s = ViaRoma12::nuovo($this);
    $a = sdeSuCarta($s, [['testo' => 'Sostituzione del citofono del portone', 'materia' => 'altro']], '2026-12-15', [1 => 'rinviata']);
    $punto = $a->punti()->where('numero', 1)->sole();
    expect($daTe($s->c))->toContain('Sostituzione del citofono del portone');
    StatiDellAssemblea::esitoCambiato($punto, \App\Enums\Assemblee\EsitoDelibera::RINVIATA, \App\Enums\Assemblee\EsitoDelibera::APPROVATA, $s->admin);
    expect($daTe($s->c))->not->toContain('Sostituzione del citofono del portone');

    $t = ViaRoma12::nuovo($this);
    $b = sdeSuCarta($t, [['testo' => 'Regole per l\'uso del cortile', 'materia' => 'altro']], '2026-12-15', [1 => 'approvata']);
    $cortile = $b->punti()->where('numero', 1)->sole();
    StatiDellAssemblea::esitoCambiato($cortile, \App\Enums\Assemblee\EsitoDelibera::APPROVATA, \App\Enums\Assemblee\EsitoDelibera::RINVIATA, $t->admin);
    expect($daTe($t->c))->toContain('Regole per l\'uso del cortile');
});

it('R8 · il punto scritto a mano e rinviato torna fra le proposte, come un memo che ricorda il punto (rilievo C62)', function () {
    // Il caso della prova a video del 22/11: «Approvazione del preventivo», scritto a mano, senza fonte né memo, rinviato dal presidente; il seguito diceva
    // «Il punto torna nella memoria del condominio», e alla prossima «Prepara l'assemblea» non c'era.
    $s = ViaRoma12::nuovo($this);
    sdeSuCarta($s, [['testo' => 'Sostituzione del citofono del portone', 'materia' => 'altro']], '2026-12-15', [1 => 'rinviata']);
    $daTe = collect(ProposteDellOrdineDelGiorno::per($s->c, null))->firstWhere('chiave', 'da_te')['punti'] ?? [];
    expect(collect($daTe)->pluck('testo')->all())->toContain('Sostituzione del citofono del portone');
    // Un punto approvato non torna.
    $t = ViaRoma12::nuovo($this);
    sdeSuCarta($t, [['testo' => 'Regole per l\'uso del cortile', 'materia' => 'altro']], '2026-12-15', [1 => 'approvata']);
    $daTeAncora = collect(ProposteDellOrdineDelGiorno::per($t->c, null))->firstWhere('chiave', 'da_te')['punti'] ?? [];
    expect(collect($daTeAncora)->pluck('testo')->all())->not->toContain('Regole per l\'uso del cortile');
});
