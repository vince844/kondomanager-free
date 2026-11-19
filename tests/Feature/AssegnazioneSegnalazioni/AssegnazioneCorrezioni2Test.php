<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Segnalazioni: il giro 2 (richieste di Vincenzo dopo la consegna dell'assegnazione)
 *
 * - **B1** La pagina di modifica del pannello riceve chi segue la segnalazione e ciò che serve ad assegnarla. Dal giro 3
 *   (decisione 303) «Assegnata a» è un campo del modulo: le prove sono in `AssegnazioneCorrezioni3Test.php`.
 * - **B2** «Approva» dalla scheda è lo stesso comando dello scudo dell'elenco, con lo stato voluto: così un doppio clic,
 *   o una pagina rimasta aperta, non tolgono un'approvazione appena data. Nella pagina di modifica dal giro 3 c'è la
 *   tendina «Approvazione»; senza quel campo, salvare il modulo continua a non approvare (decisione 127.2).
 * - **B3** Le due frasi sotto «Visibilità segnalazione» sono corte: l'azione è lì accanto, non serve spiegare dove andare.
 * - **B4** L'elenco delle segnalazioni, nel pannello e nel portale, parte dalle più recenti; l'ordine scelto dall'utente
 *   vince.
 */

use App\Events\Segnalazioni\NotifyUserOfApprovedSegnalazione;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Testing\TestResponse;

require_once __DIR__ . '/AssegnazioneScenario.php';

beforeEach(function () {
    asgPrepara($this);
});

/** Il comando dell'approvazione: senza dati è l'interruttore dello scudo, con `is_approved` chiede uno stato. */
function asgApprova($test, User $chi, Segnalazione $s, array $dati = []): TestResponse
{
    return $test->actingAs($chi)->put(route('admin.segnalazioni.toggle-approval', $s), $dati);
}

/** Dà a una segnalazione il suo momento di creazione, in UTC. */
function asgCreataIl(Segnalazione $s, string $quando): Segnalazione
{
    DB::table('segnalazioni')->where('id', $s->id)->update(['created_at' => $quando]);

    return $s;
}

/** Gli id delle righe di un elenco, nell'ordine in cui arrivano alla pagina. */
function asgOrdine($test, User $chi, string $rotta, array $parametri = []): array
{
    $risposta = $test->actingAs($chi)->get(route($rotta, $parametri));
    $risposta->assertSessionHasNoErrors();

    return collect(asgElenco(asgProps($risposta)))->pluck('id')->map(fn ($id) => (int) $id)->all();
}

// --- B1: il riquadro nella pagina di modifica ----------------------------------------------------------------------

test('la pagina di modifica riceve chi segue la segnalazione e ciò che serve al riquadro', function () {
    $s = asgAssegnata($this, $this->elena);

    $props = asgProps($this->actingAs($this->admin)->get(route('admin.segnalazioni.edit', $s)));

    expect($props)->not->toBeNull('La pagina di modifica non si apre')
        ->and(asgRisorsa($props['segnalazione'])['assegnata_a'])->toEqual([
            'id' => $this->elena->id, 'name' => 'Elena Studio', 'attivo' => true,
            'da' => 'Studio Bianchi', 'il' => '10/11/2026', 'alle' => '09:30',
        ])
        ->and($props['assegnazione'] ?? null)->toEqual([
            'puo_assegnare' => true,
            'io' => $this->admin->id,
            'assegnabili' => [
                ['id' => $this->collaboratrice->id, 'name' => 'Anna Collaboratrice'],
                ['id' => $this->elena->id, 'name' => 'Elena Studio'],
                ['id' => $this->admin->id, 'name' => 'Studio Bianchi'],
            ],
        ]);
});

test('nella pagina di modifica chi non può assegnare riceve il riquadro in sola lettura', function () {
    $ruolo = ccmRuolo('redattrice', ['Accesso pannello amministratore', 'Visualizza segnalazioni', 'Modifica segnalazioni']);
    $redattrice = ccmStudioSenzaAnagrafica('Rita Redattrice', $ruolo);
    $s = asgAssegnata($this, $this->elena);

    $props = asgProps($this->actingAs($redattrice)->get(route('admin.segnalazioni.edit', $s)));

    expect($props['assegnazione'] ?? null)->toEqual(['puo_assegnare' => false, 'io' => $redattrice->id, 'assegnabili' => []])
        ->and(asgRisorsa($props['segnalazione'])['assegnata_a']['name'])->toBe('Elena Studio');
});

test('la scheda e la pagina di modifica danno al riquadro le stesse cose', function () {
    $s = asgAssegnata($this, $this->elena);

    $scheda = asgProps($this->actingAs($this->collaboratrice)->get(route('admin.segnalazioni.show', $s)));
    $modifica = asgProps($this->actingAs($this->collaboratrice)->get(route('admin.segnalazioni.edit', $s)));

    expect($modifica['assegnazione'])->toEqual($scheda['assegnazione'])
        ->and(asgRisorsa($modifica['segnalazione'])['assegnata_a'])->toEqual(asgRisorsa($scheda['segnalazione'])['assegnata_a']);
});

// --- B2: «Approva» con lo stato voluto -----------------------------------------------------------------------------

test('«Approva» approva e pubblica, e avvisa chi ha scritto la segnalazione', function () {
    Event::fake([NotifyUserOfApprovedSegnalazione::class]);
    $s = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => false, 'is_published' => false]);

    asgApprova($this, $this->admin, $s, ['is_approved' => true])->assertSessionHasNoErrors();

    expect((bool) asgRiga($s)->is_approved)->toBeTrue()
        ->and((bool) asgRiga($s)->is_published)->toBeTrue();
    Event::assertDispatchedTimes(NotifyUserOfApprovedSegnalazione::class, 1);
});

test('«Approva» su una segnalazione già approvata non toglie l\'approvazione e non avvisa di nuovo', function () {
    Event::fake([NotifyUserOfApprovedSegnalazione::class]);
    $s = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => true, 'is_published' => true]);

    asgApprova($this, $this->admin, $s, ['is_approved' => true])->assertSessionHasNoErrors();
    asgApprova($this, $this->admin, $s, ['is_approved' => true])->assertSessionHasNoErrors();

    expect((bool) asgRiga($s)->is_approved)->toBeTrue()
        ->and((bool) asgRiga($s)->is_published)->toBeTrue();
    Event::assertNotDispatched(NotifyUserOfApprovedSegnalazione::class);
});

test('lo stato voluto vale anche per togliere l\'approvazione, senza cambiare niente se è già così', function () {
    $approvata = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => true, 'is_published' => true]);
    asgApprova($this, $this->admin, $approvata, ['is_approved' => false])->assertSessionHasNoErrors();
    expect((bool) asgRiga($approvata)->is_approved)->toBeFalse();

    // Non approvata, con «Pubblicata» scelta da chi l'ha scritta: chiedere «non approvata» non la tocca.
    $inAttesa = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => false, 'is_published' => true]);
    asgApprova($this, $this->admin, $inAttesa, ['is_approved' => false])->assertSessionHasNoErrors();
    expect((bool) asgRiga($inAttesa)->is_approved)->toBeFalse()
        ->and((bool) asgRiga($inAttesa)->is_published)->toBeTrue();
});

test('senza lo stato voluto il comando resta l\'interruttore dello scudo', function () {
    $s = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => false, 'is_published' => true]);

    asgApprova($this, $this->admin, $s)->assertSessionHasNoErrors();
    expect((bool) asgRiga($s)->is_approved)->toBeTrue();

    asgApprova($this, $this->admin, $s->fresh())->assertSessionHasNoErrors();
    expect((bool) asgRiga($s)->is_approved)->toBeFalse();
});

test('uno stato voluto scritto male è rifiutato, e non cambia niente', function () {
    $s = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => false, 'is_published' => true]);

    asgApprova($this, $this->admin, $s, ['is_approved' => 'forse'])->assertSessionHasErrors('is_approved');

    expect((bool) asgRiga($s)->is_approved)->toBeFalse();
});

test('chi non approva le segnalazioni non approva nemmeno con lo stato voluto', function () {
    $s = ccmSegnalazione($this->aurora, $this->mario, ['is_approved' => false, 'is_published' => true]);

    asgApprova($this, $this->collaboratrice, $s, ['is_approved' => true])->assertForbidden();

    expect((bool) asgRiga($s)->is_approved)->toBeFalse();
});

// --- B3: le frasi --------------------------------------------------------------------------------------------------

test('le due frasi sotto la visibilità sono corte, e «Approva» c\'è nelle quattro lingue', function () {
    $it = fn (string $chiave) => __("segnalazioni.{$chiave}", [], 'it');

    expect($it('publication.needs_approval'))->toBe('Non è ancora approvata: i condòmini non la vedono.')
        ->and($it('publication.waiting_approver'))->toBe('Non è ancora approvata: i condòmini non la vedono. Deve approvarla chi approva le segnalazioni.')
        ->and($it('actions.approve'))->toBe('Approva');

    $scudo = ['it' => 'scudo', 'en' => 'shield', 'es' => 'escudo', 'pt' => 'escudo'];
    foreach (['it', 'en', 'es', 'pt'] as $lingua) {
        expect(Lang::hasForLocale('segnalazioni.actions.approve', $lingua))->toBeTrue("Manca segnalazioni.actions.approve in {$lingua}");
        // L'azione è accanto alla frase: non serve più mandare a cercare lo scudo nell'elenco.
        $frase = __('segnalazioni.publication.needs_approval', [], $lingua);
        expect(str_contains(mb_strtolower($frase), $scudo[$lingua]))->toBeFalse("{$lingua}: la frase parla ancora dello scudo: {$frase}")
            ->and(mb_strlen($frase))->toBeLessThan(80, "{$lingua}: la frase è lunga: {$frase}");
    }
    foreach (['en', 'es', 'pt'] as $lingua) {
        foreach (['publication.needs_approval', 'actions.approve'] as $chiave) {
            expect(__("segnalazioni.{$chiave}", [], $lingua))->not->toBe($it($chiave), "segnalazioni.{$chiave} in {$lingua} è rimasta in italiano");
        }
    }
});

// --- B4: l'ordine dell'elenco --------------------------------------------------------------------------------------

test('l\'elenco del pannello parte dalle segnalazioni più recenti, non dal titolo', function () {
    asgCreataIl($this->s, '2026-10-01 09:00:00');
    $vecchia = asgCreataIl(ccmSegnalazione($this->aurora, $this->mario, ['subject' => 'Aaa la più vecchia']), '2026-11-01 09:00:00');
    $nuova = asgCreataIl(ccmSegnalazione($this->aurora, $this->mario, ['subject' => 'Mmm la più nuova']), '2026-11-10 09:00:00');
    $media = asgCreataIl(ccmSegnalazione($this->aurora, $this->mario, ['subject' => 'Zzz quella di mezzo']), '2026-11-05 09:00:00');

    expect(asgOrdine($this, $this->admin, 'admin.segnalazioni.index'))->toBe([$nuova->id, $media->id, $vecchia->id, $this->s->id]);
});

test('l\'elenco del portale parte dalle segnalazioni più recenti', function () {
    asgCreataIl($this->s, '2026-10-01 09:00:00');
    $vecchia = asgCreataIl(ccmSegnalazione($this->aurora, $this->mario, ['subject' => 'Aaa la più vecchia']), '2026-11-01 09:00:00');
    $nuova = asgCreataIl(ccmSegnalazione($this->aurora, $this->mario, ['subject' => 'Mmm la più nuova']), '2026-11-10 09:00:00');

    expect(asgOrdine($this, $this->mario, 'user.segnalazioni.index'))->toBe([$nuova->id, $vecchia->id, $this->s->id]);
});

test('due segnalazioni dello stesso istante: prima quella inserita dopo', function () {
    $prima = ccmSegnalazione($this->aurora, $this->mario, ['subject' => 'Zzz inserita prima']);
    $dopo = ccmSegnalazione($this->aurora, $this->mario, ['subject' => 'Aaa inserita dopo']);

    expect(asgOrdine($this, $this->admin, 'admin.segnalazioni.index'))->toBe([$dopo->id, $prima->id, $this->s->id]);
});

test('l\'ordine scelto dall\'utente vince, e si può ordinare per data', function () {
    asgCreataIl($this->s, '2026-10-01 09:00:00');
    $a = asgCreataIl(ccmSegnalazione($this->aurora, $this->mario, ['subject' => 'Aaa']), '2026-11-10 09:00:00');
    $z = asgCreataIl(ccmSegnalazione($this->aurora, $this->mario, ['subject' => 'Zzz']), '2026-11-01 09:00:00');

    expect(asgOrdine($this, $this->admin, 'admin.segnalazioni.index', ['sort' => 'subject', 'direction' => 'asc']))->toBe([$a->id, $this->s->id, $z->id])
        ->and(asgOrdine($this, $this->admin, 'admin.segnalazioni.index', ['sort' => 'created_at', 'direction' => 'asc']))->toBe([$this->s->id, $z->id, $a->id])
        ->and(asgOrdine($this, $this->admin, 'admin.segnalazioni.index', ['sort' => 'created_at', 'direction' => 'desc']))->toBe([$a->id, $z->id, $this->s->id]);
});

test('ogni riga del pannello dice quando è stata inviata, anche per esteso nel fuso dell\'installazione', function () {
    asgCreataIl($this->s, '2026-11-01 09:00:00');

    $pannello = collect(asgElenco(asgProps($this->actingAs($this->admin)->get(route('admin.segnalazioni.index')))))->firstWhere('id', $this->s->id);
    $portale = collect(asgElenco(asgProps($this->actingAs($this->mario)->get(route('user.segnalazioni.index')))))->firstWhere('id', $this->s->id);

    expect($pannello['creata_il'] ?? null)->toBe('01/11/2026 10:00')
        ->and($pannello['created_at'])->not->toBe('')
        ->and($portale)->not->toHaveKey('creata_il');
});
