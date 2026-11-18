<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Comunicazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Comunicazione\StoreComunicazioneCommentoRequest;
use App\Http\Requests\Comunicazione\UpdateComunicazioneCommentiImpostazioniRequest;
use App\Models\Comunicazione;
use App\Services\CommentoService;
use App\Support\Commenti\RegoleCommenti;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ComunicazioneCommentoController extends Controller
{
    use HandleFlashMessages;

    public function __construct(private readonly CommentoService $commentoService) {}

    public function store(StoreComunicazioneCommentoRequest $request, Comunicazione $comunicazione): RedirectResponse
    {
        $chiave = (string) $request->validated('discussione');
        $porta = $request->routeIs('admin.*') ? 'admin' : 'user';

        Gate::allowIf(RegoleCommenti::puoScrivereInDiscussione($request->user(), $comunicazione, $chiave, $porta));

        $info = RegoleCommenti::risolviDiscussione($comunicazione, $chiave, $request->user());
        if ($info === null) {
            abort(404);
        }

        $commento = $this->commentoService->crea(
            commentable:        $comunicazione,
            autore:             $request->user(),
            corpo:              (string) $request->validated('corpo'),
            condominioId:       $info['condominio_id'],
            discussioneUserId:  $info['discussione_user_id'],
        );

        $messaggio = $commento->eInAttesa()
            ? 'Commento inviato. Sarà visibile dopo l\'approvazione di un amministratore.'
            : 'Commento pubblicato con successo.';

        return back()->with($this->flashSuccess($messaggio));
    }

    public function impostazioni(UpdateComunicazioneCommentiImpostazioniRequest $request, Comunicazione $comunicazione): RedirectResponse
    {
        Gate::authorize('update', $comunicazione);

        // «Commenti in moderazione» la cambia solo chi modera (`RegoleCommenti::puoImpostareModerazione`): per gli altri il campo
        // si ignora, e «Consenti commenti» si salva lo stesso.
        $comunicazione->update(array_merge(
            ['can_comment' => (bool) $request->validated('can_comment')],
            RegoleCommenti::puoImpostareModerazione($request->user(), $comunicazione)
                ? ['commenti_moderati' => (bool) $request->validated('commenti_moderati')]
                : [],
        ));

        return back()->with($this->flashSuccess('Impostazioni commenti aggiornate.'));
    }
}
