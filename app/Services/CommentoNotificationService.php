<?php

namespace App\Services;

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Models\Commento;
use App\Models\NotificationPreference;
use App\Models\User;
use Illuminate\Support\Collection;

class CommentoNotificationService
{
    /**
     * Calcola i destinatari della notifica per un nuovo commento.
     *
     * Aggregazione:
     * 1. Creatore dell'entità commentata (es. chi ha aperto la segnalazione)
     * 2. Tutti gli utenti con ruolo admin del condominio
     *    (user → anagrafica → condomini → anagrafiche → users con ACCESS_ADMIN_PANEL)
     * 3. Gli altri partecipanti alla conversazione (chi ha già commentato)
     *
     * Regole di filtro:
     * - L'autore del commento appena creato NON riceve notifica
     * - Nessun duplicato (dedup per user id)
     * - Utenti senza email o sospesi esclusi
     */
    public function destinatari(Commento $commento): Collection
    {
        $utenti = collect();

        // 1. Autore dell'entità commentata
        $commentable = $commento->commentable;
        if ($commentable && isset($commentable->createdBy)) {
            $utenti->push($commentable->createdBy);
        }

        // 2. Amministratori del condominio
        if ($commento->condominio_id) {
            $adminDelCondominio = $this->amministratoriDel($commento->condominio_id);
            $utenti = $utenti->merge($adminDelCondominio);
        }

        // 3. Altri partecipanti alla conversazione
        if ($commentable) {
            $partecipanti = $this->partecipanti($commento);
            $utenti = $utenti->merge($partecipanti);
        }

        $userIds = $utenti->pluck('id')->unique()->toArray();
        $enabledUserIds = NotificationPreference::whereIn('user_id', $userIds)
            ->where('type', NotificationType::NEW_COMMENT->value)
            ->where('enabled', true)
            ->pluck('user_id')
            ->toArray();

        return $utenti
            ->filter()                                               // rimuovi null
            ->unique('id')                                           // dedup
            ->reject(fn (User $u) => $u->id === $commento->user_id) // non notificare l'autore
            ->reject(fn (User $u) => ! $u->email)                   // esclude utenti senza email
            // Filtro permessi: solo chi ha accesso al commento o gli admin
            ->filter(fn (User $u) => $commentable && $commentable->utenteHaAccessoAiCommenti($u) || $u->can(Permission::APPROVE_COMMENTS_SEGNALAZIONI->value))
            // Filtro preferenze utente (opt-in GDPR)
            ->filter(fn (User $u) => in_array($u->id, $enabledUserIds))
            ->values();
    }

    /**
     * Restituisce tutti gli utenti amministratori del condominio dato.
     * Percorso: condominio → anagrafiche → user (con ACCESS_ADMIN_PANEL)
     */
    private function amministratoriDel(int $condominioId): Collection
    {
        return User::whereHas('anagrafica', function ($q) use ($condominioId) {
            $q->whereHas('condomini', fn ($c) => $c->where('condomini.id', $condominioId));
        })
        ->permission(Permission::ACCESS_ADMIN_PANEL->value)
        ->get();
    }

    /**
     * Restituisce tutti gli utenti che hanno già commentato la stessa entità
     * (escluso il commento corrente appena creato).
     */
    private function partecipanti(Commento $commento): Collection
    {
        return User::whereIn('id',
            Commento::where('commentable_type', $commento->commentable_type)
                ->where('commentable_id', $commento->commentable_id)
                ->where('id', '!=', $commento->id)
                ->whereNull('deleted_at')
                ->pluck('user_id')
                ->unique()
        )->get();
    }
}
