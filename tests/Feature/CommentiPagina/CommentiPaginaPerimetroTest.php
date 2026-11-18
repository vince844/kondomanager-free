<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # La pagina «Commenti» del pannello: che cosa legge chi non modera, il filtro per elemento, la data (Fase 1-bis della 1.12.0-beta.11)
 *
 * - Chi non ha «Approva commenti» di un tipo legge di quel tipo i pubblicati e i propri in attesa, come nella scheda
 *   (`RegoleCommenti::commentiInDiscussione`) e come nella pagina degli allegati; né gli in attesa degli altri né i nascosti.
 * - Il filtro «elemento» vuole il tipo: una segnalazione e una comunicazione con lo stesso numero non si mescolano. Lo
 *   stesso nella pagina «Allegati».
 * - «Scritto il» arriva già nel giorno e nell'ora di Roma, non lo calcola il browser.
 */

use App\Models\Commento;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/CommentiPaginaScenario.php';

beforeEach(function () {
    ccmPrepara($this);
    $this->withoutVite();
});

test('chi non approva i commenti di un tipo legge di quel tipo i pubblicati e i propri in attesa, come nella scheda; chi approva legge anche gli altri in attesa e i nascosti', function () {
    $sc = cpScenario($this);
    $collab = $this->collaboratrice->fresh();
    $suo = ccmCommentoSalvato($sc['c'], $collab, 'Il mio, ancora in attesa', $this->aurora, null, 'in_attesa');

    // La collaboratrice di serie non approva né le segnalazioni né le comunicazioni.
    $visti = cpIds($this, $collab, ['per_page' => 100]);
    expect($visti)->toContain($sc['id']['s_pub'], $sc['id']['c_aur'], $sc['id']['c_bor'], $sc['id']['p_mario'], $suo)
        ->and($visti)->not->toContain($sc['id']['s_att'])
        ->and($visti)->not->toContain($sc['id']['s_nas'])
        ->and($visti)->not->toContain($sc['id']['c_aur_att'])
        ->and($visti)->not->toContain($sc['id']['c_bor_att'])
        ->and($visti)->not->toContain($sc['id']['p_lucia']);

    // «In attesa» (il collegamento dell'Action inbox) le dà solo il suo; «Nascosto» niente.
    expect(cpIds($this, $collab, ['stato' => 'in_attesa', 'per_page' => 100]))->toBe([$suo])
        ->and(cpIds($this, $collab, ['stato' => 'nascosto', 'per_page' => 100]))->toBe([]);

    // Il testo non arriva nemmeno nelle props della pagina.
    $json = json_encode(cpProps($this, $collab, ['per_page' => 100]), JSON_UNESCAPED_UNICODE);
    expect($json)->not->toContain('Un commento sgradevole')
        ->and($json)->not->toContain('Per Borea: serve il permesso?')
        ->and($json)->not->toContain('Lucia allo studio');

    // La regola è per tipo: chi approva i commenti delle segnalazioni e non quelli delle comunicazioni.
    $misto = cpStudioConRuolo('Approva Solo Segnalazioni', [
        'Accesso pannello amministratore', 'Visualizza segnalazioni', 'Visualizza comunicazioni',
        CCM_PERMESSI_SEGNALAZIONI['visualizza'], CCM_PERMESSI['visualizza'], CCM_PERMESSI_SEGNALAZIONI['approva'],
    ]);
    $vistiMisto = cpIds($this, $misto->fresh(), ['per_page' => 100]);
    expect($vistiMisto)->toContain($sc['id']['s_att'], $sc['id']['s_nas'], $sc['id']['c_aur'])
        ->and($vistiMisto)->not->toContain($sc['id']['c_aur_att'])
        ->and($vistiMisto)->not->toContain($sc['id']['p_lucia'])
        ->and($vistiMisto)->not->toContain($suo);

    // Lo studio approva tutto: legge tutto, i nascosti compresi (gli eliminati no).
    expect(cpIds($this, $this->admin->fresh(), ['per_page' => 100]))->toHaveCount(count($sc['id']));
});

test('a chi non modera la pagina dà, commento per commento, gli stessi commenti delle schede che apre', function () {
    $sc = cpScenario($this);
    $collab = $this->collaboratrice->fresh();

    $nelleSchede = collect([$sc['c'], $sc['p']])
        ->flatMap(fn ($c) => collect(ccmScheda($this, $collab, $c, 'admin')['discussioni'])->flatMap(fn ($d) => collect($d['commenti'])->pluck('id')))
        ->merge(Commento::query()->where('commentable_type', $sc['s']->getMorphClass())->where('stato', 'pubblicato')->pluck('id'))
        ->sort()->values()->all();

    expect(collect(cpIds($this, $collab, ['per_page' => 100]))->sort()->values()->all())->toBe($nelleSchede);
});

test('il filtro «elemento» vuole il tipo: senza, la pagina «Commenti» non mescola una segnalazione e una comunicazione con lo stesso numero', function () {
    $s = ccmSegnalazione($this->aurora, $this->admin, ['subject' => 'Ascensore fermo']);
    $c = ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Avviso di giugno']);
    expect($s->id)->toBe($c->id);
    ccmCommentoSalvato($s, $this->mario, 'Sulla segnalazione', $this->aurora, null, 'in_attesa');
    ccmCommentoSalvato($c, $this->mario, 'Sulla comunicazione', $this->aurora, null, 'in_attesa');

    // Un indirizzo con l'elemento e senza il tipo (scritto a mano, o il tipo tolto) non vale.
    $this->actingAs($this->admin)->get(route('admin.commenti.index', ['stato' => 'in_attesa', 'elemento' => $c->id]))
        ->assertRedirect(route('admin.commenti.index'))
        ->assertSessionHasErrors('tipo');

    $props = cpProps($this, $this->admin, ['stato' => 'in_attesa', 'tipo' => 'comunicazioni', 'elemento' => $c->id]);
    expect(collect($props['commenti']['data'])->pluck('testo')->all())->toBe(['Sulla comunicazione'])
        ->and($props['filtri']['elemento_nome'])->toBe('Avviso di giugno');

    $props = cpProps($this, $this->admin, ['stato' => 'in_attesa', 'tipo' => 'segnalazioni', 'elemento' => $s->id]);
    expect(collect($props['commenti']['data'])->pluck('testo')->all())->toBe(['Sulla segnalazione'])
        ->and($props['filtri']['elemento_nome'])->toBe('Ascensore fermo');
});

test('il filtro «elemento» vuole il tipo anche nella pagina «Allegati»', function () {
    $s = ccmSegnalazione($this->aurora, $this->admin, ['subject' => 'Ascensore fermo']);
    $c = ccmComunicazione([$this->aurora], $this->admin, ['subject' => 'Avviso di giugno']);
    expect($s->id)->toBe($c->id);
    foreach ([[$s, 'segnalazione.jpg'], [$c, 'comunicazione.jpg']] as [$elemento, $nome]) {
        DB::table('documenti')->insert([
            'name' => $nome, 'path' => 'allegati/' . uniqid() . '.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 10,
            'created_by' => $this->mario->id, 'is_published' => true, 'is_approved' => false,
            'documentable_type' => $elemento->getMorphClass(), 'documentable_id' => $elemento->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $this->actingAs($this->admin)->get(route('admin.allegati.index', ['stato' => 'in_attesa', 'elemento' => $c->id]))
        ->assertRedirect(route('admin.allegati.index'))
        ->assertSessionHasErrors('tipo');

    $props = json_decode(json_encode($this->actingAs($this->admin)
        ->get(route('admin.allegati.index', ['stato' => 'in_attesa', 'tipo' => 'comunicazioni', 'elemento' => $c->id]))
        ->assertOk()->viewData('page')['props']), true);
    $righe = collect($props['allegati']['data'] ?? $props['allegati']);
    expect($righe->pluck('tipo')->unique()->values()->all())->toBe(['comunicazioni'])
        ->and($righe)->toHaveCount(1);
});

test('«Scritto il» arriva dal server già nel giorno e nell\'ora di Roma: un commento delle 00:30 del 18/11 non è del 17/11', function () {
    $s = ccmSegnalazione($this->aurora, $this->admin);
    $this->travelTo(Carbon::parse('2026-11-18 00:30', 'Europe/Rome')->utc());
    $id = ccmCommentoSalvato($s, $this->mario, 'Scritto dopo mezzanotte', $this->aurora);
    $this->travelTo(Carbon::parse('2026-07-01 09:05', 'Europe/Rome')->utc());
    $estate = ccmCommentoSalvato($s, $this->mario, 'Scritto d\'estate', $this->aurora);
    $this->travelBack();

    $righe = collect(cpRighe($this, $this->admin, ['per_page' => 100]))->keyBy('id');
    expect($righe[$id]['creato_il'])->toBe('2026-11-18 00:30')
        ->and($righe[$estate]['creato_il'])->toBe('2026-07-01 09:05');
});
