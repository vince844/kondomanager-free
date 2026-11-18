<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Le funzioni di supporto per le prove del compito «da moderare» dell'Action inbox: una segnalazione, una comunicazione, un commento e un allegato in attesa, i
 * compiti aperti, la pagina dell'Action inbox letta.
 *
 * NON contiene nessun it() o test — solo funzioni di supporto.
 */

use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Documento;
use App\Models\Evento;
use App\Models\Segnalazione;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

function mdSegnalazione($test, string $oggetto = 'Ascensore fermo', array $extra = []): Segnalazione
{
    return Segnalazione::create($extra + ['subject' => $oggetto, 'description' => 'Si è fermato.', 'created_by' => $test->admin->id,
        'condominio_id' => $test->condominio->id, 'priority' => 'media', 'stato' => 'aperta', 'is_published' => true, 'is_approved' => true,
        'is_private' => false, 'can_comment' => true]);
}

function mdComunicazione($test, string $oggetto = 'Assemblea di giugno', bool $approvata = true): Comunicazione
{
    $c = Comunicazione::create(['subject' => $oggetto, 'description' => 'Testo.', 'created_by' => $test->admin->id, 'priority' => 'media', 'is_featured' => false,
        'is_private' => false, 'is_published' => true, 'is_approved' => $approvata, 'can_comment' => true, 'slug' => 'c-' . uniqid(), 'reference' => uniqid()]);
    $c->condomini()->syncWithoutDetaching([$test->condominio->id]);

    return $c->fresh();
}

/**
 * Un commento come lo scrive il programma: nella discussione del palazzo (per una comunicazione il primo dei suoi palazzi, per una segnalazione il suo).
 * Un commento di una comunicazione senza palazzo né persona non sta in nessuna discussione, e la scheda non lo mostra.
 */
function mdCommento(Segnalazione|Comunicazione $elemento, User $autore, string $stato = 'in_attesa', ?int $condominio = null): Commento
{
    $condominio ??= $elemento instanceof Comunicazione
        ? (int) $elemento->condomini()->orderBy('condomini.id')->value('condomini.id')
        : $elemento->condominio_id;

    return Commento::create(['commentable_type' => $elemento->getMorphClass(), 'commentable_id' => $elemento->getKey(), 'user_id' => $autore->id,
        'condominio_id' => $condominio, 'corpo' => 'Un commento', 'stato' => $stato]);
}

function mdAllegato(Segnalazione|Comunicazione $elemento, User $autore, bool $approvato = false): Documento
{
    return $elemento->allegati()->create(['name' => 'foto.jpg', 'path' => 'allegati/' . uniqid() . '.jpg', 'mime_type' => 'image/jpeg', 'file_size' => 100,
        'created_by' => $autore->id, 'is_published' => true, 'is_approved' => $approvato]);
}

/** I compiti aperti «da moderare» (del tipo nuovo). */
function mdAperti(): \Illuminate\Support\Collection
{
    return Evento::where('tipo', 'da_moderare')->where('is_completed', false)->orderBy('id')->get();
}

function mdProps($test, array $query = [], ?User $chi = null): array
{
    Cache::flush();

    return $test->actingAs($chi ?? $test->admin)->get(route('admin.inbox', $query))->assertOk()->viewData('page')['props'];
}

function mdRiga(array $props, int $id): array
{
    return collect($props['tasks']['data'])->firstWhere('id', $id);
}
