<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assemblee\TabellaDiProprietaRequest;
use App\Models\Condominio;
use App\Models\Tabella;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Services\Assemblee\TabellaDiProprieta;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * La tabella di proprietà generale di Via Roma, di una palazzina o di una scala (§3 P12, R14): l'interruttore della tabella e «Cambia» nella pagina
 * dell'assemblea. Il perimetro e la tabella sono del condominio (R18). Una riga del registro sulla scheda del perimetro.
 */
class TabellaDiProprietaController extends Controller
{
    use HandleFlashMessages;

    public function __invoke(TabellaDiProprietaRequest $request, Condominio $condominio): RedirectResponse
    {
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $tipo = (string) $request->validated('perimetro_tipo');
        $perimetro = $tipo === 'condominio' ? $condominio : SchedaDelCondominio::risolvi($condominio, $tipo, (int) $request->validated('perimetro_id'));
        $tabella = $request->validated('tabella_id') === null ? null
            : SchedaDelCondominio::risolvi($condominio, 'tabella', (int) $request->validated('tabella_id'));
        /** @var Tabella|null $tabella */
        TabellaDiProprieta::scegli($perimetro, $tabella);
        $di = match ($tipo) {
            'palazzina' => 'della palazzina ' . $perimetro->getAttribute('name'),
            'scala' => 'della scala ' . $perimetro->getAttribute('name'),
            default => 'di ' . $condominio->nome,
        };

        return back()->with($this->flashSuccess($tabella === null ? 'Tolta la tabella di proprietà generale ' . $di . '.'
            : '«' . $tabella->nome . '» è la tabella di proprietà generale ' . $di . '.'));
    }
}
