<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * # Conferma di lettura delle comunicazioni: un database senza la riga di un permesso (Coda 594)
 *
 * Questo lavoro non aggiunge permessi e **non ne legge nessuno in un punto nuovo**: le letture le vede chi nel pannello
 * apre la scheda, l'apertura la registra chi nel portale apre la comunicazione.
 *
 * Perché un test: `hasPermissionTo()` su un permesso la cui riga non c'è nel database lancia un'eccezione, e la pagina
 * cade con un errore 500. Succede su un'installazione prima del seeder dei ruoli, e in molti test del core, che creano
 * solo i permessi che usano. Qui ogni pagina toccata dal compito si apre in un database dove restano **solo i permessi
 * che quella pagina legge già oggi** (misurati sul codice di prima del compito): se il codice nuovo ne legge un altro,
 * il test lo dice. Dove serve un controllo, si usa `checkPermissionTo()`, che risponde «no» invece di lanciare.
 */

use App\Models\Comunicazione;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/LettureScenario.php';

/** I permessi che ogni pagina legge già oggi, misurati sul codice di prima del compito. */
const LCT_PERMESSI_DI_OGGI = [
    'portale' => ['Accesso pannello amministratore', 'Visualizza comunicazioni', 'Visualizza proprie comunicazioni'],
    'scheda' => ['Accesso pannello amministratore', 'Modifica comunicazioni'],
    'elenco' => ['Visualizza comunicazioni'],
    'modifica' => ['Accesso pannello amministratore', 'Modifica comunicazioni', 'Pubblica comunicazioni'],
    'crea' => ['Crea comunicazioni', 'Pubblica comunicazioni'],
    'aggiorna' => ['Accesso pannello amministratore', 'Modifica comunicazioni'],
    'impostazioni' => ['Gestisci impostazioni generali'],
];

beforeEach(function () {
    lctPrepara($this);
    $this->modulo = [
        'subject' => 'Lettura dei contatori',
        'description' => 'Giovedì passa il letturista.',
        'priority' => 'media',
        'can_comment' => false,
        'is_featured' => false,
        'is_published' => true,
        'condomini_ids' => [$this->aurora->id],
    ];
});

test('il portale apre la comunicazione e registra l\'apertura', function () {
    lctImpostazione(true);
    lctChiedi($this->c);
    lctSoloIPermessi(LCT_PERMESSI_DI_OGGI['portale']);

    $props = lctProps(lctApri($this, $this->mario, $this->c));

    expect($props)->not->toBeNull('La scheda del portale non si apre')
        ->and($props['lettura']['puo_confermare'] ?? null)->toBeTrue()
        ->and(lctRiga($this->c, $this->mario)?->aperta_il)->toBe(LCT_ADESSO);
});

test('il portale apre la comunicazione anche con l\'impostazione spenta', function () {
    lctSoloIPermessi(LCT_PERMESSI_DI_OGGI['portale']);

    $props = lctProps(lctApri($this, $this->mario, $this->c));

    expect($props)->not->toBeNull('La scheda del portale non si apre')
        ->and($props)->toHaveKey('lettura')
        ->and(lctRighe())->toHaveCount(0);
});

test('il condòmino conferma la presa visione', function () {
    lctChiedi($this->c);
    lctSoloIPermessi(LCT_PERMESSI_DI_OGGI['portale']);

    lctConferma($this, $this->mario, $this->c)->assertRedirect()->assertSessionHasNoErrors();

    expect(lctRiga($this->c, $this->mario)?->presa_visione_il)->toBe(LCT_ADESSO);
});

test('la scheda del pannello si apre, con le letture', function () {
    lctImpostazione(true);
    lctChiedi($this->c);
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');
    lctSoloIPermessi(LCT_PERMESSI_DI_OGGI['scheda']);

    $props = lctProps(lctApri($this, $this->admin, $this->c, 'admin'));

    expect($props)->not->toBeNull('La scheda del pannello non si apre')
        ->and($props['letture']['righe'] ?? [])->toHaveCount(4)
        ->and($props['letture']['riassunto']['aperte'] ?? null)->toBe(1);
});

test('l\'elenco del pannello si apre, con i due numeri', function () {
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');
    lctSoloIPermessi(LCT_PERMESSI_DI_OGGI['elenco']);

    $props = lctProps($this->actingAs($this->admin)->get(route('admin.comunicazioni.index')));

    expect($props)->not->toBeNull('L\'elenco del pannello non si apre')
        ->and(lctElenco($props)[$this->c->id]['letture'] ?? null)->toBe(['aperte' => 1, 'prese_visione' => 0]);
});

test('la pagina di modifica si apre, con la spunta', function () {
    lctChiedi($this->c);
    lctSoloIPermessi(LCT_PERMESSI_DI_OGGI['modifica']);

    $props = lctProps($this->actingAs($this->admin)->get(route('admin.comunicazioni.edit', $this->c)));

    expect($props)->not->toBeNull('La pagina di modifica non si apre')
        ->and(lctRisorsa($props['comunicazione'])[LCT_COLONNA] ?? null)->toBeTrue();
});

test('il modulo salva la spunta alla creazione', function () {
    lctSoloIPermessi(LCT_PERMESSI_DI_OGGI['crea']);

    $this->actingAs($this->admin)->post(route('admin.comunicazioni.store'), $this->modulo + [LCT_COLONNA => true])
        ->assertRedirect()->assertSessionHasNoErrors();

    $nuova = Comunicazione::query()->orderByDesc('id')->firstOrFail();
    expect($nuova->subject)->toBe('Lettura dei contatori')
        ->and(lctChiesta($nuova->id))->toBeTrue();
});

test('il modulo salva la spunta in modifica', function () {
    lctSoloIPermessi(LCT_PERMESSI_DI_OGGI['aggiorna']);

    $this->actingAs($this->admin)->put(route('admin.comunicazioni.update', $this->c), $this->modulo + [LCT_COLONNA => true])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(lctChiesta($this->c->id))->toBeTrue();
});

test('le impostazioni generali si aprono, si salvano, e il pulsante cancella le aperture', function () {
    lctScrivi($this->c, $this->mario, '2026-11-11 09:00:00');
    lctSoloIPermessi(LCT_PERMESSI_DI_OGGI['impostazioni']);

    $props = lctProps($this->actingAs($this->admin)->get(route('impostazioni.generali')));
    expect($props)->not->toBeNull('Le impostazioni generali non si aprono')
        ->and($props['aperture_registrate'] ?? null)->toBe(1);

    $this->actingAs($this->admin)->post(route('impostazioni.generali.store'), [
        'user_frontend_registration' => false, 'language' => 'it', 'app_name' => 'Kondomanager',
        'open_condominio_on_login' => false, 'default_condominio_id' => null, 'default_user_role' => 'utente',
        'default_per_page' => 10, LCT_IMPOSTAZIONE => true,
    ])->assertSessionHasNoErrors();
    expect(lctImpostazioneAccesa())->toBeTrue();

    $this->actingAs($this->admin)->delete(route(LCT_ROTTA_CANCELLA))->assertRedirect()->assertSessionHasNoErrors();
    expect(lctRighe())->toHaveCount(0);
});
