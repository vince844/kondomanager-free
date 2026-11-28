<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Eventi;

use App\Http\Controllers\Controller;
use App\Models\CategoriaEvento;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class FetchCategorieController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): JsonResponse
    {
        // Le categorie per il filtro dell'agenda, a chi vede l'agenda («Visualizza scadenze in agenda»).
        Gate::allowIf((bool) request()->user()?->hasPermissionTo(\App\Enums\Permission::VIEW_EVENTS->value), __('policies.view_events'));
        $categorie = CategoriaEvento::select('id', 'name')->orderBy('name')->get();

        return response()->json($categorie);
    }
}
