<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # «Commenti in moderazione» lo cambia solo chi può moderare (Fase 1-bis della 1.12.0-beta.11)
 *
 * Spegnere la moderazione è approvare in anticipo tutto ciò che verrà: la sceglie chi ha «Approva commenti» del tipo
 * (`RegoleCommenti::puoModerare`), come per gli allegati serve «Approva». Chi modifica l'elemento senza approvare cambia
 * ancora «Consenti commenti»; il suo «Commenti in moderazione» si ignora, senza rifiutare il modulo: alla creazione vale
 * il di serie delle impostazioni, alla modifica resta com'era. Vale dalle rotte delle impostazioni dei commenti e dai
 * moduli di creazione e di modifica delle comunicazioni e delle segnalazioni.
 */

use App\Models\Comunicazione;
use App\Models\Segnalazione;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/CommentiScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->withoutVite();
});

function msmModerata(string $tabella, int $id): bool
{
    return (bool) DB::table($tabella)->where('id', $id)->value('commenti_moderati');
}

function msmCorpoComunicazione(array $cambi = []): array
{
    return array_merge([
        'subject' => 'Lavori in cortile', 'description' => 'Si comincia lunedì.', 'priority' => 'media',
        'can_comment' => true, 'is_featured' => false, 'is_published' => true, 'anagrafiche' => [],
    ], $cambi);
}

function msmCorpoSegnalazione(array $cambi = []): array
{
    return array_merge([
        'subject' => 'Perdita in cantina', 'description' => 'Acqua sul pavimento.', 'priority' => 'media', 'stato' => 'aperta',
        'can_comment' => true, 'is_featured' => false, 'is_published' => true,
    ], $cambi);
}

test('dalle impostazioni dei commenti: chi non approva cambia «Consenti commenti», non «Commenti in moderazione»; lo studio sì', function () {
    $c = ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Moderata', 'commenti_moderati' => true]);
    $s = ccmSegnalazione($this->aurora, $this->admin, ['subject' => 'Moderata anche lei']);
    ccmModerazione($s, true);
    $collab = $this->collaboratrice->fresh();

    $this->actingAs($collab)->from(route('admin.comunicazioni.show', $c))
        ->patch(route('admin.comunicazioni.commenti.impostazioni', $c), ['can_comment' => false, 'commenti_moderati' => false])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($collab)->from(route('admin.segnalazioni.show', $s))
        ->patch(route('admin.segnalazioni.commenti.impostazioni', $s), ['can_comment' => false, 'commenti_moderati' => false])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(msmModerata('comunicazioni', $c->id))->toBeTrue()
        ->and(msmModerata('segnalazioni', $s->id))->toBeTrue()
        ->and((bool) DB::table('comunicazioni')->where('id', $c->id)->value('can_comment'))->toBeFalse()
        ->and((bool) DB::table('segnalazioni')->where('id', $s->id)->value('can_comment'))->toBeFalse();

    // Il commento del condòmino, a commenti riaperti dallo studio, aspetta ancora.
    $this->actingAs($this->admin)->patch(route('admin.comunicazioni.commenti.impostazioni', $c), ['can_comment' => true, 'commenti_moderati' => true]);
    ccmScrivi($this, $this->mario, $c, ccmChiaveUnica(), 'Aspetta ancora')->assertRedirect();
    expect(ccmCommenti($c)->last()->stato)->toBe('in_attesa');

    // Lo studio la spegne.
    $this->actingAs($this->admin)->patch(route('admin.comunicazioni.commenti.impostazioni', $c), ['can_comment' => true, 'commenti_moderati' => false])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->patch(route('admin.segnalazioni.commenti.impostazioni', $s), ['can_comment' => true, 'commenti_moderati' => false])
        ->assertSessionHasNoErrors();
    expect(msmModerata('comunicazioni', $c->id))->toBeFalse()
        ->and(msmModerata('segnalazioni', $s->id))->toBeFalse();
});

test('la scheda dice se chi guarda può cambiare «Commenti in moderazione»', function () {
    $c = ccmComunicazione([$this->aurora], $this->admin, ['commenti_moderati' => true]);
    $s = ccmSegnalazione($this->aurora, $this->admin);

    $collab = ccmScheda($this, $this->collaboratrice->fresh(), $c, 'admin')['commenti_config'];
    $studio = ccmScheda($this, $this->admin, $c, 'admin')['commenti_config'];
    expect($collab['puo_impostare'])->toBeTrue()->and($collab['puo_impostare_moderazione'])->toBeFalse()
        ->and($studio['puo_impostare'])->toBeTrue()->and($studio['puo_impostare_moderazione'])->toBeTrue();

    $config = fn ($chi) => $this->actingAs($chi)->get(route('admin.segnalazioni.show', $s))->assertOk()->viewData('page')['props']['commenti_config'];
    expect($config($this->collaboratrice->fresh())['puo_impostare_moderazione'] ?? null)->toBeFalse()
        ->and($config($this->admin)['puo_impostare_moderazione'] ?? null)->toBeTrue();

    // Il modulo della comunicazione nuova lo dice anche lui.
    $nuova = fn ($chi) => $this->actingAs($chi)->get(route('admin.comunicazioni.create'))->assertOk()->viewData('page')['props'];
    expect($nuova($this->collaboratrice->fresh())['puo_scegliere_moderazione'] ?? null)->toBeFalse()
        ->and($nuova($this->admin)['puo_scegliere_moderazione'] ?? null)->toBeTrue();
});

test('dai moduli della comunicazione: alla creazione di chi non approva vale il di serie, alla modifica resta com\'era; lo studio sceglie', function () {
    $collab = $this->collaboratrice->fresh();

    // Di serie le comunicazioni nascono moderate.
    $this->actingAs($collab)->post(route('admin.comunicazioni.store'), msmCorpoComunicazione([
        'subject' => 'Della collaboratrice', 'commenti_moderati' => false, 'condomini_ids' => [$this->aurora->id],
        'created_by' => $collab->id, 'is_approved' => true,
    ]))->assertSessionHasNoErrors();
    $sua = Comunicazione::where('subject', 'Della collaboratrice')->sole();
    expect(msmModerata('comunicazioni', $sua->id))->toBeTrue();

    $this->actingAs($this->admin)->post(route('admin.comunicazioni.store'), msmCorpoComunicazione([
        'subject' => 'Dello studio', 'commenti_moderati' => false, 'condomini_ids' => [$this->aurora->id],
        'created_by' => $this->admin->id, 'is_approved' => true,
    ]))->assertSessionHasNoErrors();
    expect(msmModerata('comunicazioni', Comunicazione::where('subject', 'Dello studio')->sole()->id))->toBeFalse();

    // In modifica la collaboratrice cambia il resto, la moderazione no.
    $this->actingAs($collab)->put(route('admin.comunicazioni.update', $sua), msmCorpoComunicazione([
        'subject' => 'Della collaboratrice, corretta', 'commenti_moderati' => false, 'condomini_ids' => [$this->aurora->id], 'avvisa_destinatari' => false,
    ]))->assertSessionHasNoErrors();
    expect(Comunicazione::find($sua->id)->subject)->toBe('Della collaboratrice, corretta')
        ->and(msmModerata('comunicazioni', $sua->id))->toBeTrue();

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $sua), msmCorpoComunicazione([
        'subject' => 'Della collaboratrice, corretta', 'commenti_moderati' => false, 'condomini_ids' => [$this->aurora->id], 'avvisa_destinatari' => false,
    ]))->assertSessionHasNoErrors();
    expect(msmModerata('comunicazioni', $sua->id))->toBeFalse();
});

test('dai moduli della segnalazione: alla creazione di chi non approva vale il di serie, alla modifica resta com\'era; lo studio sceglie', function () {
    $collab = $this->collaboratrice->fresh();

    // Di serie le segnalazioni nascono senza moderazione: la collaboratrice non l'accende.
    $this->actingAs($collab)->post(route('admin.segnalazioni.store'), msmCorpoSegnalazione([
        'subject' => 'Della collaboratrice', 'commenti_moderati' => true, 'condominio_id' => $this->aurora->id,
        'created_by' => $collab->id, 'is_approved' => true,
    ]))->assertSessionHasNoErrors();
    $sua = Segnalazione::where('subject', 'Della collaboratrice')->sole();
    expect(msmModerata('segnalazioni', $sua->id))->toBeFalse();

    $this->actingAs($this->admin)->post(route('admin.segnalazioni.store'), msmCorpoSegnalazione([
        'subject' => 'Dello studio', 'commenti_moderati' => true, 'condominio_id' => $this->aurora->id,
        'created_by' => $this->admin->id, 'is_approved' => true,
    ]))->assertSessionHasNoErrors();
    $dello = Segnalazione::where('subject', 'Dello studio')->sole();
    expect(msmModerata('segnalazioni', $dello->id))->toBeTrue();

    // In modifica la collaboratrice non la spegne.
    $this->actingAs($collab)->put(route('admin.segnalazioni.update', $dello), msmCorpoSegnalazione([
        'subject' => 'Dello studio, corretta', 'commenti_moderati' => false, 'condominio_id' => $this->aurora->id, 'avvisa_destinatari' => false,
    ]))->assertSessionHasNoErrors();
    expect(Segnalazione::find($dello->id)->subject)->toBe('Dello studio, corretta')
        ->and(msmModerata('segnalazioni', $dello->id))->toBeTrue();

    $this->actingAs($this->admin)->put(route('admin.segnalazioni.update', $dello), msmCorpoSegnalazione([
        'subject' => 'Dello studio, corretta', 'commenti_moderati' => false, 'condominio_id' => $this->aurora->id, 'avvisa_destinatari' => false,
    ]))->assertSessionHasNoErrors();
    expect(msmModerata('segnalazioni', $dello->id))->toBeFalse();
});

// Fase 2 della 1.12.0-beta.11 (lo scettico del changelog, C1): i moduli della segnalazione dicono anche loro se chi li apre può cambiare
// «Commenti in moderazione», come quelli della comunicazione; la pagina nasconde la casella a chi non può.
test('i moduli della segnalazione dicono se chi li apre può cambiare «Commenti in moderazione»', function () {
    $s = ccmSegnalazione($this->aurora, $this->admin);
    $props = fn ($chi, string $rotta) => $this->actingAs($chi)->get($rotta)->assertOk()->viewData('page')['props'];

    expect($props($this->collaboratrice->fresh(), route('admin.segnalazioni.create'))['puo_scegliere_moderazione'] ?? null)->toBeFalse()
        ->and($props($this->admin, route('admin.segnalazioni.create'))['puo_scegliere_moderazione'] ?? null)->toBeTrue()
        ->and($props($this->collaboratrice->fresh(), route('admin.segnalazioni.edit', $s))['puo_scegliere_moderazione'] ?? null)->toBeFalse()
        ->and($props($this->admin, route('admin.segnalazioni.edit', $s))['puo_scegliere_moderazione'] ?? null)->toBeTrue();
});
