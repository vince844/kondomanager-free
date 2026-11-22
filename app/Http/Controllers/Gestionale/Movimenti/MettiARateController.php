<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Gestionale\Movimenti;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Models\Gestionale\FatturaPassiva;
use App\Services\Gestionale\SpesaDaMettereARate;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Coda 689, «Metti a rate questa spesa» dal menu della riga dell'elenco delle fatture: la scheda giusta del nuovo piano, con tutto già scelto
 * ({@see SpesaDaMettereARate}). Se non resta niente da mettere a rate, la pagina della fattura con la frase e «Apri il piano». Con i permessi della pagina della fattura (le rotte del gestionale).
 */
class MettiARateController extends Controller
{
    use HandleFlashMessages;

    public function __invoke(Request $request, Condominio $condominio, FatturaPassiva $fattura): RedirectResponse
    {
        abort_unless((int) $fattura->condominio_id === (int) $condominio->id, 404);
        $spesa = SpesaDaMettereARate::perLaFattura($fattura, $request->user());
        if ($spesa !== null && $spesa['url'] !== null) {
            return redirect()->to($spesa['url']);
        }

        return to_route('admin.gestionale.fatture.show', ['condominio' => $condominio->id, 'fattura' => $fattura->id])
            ->with($this->flashInfo($spesa === null ? 'Questa spesa non si mette a rate: è una nota di credito, è stornata, o la sua gestione non si conosce.'
                : $spesa['frase'] . '.'));
    }
}
