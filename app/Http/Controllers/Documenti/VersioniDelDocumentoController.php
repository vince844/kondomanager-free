<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Documenti;

use App\Http\Controllers\Controller;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Documento;
use App\Models\DocumentoVersione;
use App\Models\Fornitore;
use App\Models\Immobile;
use App\Services\Documenti\ArchivioDocumenti;
use App\Traits\HandleFlashMessages;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Lo scaricamento di una versione di prima di un documento (§9 del registro delle modifiche, decisione 559): in sola lettura, dall'archivio dello studio e
 * dalle schede della persona, del fornitore e dell'unità, ognuna con il permesso e la guardia del proprio scaricamento del file di adesso. La versione deve
 * essere di quel documento (404 altrimenti).
 */
class VersioniDelDocumentoController extends Controller
{
    use HandleFlashMessages;

    public function download(Documento $documento, DocumentoVersione $versione)
    {
        Gate::authorize('view', $documento);
        abort_unless($documento->documentable_type === null, 404);

        return $this->scarica($documento, $versione);
    }

    /** Dalla scheda della persona (decisione 559): con il permesso di vedere la persona, il documento della persona dell'indirizzo. */
    public function dallaPersona(Anagrafica $anagrafica, Documento $documento, DocumentoVersione $versione)
    {
        Gate::authorize('view', $anagrafica);
        abort_unless($documento->documentable_type === Anagrafica::class && (int) $documento->documentable_id === (int) $anagrafica->id, 404);

        return $this->scarica($documento, $versione);
    }

    /** Dalla scheda del fornitore (decisione 559): come lo scaricamento del file di adesso, con la scheda del fornitore. */
    public function dalFornitore(Fornitore $fornitore, Documento $documento, DocumentoVersione $versione)
    {
        Gate::authorize('view', $fornitore);
        abort_unless($documento->documentable_type === Fornitore::class && (int) $documento->documentable_id === (int) $fornitore->id, 404);

        return $this->scarica($documento, $versione);
    }

    /** Dalla scheda dell'unità (decisione 559): l'unità del condominio dell'indirizzo, il documento dell'unità, come lo scaricamento di adesso. */
    public function dallUnita(Condominio $condominio, Immobile $immobile, Documento $documento, DocumentoVersione $versione)
    {
        abort_unless((int) $immobile->condominio_id === (int) $condominio->id, 404);
        abort_unless($documento->documentable_type === Immobile::class && (int) $documento->documentable_id === (int) $immobile->id, 404);
        Gate::authorize('view', $documento);

        return $this->scarica($documento, $versione);
    }

    private function scarica(Documento $documento, DocumentoVersione $versione)
    {
        abort_unless((int) $versione->documento_id === (int) $documento->id, 404);

        try {
            $archivio = app(ArchivioDocumenti::class);
            if (! $archivio->esiste($versione->path)) {
                return redirect()->back()->with($this->flashError(__('documenti.file_not_found')));
            }

            return $archivio->scarica($versione->path, $versione->nomeDiScaricamento());
        } catch (\Exception $e) {
            Log::error('Errore nello scaricamento di una versione di prima di un documento', ['versione_id' => $versione->id, 'errore' => $e->getMessage()]);

            return redirect()->back()->with($this->flashError(__('documenti.error_downloading_document')));
        }
    }
}
