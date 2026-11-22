<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\CollegamentiDelleDelibere;
use App\Services\Assemblee\DiarioDellAssemblea;
use App\Services\Assemblee\OrdineDelGiorno;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, §6.4 del progetto della tappa 2; decisioni 518 e 567): il «Diario» mette insieme la «Storia» dell'assemblea (la preparazione, le
 * sedute, i punti) e le righe di altre schede con il rimando a una sua delibera, dalla più recente, con il filtro; gli invii sono vuoti finché la convocazione
 * non c'è.
 *
 * Che cosa non copre: le righe degli invii (la terza fonte, della convocazione); il cursore su più pagine con righe nello stesso istante.
 */

beforeEach(fn () => ContestoDelRegistro::azzera());

it('la preparazione, il punto aggiunto e il collegamento a una delibera della riunione, dalla più recente', function () {
    $s = ViaRoma12::conFacciata($this)->pianoStraordinario('Facciata 2026 — rate', 'Facciata 2026', ['Facciata' => 1450000], '2026-10-22');
    $a = ViaRoma12::ordinaria($s);
    $this->travel(1)->minutes();
    OrdineDelGiorno::aggiungi($a, ['testo' => 'Sostituzione del portone', 'materia' => 'lavori'], $s->admin);
    $d = ViaRoma12::delibera($s, '2026-10-22', 'Rifacimento della facciata', 'approvata', '41');
    $this->travel(1)->minutes();
    CollegamentiDelleDelibere::collega($s->piano('Facciata 2026 — rate'), 'delibera', $d, null, $s->admin);

    $diario = DiarioDellAssemblea::righe($a, 'tutto');
    expect(array_column($diario['righe'], 'fonte'))->toBe(['modifica', 'modifica'])
        ->and($diario['righe'][0]['frase'])->toStartWith('Punto 1 — Sostituzione del portone')
        // La riga dell'assemblea stessa, senza il suo nome davanti: comincia dal fatto.
        ->and($diario['righe'][1]['frase'])->toStartWith('Preparata: 0 punti')
        ->and($diario['altre'])->toBeFalse();

    $riunione = DiarioDellAssemblea::righe($d->assemblea, 'modifiche');
    expect(collect($riunione['righe'])->pluck('registro.oggetto.tipo')->all())->toContain('piano_rate', 'delibera')
        ->and(DiarioDellAssemblea::righe($a, 'invii')['righe'])->toBe([]);
});
