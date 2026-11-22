<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assemblee\CorreggiDataRequest;
use App\Http\Requests\Assemblee\VerbaleRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Condominio;
use App\Models\Documento;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\DeliberaAMano;
use App\Traits\HandleFlashMessages;
use App\Traits\ScaricaEApprovaDocumento;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * La riunione registrata a mano (§3 P6): «Correggi la data» (R2, le schede che usano le sue delibere non cambiano e la pagina le elenca) e il verbale
 * sottoscritto, che si carica, si scarica, si sostituisce (la versione di prima resta, decisione 559) e si toglie. Il verbale è un documento della riunione,
 * fuori dall'archivio e dal portale: lo scarica chi vede le assemblee, da qui; lo carica e lo toglie chi ha «Registra il verbale sottoscritto».
 */
class RiunioneAManoController extends Controller
{
    use HandleFlashMessages, ScaricaEApprovaDocumento;

    public function correggiData(CorreggiDataRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $esito = DeliberaAMano::correggiData($assemblea, (string) $request->validated('giorno'), $request->user());

        return back()->with($this->flashSuccess('Il giorno della riunione è il ' . $esito['assemblea']->data?->format('d/m/Y') . ', con le sue delibere.'
            . ($esito['frase'] !== null ? ' ' . $esito['frase'] . ': le schede non cambiano da sole.' : '')));
    }

    public function caricaVerbale(VerbaleRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::registraIlVerbale($request->user()), __('errors.403_message'));
        $cera = $assemblea->verbale_documento_id !== null;
        DeliberaAMano::caricaVerbale($assemblea, $request->file('verbale'), $request->user());

        return back()->with($this->flashSuccess($cera ? 'Verbale sostituito: la versione di prima resta, in sola lettura.' : 'Verbale sottoscritto caricato.'));
    }

    public function scaricaVerbale(Request $request, Condominio $condominio, Assemblea $assemblea)
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        $documento = $assemblea->verbale_documento_id === null ? null : Documento::query()->find($assemblea->verbale_documento_id);
        abort_if($documento === null || (int) $documento->documentable_id !== (int) $assemblea->id || $documento->documentable_type !== $assemblea->getMorphClass(), 404);

        return $this->scaricaIlDocumento($documento);
    }

    /** Toglie il verbale sottoscritto (con le sue versioni): il primo passo per eliminare una riunione registrata per errore. */
    public function togliVerbale(Request $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::registraIlVerbale($request->user()), __('errors.403_message'));
        DeliberaAMano::togliVerbale($assemblea, $request->user());

        return back()->with($this->flashSuccess('Verbale sottoscritto tolto, con le sue versioni. La riga resta nel registro delle modifiche.'));
    }
}
