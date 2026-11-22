<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee\Convocazione;

use App\Http\Controllers\Controller;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\PuntoDellOrdineDelGiorno;
use App\Models\Condominio;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\Convocazione\DocumentiDeiPunti;
use App\Support\LimiteCaricamento;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * I documenti di un punto, dal passo «Documenti» della pagina dell'assemblea: la finestra «Aggiungi un documento» (un JSON, chiesto quando si apre e a
 * ogni ricerca), «Aggiungi» (i documenti scelti e il PDF nuovo) e «Togli». Li usa chi gestisce le assemblee; il punto è di questa assemblea
 * (`scopeBindings`), i documenti li rilegge il servizio (R18). I permessi si controllano prima di leggere il corpo.
 */
class DocumentiDeiPuntiController extends Controller
{
    use HandleFlashMessages;

    public function index(Request $request, Condominio $condominio, Assemblea $assemblea, PuntoDellOrdineDelGiorno $punto): JsonResponse
    {
        $this->assicuraLAssemblea($request, $condominio, $assemblea);

        return response()->json(app(DocumentiDeiPunti::class)->perLaFinestra($condominio, $punto, $request->filled('cerca') ? (string) $request->query('cerca') : null));
    }

    public function store(Request $request, Condominio $condominio, Assemblea $assemblea, PuntoDellOrdineDelGiorno $punto): RedirectResponse
    {
        $this->assicuraLAssemblea($request, $condominio, $assemblea);
        $request->validate([
            'documenti' => ['sometimes', 'array', 'max:50'],
            'documenti.*' => ['integer'],
            'file' => ['sometimes', 'nullable', 'file', 'mimes:pdf', 'max:' . LimiteCaricamento::regolaMax()],
        ], [
            'file.mimes' => DocumentiDeiPunti::FRASE_SOLO_PDF,
            'file.max' => 'Il PDF supera ' . LimiteCaricamento::etichetta() . ': è il tetto dei documenti.',
        ]);
        $quanti = app(DocumentiDeiPunti::class)->aggiungi($condominio, $punto, (array) $request->input('documenti', []), $request->file('file'), $request->user());

        return back()->with($this->flashSuccess(match ($quanti) {
            0 => 'Erano già nel punto ' . $punto->numero . ': niente da aggiungere.',
            1 => 'Un documento aggiunto al punto ' . $punto->numero . '.',
            default => $quanti . ' documenti aggiunti al punto ' . $punto->numero . '.',
        }));
    }

    public function destroy(Request $request, Condominio $condominio, Assemblea $assemblea, PuntoDellOrdineDelGiorno $punto, int $documento): RedirectResponse
    {
        $this->assicuraLAssemblea($request, $condominio, $assemblea);
        $tolto = app(DocumentiDeiPunti::class)->togli($punto, $documento, $request->user());

        return back()->with($this->flashSuccess('«' . $tolto->name . '» tolto dal punto ' . $punto->numero . ': resta nei documenti del condominio.'));
    }

    /** L'assemblea è del condominio dell'indirizzo (il punto è dell'assemblea per `scopeBindings`), e chi chiede gestisce le assemblee. */
    private function assicuraLAssemblea(Request $request, Condominio $condominio, Assemblea $assemblea): void
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
    }
}
