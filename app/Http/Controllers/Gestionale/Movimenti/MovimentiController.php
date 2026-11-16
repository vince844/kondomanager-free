<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use Illuminate\Http\RedirectResponse; // <--- Importante
use Inertia\Inertia;
use Inertia\Response;

class MovimentiController extends Controller
{
    /**
     * Entry point principale dei Movimenti.
     */
    public function index(Condominio $condominio): Response|RedirectResponse // <--- Aggiornato qui
    {
        // --- LOGICA FUTURA DASHBOARD ---
        /*
        return Inertia::render('gestionale/movimenti/Dashboard', [
            'condominio' => $condominio,
            // ...
        ]);
        */
        
        // --- LOGICA ATTUALE (Redirect alla prima tab) ---
        return to_route('admin.gestionale.movimenti-rate.index', $condominio);
    }
}