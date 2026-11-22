<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Enums\Assemblee\TipoCosaDaFare;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Services\RegistroModifiche\ContestoDelRegistro;

require_once __DIR__ . '/SeguitoDiViaRoma12.php';

/**
 * La comunicazione aperta da un'assemblea riporta all'assemblea (decisione 646): «Avviso anche in bacheca» dalla convocazione e «Avvisa i condòmini» dal
 * passo 6 aprono la pagina della comunicazione con «Torna all'assemblea»; «Salva» riporta al passo da cui si è partiti, con il messaggio. Il server decide
 * dove tornare dagli id che il modulo manda, del condominio e per chi gestisce le assemblee: mai un indirizzo preso dalla richiesta (sarebbe un open redirect).
 *
 * Che cosa non copre: il pulsante a video.
 */
beforeEach(function () {
    ContestoDelRegistro::azzera();
    ConvocazioneDiViaRoma12::oggi('2026-12-16 10:00');
    $this->withoutVite();
});
afterEach(fn () => \Illuminate\Support\Carbon::setTestNow());

function ritornoCorpo(array $piu = []): array
{
    return $piu + ['subject' => 'Avviso', 'description' => 'Testo', 'priority' => 'media', 'can_comment' => false, 'is_featured' => false, 'is_published' => true,
        'anagrafiche' => []];
}

function ritornoScenario($t): array
{
    $s = ViaRoma12::nuovo($t);
    foreach (\App\Enums\Permission::cases() as $p) {
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $p->value, 'guard_name' => 'web']);
    }
    $s->admin->givePermissionTo(\App\Enums\Permission::CREATE_COMUNICAZIONI->value);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $a = ViaRoma12::ordinaria($s, [['testo' => 'Orari del riscaldamento', 'testo_pubblico' => 'Orari del riscaldamento 2026/2027', 'materia' => 'riscaldamento']]);

    return [$s, $a];
}

it('aperta dalla convocazione: «Torna all\'assemblea» alla scheda della convocazione; «Salva» riporta lì con il messaggio, bozza o pubblicata', function () {
    [$s, $a] = ritornoScenario($this);
    $url = route('admin.gestionale.assemblee.show', ['condominio' => $s->c->id, 'assemblea' => $a->id, 'scheda' => 'convocazione'], false);

    $pagina = $this->actingAs($s->admin)->get(route('admin.comunicazioni.create', ['assemblea' => $a->id]))->assertOk()->viewData('page')['props'];
    expect($pagina['ritorno'])->toBe(['url' => $url, 'testo' => 'Torna all\'assemblea']);

    $r = $this->actingAs($s->admin)->post(route('admin.comunicazioni.store'), ritornoCorpo(['condomini_ids' => [$s->c->id], 'assemblea_id' => $a->id, 'is_published' => false]));
    $r->assertSessionHasNoErrors()->assertRedirect($url);
    expect(session('message.message'))->toBe('Avviso in bacheca salvato in bozza: lo pubblichi da qui o dalla bacheca.');

    $r = $this->actingAs($s->admin)->post(route('admin.comunicazioni.store'), ritornoCorpo(['condomini_ids' => [$s->c->id], 'assemblea_id' => $a->id]));
    $r->assertRedirect($url);
    expect(session('message.message'))->toBe('Avviso in bacheca pubblicato.');
});

it('aperta da «Avvisa i condòmini» del passo 6: «Torna all\'assemblea» al passo delle delibere; «Salva» riporta lì', function () {
    [$s, $a] = ritornoScenario($this);
    $a2 = sdaSuCartaRitorno($s);
    $calore = SeguitoDiViaRoma12::delibera($a2, 1);
    SeguitoDiViaRoma12::conferma($this, $s, $a2)->assertOk();
    $cosa = CosaDaFareDellaDelibera::query()->where('delibera_id', $calore->id)->where('tipo', TipoCosaDaFare::AVVISA_CONDOMINI->value)->firstOrFail();
    $url = route('admin.gestionale.assemblee.show', ['condominio' => $s->c->id, 'assemblea' => $a2->id, 'scheda' => 'delibere'], false);

    $pagina = $this->actingAs($s->admin)->get(route('admin.comunicazioni.create', ['cosa_da_fare' => $cosa->id]))->assertOk()->viewData('page')['props'];
    expect($pagina['ritorno'])->toBe(['url' => $url, 'testo' => 'Torna all\'assemblea']);

    $this->actingAs($s->admin)->post(route('admin.comunicazioni.store'), ritornoCorpo(['condomini_ids' => [$s->c->id], 'cosa_da_fare_id' => $cosa->id, 'is_published' => false]))
        ->assertRedirect($url);
    expect(session('message.message'))->toBe('Avviso in bacheca salvato in bozza: lo pubblichi da qui o dalla bacheca.');
});

it('una comunicazione normale resta com\'è: niente ritorno, «Salva» porta all\'elenco; un\'assemblea di un altro condominio o un indirizzo libero non portano da nessuna parte', function () {
    [$s, $a] = ritornoScenario($this);
    $altro = ViaRoma12::nuovo($this, 'Corso Italia 3');
    $aAltrove = ViaRoma12::ordinaria($altro, [['testo' => 'Orari', 'materia' => 'riscaldamento']]);

    $pagina = $this->actingAs($s->admin)->get(route('admin.comunicazioni.create'))->assertOk()->viewData('page')['props'];
    expect($pagina['ritorno'])->toBeNull();

    $this->actingAs($s->admin)->post(route('admin.comunicazioni.store'), ritornoCorpo(['condomini_ids' => [$s->c->id]]))->assertRedirect(route('admin.comunicazioni.index'));

    // L'assemblea è di un altro condominio rispetto ai destinatari: si torna all'elenco.
    $this->actingAs($s->admin)->post(route('admin.comunicazioni.store'), ritornoCorpo(['condomini_ids' => [$s->c->id], 'assemblea_id' => $aAltrove->id]))
        ->assertRedirect(route('admin.comunicazioni.index'));
    // Un indirizzo di ritorno nella richiesta non conta.
    $this->actingAs($s->admin)->post(route('admin.comunicazioni.store'), ritornoCorpo(['condomini_ids' => [$s->c->id], 'ritorno' => 'https://esempio.test/', 'torna' => 'https://esempio.test/']))
        ->assertRedirect(route('admin.comunicazioni.index'));
});

/** Una riunione su carta con il punto del riscaldamento approvato (come `sdaSuCarta`). */
function sdaSuCartaRitorno(Beta51Scenario $s): \App\Models\Assemblee\Assemblea
{
    if (! function_exists('sdaSuCarta')) {
        require_once __DIR__ . '/SegniEAvvisoTest.php';
    }

    return sdaSuCarta($s, [['testo' => 'Orari del riscaldamento', 'testo_pubblico' => 'Orari del riscaldamento 2026/2027', 'materia' => 'riscaldamento']]);
}
