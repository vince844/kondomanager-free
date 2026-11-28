<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Pubblicare una bozza approvata chiede il permesso di pubblicare (Coda 695)
 *
 * Il passaggio da bozza a pubblicata di una comunicazione approvata, in modifica, chiede «Pubblica comunicazioni», oppure «Pubblica proprie comunicazioni»
 * sulla propria (`PubblicazioneProgrammata::puoPubblicare()`). Senza, la comunicazione resta in bozza, un messaggio dice chi può pubblicarla e non parte
 * nessun avviso. La pagina di modifica lo riceve dal server (`pubblicazione`). Chi la programma segue la regola sua («Pubblica comunicazioni»).
 *
 * La catena: bozza approvata, pubblicazione, avviso ai destinatari, bacheca del portale.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Comunicazione;
use App\Notifications\Comunicazioni\NewComunicazioneNotification;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/../ComunicazioniProgrammate/ProgrammateScenario.php';

beforeEach(function () {
    cprPrepara($this);
});

/** «Redazione»: il pannello, crea e modifica le comunicazioni, senza pubblicarle. */
function pubSara($test, array $permessi = []): \App\Models\User
{
    $ruolo = ccmRuolo('redazione', ['Accesso pannello amministratore', 'Visualizza comunicazioni', 'Crea comunicazioni', 'Modifica comunicazioni']);
    $sara = ccmStudioSenzaAnagrafica('Sara Redazione', $ruolo);
    if ($permessi !== []) {
        $sara->givePermissionTo($permessi);
    }

    return $sara->fresh();
}

/** Una bozza approvata di Aurora, scritta da `$autore`. */
function pubBozza($test, \App\Models\User $autore): Comunicazione
{
    return ccmComunicazione([$test->aurora], $autore, ['is_published' => false, 'is_approved' => true, 'subject' => 'Lavori al portone']);
}

/** Il modulo della pagina di modifica, con la visibilità scelta. */
function pubModulo($test, Comunicazione $c, bool $pubblicata, array $campi = []): array
{
    return array_merge([
        'subject' => $c->subject, 'description' => $c->description, 'priority' => 'media', 'can_comment' => true, 'is_featured' => false,
        'is_published' => $pubblicata, 'condomini_ids' => [$test->aurora->id], 'data_programmata' => null,
    ], $campi);
}

it('chi non può pubblicare non toglie la bozza: la comunicazione resta in bozza, un messaggio dice chi può e non parte nessun avviso', function () {
    $sara = pubSara($this);
    $c = pubBozza($this, $sara);

    cprModifica($this, $sara, $c, pubModulo($this, $c, true))->assertSessionHasErrors('is_published');

    expect((bool) cprRiga($c)->is_published)->toBeFalse()->and(cprRiga($c)->pubblicata_il)->toBeNull();
    expect(session('errors')->first('is_published'))->toContain('Pubblica comunicazioni');
    Notification::assertNothingSent();
    // La catena: non compare nella bacheca del condòmino.
    $elenco = cprProps($this->actingAs($this->mario)->get(route('user.comunicazioni.index')));
    expect(json_encode($elenco))->not->toContain('Lavori al portone');
});

it('con «Pubblica comunicazioni» la bozza si pubblica, il destinatario riceve l\'avviso e la trova in bacheca', function () {
    $sara = pubSara($this, [Permission::PUBLISH_COMUNICAZIONI->value]);
    $c = pubBozza($this, $this->admin);

    cprModifica($this, $sara, $c, pubModulo($this, $c, true))->assertSessionHasNoErrors();

    expect((bool) cprRiga($c)->is_published)->toBeTrue()->and(cprAvvisati(NewComunicazioneNotification::class))->toContain($this->mario->id);
    $elenco = cprProps($this->actingAs($this->mario)->get(route('user.comunicazioni.index')));
    expect(json_encode($elenco))->toContain('Lavori al portone');
});

it('con «Pubblica proprie comunicazioni» si pubblica la propria', function () {
    $sara = pubSara($this, [Permission::PUBLISH_OWN_COMUNICAZIONI->value]);
    $c = pubBozza($this, $sara);

    cprModifica($this, $sara, $c, pubModulo($this, $c, true))->assertSessionHasNoErrors();

    expect((bool) cprRiga($c)->is_published)->toBeTrue()->and(cprAvvisati(NewComunicazioneNotification::class))->toContain($this->mario->id);
});

it('con «Pubblica proprie comunicazioni» non si pubblica quella di un altro', function () {
    $sara = pubSara($this, [Permission::PUBLISH_OWN_COMUNICAZIONI->value]);
    $c = pubBozza($this, $this->admin);

    cprModifica($this, $sara, $c, pubModulo($this, $c, true))->assertSessionHasErrors('is_published');

    expect((bool) cprRiga($c)->is_published)->toBeFalse();
    Notification::assertNothingSent();
});

it('chi non può pubblicare corregge il testo di una bozza, che resta bozza', function () {
    $sara = pubSara($this);
    $c = pubBozza($this, $sara);

    cprModifica($this, $sara, $c, pubModulo($this, $c, false, ['subject' => 'Lavori al portone, nuova data']))->assertSessionHasNoErrors();

    expect(cprRiga($c)->subject)->toBe('Lavori al portone, nuova data')->and((bool) cprRiga($c)->is_published)->toBeFalse();
});

it('chi non può pubblicare corregge una comunicazione già pubblicata senza toglierla', function () {
    $sara = pubSara($this);
    $c = ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Lavori al portone']);

    cprModifica($this, $sara, $c, pubModulo($this, $c, true, ['subject' => 'Lavori al portone, corretto']))->assertSessionHasNoErrors();

    expect(cprRiga($c)->subject)->toBe('Lavori al portone, corretto')->and((bool) cprRiga($c)->is_published)->toBeTrue();
});

it('su una comunicazione in attesa di approvazione la visibilità non chiede niente: la mostra solo l\'approvazione', function () {
    $sara = pubSara($this);
    $c = ccmComunicazione([$this->aurora], $sara, ['is_published' => false, 'is_approved' => false, 'subject' => 'Lavori al portone']);

    cprModifica($this, $sara, $c, pubModulo($this, $c, true))->assertSessionHasNoErrors();

    expect((bool) cprRiga($c)->is_approved)->toBeFalse();
    Notification::assertNothingSent();
});

it('la pagina di modifica dice a chi non può pubblicare che non può, e perché', function (string $chi, bool $puo) {
    $utente = match ($chi) {
        'senza permesso' => pubSara($this),
        'proprie, la sua' => pubSara($this, [Permission::PUBLISH_OWN_COMUNICAZIONI->value]),
        'collaboratrice' => $this->collaboratrice,
    };
    $c = pubBozza($this, $chi === 'proprie, la sua' ? $utente : $this->admin);

    $props = cprProps($this->actingAs($utente)->get(route('admin.comunicazioni.edit', $c)));

    expect($props['pubblicazione']['puo_pubblicare'])->toBe($puo);
    $puo
        ? expect($props['pubblicazione']['nota'])->toBeNull()
        : expect($props['pubblicazione']['nota'])->toContain('Pubblica comunicazioni');
})->with([
    ['senza permesso', false],
    ['proprie, la sua', true],
    ['collaboratrice', true],
]);

it('su una comunicazione già pubblicata la pagina non toglie la scelta a nessuno', function () {
    $c = ccmComunicazione([$this->aurora], $this->admin);

    $props = cprProps($this->actingAs(pubSara($this))->get(route('admin.comunicazioni.edit', $c)));

    expect($props['pubblicazione']['puo_pubblicare'])->toBeTrue();
});
