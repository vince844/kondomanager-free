<?php

namespace App\Listeners\Documenti;

use App\Enums\NotificationType;
use App\Events\Documenti\NotifyUserOfApprovedDocumento;
use App\Models\Anagrafica;
use App\Models\User;
use App\Notifications\Documenti\ApprovedDocumentoNotification;
use App\Notifications\Documenti\NewDocumentoNotification;
use App\Traits\FilterByNotificationPreference;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;

class SendApprovedDocumentoNotificationToUser implements ShouldQueue
{
    use FilterByNotificationPreference;

    /**
     * Handle the event.
     */
    public function handle(NotifyUserOfApprovedDocumento $event): void
    {

        $documento = $event->documento;
        $user = $event->user;


        try {
            $creator = $documento->createdBy;

            if ($creator && $this->isUserEligibleForNotification($creator, NotificationType::APPROVED_ARCHIVE_DOCUMENT->value)) {
                Notification::send($creator, new ApprovedDocumentoNotification($documento, $user));
            }

            if (!$documento->is_private && $documento->is_approved) {
                $condominioIds = $documento->condomini->pluck('id')->toArray();

                $query = Anagrafica::whereHas('condomini', fn ($q) =>
                    $q->whereIn('condominio_id', $condominioIds)
                );

                $anagrafiche = $this->filterByNotificationPreference($query, NotificationType::NEW_ARCHIVE_DOCUMENT->value)
                    ->get()
                    ->unique('email');

                if ($creator !== null) {
                    $anagrafiche = $anagrafiche->reject(fn ($a) => $a->user_id === $creator->id);
                }

                $anagrafiche = $anagrafiche->values();

                if ($anagrafiche->isNotEmpty()) {
                    Notification::send($anagrafiche, new NewDocumentoNotification($documento));
                }
            }

        } catch (\Exception $e) {
            Log::error("NotifyUsersOfApprovedDocumento error for documento ID {$documento->id}: " . $e->getMessage());
        }
    }

    private function isUserEligibleForNotification(User $user, string $notificationType): bool
    {
        return $this->filterByNotificationPreference(
            User::where('id', $user->id),
            $notificationType
        )->exists();
    }
}
