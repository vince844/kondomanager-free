<?php

namespace App\Listeners\Documenti;

use App\Enums\NotificationType;
use App\Enums\Permission;
use App\Events\Documenti\NotifyAdminOfCreatedDocumento;
use App\Models\Anagrafica;
use App\Models\User;
use App\Notifications\Documenti\ApproveDocumentoNotification;
use App\Notifications\Documenti\NewDocumentoNotification;
use App\Traits\FilterByNotificationPreference;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class SendNewDocumentoNotificationToAdmin implements ShouldQueue
{
    use FilterByNotificationPreference;

    /**
     * Handle the event.
     */
    public function handle(NotifyAdminOfCreatedDocumento $event): void
    {
         try {

            $documento = $event->documento;
            $validated = $event->validated;

            $adminQuery = $documento->is_approved
                ? User::permission(Permission::APPROVE_ARCHIVE_DOCUMENTS->value)
                : User::permission(Permission::PUBLISH_ARCHIVE_DOCUMENTS->value);

            $admins = $this->filterByNotificationPreference(
                $adminQuery,
                NotificationType::NEW_ARCHIVE_DOCUMENT->value
            )->get();

            if ($documento->is_approved) {

                if ($documento->is_private) {

                    Notification::send($admins, new NewDocumentoNotification($documento));

                } else {

                    $anagrafiche = $this->getAnagrafiche($validated);

                    $recipients = $admins->keyBy('email')
                        ->union($anagrafiche)
                        ->values();

                    Notification::send($recipients, new NewDocumentoNotification($documento));

                }

            } else {

                Notification::send($admins, new ApproveDocumentoNotification($documento));
                
            }

        } catch (\Exception $e) {
            Log::error("NotifyAdminsOfNewDocumento error for documento ID {$event->documento->id}: " . $e->getMessage());
        }
    }

    private function getAnagrafiche(array $validated): Collection
    {
        $query = !empty($validated['anagrafiche'])
            ? Anagrafica::whereIn('id', $validated['anagrafiche'])
            : Anagrafica::whereHas('condomini', fn ($q) =>
                $q->whereIn('condominio_id', $validated['condomini_ids'])
            );

        $filtered = $this->filterByNotificationPreference($query, NotificationType::NEW_ARCHIVE_DOCUMENT->value)->get();
        
        return $filtered
            ->reject(fn ($a) => $a->user_id === $validated['created_by'])
            ->unique('email')
            ->values();
    }
}
