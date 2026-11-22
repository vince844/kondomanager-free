<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Ripristino;

use App\Http\Requests\Documento\UpdateDocumentoRequest;
use App\Models\Documento;
use App\Models\User;

/**
 * Il documento dell'archivio (`DocumentiEdit.vue`, `DocumentoController::update`): il nome e la descrizione bastano, le altre chiavi assenti la pagina
 * non le tocca (niente file, niente destinatari, niente avvisi). Due regole del controller, fuori dalla `FormRequest`: un documento di una persona o di un
 * fornitore non si modifica da qui (si modifica dalla sua scheda), e la descrizione vuota non si salva (il controller tiene quella di prima).
 */
final class ModuloDocumento extends ModuloDellaScheda
{
    public function cosa(?int $id): ?object
    {
        return $id === null ? null : Documento::query()->find($id);
    }

    public function rotta(): string
    {
        return 'admin.documenti.update';
    }

    public function parametri(object $cosa): array
    {
        return ['documento' => $cosa];
    }

    public function richiesta(): string
    {
        return UpdateDocumentoRequest::class;
    }

    public function modulo(object $cosa): array
    {
        /** @var Documento $cosa */
        return ['name' => $cosa->getRawOriginal('name'), 'description' => $cosa->getRawOriginal('description')];
    }

    public function nonAmmesso(object $cosa, string $campo, mixed $valore): ?string
    {
        /** @var Documento $cosa */
        if ($cosa->documentable_type !== null) {
            return 'Il documento di una persona o di un fornitore si cambia dalla sua scheda: da qui non si rimette.';
        }
        if ($campo === 'description' && ($valore === null || trim((string) $valore) === '')) {
            return 'La pagina del documento non lascia vuota la descrizione, e il ripristino segue la stessa regola: scrivi la descrizione dalla scheda.';
        }

        return null;
    }

    public function scrivi(object $cosa, string $campo, mixed $valore, User $utente): void
    {
        /** @var Documento $cosa */
        $cosa->update([$campo => $valore, 'updated_by' => $utente->id]);
    }
}
