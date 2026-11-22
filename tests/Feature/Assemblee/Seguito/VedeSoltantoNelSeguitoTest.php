<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Permission as Permesso;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Services\Assemblee\Seguito\SeguitoDelleDelibere;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';
require_once __DIR__ . '/../Pagine/RotteDelleAssemblee.php';

/**
 * Marco Galli, collaboratore con «Vede le assemblee» soltanto, apre la dashboard di Via Roma 12 il 16/12/2026: la card «Delibere da eseguire» gli mostra
 * la cosa «Crea il piano straordinario» del portone, ma l'azione è «Apri la delibera», che può leggere; la finestra che crea il piano risponde 403 a lui, e
 * il pulsante non c'è. Chi gestisce le assemblee ha l'azione come prima.
 *
 * Che cosa non copre: la testa del passo 6 a video (`AssembleaDelibere.test.ts`, «chi vede soltanto»).
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('la card «Delibere da eseguire» per chi vede soltanto: «Apri la delibera» al posto dell\'azione che gli risponderebbe 403; per chi gestisce l\'azione', function () {
    $s = ViaRoma12::nuovo($this)->tabella('Proprietà', ['A' => 600, 'B' => 400])->titolare('A', 'ugo')->titolare('B', 'anna');
    $a = SeguitoDiViaRoma12::suCarta($s, [['testo' => 'Portone: € 14.500,00 con Serramenti Marino', 'materia' => 'lavori', 'importo' => 1450000]]);
    $d = SeguitoDiViaRoma12::delibera($a, 1);
    SeguitoDiViaRoma12::conferma($this, $s, $a, null, ['cose' => [$d->id . ':cosa:crea_piano_straordinario' => ['entro_il' => '2027-01-15', 'a_chi' => 'me']]])
        ->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $d->id)->firstOrFail();
    $marco = apUtente(null, [Permesso::VIEW_ASSEMBLEE]);
    $apri = route('admin.gestionale.assemblee.show', ['condominio' => $s->c->id, 'assemblea' => $a->id, 'scheda' => 'delibere', 'delibera' => $d->id], false);

    // La finestra non è per lui.
    $this->actingAs($marco)->getJson(route('admin.gestionale.assemblee.seguito.piano-straordinario.proposta',
        ['condominio' => $s->c->id, 'assemblea' => $a->id, 'cosa' => $cosa->id]))->assertForbidden();

    expect(SeguitoDelleDelibere::perLaDashboard($s->c, $marco)[0]['azione'])->toBe(['testo' => 'Apri la delibera', 'url' => $apri])
        ->and(SeguitoDelleDelibere::perLaDashboard($s->c, $s->admin)[0]['azione']['testo'])->toBe('Crea il piano straordinario');

    // La dashboard passa chi guarda alla card.
    $this->actingAs($s->admin)->get(route('admin.gestionale.index', $s->c))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->loadDeferredProps('assemblee', fn (AssertableInertia $r) => $r
            ->where('delibereDaEseguire.0.azione.testo', 'Crea il piano straordinario')));
    $this->actingAs($marco)->get(route('admin.gestionale.index', $s->c))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->loadDeferredProps('assemblee', fn (AssertableInertia $r) => $r
            ->where('delibereDaEseguire.0.azione', ['testo' => 'Apri la delibera', 'url' => $apri])));
});
