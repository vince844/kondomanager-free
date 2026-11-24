<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assemblee\TabellaDiUnGruppoRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Models\Tabella;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\ProposteDellOrdineDelGiorno;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Services\Assemblee\UnitaDelPunto;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Il segno della tabella di un gruppo (Coda 734, il condominio parziale; art. 1123 c. 3 c.c.): l'interruttore «È la tabella di un gruppo» della pagina della
 * tabella e il pulsante «Segna «Box» come tabella di un gruppo» della finestra del punto. Su un punto votato con la tabella segnata votano solo i condòmini
 * delle sue unità ({@see UnitaDelPunto}). Il segno lo mette l'amministratore, mai il programma. La tabella è del condominio (R18); la riga del registro nasce
 * dal modello, sulla storia della tabella. Il segno della tabella che è il gruppo di un'assemblea non ancora chiusa non si toglie (Coda 734, §1.1-bis, 7).
 */
class TabellaDiUnGruppoController extends Controller
{
    use HandleFlashMessages;

    public function __invoke(TabellaDiUnGruppoRequest $request, Condominio $condominio): RedirectResponse
    {
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        /** @var Tabella $tabella */
        $tabella = SchedaDelCondominio::risolvi($condominio, 'tabella', (int) $request->validated('tabella_id'));
        $segno = (bool) $request->validated('di_un_gruppo');
        // Il segno di una tabella che è il gruppo di un'assemblea non chiusa (in preparazione, convocata, in corso) resta: decide chi è convocato e come conta
        // la sala (§1.1-bis, 7).
        $aperta = $segno ? null : Assemblea::query()->where('condominio_id', $condominio->id)->where('ambito', AmbitoAssemblea::GRUPPO->value)
            ->where('tabella_id', $tabella->id)->whereIn('stato', ProposteDellOrdineDelGiorno::STATI_CHE_TENGONO_IL_PUNTO)->orderBy('data')->first();
        if ($aperta !== null) {
            throw ValidationException::withMessages(['di_un_gruppo' => 'La tabella «' . $tabella->nome . '» è il gruppo dell\'assemblea'
                . ($aperta->data !== null ? ' del ' . $aperta->data->format('d/m/Y') : '') . ': togli il segno quando è chiusa.']);
        }
        $tabella->update(['di_un_gruppo' => $segno]);
        $unita = count(UnitaDelPunto::quoteMaggioriDiZero([(int) $tabella->id])[(int) $tabella->id] ?? []);

        return back()->with($this->flashSuccess($segno
            ? '«' . $tabella->nome . '» è la tabella di un gruppo: sui suoi punti votano solo i condòmini delle sue ' . ($unita === 1 ? 'unità' : $unita . ' unità') . '.'
            : 'Tolto il segno: sui punti con «' . $tabella->nome . '» vota tutto il perimetro del punto.'));
    }
}
