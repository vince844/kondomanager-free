<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee\Sala;

use App\Http\Controllers\Controller;
use App\Models\Condominio;
use App\Services\Assemblee\Sala\ImpostazioniDellaSala;
use App\Services\RegistroModifiche\AccessoAlRegistro;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Le impostazioni della sala. Dello studio (solo l'amministratore): la delega oltre il quinto, il voto discorde dei comproprietari e i giorni di posta. Del
 * condominio (chi modifica il condominio): le teste nel caso misto, ferma mentre un'assemblea del condominio è in corso. Ogni cambio è una riga del registro.
 */
class ImpostazioniDellaSalaController extends Controller
{
    use HandleFlashMessages;

    /** La card «Assemblee» delle impostazioni dello studio. */
    public function index(Request $request): Response
    {
        Gate::allowIf($request->user() !== null && AccessoAlRegistro::eAmministratore($request->user()), __('errors.403_message'));

        return Inertia::render('impostazioni/ImpostazioniAssemblee', ['impostazioni' => ImpostazioniDellaSala::delloStudio()]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::allowIf($request->user() !== null && AccessoAlRegistro::eAmministratore($request->user()), __('errors.403_message'));
        $dati = $request->validate([
            'delega_oltre_il_quinto' => ['required', Rule::in(ImpostazioniDellaSala::QUINTO)],
            'voto_discorde_comproprietari' => ['required', Rule::in(ImpostazioniDellaSala::DISCORDE)],
            // I giorni di posta (decisione 565) si cambiano dalla stessa card; senza, restano com'erano.
            'giorni_di_posta' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ], ['required' => 'Scegli una delle due.', 'in' => 'Scegli una delle due.', 'giorni_di_posta.*' => 'I giorni di posta vanno da 1 a 10.']);
        ImpostazioniDellaSala::salvaDelloStudio($dati);

        return back()->with($this->flashSuccess('Impostazioni delle assemblee salvate.'));
    }

    /** Le teste nel caso misto, del condominio. */
    public function teste(Request $request, Condominio $condominio): RedirectResponse
    {
        Gate::authorize('update', $condominio);
        $dati = $request->validate(['assemblee_teste' => ['required', Rule::in(ImpostazioniDellaSala::TESTE)]],
            ['required' => 'Scegli come contano le teste.', 'in' => 'Scegli come contano le teste.']);
        ImpostazioniDellaSala::salvaLeTeste($condominio, (string) $dati['assemblee_teste'], $request->user());

        return back()->with($this->flashSuccess('Le teste nel caso misto: salvate per ' . $condominio->nome . '.'));
    }
}
