<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Services\Assemblee\Seguito\CoseDaFare;
use App\Services\Assemblee\Seguito\StatoDiEsecuzione;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * Il punto «Stato di esecuzione delle delibere precedenti» (R10, decisione 622 (7)): le eseguite dall'ultima ordinaria si scelgono per il giorno in cui sono
 * state eseguite, non per quello della delibera; e il punto, che va nell'avviso e nel verbale, porta la frase pubblica del punto e le parole del tipo delle
 * cose, mai il testo scritto dall'amministratore (Cass. 29323/2022).
 *
 * Che cosa non copre: la stampa dello stato di esecuzione (la sua beta).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('la facciata del 22/10/2026 eseguita il 12/01/2027 è fra le «eseguite dall\'ultima ordinaria» del punto di maggio', function () {
    $s = ViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi('2026-10-23 10:00');
    $facciata = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Rifacimento della facciata', 'materia' => 'lavori', 'importo' => 9000000]], '2026-10-22', [],
        ['tipo' => 'straordinaria']);
    $df = SeguitoDiViaRoma12::delibera($facciata, 1);
    SeguitoDiViaRoma12::conferma($this, $s, $facciata, [$df->id . ':cosa:crea_piano_straordinario'])->assertOk();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $ordinaria = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Riscaldamento: orari', 'materia' => 'riscaldamento']], '2026-12-15');
    SeguitoDiViaRoma12::conferma($this, $s, $ordinaria, [])->assertOk();
    ConvocazioneDiViaRoma12::oggi('2027-01-12 10:00');
    CoseDaFare::segnaEseguita(CosaDaFareDellaDelibera::query()->where('delibera_id', $df->id)->firstOrFail(), '2027-01-12', 'Piano creato', null, $s->admin);

    ConvocazioneDiViaRoma12::oggi('2027-04-28 10:00');
    $elenco = StatoDiEsecuzione::elenco($s->c, null);

    expect(array_column($elenco['eseguite'], 'delibera_id'))->toContain($df->id)
        ->and(array_column($elenco['aperte'], 'delibera_id'))->not->toContain($df->id);
});

it('il punto dello stato di esecuzione porta la frase pubblica del punto e la parola del tipo delle cose, mai il loro testo né il testo del punto', function () {
    $s = ViaRoma12::nuovo($this);
    ConvocazioneDiViaRoma12::oggi('2026-10-23 10:00');
    $a = SeguitoDiViaRoma12::suCarta($s, [
        ['testo' => 'Decreto ingiuntivo contro Gallo Enzo', 'materia' => 'lavori', 'importo' => 120000],
        ['testo' => 'Riscaldamento: orari di Gallo Enzo', 'testo_pubblico' => 'Orari del riscaldamento', 'materia' => 'riscaldamento'],
    ], '2026-10-22');
    $uno = SeguitoDiViaRoma12::delibera($a, 1);
    SeguitoDiViaRoma12::conferma($this, $s, $a, [], ['altre' => [['delibera_id' => $uno->id, 'testo' => 'Sollecita Gallo Enzo per la rata di dicembre']]])
        ->assertOk();
    ConvocazioneDiViaRoma12::oggi('2027-04-28 10:00');

    $testo = json_encode(StatoDiEsecuzione::elenco($s->c, null), JSON_UNESCAPED_UNICODE);

    expect($testo)->not->toContain('Gallo')
        ->and($testo)->toContain('Orari del riscaldamento')
        ->and($testo)->toContain(TipoCosaDaFare::FUORI_DAL_PROGRAMMA->parolaNelloStato());
});
