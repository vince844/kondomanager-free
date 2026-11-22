<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Assemblee\CollegamentoAllaDelibera;
use App\Services\Assemblee\DelibereDelCondominio;
use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\StatiDellAssemblea;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679, R3 del progetto della tappa 2, decisione 500 (2)): niente si collega senza il clic. Un memo con origine la fattura n. 45, l'assemblea
 * che lo porta nell'ordine del giorno, la delibera nata dal suo punto e il menu di «Scegli la delibera» aperto lasciano la fattura senza collegamento e in
 * sforo, finché la ratifica non è confermata.
 *
 * Che cosa non copre: la raccolta delle date (le sole righe spuntate: `RaccoltaDelleDateTest`).
 */

it('memo, ordine del giorno, delibera sul punto e menu aperto: la fattura resta senza collegamento', function () {
    $s = ViaRoma12::nuovo($this);
    $fattura = ViaRoma12::fatturaInSforo($s);
    $memo = MemoriaDelCondominio::salva($s->c, ['testo' => 'Ratifica della spesa urgente', 'origine_tipo' => 'fattura_passiva', 'origine_id' => $fattura->id], $s->admin);
    $a = ViaRoma12::ordinaria($s);
    $punto = MemoriaDelCondominio::nellOrdine($memo, $a, $s->admin);
    StatiDellAssemblea::tenutaFuoriDalProgramma($a->fresh(), $s->admin, [$punto->id => ['esito' => 'approvata']], '2026-11-18');
    DelibereDelCondominio::scegli($s->c, 'fattura_passiva', $fattura->id, 'ratifica', null);

    expect(CollegamentoAllaDelibera::query()->count())->toBe(0)
        ->and($fattura->fresh()->stato_approvazione)->toBe('sforo_motivato')
        ->and($memo->fresh()->stato->value)->toBe('deliberato');
});
