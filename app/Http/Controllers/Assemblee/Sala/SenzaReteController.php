<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee\Sala;

use App\Http\Controllers\Controller;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\Sala\SalaSenzaRete;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * L'operaio del browser della sala di un'assemblea ({@see SalaSenzaRete}): un JavaScript che vive nella cartella dell'assemblea (`Service-Worker-Allowed`),
 * che il browser non tiene nella sua cache HTTP (così vede subito una versione nuova). Con «Vede le assemblee», come la pagina della sala che lo registra.
 */
class SenzaReteController extends Controller
{
    public function operaio(Request $request, Condominio $condominio, Assemblea $assemblea, SalaSenzaRete $senzaRete): Response
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        $script = $senzaRete->script($assemblea);
        abort_if($script === null, 404);

        return response($script, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Service-Worker-Allowed' => SalaSenzaRete::ambito($assemblea),
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
