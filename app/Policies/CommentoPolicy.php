<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Policies;

use App\Contracts\Commentable;
use App\Enums\Permission;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Segnalazione;
use App\Models\User;
use App\Support\Commenti\RegoleCommenti;

class CommentoPolicy
{
    /**
     * Determina se l'utente può creare un commento su un'entità commentabile.
     */
    public function create(User $user, Commentable $commentable, ?string $porta = null): bool
    {
        if ($commentable instanceof Segnalazione) {
            return RegoleCommenti::puoScrivereSegnalazione($user, $commentable, $porta);
        }

        if ($commentable instanceof Comunicazione) {
            return RegoleCommenti::puoScrivereInAlmenoUnaDiscussione($user, $commentable);
        }

        return false;
    }

    /**
     * Determina se l'utente può modificare il commento.
     */
    public function update(User $user, Commento $commento): bool
    {
        return RegoleCommenti::puoModificare($user, $commento);
    }

    /**
     * Determina se l'utente può eliminare il commento (soft delete).
     */
    public function delete(User $user, Commento $commento): bool
    {
        return RegoleCommenti::puoEliminare($user, $commento);
    }

    /**
     * Determina se l'utente può moderare (nascondere/approvare) un commento.
     */
    public function moderate(User $user, ?Commento $commento = null): bool
    {
        if ($commento !== null) {
            $elemento = $commento->commentable;
            if ($elemento instanceof Segnalazione || $elemento instanceof Comunicazione) {
                return RegoleCommenti::puoModerare($user, $elemento);
            }
        }

        return RegoleCommenti::haPannello($user)
            && (RegoleCommenti::haPermesso($user, Permission::APPROVE_COMMENTS_SEGNALAZIONI->value)
                || RegoleCommenti::haPermesso($user, Permission::APPROVE_COMMENTS_COMUNICAZIONI->value));
    }
}
