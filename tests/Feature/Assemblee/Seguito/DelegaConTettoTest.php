<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Seguito\DelegheDellAmministratore;
use App\Services\Assemblee\Seguito\SeguitoDelleDelibere;
use App\Services\Assemblee\StatiDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * Catena 5, la delega all'amministratore con il tetto (decisione 622 (3)): la delibera è la delega. La lettura che lo stralcio chiamerà nella sua beta
 * (`DelegheDellAmministratore::valida`): la delibera per € 1.500,00 il 01/03/2027; niente, con la frase, oltre il tetto, dopo la scadenza, revocata. Nel
 * riepilogo la riga «Delega con tetto», senza effetti nei conti.
 *
 * Che cosa non copre: lo stralcio (non c'è ancora); i tre campi del punto in «Prepara l'assemblea» (la pagina).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('la delega con tetto: € 1.500,00 il 01/03/2027 la trova; € 2.500,00 no, oltre il tetto; il 02/01/2028 no, scaduta; revocata no', function () {
    $s = ViaRoma12::nuovo($this);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Delega all\'amministratore: accordi con i fornitori fino a € 2.000,00, fino al 31/12/2027', 'materia' => 'delega',
        'importo' => 200000]]);
    $punto = $a->punti()->firstOrFail();
    $punto->forceFill(['dati' => ['delega' => ['ambito' => 'accordi_fornitori', 'fino_al' => '2027-12-31']]])->saveQuietly();
    StatiDellAssemblea::tenutaFuoriDalProgramma($a, $s->admin, [(int) $punto->id => ['esito' => 'approvata']], '2026-12-15');
    $d = SeguitoDiViaRoma12::delibera($a, 1);

    expect(DelegheDellAmministratore::valida($s->c, 'accordi_fornitori', '2027-03-01', 150000)['delibera']?->id)->toBe($d->id)
        ->and(DelegheDellAmministratore::valida($s->c, 'accordi_fornitori', '2027-03-01', 250000))->toBe(['delibera' => null, 'frase' => 'oltre il tetto (€ 2.000,00)'])
        ->and(DelegheDellAmministratore::valida($s->c, 'accordi_fornitori', '2028-01-02', 150000)['frase'])->toBe('scaduta il 31/12/2027')
        ->and(DelegheDellAmministratore::valida($s->c, 'accordi_fornitori', '2026-12-01', 150000)['frase'])
        ->toBe('nessuna delega dell\'assemblea per accordi con i fornitori');

    $riga = collect(SeguitoDelleDelibere::riepilogo($a->refresh())['punti'][0]['righe'])->firstWhere('tipo', 'delega');
    expect($riga['titolo'])->toBe('Delega con tetto: € 2.000,00 fino al 31/12/2027')
        ->and($riga['frase'])->toBe('Nessun effetto nei conti adesso: la delega è la delibera stessa.')
        ->and(SeguitoDelleDelibere::riepilogo($a)['conto']['deleghe'])->toBe(1);

    $d->forceFill(['revocata_il' => '2027-05-20'])->saveQuietly();
    expect(DelegheDellAmministratore::valida($s->c, 'accordi_fornitori', '2027-06-01', 150000)['frase'])->toBe('revocata il 20/05/2027')
        ->and(DelegheDellAmministratore::valida($s->c, 'accordi_fornitori', '2027-03-01', 150000)['delibera']?->id)->toBe($d->id);
});
