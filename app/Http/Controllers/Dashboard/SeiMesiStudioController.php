<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Services\Gestionale\Debiti\SeiMesiPerAgire;
use Illuminate\Http\JsonResponse;

/**
 * Un condominio nella lista dello studio dei sei mesi per agire (la colonna, la card e il cassetto dell'Action inbox). La pagina si apre con i soli condomini
 * da calcolare, e poi li chiede uno alla volta, in fila: ogni richiesta pesa quanto il suo condominio, la memoria non cresce con lo studio, e la pagina dice a
 * che punto è («12 su 40»). Il riassunto è quello del riquadro del condominio ({@see SeiMesiPerAgire::perLoStudio()}), con le stesse persone aperte, le stesse cifre e lo
 * stesso compito. Non scrive niente; lo apre chi apre il pannello, come la dashboard di ogni condominio.
 */
final class SeiMesiStudioController extends Controller
{
    public function __invoke(Condominio $condominio, SeiMesiPerAgire $seiMesi): JsonResponse
    {
        return response()->json($seiMesi->perLoStudio($condominio));
    }
}
