<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\TabellaDiProprieta;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R14 del progetto della tappa 2): la tabella di proprietà generale è una scelta dell'amministratore, mai salvata da sola; il
 * cambio è una riga sul condominio; il controllo avvisa e non blocca: l'unità senza quota, la somma diversa da 1.000, l'unità senza titolare. L'assemblea
 * propone la tabella scelta.
 *
 * Che cosa non copre: le due pagine (l'interruttore della tabella e «Cambia» dell'assemblea).
 */

beforeEach(fn () => ContestoDelRegistro::azzera());

it('nessuna scelta da sola; la scelta è una riga sul condominio; l\'assemblea la propone', function () {
    $s = ViaRoma12::nuovo($this)->tabella('A — Proprietà', ['1' => 600, '2' => 400])->titolare('1', 'ugo')->titolare('2', 'anna');
    expect(TabellaDiProprieta::di($s->c))->toBeNull();
    $prima = ViaRoma12::ultimaRiga();

    TabellaDiProprieta::scegli($s->c, $s->tabelle['A — Proprietà']);

    $righe = ViaRoma12::righe($prima);
    expect($righe)->toHaveCount(1)
        ->and($righe[0]->campi[0])->toMatchArray(['etichetta' => 'Tabella di proprietà generale', 'prima' => null, 'dopo' => 'A — Proprietà'])
        ->and(TabellaDiProprieta::di($s->c->fresh())->id)->toBe($s->tabelle['A — Proprietà']->id)
        ->and(ViaRoma12::ordinaria($s)->tabella_id)->toBe($s->tabelle['A — Proprietà']->id)
        ->and(TabellaDiProprieta::controllo($s->tabelle['A — Proprietà'], $s->c))->toMatchArray(['frase' => '2 unità su 2 · 1.000,00 millesimi', 'avvisi' => []]);
});

it('avvisa senza bloccare: l\'Int. 3 senza quota, la somma 998,40, l\'Int. 2 senza titolare', function () {
    $s = ViaRoma12::nuovo($this)->tabella('A — Proprietà', ['1' => 600, '2' => 398.4])->titolare('1', 'ugo');
    $s->unitaSeManca('3');

    $c = TabellaDiProprieta::controllo($s->tabelle['A — Proprietà'], $s->c);
    expect($c['frase'])->toBe('2 unità su 3 · 998,40 millesimi')
        ->and($c['avvisi'])->toBe(['l\'Int. 3 non ha una quota', 'la somma è 998,40', 'l\'Int. 2 non ha un titolare', 'l\'Int. 3 non ha un titolare']);
});
