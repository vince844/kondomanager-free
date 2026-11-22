<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Assemblee\Sala;

use App\Http\Controllers\Controller;
use App\Http\Requests\Assemblee\Sala\DelegaRicevutaRequest;
use App\Models\Assemblee\Assemblea;
use App\Models\Assemblee\DelegaDellAssemblea;
use App\Models\Condominio;
use App\Models\Documento;
use App\Services\Documenti\ArchivioDocumenti;
use App\Services\Assemblee\AccessoAlleAssemblee;
use App\Services\Assemblee\Sala\DelegheDellAssemblea;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * «Deleghe ricevute» nel passo «In sala» (visite Inertia, non gesti): la delega su carta prima dell'assemblea, con la scansione facoltativa, e la revoca,
 * con «Gestisce le assemblee»; la scansione da aprire, con «Vede le assemblee». La guardia del condominio dell'assemblea e della delega.
 */
class DelegheController extends Controller
{
    use HandleFlashMessages;

    public function __construct(private readonly DelegheDellAssemblea $deleghe) {}

    public function store(DelegaRicevutaRequest $request, Condominio $condominio, Assemblea $assemblea): RedirectResponse
    {
        abort_unless($assemblea->condominio_id === $condominio->id, 404);
        $delega = $this->deleghe->ricevuta($assemblea, $request->validated(), $request->user(), $request->file('scansione'));

        return $delega->eValida()
            ? back()->with($this->flashSuccess('Delega di ' . $delega->delegante_nome . ' a ' . $delega->delegato_nome . ' segnata.'))
            : back()->with($this->flashError($delega->motivo ?? 'Delega rifiutata.'));
    }

    public function revoca(Request $request, Condominio $condominio, Assemblea $assemblea, DelegaDellAssemblea $delegaRicevuta): RedirectResponse
    {
        $delega = $delegaRicevuta;
        abort_unless($assemblea->condominio_id === $condominio->id && (int) $delega->assemblea_id === (int) $assemblea->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::gestisce($request->user()), __('errors.403_message'));
        $this->deleghe->revoca($assemblea, $delega, (string) $request->input('motivo', ''), $request->user(), now());

        return back()->with($this->flashSuccess('Delega di ' . $delega->delegante_nome . ' revocata.'));
    }

    /** La scansione della delega, aperta nel browser («Apri» nel passo «In sala»): con «Vede le assemblee». Senza scansione, o di un'altra assemblea, 404. */
    public function scansione(Request $request, Condominio $condominio, Assemblea $assemblea, DelegaDellAssemblea $delegaRicevuta): StreamedResponse
    {
        $delega = $delegaRicevuta;
        abort_unless($assemblea->condominio_id === $condominio->id && (int) $delega->assemblea_id === (int) $assemblea->id, 404);
        Gate::allowIf(AccessoAlleAssemblee::vede($request->user()), __('errors.403_message'));
        $documento = $delega->documento_id === null ? null : Documento::query()->whereKey($delega->documento_id)->first();
        $archivio = app(ArchivioDocumenti::class);
        abort_unless($documento !== null && $archivio->esiste($documento->path), 404);

        return $archivio->apri($documento->path, $documento->nomeDiScaricamento(), (string) $documento->mime_type);
    }
}
