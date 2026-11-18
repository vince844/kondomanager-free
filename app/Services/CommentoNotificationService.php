<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\NotificationPreference;
use App\Models\Segnalazione;
use App\Models\User;
use App\Support\Commenti\RegoleCommenti;
use Illuminate\Support\Collection;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class CommentoNotificationService
{
    /**
     * Calcola i destinatari della notifica per un commento pubblicato (alla nascita o all'approvazione).
     *
     * A chi:
     * - l'autore dell'elemento commentabile;
     * - gli utenti con il pannello che hanno un'anagrafica nei palazzi della discussione: quello della segnalazione, quello della
     *   persona, tutti i palazzi destinatari per la discussione unica di una comunicazione (decisione 458); senza, tutti quelli con il pannello;
     * - chi ha già commentato nella stessa discussione (nella discussione unica, di qualunque palazzo, con un commento che ci resta:
     *   decisione 459);
     * - la persona della discussione (se discussione per persona);
     *
     * Filtri:
     * - nessuno, se il commento è scritto da un palazzo che è stato tolto dai destinatari (decisione 459): non si vede;
     * - solo chi vede quella discussione;
     * - mai l'autore del commento né chi l'ha approvato;
     * - preferenza «nuovo commento» del tipo (segnalazione o comunicazione).
     *
     * @param  array<int, int>  $escludiUserIds
     * @return Collection<int, User>
     */
    public function destinatariPubblicato(Commento $commento, array $escludiUserIds = []): Collection
    {
        $commentable = $commento->commentable;
        if (! $commentable || ! RegoleCommenti::restaFraIDestinatari($commento, $commentable)) {
            return collect();
        }

        $prefTipo = $commentable instanceof Segnalazione
            ? NotificationType::NEW_COMMENT->value
            : NotificationType::NEW_COMMUNICATION_COMMENT->value;

        $candidateIds = collect();

        // 1. L'autore dell'elemento
        if ($commentable->created_by !== null) {
            $candidateIds->push((int) $commentable->created_by);
        }

        // 2. Gli utenti con il pannello che hanno un'anagrafica nei palazzi della discussione. La discussione unica di una comunicazione
        //    è di tutti i palazzi destinatari (decisione 458), non del palazzo di chi scrive, che lo studio senza anagrafica non ha.
        $palazziIds = match (true) {
            $commentable instanceof Segnalazione => array_filter([$commentable->condominio_id]),
            $commento->discussione_user_id !== null => array_filter([$commento->condominio_id]),
            default => $commentable->condomini()->pluck('condomini.id')->all(),
        };

        if ($palazziIds !== []) {
            try {
                $panelPalazzoUserIds = User::query()
                    ->whereHas('anagrafica.condomini', fn ($q) => $q->whereIn('condomini.id', $palazziIds))
                    ->permission(Permission::ACCESS_ADMIN_PANEL->value)
                    ->pluck('id');
                $candidateIds = $candidateIds->merge($panelPalazzoUserIds);
            } catch (PermissionDoesNotExist) {
            }
        } else {
            try {
                $panelUserIds = User::query()
                    ->permission(Permission::ACCESS_ADMIN_PANEL->value)
                    ->pluck('id');
                $candidateIds = $candidateIds->merge($panelUserIds);
            } catch (PermissionDoesNotExist) {
            }
        }

        // 3. Chi ha già commentato nella stessa discussione
        $queryCommenti = Commento::query()
            ->where('commentable_type', $commentable->getMorphClass())
            ->where('commentable_id', $commentable->getKey())
            ->where('id', '!=', $commento->id)
            ->whereNull('deleted_at');

        if ($commentable instanceof Segnalazione) {
            // Unica discussione per la segnalazione
        } else {
            if ($commento->discussione_user_id !== null) {
                $queryCommenti->where('discussione_user_id', $commento->discussione_user_id);
            } else {
                // La discussione unica dei palazzi: chi ha commentato da qualunque palazzo (decisione 458), con un commento che ci resta
                // (decisione 459): chi aveva scritto solo da un palazzo poi tolto non partecipa più.
                $queryCommenti->whereNull('discussione_user_id');
                RegoleCommenti::soloQuelliCheRestanoFraIDestinatari($queryCommenti);
            }
        }

        $candidateIds = $candidateIds->merge($queryCommenti->pluck('user_id'));

        // 4. La persona della discussione
        if ($commento->discussione_user_id !== null) {
            $candidateIds->push((int) $commento->discussione_user_id);
        }

        $esclusioni = array_map('intval', array_merge([(int) $commento->user_id], $escludiUserIds));

        $filteredIds = $candidateIds
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->reject(fn (int $id) => in_array($id, $esclusioni, true))
            ->values();

        if ($filteredIds->isEmpty()) {
            return collect();
        }

        $utenti = User::query()
            ->whereIn('id', $filteredIds)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();

        $chiaveDiscussione = RegoleCommenti::chiaveDelCommento($commento, $commentable);

        return $utenti->filter(function (User $user) use ($commentable, $prefTipo, $chiaveDiscussione) {
            if (! $this->preferenzaAttiva($user, $prefTipo)) {
                return false;
            }

            $porta = RegoleCommenti::haPannello($user) ? 'admin' : 'user';
            $visibili = RegoleCommenti::discussioniVisibili($user, $commentable, $porta);

            return $visibili->pluck('chiave')->contains($chiaveDiscussione);
        })->values();
    }

    /**
     * Calcola i destinatari della notifica per un commento in attesa di approvazione.
     *
     * A chi:
     * - chi ha il pannello e «Approva commenti» del tipo;
     * - apre la scheda dal pannello;
     * - non è l'autore del commento;
     * - preferenza «commento in attesa» del tipo attiva.
     *
     * @return Collection<int, User>
     */
    public function destinatariInAttesa(Commento $commento): Collection
    {
        $commentable = $commento->commentable;
        if (! $commentable) {
            return collect();
        }

        $permessoApprova = RegoleCommenti::permesso($commentable, 'approva');
        $prefTipo = $commentable instanceof Segnalazione
            ? NotificationType::COMMENT_UNDER_MODERATION->value
            : NotificationType::COMMUNICATION_COMMENT_UNDER_MODERATION->value;

        try {
            $candidati = User::query()
                ->whereKeyNot($commento->user_id)
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->permission(Permission::ACCESS_ADMIN_PANEL->value)
                ->permission($permessoApprova)
                ->get();
        } catch (PermissionDoesNotExist) {
            return collect();
        }

        return $candidati->filter(function (User $user) use ($commentable, $prefTipo) {
            if (! $this->preferenzaAttiva($user, $prefTipo)) {
                return false;
            }

            return RegoleCommenti::apreSchedaPannello($user, $commentable);
        })->values();
    }

    /**
     * Calcola i destinatari della notifica per un commento modificato che torna in attesa.
     *
     * A chi:
     * - chi ha il pannello e «Approva commenti» del tipo;
     * - apre la scheda dal pannello;
     * - non è l'autore del commento;
     * - preferenza «commento modificato in attesa» del tipo attiva.
     *
     * @return Collection<int, User>
     */
    public function destinatariModificatoInAttesa(Commento $commento): Collection
    {
        $commentable = $commento->commentable;
        if (! $commentable) {
            return collect();
        }

        $permessoApprova = RegoleCommenti::permesso($commentable, 'approva');
        $prefTipo = $commentable instanceof Segnalazione
            ? NotificationType::COMMENT_EDITED_UNDER_MODERATION->value
            : NotificationType::COMMUNICATION_COMMENT_EDITED_UNDER_MODERATION->value;

        try {
            $candidati = User::query()
                ->whereKeyNot($commento->user_id)
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->permission(Permission::ACCESS_ADMIN_PANEL->value)
                ->permission($permessoApprova)
                ->get();
        } catch (PermissionDoesNotExist) {
            return collect();
        }

        return $candidati->filter(function (User $user) use ($commentable, $prefTipo) {
            if (! $this->preferenzaAttiva($user, $prefTipo)) {
                return false;
            }

            return RegoleCommenti::apreSchedaPannello($user, $commentable);
        })->values();
    }

    /**
     * Metodo di comodo per retrocompatibilità.
     *
     * @return Collection<int, User>
     */
    public function destinatari(Commento $commento): Collection
    {
        return $this->destinatariPubblicato($commento, [(int) $commento->user_id]);
    }

    /**
     * Verifica se un utente ha abilitato la preferenza di notifica indicata.
     */
    public function preferenzaAttiva(User $user, string $tipo): bool
    {
        return NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('type', $tipo)
            ->where('enabled', true)
            ->exists();
    }
}
