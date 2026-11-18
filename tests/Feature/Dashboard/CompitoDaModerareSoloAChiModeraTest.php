<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il compito «Da moderare» lo vede, e lo chiude, solo chi può moderare (decisione 454, punto 1; rilievi S3 e S7 della Fase 1-bis della
 * 1.12.0-beta.11).
 *
 * Il compito porta a una prossima azione: «Modera i commenti», «Modera gli allegati», «Approva la comunicazione», «Approva il documento», «Approva
 * l'evento». Lo legge chi sa farla: per i commenti il pannello con «Approva commenti» del tipo (`RegoleCommenti::puoModerare`) e la pagina
 * «Commenti» che si apre; per gli allegati la regola dell'approvazione degli allegati; per la comunicazione, il documento e l'evento
 * l'approvazione del loro tipo. Gli altri non lo vedono, non lo contano (la card, il numero del menu) e non gli rispondono; e nessuno trova
 * «Modera i commenti» che gli risponde 403.
 */

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\Commento;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\User;
use Spatie\Permission\Models\Role as RuoloSpatie;

require_once __DIR__.'/InboxCompitiScenario.php';
require_once __DIR__.'/ModerazioneScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
    $this->collaboratrice = User::factory()->create(['email_verified_at' => now()]);
    $this->collaboratrice->assignRole(Role::COLLABORATORE->value);
    $this->collaboratrice = $this->collaboratrice->fresh();
});

function cmmUtente(string $ruolo, array $permessi): User
{
    RuoloSpatie::findOrCreate($ruolo, 'web')->syncPermissions(array_map(fn (Permission $p) => $p->value, $permessi));
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $u = User::factory()->create(['email_verified_at' => now()]);
    $u->assignRole($ruolo);

    return $u->fresh();
}

function cmmIds(array $props): array
{
    return collect($props['tasks']['data'])->pluck('id')->all();
}

it('chi non approva i commenti non vede il compito dei commenti in attesa, non lo conta e non gli risponde; lo studio sì', function () {
    $s = mdSegnalazione($this);
    $com = mdComunicazione($this, 'Assemblea');
    mdCommento($s, $this->admin);
    mdCommento($com, $this->admin);
    [$diS, $diCom] = [mdAperti()->firstWhere('eventable_id', $s->id), mdAperti()->firstWhere('eventable_type', $com->getMorphClass())];

    // La collaboratrice di serie apre segnalazioni e comunicazioni, ma non approva i loro commenti.
    $props = mdProps($this, [], $this->collaboratrice);
    expect(cmmIds($props))->not->toContain($diS->id)->not->toContain($diCom->id)
        ->and($props['counts']['da_moderare'])->toBe(0)
        ->and($props['gruppi']['da_moderare'] ?? 0)->toBe(0);
    $this->actingAs($this->collaboratrice)->from(route('admin.inbox'))->post(route('admin.inbox.risposta', $diCom), ['tipo' => 'fatto'])->assertNotFound();
    $this->actingAs($this->collaboratrice)->from(route('admin.inbox'))->post(route('admin.inbox.risposta', $diS), ['tipo' => 'non_serve', 'motivo' => 'Non tocca a me'])
        ->assertNotFound();
    expect($diCom->fresh()->is_completed)->toBeFalse()->and($diS->fresh()->is_completed)->toBeFalse();

    // Lo studio li vede, li conta, e il collegamento apre la pagina.
    $propsStudio = mdProps($this);
    expect(cmmIds($propsStudio))->toContain($diS->id, $diCom->id)
        ->and($propsStudio['counts']['da_moderare'])->toBe(2);
});

it('chi apre le comunicazioni ma non legge i loro commenti non vede «1 commento in attesa», e non trova un collegamento che gli risponde 403', function () {
    $com = mdComunicazione($this, 'Bacheca');
    mdCommento($com, $this->admin);
    $compito = mdAperti()->sole();

    $bacheca = cmmUtente('Solo bacheca', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_COMUNICAZIONI, Permission::VIEW_EVENTS]);
    $props = mdProps($this, [], $bacheca);
    expect(cmmIds($props))->not->toContain($compito->id)->and($props['counts']['da_moderare'])->toBe(0);

    // Chi approva i commenti delle comunicazioni e li legge vede il compito, e «Modera i commenti» gli apre la pagina.
    $moderatore = cmmUtente('Modera comunicazioni', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_COMUNICAZIONI, Permission::VIEW_EVENTS,
        Permission::VIEW_COMMENTS_COMUNICAZIONI, Permission::APPROVE_COMMENTS_COMUNICAZIONI]);
    $riga = mdRiga(mdProps($this, [], $moderatore), $compito->id);
    expect($riga)->not->toBeNull()->and($riga['azione']['testo'])->toBe('Modera i commenti');
    $this->actingAs($moderatore)->get($riga['azione']['url'])->assertOk();
});

it('il compito segue la sua prossima azione: chi approva solo gli allegati lo vede quando restano solo gli allegati', function () {
    $s = mdSegnalazione($this);
    $commento = mdCommento($s, $this->admin);
    mdAllegato($s, $this->admin);
    $compito = mdAperti()->sole();
    expect($compito->title)->toBe('1 commento e 1 allegato in attesa');

    $allegati = cmmUtente('Modera allegati', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_SEGNALAZIONI, Permission::VIEW_EVENTS,
        Permission::VIEW_ATTACHMENTS_SEGNALAZIONI, Permission::APPROVE_ATTACHMENTS_SEGNALAZIONI]);
    // «Modera i commenti» non è sua.
    expect(cmmIds(mdProps($this, [], $allegati)))->not->toContain($compito->id);

    $this->actingAs($this->admin)->post(route('admin.commenti.approva', $commento))->assertSessionHasNoErrors();
    expect(mdAperti()->sole()->title)->toBe('1 allegato in attesa');

    $riga = mdRiga(mdProps($this, [], $allegati), $compito->id);
    expect($riga)->not->toBeNull()->and($riga['azione']['testo'])->toBe('Modera gli allegati');
    $this->actingAs($allegati)->get($riga['azione']['url'])->assertOk();
});

it('una comunicazione, un documento e un evento da approvare li vede chi li approva', function () {
    $com = mdComunicazione($this, 'Lavori in cortile', false);
    $d = Documento::create(['name' => 'Regolamento', 'path' => 'documenti/r.pdf', 'mime_type' => 'application/pdf', 'file_size' => 10,
        'created_by' => $this->admin->id, 'is_published' => true, 'is_approved' => false]);
    $d->condomini()->attach($this->condominio->id);
    $d->touch();
    $e = Evento::create(['title' => 'Festa del palazzo', 'description' => 'x', 'start_time' => now()->addDays(10), 'end_time' => now()->addDays(10)->addHour(),
        'created_by' => $this->admin->id, 'visibility' => 'hidden', 'is_approved' => false]);
    $e->condomini()->attach($this->condominio->id);
    $e->touch();
    $diCom = mdAperti()->firstWhere('eventable_type', $com->getMorphClass());
    $diD = mdAperti()->firstWhere('eventable_type', $d->getMorphClass());
    $diE = mdAperti()->firstWhere('eventable_type', $e->getMorphClass());
    expect([$diCom, $diD, $diE])->each->not->toBeNull();

    // La collaboratrice di serie approva i documenti e gli eventi, non le comunicazioni.
    $ids = cmmIds(mdProps($this, [], $this->collaboratrice));
    expect($ids)->toContain($diD->id, $diE->id)->not->toContain($diCom->id);

    // Chi legge l'archivio e l'agenda senza approvarli non vede nessuno dei due.
    $lettore = cmmUtente('Legge e basta', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_COMUNICAZIONI, Permission::VIEW_ARCHIVE_DOCUMENTS, Permission::VIEW_EVENTS]);
    $props = mdProps($this, [], $lettore);
    expect(cmmIds($props))->not->toContain($diCom->id)->not->toContain($diD->id)->not->toContain($diE->id)
        ->and($props['counts']['da_moderare'])->toBe(0);

    // Chi approva le comunicazioni vede la sua.
    $approva = cmmUtente('Approva comunicazioni', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_COMUNICAZIONI, Permission::APPROVE_COMUNICAZIONI, Permission::VIEW_EVENTS]);
    expect(cmmIds(mdProps($this, [], $approva)))->toContain($diCom->id)->not->toContain($diD->id);
});

it('il numero del menu non conta i compiti da moderare di chi non modera', function () {
    $s = mdSegnalazione($this);
    mdCommento($s, $this->admin);

    $menu = fn (User $chi) => \App\Services\Gestionale\InboxService::numeroPerIlMenu(
        \App\Services\Gestionale\Inbox\CompitoDellaSegnalazione::chiVede($chi), \App\Services\Gestionale\Inbox\CompitoDaModerare::chiVede($chi));

    // Il compito della segnalazione aperta c'è per tutti e due; quello da moderare solo per lo studio.
    expect($menu($this->admin))->toBe($menu($this->collaboratrice) + 1);
});
