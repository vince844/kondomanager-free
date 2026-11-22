<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee\Convocazione;

use App\Enums\Assemblee\MateriaPunto;
use App\Http\Controllers\Controller;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\Convocazione\FascicoloDellAssemblea;
use App\Services\Assemblee\Convocazione\VersioniDelPreventivo;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Il fascicolo del passo «Documenti» della pagina dell'assemblea (§3 P14 del progetto della tappa 2): «Ferma il preventivo», «Componi il fascicolo», «Uno per persona
 * (.zip)». Il punto del preventivo, se arriva nel corpo, passa da {@see SchedaDelCondominio::risolvi()} ed è di questa assemblea.
 */
class FascicoloController extends Controller
{
    use HandleFlashMessages;

    public function fermaIlPreventivo(Request $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $punto = $request->filled('punto_id')
            ? SchedaDelCondominio::risolvi($condominio, 'punto', (int) $request->input('punto_id'))
            : PuntoDellOrdineDelGiorno::query()->where('assemblea_id', $assemblea->id)->where('materia', MateriaPunto::PREVENTIVO->value)->orderBy('numero')->first();
        abort_if($punto === null || (int) $punto->assemblea_id !== (int) $assemblea->id, 404);
        $versione = app(VersioniDelPreventivo::class)->ferma($assemblea, $punto, $request->user());

        return back()->with($this->flashSuccess('Preventivo fermato: versione ' . $versione->numero . ', con le sue stampe.'));
    }

    public function componi(Request $request, Condominio $condominio, Assemblea $assemblea): Response
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $esito = app(FascicoloDellAssemblea::class)->componi($assemblea, $request->user());

        return ConvocazioneController::pdf($esito['pdf'], $esito['nome'], ['X-Documenti' => (string) $esito['documenti']]);
    }

    public function perPersona(Request $request, Condominio $condominio, Assemblea $assemblea)
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $esito = app(FascicoloDellAssemblea::class)->perPersona($assemblea);

        return response()->download($esito['file'], $esito['nome'], ['Content-Type' => 'application/zip'])->deleteFileAfterSend();
    }
}
