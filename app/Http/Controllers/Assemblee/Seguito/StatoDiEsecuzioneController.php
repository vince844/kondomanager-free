<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee\Seguito;

use App\Http\Controllers\Controller;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\Seguito\StampaDelloStatoDiEsecuzione;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * La stampa «Lo stato di esecuzione delle delibere» di un'assemblea (§8 del progetto della tappa 5): si legge con «Vede le assemblee», come il passo 6 e i
 * documenti; l'assemblea è del condominio della rotta (R13).
 */
class StatoDiEsecuzioneController extends Controller
{
    public function stampa(Request $request, Condominio $condominio, Assemblea $assemblea, StampaDelloStatoDiEsecuzione $stampa): Response
    {
        abort_unless((int) $assemblea->condominio_id === (int) $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));

        return response($stampa->pdf($assemblea))
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . StampaDelloStatoDiEsecuzione::nomeDelFile($assemblea) . '"');
    }
}
