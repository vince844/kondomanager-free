<?php

namespace App\Services\Allegati;

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Models\Commento;
use App\Models\Comunicazione;
use App\Models\Documento;
use App\Models\NotificationPreference;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Allegati\AllegatiComunicazioneNotification;
use App\Notifications\Allegati\AllegatoApprovatoNotification;
use App\Notifications\Allegati\AllegatoEliminatoNotification;
use App\Notifications\Allegati\AllegatoInAttesaNotification;
use App\Notifications\Allegati\NuovoAllegatoNotification;
use App\Support\Allegati\RegoleAllegati;
use Illuminate\Support\Collection;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

final class AllegatoNotificationService
{
    /**
     * @param  Collection<int, Documento>  $creati
     */
    public function dopoCaricamento(
        User $chiCarica,
        Segnalazione|Comunicazione $elemento,
        Collection $creati,
        bool $inCreazione = false,
    ): void {
        if ($creati->isEmpty()) {
            return;
        }

        $inAttesa = $creati->filter(fn (Documento $d) => ! $d->is_approved)->values();
        $pubblicati = $creati->filter(fn (Documento $d) => (bool) $d->is_approved)->values();

        if ($inAttesa->isNotEmpty()) {
            $this->notificaInAttesa($chiCarica, $elemento, $inAttesa);
        }

        if (! $inCreazione && $pubblicati->isNotEmpty()) {
            $this->notificaPubblicati($elemento, $pubblicati, [(int) $chiCarica->id]);
        }
    }

    /**
     * @param  Collection<int, Documento>  $approvati
     */
    public function dopoApprovazione(
        User $moderatore,
        Segnalazione|Comunicazione $elemento,
        Collection $approvati,
    ): void {
        if ($approvati->isEmpty()) {
            return;
        }

        $perAutore = $approvati
            ->filter(fn (Documento $d) => $d->created_by !== null)
            ->groupBy(fn (Documento $d) => (int) $d->created_by);

        $autoriNotificati = [];

        foreach ($perAutore as $autoreId => $fileAutore) {
            $autoriNotificati[] = (int) $autoreId;

            if ((int) $autoreId === (int) $moderatore->id) {
                continue;
            }

            $autore = User::find($autoreId);
            if (! $autore || empty($autore->email)) {
                continue;
            }

            if (! $this->preferenzaAttiva($autore, NotificationType::ATTACHMENT_APPROVED->value)) {
                continue;
            }

            $ids = $fileAutore->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            $nomi = $fileAutore->pluck('name')->map(fn ($n) => (string) $n)->values()->all();
            $autore->notify(new AllegatoApprovatoNotification(
                RegoleAllegati::tipo($elemento),
                (int) $elemento->getKey(),
                (string) $elemento->subject,
                $ids,
                $nomi,
            ));
        }

        $escludi = array_values(array_unique([
            (int) $moderatore->id,
            ...$autoriNotificati,
        ]));

        $this->notificaPubblicati($elemento, $approvati, $escludi);
    }

    public function dopoEliminazione(
        User $chiElimina,
        Segnalazione|Comunicazione $elemento,
        Documento $allegato,
    ): void {
        $autoreId = $allegato->created_by !== null ? (int) $allegato->created_by : null;
        if (! $autoreId || $autoreId === (int) $chiElimina->id) {
            return;
        }

        $autore = User::find($autoreId);
        if (! $autore || empty($autore->email)) {
            return;
        }

        if (! $this->preferenzaAttiva($autore, NotificationType::ATTACHMENT_DELETED->value)) {
            return;
        }

        $autore->notify(new AllegatoEliminatoNotification(
            (string) $elemento->subject,
            (string) $allegato->name,
        ));
    }

    /**
     * @param  Collection<int, Documento>  $inAttesa
     */
    private function notificaInAttesa(
        User $chiCarica,
        Segnalazione|Comunicazione $elemento,
        Collection $inAttesa,
    ): void {
        $permessoApprova = RegoleAllegati::permesso($elemento, 'approva');
        $primo = $inAttesa->first();
        $ids = $inAttesa->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $nomi = $inAttesa->pluck('name')->map(fn ($n) => (string) $n)->values()->all();
        $autoreNome = $primo ? RegoleAllegati::nomeAutore($primo) : 'Utente';

        try {
            $candidati = User::query()
                ->whereKeyNot($chiCarica->id)
                ->whereNotNull('email')
                ->where('email', '!=', '')
                ->permission(Permission::ACCESS_ADMIN_PANEL->value)
                ->permission($permessoApprova)
                ->get();
        } catch (PermissionDoesNotExist) {
            return;
        }

        foreach ($candidati as $utente) {
            if (! $this->preferenzaAttiva($utente, NotificationType::ATTACHMENT_UNDER_MODERATION->value)) {
                continue;
            }

            if (! $primo || ! RegoleAllegati::puoApprovare($utente, $primo, $elemento)) {
                continue;
            }

            $utente->notify(new AllegatoInAttesaNotification(
                RegoleAllegati::tipo($elemento),
                (int) $elemento->getKey(),
                (string) $elemento->subject,
                $ids,
                $nomi,
                $autoreNome,
            ));
        }
    }

    /**
     * @param  Collection<int, Documento>  $pubblicati
     * @param  array<int, int>  $escludiUserIds
     */
    private function notificaPubblicati(
        Segnalazione|Comunicazione $elemento,
        Collection $pubblicati,
        array $escludiUserIds,
    ): void {
        $primo = $pubblicati->first();
        if (! $primo) {
            return;
        }

        $ids = $pubblicati->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $nomi = $pubblicati->pluck('name')->map(fn ($n) => (string) $n)->values()->all();
        $autoreNome = RegoleAllegati::nomeAutore($primo);

        if ($elemento instanceof Segnalazione) {
            $destinatari = $this->destinatariSegnalazione($elemento, $escludiUserIds);

            foreach ($destinatari as $utente) {
                if (! $this->preferenzaAttiva($utente, NotificationType::NEW_ATTACHMENT->value)) {
                    continue;
                }

                if (! RegoleAllegati::puoVedere($utente, $primo, $elemento)) {
                    continue;
                }

                $utente->notify(new NuovoAllegatoNotification(
                    (int) $elemento->id,
                    (string) $elemento->subject,
                    $ids,
                    $nomi,
                    $autoreNome,
                ));
            }

            return;
        }

        if (! $elemento->is_published || ! $elemento->is_approved) {
            return;
        }

        $destinatari = $this->destinatariComunicazione($elemento, $escludiUserIds);

        foreach ($destinatari as $utente) {
            if (! $this->preferenzaAttiva($utente, NotificationType::UPDATED_COMMUNICATION->value)) {
                continue;
            }

            if (! RegoleAllegati::puoVedere($utente, $primo, $elemento)) {
                continue;
            }

            $utente->notify(new AllegatiComunicazioneNotification(
                (int) $elemento->id,
                (string) $elemento->subject,
                $ids,
                $nomi,
            ));
        }
    }

    /**
     * @param  array<int, int>  $escludiUserIds
     * @return Collection<int, User>
     */
    private function destinatariSegnalazione(Segnalazione $segnalazione, array $escludiUserIds): Collection
    {
        $ids = collect();

        if ($segnalazione->created_by) {
            $ids->push((int) $segnalazione->created_by);
        }

        $commentatori = Commento::query()
            ->where('commentable_type', Segnalazione::class)
            ->where('commentable_id', $segnalazione->id)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id);

        $caricatori = Documento::query()
            ->where('documentable_type', Segnalazione::class)
            ->where('documentable_id', $segnalazione->id)
            ->whereNotNull('created_by')
            ->pluck('created_by')
            ->map(fn ($id) => (int) $id);

        $adminCondominio = User::query()
            ->whereHas('anagrafica.condomini', fn ($q) => $q->where('condomini.id', $segnalazione->condominio_id))
            ->permission(Permission::ACCESS_ADMIN_PANEL->value)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $userIds = $ids
            ->merge($commentatori)
            ->merge($caricatori)
            ->merge($adminCondominio)
            ->unique()
            ->reject(fn (int $id) => in_array($id, $escludiUserIds, true))
            ->values();

        if ($userIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();
    }

    /**
     * @param  array<int, int>  $escludiUserIds
     * @return Collection<int, User>
     */
    private function destinatariComunicazione(Comunicazione $comunicazione, array $escludiUserIds): Collection
    {
        $anagraficheIds = $comunicazione->anagraficheCheLaVedono();
        if ($anagraficheIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereHas('anagrafica', fn ($q) => $q->whereIn('anagrafiche.id', $anagraficheIds->all()))
            ->whereNotIn('id', $escludiUserIds)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();
    }

    private function preferenzaAttiva(User $user, string $tipo): bool
    {
        return NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('type', $tipo)
            ->where('enabled', true)
            ->exists();
    }
}
