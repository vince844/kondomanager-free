<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\RegistroModifiche\Ripristino;

use App\Http\Requests\Comunicazione\CreateComunicazioneRequest;
use App\Models\Comunicazione;
use App\Models\User;

/**
 * La comunicazione (`ComunicazioniEdit.vue`, `ComunicazioneController::update`, con la `FormRequest` della creazione). Nel modulo i dati obbligatori
 * com'è oggi (titolo, testo, priorità, commenti, in evidenza, pubblicata, condomini); fuori la programmazione (la sua chiave, anche vuota, la azzererebbe),
 * i destinatari, «avvisa i destinatari» e la moderazione dei commenti: con quelle chiavi assenti la pagina non le tocca e non manda avvisi. La scrittura
 * del titolo o del testo segna chi l'ha cambiata, come la pagina.
 */
final class ModuloComunicazione extends ModuloDellaScheda
{
    public function cosa(?int $id): ?object
    {
        return $id === null ? null : Comunicazione::query()->find($id);
    }

    public function rotta(): string
    {
        return 'admin.comunicazioni.update';
    }

    public function parametri(object $cosa): array
    {
        return ['comunicazione' => $cosa];
    }

    public function richiesta(): string
    {
        return CreateComunicazioneRequest::class;
    }

    public function modulo(object $cosa): array
    {
        /** @var Comunicazione $cosa */
        return [
            'subject' => $cosa->getRawOriginal('subject'),
            'description' => $cosa->getRawOriginal('description'),
            'priority' => $cosa->getRawOriginal('priority'),
            'can_comment' => (bool) $cosa->getRawOriginal('can_comment'),
            'is_featured' => (bool) $cosa->getRawOriginal('is_featured'),
            'is_published' => (bool) $cosa->getRawOriginal('is_published'),
            'condomini_ids' => $cosa->condomini()->pluck('condomini.id')->map(fn ($id) => (int) $id)->all(),
        ];
    }

    public function scrivi(object $cosa, string $campo, mixed $valore, User $utente): void
    {
        /** @var Comunicazione $cosa */
        $cosa->update([$campo => $valore, 'updated_by' => $utente->id]);
    }
}
