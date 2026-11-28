<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Documenti;

use App\Http\Controllers\Controller;
use App\Models\CategoriaDocumento;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class FetchCategorieController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): JsonResponse
    {
        // Le categorie per il filtro dell'archivio, a chi vede l'archivio («Visualizza documenti archivio»).
        Gate::allowIf((bool) request()->user()?->hasPermissionTo(\App\Enums\Permission::VIEW_ARCHIVE_DOCUMENTS->value), __('policies.view_archive_documents'));
        
        $categorie = CategoriaDocumento::select('id', 'name')->orderBy('name')->get();

        return response()->json($categorie);
    }
}
