<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Http\Controllers\Assemblee\DeliberaNelleSchede;
use App\Models\Assemblee\Delibera;
use App\Models\Assemblee\VerbaleDellAssemblea;
use App\Services\Assemblee\DelibereDelCondominio;
use App\Services\Assemblee\RatificaDelloSforo;
use App\Services\Assemblee\Verbale\VerbaliDellAssemblea;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * Il numero del verbale del giorno della delibera (decisione 615 (7)) dove le schede lo copiano: un'assemblea sospesa e proseguita ha due verbali, «42 e 43»,
 * e la delibera del secondo giorno dice 43 nella ratifica della fattura, nel menu di «Scegli la delibera» e nel numero che le pagine scrivono (l'accordo,
 * la dispensa). Prima leggevano il numero dell'assemblea, «42 e 43».
 *
 * Che cosa non copre: la sala con «Sospendi» e la prosecuzione (i verbali qui sono scritti dalla prova, registrati).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-20 10:00');
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('la delibera del secondo giorno dice il verbale n. 43 nelle schede, non «42 e 43»', function () {
    $s = ViaRoma12::nuovo($this);
    $f = ViaRoma12::fatturaInSforo($s);
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Ratifica della spesa urgente', 'materia' => 'ratifica']]);
    $a->forceFill(['stato' => 'chiusa', 'numero_verbale' => '42 e 43', 'data' => '2026-12-14'])->saveQuietly();
    foreach ([['prima', 42], ['seconda', 43]] as [$convocazione, $numero]) {
        $seduta = $a->sedute()->where('convocazione', $convocazione)->firstOrFail();
        VerbaleDellAssemblea::query()->forceCreate(['assemblea_id' => $a->id, 'condominio_id' => $s->c->id, 'registro' => VerbaliDellAssemblea::registroDi($a),
            'seduta_id' => $seduta->id, 'stato' => 'registrato', 'numero' => $numero, 'registrato_il' => now(), 'sottoscritto_il' => $seduta->fissata_il]);
    }
    $d = Delibera::create(['condominio_id' => $s->c->id, 'assemblea_id' => $a->id, 'punto_id' => $a->punti()->value('id'), 'testo' => 'Ratificata',
        'esito' => 'approvata', 'deliberata_il' => '2026-12-15']);

    expect(DeliberaNelleSchede::numeroDelVerbale($d))->toBe('43')
        ->and(DelibereDelCondominio::riga($d)['numero_verbale'])->toBe('43');
    RatificaDelloSforo::ratifica($f, $d, null, $s->admin);
    expect($f->fresh()->dati_extra['ratifica_assembleare']['numero_verbale'])->toBe('43');
});
