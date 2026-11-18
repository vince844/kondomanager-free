<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Segnalazioni;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commento\StoreCommentoRequest;
use App\Http\Requests\Commento\UpdateCommentoRequest;
use App\Models\Commento;
use App\Models\Segnalazione;
use App\Services\CommentoService;
use App\Support\Commenti\RegoleCommenti;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class CommentoController extends Controller
{
    use HandleFlashMessages;

    public function __construct(private CommentoService $commentoService) {}

    /**
     * Crea un nuovo commento su una segnalazione.
     *
     * POST segnalazioni/{segnalazione}/commenti
     */
    public function store(StoreCommentoRequest $request, Segnalazione $segnalazione): RedirectResponse
    {
        $porta = $request->routeIs('admin.*') ? 'admin' : 'user';
        Gate::authorize('create', [Commento::class, $segnalazione, $porta]);

        try {
            $commento = $this->commentoService->crea(
                commentable: $segnalazione,
                autore:      $request->user(),
                corpo:       $request->validated('corpo'),
            );

            $messaggio = $commento->eInAttesa()
                ? 'Commento inviato. Sarà visibile dopo l\'approvazione di un amministratore.'
                : 'Commento pubblicato con successo.';

            return back()->with($this->flashSuccess($messaggio));

        } catch (\Throwable $e) {
            Log::error('Errore creazione commento: ' . $e->getMessage());
            return back()->with($this->flashError('Impossibile pubblicare il commento. Riprova.'));
        }
    }

    /**
     * Modifica il testo di un proprio commento.
     *
     * PATCH commenti/{commento}
     */
    public function update(UpdateCommentoRequest $request, Commento $commento): RedirectResponse
    {
        Gate::authorize('update', $commento);

        try {
            $tornaInAttesa = $this->commentoService->aggiorna($commento, $request->validated('corpo'), $request->user());
            $messaggio = $tornaInAttesa
                ? 'Commento modificato. Tornerà visibile dopo l\'approvazione.'
                : 'Commento modificato.';

            return back()->with($this->flashSuccess($messaggio));

        } catch (\Throwable $e) {
            Log::error('Errore modifica commento ID ' . $commento->id . ': ' . $e->getMessage());
            return back()->with($this->flashError('Impossibile modificare il commento. Riprova.'));
        }
    }

    /**
     * Elimina (soft delete) un proprio commento.
     *
     * DELETE commenti/{commento}
     */
    public function destroy(Commento $commento): RedirectResponse
    {
        Gate::authorize('delete', $commento);

        try {
            $commento->delete();
            return back()->with($this->flashSuccess('Commento eliminato.'));

        } catch (\Throwable $e) {
            Log::error('Errore eliminazione commento ID ' . $commento->id . ': ' . $e->getMessage());
            return back()->with($this->flashError('Impossibile eliminare il commento. Riprova.'));
        }
    }

    /**
     * Approva un commento in_attesa rendendolo pubblicamente visibile.
     *
     * POST commenti/{commento}/approva
     */
    public function approva(Commento $commento): RedirectResponse
    {
        Gate::allowIf(RegoleCommenti::puoApprovare(request()->user(), $commento));

        try {
            $this->commentoService->approva($commento, request()->user());
            return back()->with($this->flashSuccess('Commento approvato e pubblicato.'));

        } catch (\Throwable $e) {
            Log::error('Errore approvazione commento ID ' . $commento->id . ': ' . $e->getMessage());
            return back()->with($this->flashError('Impossibile approvare il commento. Riprova.'));
        }
    }

    /**
     * Nasconde un commento pubblicato (moderazione post-pubblicazione).
     *
     * POST commenti/{commento}/modera
     */
    public function modera(Commento $commento): RedirectResponse
    {
        Gate::allowIf(RegoleCommenti::puoNascondere(request()->user(), $commento));

        try {
            $this->commentoService->modera($commento, request()->user());
            return back()->with($this->flashSuccess('Commento nascosto.'));

        } catch (\Throwable $e) {
            Log::error('Errore moderazione commento ID ' . $commento->id . ': ' . $e->getMessage());
            return back()->with($this->flashError('Impossibile nascondere il commento. Riprova.'));
        }
    }

    /**
     * Rimette visibile un commento nascosto (decisione 456).
     *
     * POST commenti/{commento}/mostra
     */
    public function mostra(Commento $commento): RedirectResponse
    {
        Gate::allowIf(RegoleCommenti::puoMostrareDiNuovo(request()->user(), $commento));

        try {
            $this->commentoService->mostraDiNuovo($commento, request()->user());
            return back()->with($this->flashSuccess('Commento di nuovo visibile.'));

        } catch (\Throwable $e) {
            Log::error('Errore nel mostrare di nuovo il commento ID ' . $commento->id . ': ' . $e->getMessage());
            return back()->with($this->flashError('Impossibile mostrare di nuovo il commento. Riprova.'));
        }
    }

    /**
     * Abilita o disabilita i commenti su una segnalazione (toggle per-segnalazione).
     *
     * PATCH segnalazioni/{segnalazione}/commenti/toggle
     */
    public function toggle(Segnalazione $segnalazione): RedirectResponse
    {
        Gate::authorize('update', $segnalazione);

        try {
            $segnalazione->update(['can_comment' => ! $segnalazione->can_comment]);

            $messaggio = $segnalazione->can_comment
                ? 'Commenti abilitati su questa segnalazione.'
                : 'Commenti disabilitati. I commenti esistenti restano visibili.';

            return back()->with($this->flashSuccess($messaggio));

        } catch (\Throwable $e) {
            Log::error('Errore toggle commenti segnalazione ID ' . $segnalazione->id . ': ' . $e->getMessage());
            return back()->with($this->flashError('Impossibile modificare le impostazioni dei commenti.'));
        }
    }

    public function impostazioni(\Illuminate\Http\Request $request, Segnalazione $segnalazione): RedirectResponse
    {
        Gate::authorize('update', $segnalazione);

        $validated = $request->validate([
            'can_comment'       => 'required|boolean',
            'commenti_moderati' => 'required|boolean',
        ]);

        // «Commenti in moderazione» la cambia solo chi modera (`RegoleCommenti::puoImpostareModerazione`): per gli altri il campo
        // si ignora, e «Consenti commenti» si salva lo stesso.
        $segnalazione->update(array_merge(
            ['can_comment' => (bool) $validated['can_comment']],
            RegoleCommenti::puoImpostareModerazione($request->user(), $segnalazione)
                ? ['commenti_moderati' => (bool) $validated['commenti_moderati']]
                : [],
        ));

        return back()->with($this->flashSuccess('Impostazioni commenti aggiornate.'));
    }
}
