<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il compito di una segnalazione, con il suo titolo e la sua descrizione, si legge e si conta solo da chi può aprire quella segnalazione: la regola della pagina
 * delle segnalazioni (`SegnalazionePolicy::view`), private comprese. Con «Visualizza segnalazioni» tutte; con «Visualizza proprie segnalazioni» quelle che ha
 * scritto; senza i due permessi nessuna. Vale per la riga, la ricerca, le card, le schede, i gruppi, i due grafici, «Dove si accumulano», il numero del menu e
 * l'Inbox operativa del condominio. Gli altri compiti restano come sono.
 */

use App\Enums\Permission;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role as RuoloSpatie;

require_once __DIR__.'/InboxCompitiScenario.php';

beforeEach(function () {
    $this->withoutVite();
    icsInizia($this);
});

/** Una segnalazione del condominio dello scenario, scritta dall'amministratore o da chi è dato. */
function isvSegnalazione($test, array $dati = []): Segnalazione
{
    return Segnalazione::create($dati + [
        'subject' => 'Cancello rotto', 'description' => 'Non si chiude.', 'created_by' => $test->admin->id, 'condominio_id' => $test->condominio->id,
        'priority' => 'media', 'stato' => 'aperta', 'is_published' => true, 'is_approved' => true, 'is_private' => false, 'can_comment' => true,
    ]);
}

/** Una persona dello studio con un ruolo fatto dei soli permessi dati. */
function isvUtente(string $ruolo, array $permessi): User
{
    RuoloSpatie::findOrCreate($ruolo, 'web')->syncPermissions(array_map(fn (Permission $p) => $p->value, $permessi));
    $utente = User::factory()->create(['email_verified_at' => now()]);
    $utente->assignRole($ruolo);

    return $utente->fresh();
}

/** Le props dell'Action inbox per chi la apre. */
function isvCasella($test, User $chi, array $filtri = []): array
{
    Cache::flush();

    return $test->actingAs($chi)->get(route('admin.inbox', $filtri))->assertOk()->viewData('page')['props'];
}

/** Le props della dashboard del condominio per chi la apre. */
function isvDashboard($test, User $chi): array
{
    Cache::flush();

    return $test->actingAs($chi)->get(route('admin.gestionale.index', $test->condominio))->assertOk()->viewData('page')['props'];
}

/** I titoli delle righe dell'Action inbox (l'elenco e l'anteprima dei gruppi). */
function isvTitoli(array $props): array
{
    $righe = collect($props['tasks']['data'] ?? [])->merge(collect($props['anteprima'] ?? [])->flatMap(fn ($g) => $g['righe']));

    return $righe->pluck('title')->unique()->sort()->values()->all();
}

/** Il caso: una segnalazione privata e una pubblica dell'amministratore, e un compito delle rate. */
function isvCaso($test): array
{
    $privata = isvSegnalazione($test, ['subject' => 'Perdita dal soffitto del sig. Bianchi', 'description' => 'Testo riservato della segnalazione.', 'priority' => 'urgente',
        'is_private' => true]);
    $pubblica = isvSegnalazione($test);
    icsCompito($test, 'controllo_incassi', [], 'Verifica incassi - Rata 3');

    return [$privata, $pubblica];
}

it('chi ha il pannello e l\'agenda ma non le segnalazioni non ne legge il titolo, la descrizione né il numero, nelle due Inbox e nel menu', function () {
    isvCaso($this);
    $agenda = isvUtente('Solo agenda', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_EVENTS]);
    expect($agenda->can('view', new Segnalazione()))->toBeFalse();

    $p = isvCasella($this, $agenda);
    $tutto = json_encode($p, JSON_UNESCAPED_UNICODE);
    expect($tutto)->not->toContain('Perdita dal soffitto')->not->toContain('Testo riservato')->not->toContain('Cancello rotto')
        ->and(isvTitoli($p))->toBe(['Verifica incassi - Rata 3'])
        ->and($p['counts'])->toMatchArray(['all' => 1, 'segnalazioni' => 0, 'segnalazioni_urgenti' => 0])
        ->and($p['schede']['tutti'])->toBe(1)
        ->and($p['gruppi']['segnalazioni'])->toBe(0)
        ->and($p['grafici']['fuori']['segnalazioni'])->toBe(0)
        ->and(collect($p['grafici']['tipi'])->pluck('tipo')->all())->not->toContain('segnalazione_guasto')
        ->and(collect($p['dove'])->sum('valore'))->toBe(1)
        ->and((int) $p['inbox_count'])->toBe(1);

    // Né con il filtro del tipo, né con la ricerca.
    $soloSegnalazioni = isvCasella($this, $agenda, ['tipo' => 'segnalazione_guasto']);
    expect($soloSegnalazioni['tasks']['data'])->toBe([])->and($soloSegnalazioni['schede']['tutti'])->toBe(0);
    expect(isvCasella($this, $agenda, ['cerca' => 'Bianchi'])['tasks']['data'])->toBe([]);

    // Nell'Inbox operativa del condominio, con l'agenda o con il solo pannello.
    foreach ([$agenda, isvUtente('Solo pannello', [Permission::ACCESS_ADMIN_PANEL])] as $chi) {
        $d = isvDashboard($this, $chi);
        expect(collect($d['inboxTasks']['data'])->pluck('title')->all())->toBe(['Verifica incassi - Rata 3'])
            ->and($d['inboxTasks']['total'])->toBe(1)
            ->and(json_encode($d['inboxTasks'], JSON_UNESCAPED_UNICODE))->not->toContain('Testo riservato')
            ->and((int) $d['inbox_count'])->toBe(1);
    }
});

it('con «Visualizza segnalazioni» le legge e le conta tutte, private comprese', function () {
    isvCaso($this);
    $tutte = isvUtente('Segreteria', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_EVENTS, Permission::VIEW_SEGNALAZIONI]);

    $p = isvCasella($this, $tutte);
    expect(isvTitoli($p))->toBe(['Cancello rotto', 'Perdita dal soffitto del sig. Bianchi', 'Verifica incassi - Rata 3'])
        ->and($p['counts'])->toMatchArray(['all' => 3, 'segnalazioni' => 2, 'segnalazioni_urgenti' => 1])
        ->and(collect($p['grafici']['tipi'])->firstWhere('tipo', 'segnalazione_guasto')['valore'])->toBe(2)
        ->and((int) $p['inbox_count'])->toBe(3)
        ->and(collect(isvDashboard($this, $tutte)['inboxTasks']['data'])->pluck('title')->sort()->values()->all())
        ->toBe(['Cancello rotto', 'Perdita dal soffitto del sig. Bianchi', 'Verifica incassi - Rata 3']);
});

it('con «Visualizza proprie segnalazioni» legge e conta solo quelle che ha scritto, anche private', function () {
    isvCaso($this);
    $proprie = isvUtente('Portineria', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_EVENTS, Permission::VIEW_OWN_SEGNALAZIONI]);
    isvSegnalazione($this, ['subject' => 'Lampadina del portone', 'description' => 'Scritta dalla portineria.', 'created_by' => $proprie->id, 'is_private' => true]);

    $p = isvCasella($this, $proprie);
    expect(isvTitoli($p))->toBe(['Lampadina del portone', 'Verifica incassi - Rata 3'])
        ->and(json_encode($p, JSON_UNESCAPED_UNICODE))->not->toContain('Testo riservato')
        ->and($p['counts'])->toMatchArray(['all' => 2, 'segnalazioni' => 1])
        ->and((int) $p['inbox_count'])->toBe(2)
        ->and(collect(isvDashboard($this, $proprie)['inboxTasks']['data'])->pluck('title')->sort()->values()->all())
        ->toBe(['Lampadina del portone', 'Verifica incassi - Rata 3']);
});

it('è la regola della pagina: per ogni persona e ogni segnalazione il compito si vede se e solo se la segnalazione si apre', function () {
    [$privata, $pubblica] = isvCaso($this);
    $persone = [
        'amministratore' => $this->admin,
        'solo agenda' => isvUtente('Solo agenda', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_EVENTS]),
        'tutte' => isvUtente('Segreteria', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_EVENTS, Permission::VIEW_SEGNALAZIONI]),
        'proprie' => isvUtente('Portineria', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_EVENTS, Permission::VIEW_OWN_SEGNALAZIONI]),
    ];
    $sua = isvSegnalazione($this, ['subject' => 'Lampadina del portone', 'created_by' => $persone['proprie']->id, 'is_private' => true]);

    foreach ($persone as $nome => $chi) {
        $viste = collect(isvCasella($this, $chi, ['tipo' => 'segnalazione_guasto'])['tasks']['data'])->pluck('title')->sort()->values()->all();
        $siAprono = collect([$privata, $pubblica, $sua])->filter(fn (Segnalazione $s) => Gate::forUser($chi)->allows('view', $s))->pluck('subject')->sort()->values()->all();
        expect($viste)->toBe($siAprono, "per «{$nome}»");
    }
});

it('il numero del menu è di chi lo guarda: ognuno ha il suo, anche dopo che un altro l\'ha calcolato', function () {
    isvCaso($this);
    $agenda = isvUtente('Solo agenda', [Permission::ACCESS_ADMIN_PANEL, Permission::VIEW_EVENTS]);
    Cache::flush();

    expect(icsBarra($this))->toBe(3)
        ->and((int) $this->actingAs($agenda)->get(route('admin.inbox'))->viewData('page')['props']['inbox_count'])->toBe(1)
        ->and(icsBarra($this))->toBe(3);
});
