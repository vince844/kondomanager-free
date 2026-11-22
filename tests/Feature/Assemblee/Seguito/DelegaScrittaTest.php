<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\Seguito\DelegheDellAmministratore;
use App\Services\Assemblee\StatiDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * La delega con tetto (art. 1131 c. 1 c.c.: i poteri «conferiti»): vale solo con il tetto e l'ambito scritti nel punto, e fra due deleghe dello stesso ambito
 * vale l'ultima, anche se ha un tetto più basso. Il confine esatto della cifra, della scadenza e della revoca.
 *
 * Che cosa non copre: lo stralcio che la leggerà (la sua beta).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

/** Una delega approvata su carta il giorno dato, con il tetto e i dati del punto. @param array<string, mixed> $dati */
function dstDelega(Beta51Scenario $s, string $giorno, string $testo, ?int $tetto, array $dati): \App\Models\Assemblee\Delibera
{
    $a = ViaRoma12::ordinaria($s, [['testo' => $testo, 'materia' => 'delega'] + ($tetto === null ? [] : ['importo' => $tetto])]);
    $punto = $a->punti()->firstOrFail();
    if ($dati !== []) {
        $punto->forceFill(['dati' => ['delega' => $dati]])->saveQuietly();
    }
    StatiDellAssemblea::tenutaFuoriDalProgramma($a, $s->admin, [(int) $punto->id => ['esito' => 'approvata']], $giorno);

    return SeguitoDiViaRoma12::delibera($a, 1);
}

it('il confine esatto della cifra, della scadenza e della revoca; senza il tetto o senza l\'ambito scritti la delega non vale, e la frase dice che cosa manca', function () {
    $s = ViaRoma12::nuovo($this);
    $d = dstDelega($s, '2026-12-15', 'Delega all\'amministratore: accordi con i fornitori fino a € 2.000,00, fino al 31/12/2027', 200000,
        ['ambito' => 'accordi_fornitori', 'fino_al' => '2027-12-31']);
    expect(DelegheDellAmministratore::valida($s->c, 'accordi_fornitori', '2027-12-31', 200000)['delibera']?->id)->toBe($d->id)
        ->and(DelegheDellAmministratore::valida($s->c, 'accordi_fornitori', '2027-03-01', 200001)['frase'])->toBe('oltre il tetto (€ 2.000,00)')
        ->and(DelegheDellAmministratore::valida($s->c, 'accordi_fornitori', '2026-12-15', 100)['delibera']?->id)->toBe($d->id);
    $d->forceFill(['revocata_il' => '2027-05-20'])->saveQuietly();
    expect(DelegheDellAmministratore::valida($s->c, 'accordi_fornitori', '2027-05-20', 100)['delibera'])->toBeNull()
        ->and(DelegheDellAmministratore::valida($s->c, 'accordi_fornitori', '2027-05-19', 100)['delibera']?->id)->toBe($d->id);

    $t = ViaRoma12::nuovo($this, 'Via Verdi 3');
    $senzaTetto = dstDelega($t, '2026-12-16', 'Delega all\'amministratore per la scelta del fornitore del gas', null, ['ambito' => 'accordi_fornitori']);
    $senza = DelegheDellAmministratore::valida($t->c, 'accordi_fornitori', '2027-06-01', 100000000);
    expect($senza['delibera'])->toBeNull()
        ->and($senza['frase'])->toBe('la delega del 16/12/2026 non ha il tetto: scrivilo nel punto')
        ->and(DelegheDellAmministratore::delega($senzaTetto)['in_vigore'])->toBeFalse();

    $u = ViaRoma12::nuovo($this, 'Via Dante 8');
    dstDelega($u, '2026-12-16', 'Delega all\'amministratore', 300000, []);
    $senzaAmbito = DelegheDellAmministratore::valida($u->c, 'accordi_fornitori', '2027-06-01', 100);
    expect($senzaAmbito['delibera'])->toBeNull()->and($senzaAmbito['frase'])->toBe('la delega del 16/12/2026 non ha l\'ambito: scrivilo nel punto');
});

it('fra due deleghe dello stesso ambito vale l\'ultima: oltre il suo tetto più basso la delega non c\'è, con la frase', function () {
    $s = ViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi('2026-03-11 10:00');
    dstDelega($s, '2026-03-10', 'Delega all\'amministratore: accordi con i fornitori fino a € 5.000,00', 500000,
        ['ambito' => 'accordi_fornitori', 'fino_al' => '2028-12-31']);
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $ultima = dstDelega($s, '2026-12-15', 'Delega all\'amministratore: accordi con i fornitori fino a € 2.000,00', 200000,
        ['ambito' => 'accordi_fornitori', 'fino_al' => '2027-12-31']);

    $tremila = DelegheDellAmministratore::valida($s->c, 'accordi_fornitori', '2027-03-01', 300000);

    expect($tremila['delibera'])->toBeNull()
        ->and($tremila['frase'])->toBe('oltre il tetto (€ 2.000,00): la delibera del 15/12/2026 ha un tetto più basso')
        ->and(DelegheDellAmministratore::valida($s->c, 'accordi_fornitori', '2027-03-01', 150000)['delibera']?->id)->toBe($ultima->id);
});
