<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Ripristino;

use App\Http\Requests\Gestionale\Scala\UpdateScalaRequest;
use App\Models\Condominio;
use App\Models\Scala;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * La scala (`ScaleEdit.vue`, `ScalaController::update`): il nome, la descrizione (obbligatoria nella pagina: rimettere una descrizione vuota è rifiutato dalla
 * sua regola) e le note. La pagina non ha una policy: il ripristino chiede «Modifica condomini», come per l'unità.
 */
final class ModuloScala extends ModuloDellaScheda
{
    public function cosa(?int $id): ?object
    {
        return $id === null ? null : Scala::query()->find($id);
    }

    public function rotta(): string
    {
        return 'admin.gestionale.scale.update';
    }

    public function parametri(object $cosa): array
    {
        return ['condominio' => Condominio::query()->findOrFail($cosa->condominio_id), 'scala' => $cosa];
    }

    public function richiesta(): string
    {
        return UpdateScalaRequest::class;
    }

    public function puoModificare(User $utente, object $cosa): bool
    {
        return Gate::forUser($utente)->allows('update', Condominio::class);
    }

    public function modulo(object $cosa): array
    {
        $modulo = ['name' => $cosa->getRawOriginal('name'), 'description' => $cosa->getRawOriginal('description'), 'note' => $cosa->getRawOriginal('note')];
        $modulo['palazzina_id'] = $cosa->getRawOriginal('palazzina_id');

        return $modulo;
    }
}
