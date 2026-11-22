<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Documenti\ArchivioDocumenti;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

require_once __DIR__ . '/ConvocazioneDiViaRoma12.php';

/**
 * 1.12.0-beta.13 (Coda 679): la pagina dell'assemblea porta la convocazione. Le card della testata non ci sono più: la riga del passo «Convocazione»
 * dice quanti da spedire ed entro quando; i passi «Convocazione» e «Documenti» arrivano quando si aprono, con le rotte dei gesti.
 *
 * Che cosa non copre: la pagina che monta i passi (la prova il suo vitest, `AssembleaConLaConvocazione.test.ts`); le regole dei passi
 * (`PassiDellAssembleaTest`).
 */

beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(ArchivioDocumenti::class)->nomeDisco());
    ConvocazioneDiViaRoma12::oggi();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

it('la riga del passo «Convocazione» al posto delle card; la convocazione e il fascicolo arrivano quando il passo si apre', function () {
    $s = ConvocazioneDiViaRoma12::nuovo($this);
    $a = ConvocazioneDiViaRoma12::ordinaria($s);

    $this->actingAs($s->admin)->get(route('admin.gestionale.assemblee.show', [$s->c, $a]))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('gestionale/assemblee/Assemblea')->missing('testata')
            ->where('passi.2.chiave', 'convocazione')->where('passi.2.riga', '9 da spedire · entro il 03/12 · 1 senza un mezzo valido')
            ->missing('convocazione')->missing('fascicolo')
            ->reloadOnly('convocazione', fn (AssertableInertia $r) => $r->where('convocazione.pec.quante', 3)
                ->where('convocazione.rotte.ricevuta', fn ($url) => str_contains((string) $url, '/invii/__INVIO__/ricevuta')))
            ->reloadOnly('fascicolo', fn (AssertableInertia $r) => $r->where('fascicolo.rotte.componi', route('admin.gestionale.assemblee.fascicolo.componi', [$s->c, $a]))));
});
