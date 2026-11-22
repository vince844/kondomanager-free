<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Ripristino;

use App\Http\Requests\Segnalazione\CreateSegnalazioneRequest;
use App\Models\Segnalazione;
use App\Models\User;

/**
 * La segnalazione (`SegnalazioniEdit.vue`, `SegnalazioneController::update`, con la `FormRequest` della creazione). Nel modulo i dati obbligatori
 * com'è oggi (titolo, testo, priorità, stato, commenti, in evidenza, pubblicata, condominio); fuori l'assegnazione, l'approvazione, i destinatari e «avvisa
 * i destinatari»: con quelle chiavi assenti la pagina non riassegna, non approva e non manda avvisi.
 */
final class ModuloSegnalazione extends ModuloDellaScheda
{
    public function cosa(?int $id): ?object
    {
        return $id === null ? null : Segnalazione::query()->find($id);
    }

    public function rotta(): string
    {
        return 'admin.segnalazioni.update';
    }

    public function parametri(object $cosa): array
    {
        return ['segnalazione' => $cosa];
    }

    public function richiesta(): string
    {
        return CreateSegnalazioneRequest::class;
    }

    public function modulo(object $cosa): array
    {
        /** @var Segnalazione $cosa */
        return [
            'subject' => $cosa->getRawOriginal('subject'),
            'description' => $cosa->getRawOriginal('description'),
            'priority' => $cosa->getRawOriginal('priority'),
            'stato' => $cosa->getRawOriginal('stato'),
            'can_comment' => (bool) $cosa->getRawOriginal('can_comment'),
            'is_featured' => (bool) $cosa->getRawOriginal('is_featured'),
            'is_published' => (bool) $cosa->getRawOriginal('is_published'),
            'condominio_id' => $cosa->getRawOriginal('condominio_id') === null ? null : (int) $cosa->getRawOriginal('condominio_id'),
        ];
    }

    public function scrivi(object $cosa, string $campo, mixed $valore, User $utente): void
    {
        /** @var Segnalazione $cosa */
        $cosa->update([$campo => $valore, 'updated_by' => $utente->id]);
    }
}
