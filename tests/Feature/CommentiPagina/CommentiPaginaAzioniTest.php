<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # La pagina «Commenti» del pannello: le azioni sulla riga e in blocco (Coda 657, decisione 442)
 *
 * - Le azioni in blocco applicano a ogni commento la regola della scheda e dicono quanti ne hanno fatti e quanti no.
 * - Ogni azione della pagina manda le stesse notifiche della stessa azione dalla scheda: una per destinatario, non «almeno
 *   una». Le azioni in blocco ne mandano una per commento, come se fossero fatte una alla volta.
 */

use App\Models\Commento;
use App\Models\Comunicazione;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/CommentiPaginaScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->withoutVite();
});

/** Il messaggio della risposta, dalla sessione. */
function cpMessaggio($risposta): array
{
    return $risposta->baseResponse->getSession()->get('message');
}

/** Una comunicazione con due commenti in attesa nella discussione di Aurora, da Mario e da Paolo (che stanno in Aurora). */
function cpDueInAttesa($test, string $oggetto): array
{
    $c = ccmComunicazione([$test->aurora, $test->borea], $test->admin, ['subject' => $oggetto, 'commenti_moderati' => true]);
    $uno = ccmCommentoSalvato($c, $test->mario, "Primo di {$oggetto}", $test->aurora, null, 'in_attesa');
    $due = ccmCommentoSalvato($c, $test->paolo, "Secondo di {$oggetto}", $test->aurora, null, 'in_attesa');
    // Un commento già pubblicato di un altro, perché gli avvisi di «nuovo commento» vanno a chi partecipa.
    ccmCommentoSalvato($c, $test->lucia, "Di Lucia in {$oggetto}", $test->borea);
    ccmCommentoSalvato($c, $test->admin, "Dello studio in {$oggetto}", $test->aurora);

    return [$c, $uno, $due];
}

test('approva in blocco: tutti quelli che la regola concede, e il messaggio lo dice', function () {
    $sc = cpScenario($this);
    $ids = [$sc['id']['s_att'], $sc['id']['c_aur_att'], $sc['id']['p_lucia']];

    $risposta = $this->actingAs($this->admin)->post(route('admin.commenti.approva-in-blocco'), ['ids' => $ids]);

    $risposta->assertRedirect();
    expect(Commento::query()->whereIn('id', $ids)->pluck('stato')->unique()->all())->toBe(['pubblicato'])
        ->and(cpMessaggio($risposta)['type'])->toBe('success')
        ->and(cpMessaggio($risposta)['message'])->toContain('3');
});

test('approva in blocco con un commento che la regola rifiuta: gli altri sì, e il messaggio dice quanti fatti e quanti no', function () {
    $sc = cpScenario($this);
    // Un commento già pubblicato non si approva (`puoApprovare` vuole «in attesa»).
    $ids = [$sc['id']['s_att'], $sc['id']['s_pub'], $sc['id']['c_aur_att']];

    $risposta = $this->actingAs($this->admin)->post(route('admin.commenti.approva-in-blocco'), ['ids' => $ids]);

    expect(Commento::find($sc['id']['s_att'])->stato)->toBe('pubblicato')
        ->and(Commento::find($sc['id']['c_aur_att'])->stato)->toBe('pubblicato')
        ->and(Commento::find($sc['id']['s_pub'])->stato)->toBe('pubblicato')
        ->and(cpMessaggio($risposta)['type'])->toBe('warning')
        ->and(cpMessaggio($risposta)['message'])->toContain('2')->toContain('1');
});

test('chi non può approvare non approva niente, e il messaggio è un errore', function () {
    $sc = cpScenario($this);
    $ids = [$sc['id']['s_att'], $sc['id']['c_aur_att']];

    $risposta = $this->actingAs($this->collaboratrice)->post(route('admin.commenti.approva-in-blocco'), ['ids' => $ids]);

    expect(Commento::query()->whereIn('id', $ids)->pluck('stato')->unique()->all())->toBe(['in_attesa'])
        ->and(cpMessaggio($risposta)['type'])->toBe('error');
});

test('nascondi in blocco: i pubblicati sì, un nascosto o uno in attesa no', function () {
    $sc = cpScenario($this);
    $ids = [$sc['id']['s_pub'], $sc['id']['c_bor'], $sc['id']['s_nas'], $sc['id']['c_aur_att']];

    $risposta = $this->actingAs($this->admin)->post(route('admin.commenti.nascondi-in-blocco'), ['ids' => $ids]);

    expect(Commento::find($sc['id']['s_pub'])->stato)->toBe('nascosto')
        ->and(Commento::find($sc['id']['c_bor'])->stato)->toBe('nascosto')
        ->and(Commento::find($sc['id']['c_aur_att'])->stato)->toBe('in_attesa')
        ->and(Commento::find($sc['id']['s_pub'])->moderato_da)->toBe($this->admin->id)
        ->and(cpMessaggio($risposta)['type'])->toBe('warning')
        ->and(cpMessaggio($risposta)['message'])->toContain('2')->toContain('2');
});

test('elimina in blocco: quelli che la regola concede; la collaboratrice solo i suoi', function () {
    $sc = cpScenario($this);
    $suo = ccmCommentoSalvato($sc['s'], $this->collaboratrice, 'Un commento di Anna', $this->aurora);
    $ids = [$suo, $sc['id']['s_pub'], $sc['id']['c_bor']];

    $risposta = $this->actingAs($this->collaboratrice)->post(route('admin.commenti.elimina-in-blocco'), ['ids' => $ids]);

    expect(Commento::find($suo))->toBeNull()
        ->and(Commento::find($sc['id']['s_pub']))->not->toBeNull()
        ->and(Commento::find($sc['id']['c_bor']))->not->toBeNull()
        ->and(cpMessaggio($risposta)['type'])->toBe('warning');

    $risposta = $this->actingAs($this->admin)->post(route('admin.commenti.elimina-in-blocco'), ['ids' => [$sc['id']['s_pub'], $sc['id']['c_bor']]]);

    expect(Commento::find($sc['id']['s_pub']))->toBeNull()
        ->and(Commento::find($sc['id']['c_bor']))->toBeNull()
        ->and(cpMessaggio($risposta)['type'])->toBe('success');
});

test('le azioni in blocco chiedono dei numeri e aprono solo a chi apre la pagina', function () {
    $sc = cpScenario($this);

    foreach (['approva-in-blocco', 'nascondi-in-blocco', 'elimina-in-blocco'] as $azione) {
        $this->actingAs($this->mario)->post(route("admin.commenti.{$azione}"), ['ids' => [$sc['id']['s_att']]])->assertForbidden();
        $this->actingAs($this->admin)->post(route("admin.commenti.{$azione}"), ['ids' => []])->assertSessionHasErrors('ids');
        $this->actingAs($this->admin)->post(route("admin.commenti.{$azione}"), ['ids' => ['abc']])->assertSessionHasErrors('ids.0');
    }
    expect(Commento::find($sc['id']['s_att'])->stato)->toBe('in_attesa');
});

test('un id che non c\'è, o eliminato, conta fra i rifiutati e non ferma gli altri', function () {
    $sc = cpScenario($this);
    $ids = [$sc['id']['s_att'], 987654, $sc['id']['s_eli']];

    $risposta = $this->actingAs($this->admin)->post(route('admin.commenti.approva-in-blocco'), ['ids' => $ids]);

    expect(Commento::find($sc['id']['s_att'])->stato)->toBe('pubblicato')
        ->and(cpMessaggio($risposta)['message'])->toContain('1')->toContain('2');
});

// -- Le notifiche: una per destinatario, uguali a quelle della stessa azione dalla scheda ---------------------------------

test('approvare in blocco manda le stesse notifiche che approvare uno alla volta dalla scheda, una per destinatario', function () {
    [$cSingoli, $a1, $a2] = cpDueInAttesa($this, 'Avviso A');
    [$cBlocco, $b1, $b2] = cpDueInAttesa($this, 'Avviso B');

    Notification::fake();
    $this->actingAs($this->admin)->post(route('admin.commenti.approva', $a1))->assertRedirect();
    $this->actingAs($this->admin)->post(route('admin.commenti.approva', $a2))->assertRedirect();
    $dallaScheda = cpNotifiche();

    Notification::fake();
    $this->actingAs($this->admin)->post(route('admin.commenti.approva-in-blocco'), ['ids' => [$b1, $b2]])->assertRedirect();
    $inBlocco = cpNotifiche();

    expect($dallaScheda)->not->toBe([], 'La scheda non ha mandato niente: il confronto non prova nulla')
        ->and($inBlocco)->toBe($dallaScheda);
    // Una, non «almeno una»: l'autore di ogni commento riceve un avviso di approvazione solo per il suo.
    expect($inBlocco[$this->mario->id . '|CommentoApprovatoNotification'])->toBe(1)
        ->and($inBlocco[$this->paolo->id . '|CommentoApprovatoNotification'])->toBe(1);
});

test('nascondere in blocco manda le stesse notifiche che nascondere uno alla volta dalla scheda, una per destinatario', function () {
    $prepara = function (string $oggetto) {
        $c = ccmComunicazione([$this->aurora], $this->admin, ['subject' => $oggetto]);

        return [
            ccmCommentoSalvato($c, $this->mario, "Di Mario in {$oggetto}", $this->aurora),
            ccmCommentoSalvato($c, $this->paolo, "Di Paolo in {$oggetto}", $this->aurora),
        ];
    };
    [$a1, $a2] = $prepara('Avviso A');
    [$b1, $b2] = $prepara('Avviso B');

    Notification::fake();
    $this->actingAs($this->admin)->post(route('admin.commenti.modera', $a1))->assertRedirect();
    $this->actingAs($this->admin)->post(route('admin.commenti.modera', $a2))->assertRedirect();
    $dallaScheda = cpNotifiche();

    Notification::fake();
    $this->actingAs($this->admin)->post(route('admin.commenti.nascondi-in-blocco'), ['ids' => [$b1, $b2]])->assertRedirect();
    $inBlocco = cpNotifiche();

    expect($dallaScheda)->not->toBe([])
        ->and($inBlocco)->toBe($dallaScheda)
        ->and($inBlocco[$this->mario->id . '|CommentoEliminatoNotification'])->toBe(1)
        ->and($inBlocco[$this->paolo->id . '|CommentoEliminatoNotification'])->toBe(1);
});

test('eliminare in blocco non manda notifiche, come eliminare dalla scheda', function () {
    $sc = cpScenario($this);

    Notification::fake();
    $this->actingAs($this->admin)->delete(route('admin.commenti.destroy', $sc['id']['c_bor']))->assertRedirect();
    $dallaScheda = cpNotifiche();

    Notification::fake();
    $this->actingAs($this->admin)->post(route('admin.commenti.elimina-in-blocco'), ['ids' => [$sc['id']['c_aur'], $sc['id']['s_pub']]])->assertRedirect();

    expect(cpNotifiche())->toBe($dallaScheda);
});

test('«Rispondi» dalla pagina manda le stesse notifiche che scrivere nella discussione dalla scheda', function () {
    [$cScheda] = cpDueInAttesa($this, 'Avviso A');
    [$cPagina] = cpDueInAttesa($this, 'Avviso B');

    Notification::fake();
    $this->actingAs($this->admin)->post(route('admin.comunicazioni.commenti.store', $cScheda), ['corpo' => 'Risposta dello studio', 'discussione' => ccmChiaveUnica()])->assertRedirect();
    $dallaScheda = cpNotifiche();

    $riga = collect(cpRighe($this, $this->admin, ['per_page' => 100]))->first(
        fn ($r) => $r['tipo'] === 'comunicazioni' && $r['elemento'] === 'Avviso B' && $r['condominio'] === 'Condominio Aurora'
    );
    Notification::fake();
    $this->actingAs($this->admin)->post($riga['rispondi']['url'], $riga['rispondi']['dati'] + ['corpo' => 'Risposta dello studio'])->assertRedirect();

    expect($dallaScheda)->not->toBe([])
        ->and(cpNotifiche())->toBe($dallaScheda);
});

test('«Modifica» dalla pagina, con la rotta della riga, fa tornare in attesa e avvisa come dalla scheda', function () {
    $prepara = function (string $oggetto) {
        $c = ccmComunicazione([$this->aurora], $this->admin, ['subject' => $oggetto, 'commenti_moderati' => true]);

        return ccmCommentoSalvato($c, $this->mario, "Di Mario in {$oggetto}", $this->aurora);
    };
    $a = $prepara('Avviso A');
    $b = $prepara('Avviso B');

    Notification::fake();
    $this->actingAs($this->mario)->patch(route('user.commenti.update', $a), ['corpo' => 'Corretto'])->assertRedirect();
    $dallaScheda = cpNotifiche();

    // Dalla pagina un commento di Mario lo modifica lo studio: torna in attesa solo se per lo studio lo stato alla nascita lo è,
    // quindi qui il confronto è fra la stessa rotta chiamata dalla riga e la rotta della scheda dello studio.
    $riga = collect(cpRighe($this, $this->admin, ['per_page' => 100]))->firstWhere('id', $b);
    expect($riga['puo_modificare'])->toBeTrue();

    Notification::fake();
    $this->actingAs($this->admin)->patch(route('admin.commenti.update', $b), ['corpo' => 'Corretto dallo studio'])->assertRedirect();
    $dallaPagina = cpNotifiche();

    Notification::fake();
    $this->actingAs($this->admin)->patch(route('admin.commenti.update', $a), ['corpo' => 'Corretto dallo studio'])->assertRedirect();

    expect(cpNotifiche())->toBe($dallaPagina)
        ->and($dallaScheda)->not->toBe([]);
});
