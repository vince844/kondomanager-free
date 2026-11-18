<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # «Mostra di nuovo» per un commento nascosto (decisione 456)
 *
 * - Lo fa chi può nascondere, solo su un commento nascosto, dalla scheda (segnalazioni e comunicazioni) e dalla pagina «Commenti».
 * - Nessuna email all'autore; la data del commento non cambia; il condòmino lo rivede e i contatori dell'elenco lo contano.
 */

use App\Models\Commento;
use App\Support\Commenti\RegoleCommenti;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/CommentiPaginaScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->withoutVite();
});

test('chi modera rimette visibile un commento nascosto di una segnalazione e di una comunicazione', function () {
    $sc = cpScenario($this);
    $c = ccmCommentoSalvato($sc['c'], $this->mario, 'Nascosto in comunicazione', $this->aurora, null, 'nascosto');

    foreach ([$sc['id']['s_nas'], $c] as $id) {
        $creato = Commento::find($id)->created_at;
        $risposta = $this->actingAs($this->admin)->post(route('admin.commenti.mostra', $id));

        $risposta->assertRedirect();
        $commento = Commento::find($id);
        expect($commento->stato)->toBe('pubblicato')
            ->and($commento->moderato_da)->toBe($this->admin->id)
            ->and($commento->moderato_at)->not->toBeNull()
            ->and($commento->created_at->equalTo($creato))->toBeTrue()
            ->and($risposta->baseResponse->getSession()->get('message')['message'])->toBe('Commento di nuovo visibile.');
    }
});

test('la collaboratrice, che non modera, prende 403 e il commento resta nascosto', function () {
    $sc = cpScenario($this);

    $this->actingAs($this->collaboratrice)->post(route('admin.commenti.mostra', $sc['id']['s_nas']))->assertForbidden();

    expect(Commento::find($sc['id']['s_nas'])->stato)->toBe('nascosto');
});

test('su un commento pubblicato o in attesa la rotta rifiuta', function () {
    $sc = cpScenario($this);

    foreach (['s_pub' => 'pubblicato', 's_att' => 'in_attesa'] as $chiave => $stato) {
        $this->actingAs($this->admin)->post(route('admin.commenti.mostra', $sc['id'][$chiave]))->assertForbidden();
        expect(Commento::find($sc['id'][$chiave])->stato)->toBe($stato);
    }
});

test('nessuna email parte quando il commento torna visibile', function () {
    $sc = cpScenario($this);
    Notification::fake();

    $this->actingAs($this->admin)->post(route('admin.commenti.mostra', $sc['id']['s_nas']))->assertRedirect();
    $this->actingAs($this->admin)->post(route('admin.commenti.mostra-in-blocco'), ['ids' => [$sc['id']['c_aur']]]);

    Notification::assertNothingSent();
});

test('dopo, il condòmino lo vede nella scheda e i contatori dell\'elenco lo contano', function () {
    $sc = cpScenario($this);
    $c = $sc['c'];
    $id = ccmCommentoSalvato($c, $this->lucia, 'Per Borea: nascosto per errore', $this->borea, null, 'nascosto');
    $contatore = fn () => json_decode(json_encode(
        $this->actingAs($this->lucia)->get(route('user.comunicazioni.index'))->assertOk()->viewData('page')['props']
    ), true)['comunicazioni']['data'];
    $riga = fn () => collect($contatore())->firstWhere('id', $c->id)['contatori']['commenti'];
    $visibili = fn () => collect(ccmDiscussione(ccmScheda($this, $this->lucia, $c), ccmChiaveUnica())['commenti'])->pluck('id')->all();

    $prima = $riga();
    expect($visibili())->not->toContain($id);

    $this->actingAs($this->admin)->post(route('admin.commenti.mostra', $id))->assertRedirect();

    expect($visibili())->toContain($id)
        ->and($riga())->toBe($prima + 1);
});

test('mostra in blocco: i nascosti sì, gli altri no, e il messaggio dice quanti fatti e quanti no', function () {
    $sc = cpScenario($this);
    $nascosto2 = ccmCommentoSalvato($sc['c'], $this->lucia, 'Un altro nascosto', $this->borea, null, 'nascosto');

    $risposta = $this->actingAs($this->admin)->post(route('admin.commenti.mostra-in-blocco'), ['ids' => [$sc['id']['s_nas'], $nascosto2]]);
    $messaggio = $risposta->baseResponse->getSession()->get('message');
    expect(Commento::query()->whereIn('id', [$sc['id']['s_nas'], $nascosto2])->pluck('stato')->unique()->all())->toBe(['pubblicato'])
        ->and($messaggio['type'])->toBe('success')
        ->and($messaggio['message'])->toContain('2');

    $altro = ccmCommentoSalvato($sc['s'], $this->paolo, 'Ancora nascosto', $this->aurora, null, 'nascosto');
    $risposta = $this->actingAs($this->admin)->post(route('admin.commenti.mostra-in-blocco'), ['ids' => [$altro, $sc['id']['s_pub'], $sc['id']['s_att']]]);
    $messaggio = $risposta->baseResponse->getSession()->get('message');
    expect(Commento::find($altro)->stato)->toBe('pubblicato')
        ->and(Commento::find($sc['id']['s_att'])->stato)->toBe('in_attesa')
        ->and($messaggio['type'])->toBe('warning')
        ->and($messaggio['message'])->toContain('1')->toContain('2');

    $risposta = $this->actingAs($this->collaboratrice)->post(route('admin.commenti.mostra-in-blocco'), ['ids' => [$sc['id']['s_pub']]]);
    expect($risposta->baseResponse->getSession()->get('message')['type'])->toBe('error');

    $this->actingAs($this->mario)->post(route('admin.commenti.mostra-in-blocco'), ['ids' => [$sc['id']['s_nas']]])->assertForbidden();
    $this->actingAs($this->admin)->post(route('admin.commenti.mostra-in-blocco'), ['ids' => []])->assertSessionHasErrors('ids');
});

test('azioni.mostra e puo_mostrare sono veri solo per chi può e solo sul nascosto', function () {
    $sc = cpScenario($this);

    foreach ([$this->admin, $this->collaboratrice] as $chi) {
        foreach (cpRighe($this, $chi->fresh(), ['per_page' => 100]) as $riga) {
            $commento = Commento::query()->with('commentable')->findOrFail($riga['id']);
            $atteso = $commento->stato === 'nascosto' && RegoleCommenti::puoNascondere($chi, $commento->replicate()->forceFill(['stato' => 'pubblicato'])->setRelation('commentable', $commento->commentable));
            expect($riga['puo_mostrare'])->toBe(RegoleCommenti::puoMostrareDiNuovo($chi->fresh(), $commento), "{$chi->name}, commento {$commento->id}")
                ->and($riga['puo_mostrare'])->toBe($atteso, "{$chi->name}, commento {$commento->id}");
        }
    }

    expect(collect(cpRighe($this, $this->admin, ['per_page' => 100]))->where('puo_mostrare', true)->pluck('id')->all())->toContain($sc['id']['s_nas'])
        ->and(collect(cpRighe($this, $this->collaboratrice->fresh(), ['per_page' => 100]))->where('puo_mostrare', true)->all())->toBe([]);

    // Nella scheda: chi modera vede i nascosti con `azioni.mostra`, e solo quelli; chi non modera non li vede.
    $nascostoC = ccmCommentoSalvato($sc['c'], $this->lucia, 'Per Borea: nascosto', $this->borea, null, 'nascosto');
    $props = ccmScheda($this, $this->admin, $sc['c'], 'admin');
    $azioni = collect($props['discussioni'])->flatMap(fn ($d) => $d['commenti'])->pluck('azioni.mostra', 'id');
    expect($azioni->filter()->keys()->all())->toBe([$nascostoC])
        ->and($azioni->has($sc['id']['c_bor']))->toBeTrue();

    $props = json_decode(json_encode(
        $this->actingAs($this->admin)->get(route('admin.segnalazioni.show', $sc['s']))->assertOk()->viewData('page')['props']
    ), true);
    expect(collect($props['segnalazione']['commentiNascosti'])->pluck('azioni.mostra', 'id')->all())->toBe([$sc['id']['s_nas'] => true])
        ->and(collect($props['segnalazione']['commenti'])->pluck('azioni.mostra')->filter()->all())->toBe([]);

    $props = ccmScheda($this, $this->lucia, $sc['c']);
    expect(collect($props['discussioni'])->flatMap(fn ($d) => $d['commenti'])->pluck('id')->all())->not->toContain($nascostoC);
});

// Chi coordina, rileggendo la decisione 456: le schede ora caricano i nascosti per chi modera, perché ci compaia «Mostra di nuovo».
// Questa prova tiene l'altra metà: a chi non modera (la collaboratrice nel pannello, il condòmino nel portale) un nascosto non arriva.
test('un commento nascosto arriva nella scheda solo a chi modera, nel pannello e mai nel portale', function () {
    $sc = cpScenario($this);
    $c = $sc['c'];
    $id = ccmCommentoSalvato($c, $this->lucia, 'Nascosto: solo chi modera lo vede', $this->borea, null, 'nascosto');
    $nellaScheda = fn ($chi, string $porta) => collect(ccmDiscussione(ccmScheda($this, $chi, $c, $porta) ?? [], ccmChiaveUnica())['commenti'] ?? [])
        ->pluck('id')->all();

    expect($nellaScheda($this->admin, 'admin'))->toContain($id)
        ->and($nellaScheda($this->collaboratrice->fresh(), 'admin'))->not->toContain($id)
        ->and($nellaScheda($this->lucia, 'user'))->not->toContain($id);

    $s = $sc['id']['s_nas'];
    $segnalazione = \App\Models\Commento::find($s)->commentable;
    $props = fn ($chi) => json_decode(json_encode(
        $this->actingAs($chi)->get(route('admin.segnalazioni.show', $segnalazione))->assertOk()->viewData('page')['props']['segnalazione']
    ), true);
    expect(collect($props($this->admin)['commentiNascosti'] ?? [])->pluck('id')->all())->toContain($s)
        ->and($props($this->collaboratrice->fresh()))->not->toHaveKey('commentiNascosti');
});
