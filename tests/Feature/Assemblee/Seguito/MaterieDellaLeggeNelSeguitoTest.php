<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\DelibereDelCondominio;
use App\Services\Assemblee\Seguito\Effetti\SuiLavori;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * Le materie con la maggioranza della legge (decisione 640 (1)) nel seguito delle delibere: ognuna fa come la materia di prima più vicina.
 *
 * - «Crea il piano straordinario» e il fondo speciale: le innovazioni agevolate come le innovazioni, la videosorveglianza e i progetti del territorio come i
 *   lavori; le altre no (la rettifica delle tabelle, il sito, la tutela della destinazione d'uso, le modalità degli impianti dei singoli, che paga il singolo);
 * - «Avvisa i condòmini» (decisione 622 (6)): acceso di serie per le cose di tutti (le agevolate, la videosorveglianza, il sito internet, i progetti);
 *   spento per la rettifica delle tabelle, come le tabelle, e per gli impianti dei singoli; assente per la tutela della destinazione d'uso, come le liti,
 *   perché dice chi usa male le parti comuni;
 * - «Scegli la delibera» propone la videosorveglianza e i progetti del territorio come i lavori, alla fattura, al piano e alla gestione.
 *
 * Che cosa non copre: le maggioranze e gli avvisi del punto (`MaterieDellaLeggeTest`).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('il piano straordinario e «Avvisa i condòmini» delle materie nuove, come la materia di prima più vicina', function () {
    $s = ViaRoma12::nuovo($this);
    $a = SeguitoDiViaRoma12::suCarta($s, [
        ['testo' => 'Ascensore per le barriere architettoniche', 'materia' => 'innovazioni_agevolate'],
        ['testo' => 'Telecamere nell\'androne', 'materia' => 'videosorveglianza'],
        ['testo' => 'Il cortile usato come deposito: diffida a chi lo usa', 'materia' => 'tutela_destinazione_uso'],
        ['testo' => 'Il sito internet del condominio', 'materia' => 'sito_internet'],
        ['testo' => 'Rettifica della tabella per l\'errore sull\'interno 3', 'materia' => 'rettifica_tabelle'],
        ['testo' => 'Messa in sicurezza del versante dietro il palazzo', 'materia' => 'progetti_territorio'],
        ['testo' => 'Pannelli solari dell\'interno 5 sul lastrico: le cautele', 'materia' => 'impianti_singoli'],
    ]);
    $righe = SeguitoDiViaRoma12::righe($a);
    $chiave = fn (int $n, string $cosa) => SeguitoDiViaRoma12::delibera($a, $n)->id . ':cosa:' . $cosa;
    $piano = array_map(fn ($n) => array_key_exists($chiave($n, 'crea_piano_straordinario'), $righe), range(1, 7));
    $avviso = array_map(fn ($n) => $righe[$chiave($n, 'avvisa_condomini')]['spuntata'] ?? 'assente', range(1, 7));

    expect($piano)->toBe([true, true, false, false, false, true, false])
        ->and($avviso)->toBe([true, true, 'assente', true, false, true, false]);
});

it('il fondo speciale legge le stesse materie del piano straordinario', function () {
    expect(array_map(fn ($m) => $m->value, SuiLavori::MATERIE))
        ->toBe(['lavori', 'innovazioni', 'innovazioni_agevolate', 'videosorveglianza', 'progetti_territorio']);
});

it('«Scegli la delibera» propone la videosorveglianza come i lavori, alla fattura del fornitore', function () {
    $s = ViaRoma12::nuovo($this);
    $f = ViaRoma12::fatturaInSforo($s);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Telecamere nell\'androne', 'materia' => 'videosorveglianza']]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);

    $scelta = DelibereDelCondominio::scegli($s->c, 'fattura_passiva', $f->id, 'delibera', null);
    expect(collect($scelta['proposte'])->pluck('id')->all())->toBe([$d->id])
        ->and($scelta['proposte'][0]['livello'])->toBe(3);
});

it('«Scegli la delibera» propone anche le innovazioni e le innovazioni agevolate come i lavori, alla fattura dell\'impresa', function () {
    // L'ascensore per le barriere, deliberato il 15/12 come «Innovazioni agevolate»: la fattura dell'impresa trova la delibera per materia.
    $s = ViaRoma12::nuovo($this);
    $f = ViaRoma12::fatturaInSforo($s);
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Ascensore per le barriere architettoniche', 'materia' => 'innovazioni_agevolate'],
        ['testo' => 'Pensilina sull\'ingresso', 'materia' => 'innovazioni']]);
    $agevolata = SeguitoDiViaRoma12::delibera($a, 1);
    $innovazione = SeguitoDiViaRoma12::delibera($a, 2);

    $scelta = DelibereDelCondominio::scegli($s->c, 'fattura_passiva', $f->id, 'delibera', null);
    expect(collect($scelta['proposte'])->pluck('id')->sort()->values()->all())->toBe(collect([$agevolata->id, $innovazione->id])->sort()->values()->all())
        ->and(collect($scelta['proposte'])->pluck('livello')->unique()->values()->all())->toBe([3]);
});
