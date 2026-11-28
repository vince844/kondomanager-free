<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La casella dell'emissione «Rendi visibili ai condòmini» rende visibili le rate nel portale e non manda niente.
 *
 * Il campo che la porta si chiama ancora `invia_notifiche`, e la frase della convalida diceva «Scegli se avvisare i condòmini.»: una promessa che
 * l'emissione non mantiene. L'avviso vero all'emissione è un lavoro a parte. Qui: emessa una rata con la casella spuntata, nessuna notifica e
 * nessuna email partono; la frase della convalida dice che cosa la casella fa; la pagina del piano la chiama come la chiama la convalida.
 *
 * Lo scenario è quello di `Beta59EstrattoScenari.php`: Mario Rossi, unico condòmino, il «Preventivo 2026» di 12 rate approvato.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

require_once __DIR__.'/Beta59EstrattoScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
});

it('emessa una rata con «Rendi visibili ai condòmini» spuntata, nessuna notifica e nessuna email partono, e la rata è a giornale', function () {
    $s = b59eScenario($this);
    Notification::fake();
    Mail::fake();

    $this->actingAs($s['admin'])->from('/piano')->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => [b59eRata($s, 1)], 'data_emissione' => '2026-01-05', 'invia_notifiche' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    Notification::assertNothingSent();
    Mail::assertNothingSent();
    expect(DB::table('rate')->where('id', b59eRata($s, 1))->value('stato'))->not->toBe('bozza');
});

it('la frase della convalida dice che la casella rende visibili le rate, non che avvisa', function () {
    $s = b59eScenario($this);

    $this->actingAs($s['admin'])->from('/piano')->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => [b59eRata($s, 1)], 'data_emissione' => '2026-01-05', 'invia_notifiche' => 'forse',
    ])->assertRedirect('/piano')->assertSessionHasErrors(['invia_notifiche' => 'Scegli se rendere visibili le rate ai condòmini.']);

    $pagina = file_get_contents(resource_path('js/pages/gestionale/pianiRate/PianiRateShow.vue'));
    expect($pagina)->toContain('Rendi visibili ai condòmini');
});
