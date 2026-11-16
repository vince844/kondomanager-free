<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * I compiti dell'agenda delle due Inbox (la casella delle attività dello studio e l'Inbox operativa del condominio) per le prove delle tre risposte: un
 * condominio, l'amministratore dei ruoli di serie, un compito per tipo come lo scrivono i punti del programma che lo creano (`InboxService::createTask` o i
 * listener, che scrivono il tipo solo in `meta.type`). Oggi è il 20/05/2026, alle 10:00 di Roma.
 *
 * NON contiene nessun it() o test — solo funzioni di supporto.
 */

use App\Enums\EventoTipo;
use App\Enums\Role;
use App\Enums\VisibilityStatus;
use App\Models\Anagrafica;
use App\Models\CategoriaEvento;
use App\Models\Condominio;
use App\Models\Evento;
use App\Models\User;
use App\Services\Gestionale\InboxService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Carbon;

/**
 * I tipi di compito dell'Inbox: quelli di cui il giorno si sposta, e quelli che hanno il promemoria a parte (i sei con la scadenza; i commenti e gli allegati,
 * che tornano prima se ne arriva uno nuovo e allora contano con il loro giorno; la segnalazione e il bonifico segnalato, il cui giorno dice da quando
 * aspettano: «aperta il», «segnalato il»).
 */
const ICS_SI_SPOSTA = ['controllo_incassi', 'emissione_rata', 'scadenza', 'scoperto_documentato', 'eccedenza_gia_versato_rilevata', 'gia_versato_acconto_dichiarato'];
const ICS_A_PARTE = ['pagamento_fornitore', 'versamento_ritenuta', 'convocazione_urgenza', 'ratifica_sforo', 'emissione_rata_sopravvenienza', 'pianifica_ripianamento_deficit',
    'commento', 'allegato', 'segnalazione_guasto', 'verifica_pagamento'];

function icsInizia($test): void
{
    $test->seed(RolesAndPermissionsSeeder::class);
    $test->travelTo(Carbon::parse('2026-05-20 10:00', 'Europe/Rome')->utc());
    CategoriaEvento::firstOrCreate(['name' => 'Scadenze amministrative'], ['description' => 'Auto']);
    $test->condominio = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $test->admin = User::factory()->create(['name' => 'Vincenzo', 'email_verified_at' => now()]);
    $test->admin->assignRole(Role::AMMINISTRATORE->value);
    $test->admin = $test->admin->fresh();
}

/**
 * Un compito dell'Inbox del tipo dato, del condominio, ieri. I tipi che sono casi di `EventoTipo` nascono con `InboxService::createTask`; gli altri come li
 * scrivono i listener (il tipo in `meta.type`, la colonna vuota). Il compito di una segnalazione nasce con la sua segnalazione, come nel programma: senza la
 * segnalazione le due Inbox non lo leggono (`CompitoDellaSegnalazione::soloQuelleCheVede()`); il titolo, la descrizione, il giorno e il collegamento sono poi
 * quelli degli altri tipi.
 */
function icsCompito($test, string $tipo, array $meta = [], ?string $titolo = null): Evento
{
    $titolo ??= 'Compito ' . $tipo;
    $quando = now()->subDay();
    if ($tipo === EventoTipo::SEGNALAZIONE_GUASTO->value) {
        $segnalazione = \App\Models\Segnalazione::create(['subject' => $titolo, 'description' => 'Descrizione di ' . $tipo, 'created_by' => $test->admin->id,
            'condominio_id' => $test->condominio->id, 'priority' => $meta['priority'] ?? 'media', 'stato' => 'aperta', 'is_published' => true,
            'is_approved' => $meta['is_approved'] ?? true, 'is_private' => false, 'can_comment' => true]);
        $e = Evento::where('tipo', $tipo)->where('eventable_id', $segnalazione->id)->sole();
        $e->update(['title' => $titolo, 'description' => 'Descrizione di ' . $tipo, 'start_time' => $quando, 'end_time' => $quando->copy()->addHour(),
            'meta' => array_merge($e->meta, array_diff_key($meta, ['context' => true]), ['action_url' => '/da/risolvere'])]);

        return $e->fresh();
    }
    $enum = EventoTipo::tryFrom($tipo);
    if ($enum !== null) {
        return InboxService::createTask(tipo: $enum, title: $titolo, description: 'Descrizione di ' . $tipo, scadenza: $quando, createdByUserId: $test->admin->id,
            condominioId: $test->condominio->id, context: $meta['context'] ?? [], actionUrl: '/da/risolvere', extraMeta: array_diff_key($meta, ['context' => true]));
    }
    $e = Evento::create([
        'title' => $titolo, 'description' => 'Descrizione di ' . $tipo, 'start_time' => $quando, 'end_time' => $quando->copy()->addHour(), 'created_by' => $test->admin->id,
        'category_id' => CategoriaEvento::where('name', 'Scadenze amministrative')->value('id'), 'visibility' => VisibilityStatus::HIDDEN->value, 'is_approved' => true,
        'meta' => $meta + ['type' => $tipo, 'requires_action' => true, 'is_completed' => false, 'action_url' => '/da/risolvere', 'context' => []],
    ]);
    $e->condomini()->syncWithoutDetaching([$test->condominio->id]);

    return $e;
}

/** Il bonifico segnalato dal portale, con l'evento della rata del condòmino collegato. */
function icsBonifico($test): array
{
    $condomino = User::factory()->create(['email_verified_at' => now()]);
    $condomino->assignRole(Role::UTENTE->value);
    $mario = Anagrafica::create(['user_id' => $condomino->id, 'nome' => 'Mario Rossi', 'indirizzo' => 'Via Roma 1']);
    $mario->condomini()->attach($test->condominio->id);
    $rata = Evento::create(['title' => 'Rata 3 di Mario', 'start_time' => now()->subDay(), 'end_time' => now()->subDay()->addHour(), 'created_by' => $condomino->id,
        'visibility' => VisibilityStatus::PRIVATE->value, 'is_approved' => true, 'tipo' => EventoTipo::SCADENZA_RATA_CONDOMINO,
        'meta' => ['status' => 'reported', 'totale_rata' => 12345, 'requires_action' => true]]);
    $rata->condomini()->attach($test->condominio->id);
    $compito = icsCompito($test, 'verifica_pagamento', ['context' => ['related_event_id' => $rata->id, 'anagrafica_id' => $mario->id], 'importo_dichiarato' => 12345],
        'Verifica incasso della rata 3 di Mario');

    return [$compito, $rata];
}

function icsRispondi($test, Evento $compito, array $dati, ?User $chi = null)
{
    return $test->actingAs($chi ?? $test->admin)->from(route('admin.inbox'))->post(route('admin.inbox.risposta', $compito), $dati);
}

/** I titoli dei compiti della casella delle attività dello studio. */
function icsCasella($test): array
{
    $props = $test->actingAs($test->admin)->get(route('admin.inbox'))->assertOk()->viewData('page')['props'];

    return collect($props['tasks']['data'] ?? $props['tasks'])->pluck('title')->all();
}

/** I conteggi della casella delle attività (tutti, e quelli del condominio). */
function icsConteggi($test): array
{
    $tutti = $test->actingAs($test->admin)->get(route('admin.inbox'))->viewData('page')['props']['counts']['all'];
    $delCondominio = $test->actingAs($test->admin)->get(route('admin.inbox', ['condominio_id' => $test->condominio->id]))->viewData('page')['props']['counts']['all'];

    return [$tutti, $delCondominio];
}

/** I titoli dei compiti dell'agenda nell'Inbox operativa del condominio. */
function icsInboxDelCondominio($test): array
{
    $props = $test->actingAs($test->admin)->get(route('admin.gestionale.index', $test->condominio))->assertOk()->viewData('page')['props'];
    $compiti = $props['inboxTasks'];

    return collect($compiti['data'] ?? $compiti)->pluck('title')->all();
}

/** Il numero della barra laterale. */
function icsBarra($test): int
{
    return (int) $test->actingAs($test->admin)->get(route('admin.inbox'))->viewData('page')['props']['inbox_count'];
}
