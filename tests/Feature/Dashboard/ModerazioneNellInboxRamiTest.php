<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il compito «Da moderare» dell'Action inbox nei rami che le prove della tappa G non percorrevano (Fase 1-bis della 1.12.0-beta.11).
 *
 * - Un commento pubblicato e corretto dal suo autore torna in attesa: è una cosa nuova da moderare, anche se l'ultimo compito
 *   l'aveva chiuso una risposta («Fatto»); il compito dice «dal» giorno in cui è tornato in attesa, non quello in cui era nato.
 * - «Fatto» non fa risuscitare il compito per ciò che aspettava già: approvare uno dei due commenti dopo «Fatto» non lo riapre.
 * - Il compito non appartiene a chi ha scritto il commento: eliminato quel condòmino, il compito resta per ciò che aspetta
 *   ancora; eliminato un altro, il numero si aggiorna.
 * - Una persona tolta dai destinatari: i commenti della sua discussione non contano più né per il compito né per la pagina
 *   «Commenti», come per la riga dell'elenco e per la scheda. Un palazzo tolto lo stesso per i commenti scritti da lì: nella
 *   discussione unica dei palazzi (decisione 458) spariscono con lui (decisione 459), e non contano più da nessuna parte.
 */

use App\Models\CategoriaEvento;
use App\Models\Commento;
use App\Models\Evento;
use Illuminate\Support\Carbon;

require_once __DIR__ . '/../CommentiComunicazioni/CommentiScenario.php';
require_once __DIR__ . '/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    ccmPrepara($this);
    CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
});

function mriAperti(): \Illuminate\Support\Collection
{
    return Evento::where('tipo', 'da_moderare')->where('is_completed', false)->orderBy('id')->get();
}

/** Il corpo della modifica di una comunicazione dal pannello, come lo manda `ComunicazioniEdit.vue`. */
function mriCorpoComunicazione(\App\Models\Comunicazione $c, array $cambi = []): array
{
    return array_merge([
        'subject' => $c->subject, 'description' => $c->description, 'priority' => $c->priority,
        'can_comment' => (bool) $c->can_comment, 'is_featured' => (bool) $c->is_featured, 'is_published' => (bool) $c->is_published,
        'condomini_ids' => $c->condomini()->pluck('condomini.id')->all(), 'avvisa_destinatari' => false,
    ], $cambi);
}

it('un commento pubblicato e corretto dal suo autore torna in attesa e riapre il compito, anche dopo «Fatto»', function () {
    $this->travelTo(Carbon::parse('2026-05-01 10:00', 'Europe/Rome')->utc());
    $c = ccmComunicazione([$this->aurora], $this->admin, ['commenti_moderati' => true]);
    ccmScrivi($this, $this->mario, $c, ccmChiaveUnica(), 'Prima versione')->assertSessionHasNoErrors();
    $commento = Commento::query()->latest('id')->firstOrFail();
    $compito = mriAperti()->sole();

    $this->travelTo(Carbon::parse('2026-05-02 10:00', 'Europe/Rome')->utc());
    icsRispondi($this, $compito, ['tipo' => 'fatto'])->assertSessionHasNoErrors();
    $this->travelTo(Carbon::parse('2026-05-03 10:00', 'Europe/Rome')->utc());
    $this->actingAs($this->admin)->post(route('admin.commenti.approva', $commento))->assertSessionHasNoErrors();
    expect(mriAperti())->toHaveCount(0);

    $this->travelTo(Carbon::parse('2026-05-13 10:00', 'Europe/Rome')->utc());
    $this->actingAs($this->mario)->patch(route('user.commenti.update', $commento), ['corpo' => 'Seconda versione'])->assertSessionHasNoErrors();
    expect($commento->fresh()->stato)->toBe('in_attesa');

    $aperto = mriAperti()->sole();
    expect($aperto->title)->toBe('1 commento in attesa')
        ->and(Carbon::parse($aperto->start_time)->toDateString())->toBe('2026-05-13');
});

it('il giorno del compito di un commento tornato in attesa è quello in cui è tornato, non quello in cui era nato', function () {
    $this->travelTo(Carbon::parse('2026-04-01 10:00', 'Europe/Rome')->utc());
    $c = ccmComunicazione([$this->aurora], $this->admin, ['commenti_moderati' => true]);
    $id = ccmCommentoSalvato($c, $this->mario, 'Scritto ad aprile, approvato', $this->aurora);

    $this->travelTo(Carbon::parse('2026-05-20 10:00', 'Europe/Rome')->utc());
    $this->actingAs($this->mario)->patch(route('user.commenti.update', $id), ['corpo' => 'Corretto a maggio'])->assertSessionHasNoErrors();
    expect(Commento::find($id)->stato)->toBe('in_attesa');

    // Il giorno del compito è scritto come l'agenda scrive le ore: il giorno di Roma.
    expect(Carbon::parse(mriAperti()->sole()->start_time)->toDateString())->toBe('2026-05-20');
});

it('«Fatto» non fa risuscitare il compito per ciò che aspettava già: approvare uno dei due commenti non lo riapre', function () {
    $s = ccmSegnalazione($this->aurora, $this->admin, ['commenti_moderati' => true]);
    $this->actingAs($this->mario)->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Primo'])->assertSessionHasNoErrors();
    $this->actingAs($this->paolo)->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Secondo'])->assertSessionHasNoErrors();
    $compito = mriAperti()->sole();
    expect($compito->title)->toBe('2 commenti in attesa');

    icsRispondi($this, $compito, ['tipo' => 'fatto'])->assertSessionHasNoErrors();
    expect(mriAperti())->toHaveCount(0);

    $this->travelTo(now()->addHour());
    $primo = Commento::where('corpo', 'Primo')->sole();
    $this->actingAs($this->admin)->post(route('admin.commenti.approva', $primo))->assertSessionHasNoErrors();

    expect($primo->fresh()->stato)->toBe('pubblicato')
        ->and(Commento::where('stato', 'in_attesa')->count())->toBe(1)
        ->and(mriAperti())->toHaveCount(0);
});

it('il compito non appartiene a chi ha scritto il commento, ma a chi ha creato l\'elemento', function () {
    $s = ccmSegnalazione($this->aurora, $this->admin, ['commenti_moderati' => true]);
    $this->actingAs($this->paolo)->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Primo, di Paolo'])->assertSessionHasNoErrors();

    expect((int) mriAperti()->sole()->created_by)->toBe($this->admin->id);
});

it('eliminato il condòmino che ha scritto per primo, il commento in attesa di un altro resta nell\'Action inbox', function () {
    $s = ccmSegnalazione($this->aurora, $this->admin, ['commenti_moderati' => true]);
    $this->actingAs($this->paolo)->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Primo, di Paolo'])->assertSessionHasNoErrors();
    $this->actingAs($this->mario)->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Secondo, di Mario'])->assertSessionHasNoErrors();
    expect(mriAperti()->sole()->title)->toBe('2 commenti in attesa');

    $this->paolo->delete();

    expect(Commento::where('stato', 'in_attesa')->count())->toBe(1);
    $aperti = mriAperti();
    expect($aperti)->toHaveCount(1)
        ->and($aperti->first()->title)->toBe('1 commento in attesa')
        ->and((int) $aperti->first()->eventable_id)->toBe($s->id);
});

it('eliminato un altro condòmino, il numero del compito si aggiorna; eliminato l\'ultimo, il compito si chiude', function () {
    $s = ccmSegnalazione($this->aurora, $this->admin, ['commenti_moderati' => true]);
    $c = ccmComunicazione([$this->aurora], $this->admin, ['commenti_moderati' => true]);
    $this->actingAs($this->paolo)->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Primo, di Paolo'])->assertSessionHasNoErrors();
    $this->actingAs($this->mario)->post(route('user.segnalazioni.commenti.store', $s), ['corpo' => 'Secondo, di Mario'])->assertSessionHasNoErrors();
    ccmScrivi($this, $this->mario, $c, ccmChiaveUnica(), 'Sulla comunicazione')->assertSessionHasNoErrors();

    $this->mario->delete();

    expect(mriAperti()->where('eventable_id', $s->id)->sole()->title)->toBe('1 commento in attesa')
        ->and(mriAperti()->where('eventable_type', $c->getMorphClass()))->toHaveCount(0);
});

it('un palazzo tolto dai destinatari: il commento di chi ci stava sparisce, e il compito, la pagina «Commenti», la riga dell\'elenco e la scheda dicono lo stesso numero in attesa', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['commenti_moderati' => true]);
    ccmScrivi($this, $this->lucia, $c, ccmChiaveUnica(), 'Da Borea')->assertSessionHasNoErrors();
    ccmScrivi($this, $this->mario, $c, ccmChiaveUnica(), 'Da Aurora')->assertSessionHasNoErrors();
    expect(mriAperti()->sole()->title)->toBe('2 commenti in attesa');

    // Lo studio toglie Borea dai destinatari, dal modulo della comunicazione: il commento di Lucia, scritto da Borea, sparisce con
    // Borea (decisione 459) e non aspetta più da nessuna parte; Lucia non vede più la comunicazione.
    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), mriCorpoComunicazione($c, ['condomini_ids' => [$this->aurora->id]]))
        ->assertSessionHasNoErrors();

    $compito = mriAperti()->sole();
    $pagina = $this->actingAs($this->admin)
        ->get(route('admin.commenti.index', ['stato' => 'in_attesa', 'tipo' => 'comunicazioni', 'elemento' => $c->id]))
        ->assertOk()->viewData('page')['props'];
    $elenco = json_decode(json_encode($this->actingAs($this->admin)->get(route('admin.comunicazioni.index'))->assertOk()->viewData('page')['props']), true);
    $riga = collect($elenco['comunicazioni'])->firstWhere('id', $c->id);
    $scheda = ccmScheda($this, $this->admin, $c, 'admin');

    expect($compito->title)->toBe('1 commento in attesa')
        ->and((int) $compito->meta['commenti_in_attesa'])->toBe(1)
        ->and($pagina['commenti']['total'])->toBe(1)
        ->and(collect($pagina['commenti']['data'])->pluck('testo')->all())->toBe(['Da Aurora'])
        ->and((int) $riga['contatori']['in_attesa'])->toBe(1)
        ->and(collect($scheda['discussioni'])->sum(fn ($d) => collect($d['commenti'])->where('stato', 'in_attesa')->count()))->toBe(1)
        ->and(ccmScheda($this, $this->lucia, $c))->toBeNull();

    // Approvato l'ultimo che aspettava e si vede (Aurora rimane, il commento di Mario): il compito si chiude.
    $this->actingAs($this->admin)->post(route('admin.commenti.approva', Commento::where('corpo', 'Da Aurora')->sole()))->assertSessionHasNoErrors();
    expect(mriAperti())->toHaveCount(0);
});

it('una persona tolta dai destinatari: il suo commento in attesa non conta più per il compito né per la pagina', function () {
    $c = ccmComunicazione([$this->aurora, $this->borea], $this->admin, ['commenti_moderati' => true], [$this->mario, $this->lucia]);
    ccmScrivi($this, $this->lucia, $c, ccmChiavePersona($this->lucia), 'Lucia allo studio')->assertSessionHasNoErrors();
    expect(mriAperti()->sole()->title)->toBe('1 commento in attesa');

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $c), mriCorpoComunicazione($c, ['anagrafiche' => [$this->mario->anagrafica->id]]))
        ->assertSessionHasNoErrors();

    $pagina = $this->actingAs($this->admin)->get(route('admin.commenti.index', ['stato' => 'in_attesa']))->assertOk()->viewData('page')['props'];
    expect(mriAperti())->toHaveCount(0)
        ->and($pagina['commenti']['total'])->toBe(0);
});
