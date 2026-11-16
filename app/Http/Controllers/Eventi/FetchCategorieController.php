<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Eventi;

use App\Http\Controllers\Controller;
use App\Models\CategoriaEvento;
use Illuminate\Http\JsonResponse;

class FetchCategorieController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(): JsonResponse
    {
        $categorie = CategoriaEvento::select('id', 'name')->orderBy('name')->get();

        return response()->json($categorie);
    }
}
