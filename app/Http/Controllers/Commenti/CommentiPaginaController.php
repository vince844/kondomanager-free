<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Commenti;

use App\Http\Controllers\Controller;
use App\Http\Requests\Commento\CommentiInBloccoRequest;
use App\Http\Requests\Commento\CommentiIndexRequest;
use App\Models\Condominio;
use App\Services\Commenti\CommentiPaginaService;
use App\Settings\GeneralSettings;
use App\Support\Commenti\RegoleCommenti;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * La pagina «Commenti» del pannello (Coda 657, decisione 442), sul modello di quella degli allegati.
 */
class CommentiPaginaController extends Controller
{
    use HandleFlashMessages;

    public function __construct(private readonly CommentiPaginaService $servizio) {}

    public function index(CommentiIndexRequest $request): InertiaResponse
    {
        $user = $request->user();
        Gate::allowIf(RegoleCommenti::puoAprirePagina($user));

        $filtri = $request->validated();
        $consentite = config('pagination.consentite', [10, 15, 25, 50, 100]);
        $perPage = (int) ($filtri['per_page'] ?? app(GeneralSettings::class)->default_per_page ?: 10);
        if (! in_array($perPage, $consentite, true)) {
            $perPage = (int) (app(GeneralSettings::class)->default_per_page ?: 10);
        }

        $commenti = $this->servizio
            ->queryPerPagina($user, $filtri)
            ->orderByRaw("CASE WHEN commenti.stato = 'in_attesa' THEN 0 ELSE 1 END ASC")
            ->orderByDesc('commenti.created_at')
            ->orderByDesc('commenti.id')
            ->paginate($perPage)
            ->withQueryString();

        $commenti->setCollection(collect($this->servizio->righe($user, $commenti->getCollection())));

        return Inertia::render('commenti/CommentiList', [
            'commenti'  => $commenti,
            'filtri'    => [
                'stato'         => (string) ($filtri['stato'] ?? ''),
                'tipo'          => (string) ($filtri['tipo'] ?? ''),
                'condominio_id' => isset($filtri['condominio_id']) && $filtri['condominio_id'] !== '' ? (int) $filtri['condominio_id'] : null,
                // L'elemento scelto (da «Modera» dell'Action inbox) e il suo nome, letto dalle righe che chi guarda può vedere.
                'elemento'      => isset($filtri['elemento']) && $filtri['elemento'] !== '' ? (int) $filtri['elemento'] : null,
                'elemento_nome' => isset($filtri['elemento']) && $filtri['elemento'] !== '' ? (collect($commenti->items())->first()['elemento'] ?? null) : null,
                'autore'        => (string) ($filtri['autore'] ?? ''),
                'dal'           => (string) ($filtri['dal'] ?? ''),
                'al'            => (string) ($filtri['al'] ?? ''),
            ],
            'condomini' => Condominio::query()->orderBy('nome')->get(['id', 'nome']),
        ]);
    }

    public function approvaInBlocco(CommentiInBloccoRequest $request): RedirectResponse
    {
        return $this->inBlocco($request, 'approva');
    }

    public function nascondiInBlocco(CommentiInBloccoRequest $request): RedirectResponse
    {
        return $this->inBlocco($request, 'nascondi');
    }

    public function mostraInBlocco(CommentiInBloccoRequest $request): RedirectResponse
    {
        return $this->inBlocco($request, 'mostra');
    }

    public function eliminaInBlocco(CommentiInBloccoRequest $request): RedirectResponse
    {
        return $this->inBlocco($request, 'elimina');
    }

    /**
     * @param  'approva'|'nascondi'|'mostra'|'elimina'  $azione
     */
    private function inBlocco(CommentiInBloccoRequest $request, string $azione): RedirectResponse
    {
        $user = $request->user();
        Gate::allowIf(RegoleCommenti::puoAprirePagina($user));

        $esito = $this->servizio->inBlocco($user, $azione, (array) $request->validated('ids'));

        return back()->with($this->messaggioDelBlocco($azione, $esito['fatti'], $esito['rifiutati']));
    }

    /**
     * Il messaggio dice quanti commenti sono stati fatti e quanti no: successo se tutti, avviso se alcuni, errore se nessuno.
     *
     * @return array<string, array{type: string, message: string}>
     */
    private function messaggioDelBlocco(string $azione, int $fatti, int $rifiutati): array
    {
        $chiave = "commenti_pagina.flash.{$azione}";

        if ($fatti === 0) {
            return $this->flashError(trans_choice("{$chiave}_nessuno", $rifiutati, ['count' => $rifiutati]));
        }

        if ($rifiutati === 0) {
            return $this->flashSuccess(trans_choice("{$chiave}_tutti", $fatti, ['count' => $fatti]));
        }

        return $this->flashWarning(__("{$chiave}_alcuni", ['fatti' => $fatti, 'rifiutati' => $rifiutati]));
    }
}
