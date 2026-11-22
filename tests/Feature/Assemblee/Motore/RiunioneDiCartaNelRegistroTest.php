<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\Verbale\RegistroDeiVerbali;
use App\Services\RegistroModifiche\ContestoDelRegistro;
use Illuminate\Support\Facades\Storage;

require_once __DIR__ . '/ViaRoma12.php';

/**
 * L'assemblea preparata nel programma e «tenuta su carta» (decisione 618) entra nel registro dei verbali come una riunione registrata a mano (decisione 595
 * (5) (a)): la riga «A mano» con il numero, e il numero non torna alla registrazione dopo. Nella finestra il numero è proposto (quello dopo l'ultimo) e
 * controllato: mai uno già usato né più basso.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    Storage::fake(app(\App\Services\Documenti\ArchivioDocumenti::class)->nomeDisco());
});

it('tenuta su carta con il n. 42: la riga «A mano» nel registro, e il prossimo è il 43; un numero già usato si rifiuta', function () {
    $s = ViaRoma12::nuovo($this);
    $reg = app(RegistroDeiVerbali::class);
    $reg->impostaPartenza($s->c, 'condominio', 'libro', 41, null, $s->admin);
    $portone = MemoriaDelCondominio::salva($s->c, ['testo' => 'Il portone'], $s->admin);
    $a = ViaRoma12::ordinaria($s, [MemoriaDelCondominio::comeProposta($portone)]);
    $punto = $a->punti()->firstOrFail();

    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.tenuta-fuori', [$s->c, $a]), ['giorno' => now()->format('Y-m-d'),
        'esiti' => [$punto->id => ['esito' => 'approvata']], 'numero_verbale' => 41])->assertSessionHasErrors(['numero_verbale']);
    $this->actingAs($s->admin)->post(route('admin.gestionale.assemblee.tenuta-fuori', [$s->c, $a]), ['giorno' => now()->format('Y-m-d'),
        'esiti' => [$punto->id => ['esito' => 'approvata']], 'numero_verbale' => 42])->assertSessionHasNoErrors();

    expect($a->refresh()->numero_verbale)->toBe('42')
        ->and(array_map(fn ($r) => $r['tipo'] . ' ' . ($r['numero'] ?? '—'), $reg->righe($s->c, 'condominio')))->toContain('a_mano 42')
        ->and($reg->prossimoNumero($s->c, 'condominio'))->toBe(43)
        ->and($reg->numeroUsato($s->c, 'condominio', 42))->toBe('Il n. 42 è già di una riunione registrata a mano.');
});

it('la riunione di carta nuova con un numero già usato nel registro si rifiuta; con un numero libero, anche di prima del registro, entra', function () {
    $s = ViaRoma12::nuovo($this);
    $reg = app(RegistroDeiVerbali::class);
    $reg->impostaPartenza($s->c, 'condominio', 'libro', 41, null, $s->admin);
    \App\Services\Assemblee\DeliberaAMano::registra($s->c, ['giorno' => '2026-10-01', 'testo' => 'Il portone', 'esito' => 'approvata', 'numero_verbale' => '42'], null,
        $s->admin);
    expect(fn () => \App\Services\Assemblee\DeliberaAMano::registra($s->c, ['giorno' => '2026-10-20', 'testo' => 'La facciata', 'esito' => 'approvata',
        'numero_verbale' => '42'], null, $s->admin))->not->toThrow(\Throwable::class);
    expect(fn () => \App\Services\Assemblee\DeliberaAMano::registra($s->c, ['giorno' => '2026-11-03', 'testo' => 'Il tetto', 'esito' => 'approvata',
        'numero_verbale' => '43', 'tipo' => 'straordinaria'], null, $s->admin))->not->toThrow(\Throwable::class);
    // Il n. 42 è della riunione del 01/10: un'altra riunione nuova con lo stesso numero no.
    expect(fn () => \App\Services\Assemblee\DeliberaAMano::registra($s->c, ['giorno' => '2026-11-05', 'testo' => 'Il cancello', 'esito' => 'approvata',
        'numero_verbale' => '42', 'tipo' => 'straordinaria'], null, $s->admin))->toThrow(\Illuminate\Validation\ValidationException::class);
    // Un numero più basso, di una riunione di prima del registro: si controlla solo che non sia usato.
    expect(fn () => \App\Services\Assemblee\DeliberaAMano::registra($s->c, ['giorno' => '2025-03-01', 'testo' => 'Le scale', 'esito' => 'approvata',
        'numero_verbale' => '12'], null, $s->admin))->not->toThrow(\Throwable::class);
});
