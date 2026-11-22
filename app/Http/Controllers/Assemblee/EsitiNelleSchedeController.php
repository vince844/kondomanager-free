<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Services\Assemblee\Seguito\EsitiNelleSchede;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Gli esiti delle delibere sulle schede di arrivo (P4 del seguito delle delibere): «Respinto dall'assemblea del 15/12/2026, punto 2 (verbale n. 42).» sul
 * piano, «Ratifica respinta …» sulla fattura, «Rinviato …», e i segni delle delibere che la scheda usa («Revocata il …», «Annullata dal giudice il …»), con
 * l'assemblea da aprire. Una lettura sola per le otto schede, che il componente della pagina chiede quando si monta: così ogni pagina cambia di una riga.
 *
 * Per chi vede le assemblee; la scheda è del condominio della rotta, altrimenti 404. Solo frasi: nessuna conseguenza nei conti.
 */
class EsitiNelleSchedeController extends Controller
{
    /** Le schede che dicono l'esito della delibera che le riguarda. */
    public const SCHEDE = ['rendiconto_versione', 'piano_rate', 'fattura_passiva', 'accordo_rientro', 'dispensa_sei_mesi', 'regola_recupero', 'cassa', 'gestione'];

    public function __invoke(Request $request, Condominio $condominio, string $tipo, int $id): JsonResponse
    {
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        $scheda = $this->assicuraLaScheda($condominio, $tipo, $id);

        return response()->json(['esiti' => EsitiNelleSchede::perLaScheda($scheda)]);
    }

    /** La scheda è una delle otto, ed è del condominio dell'indirizzo; altrimenti 404. */
    private function assicuraLaScheda(Condominio $condominio, string $tipo, int $id): \Illuminate\Database\Eloquent\Model
    {
        abort_unless(in_array($tipo, self::SCHEDE, true), 404);

        return SchedaDelCondominio::cerca($condominio, $tipo, $id) ?? abort(404);
    }
}
