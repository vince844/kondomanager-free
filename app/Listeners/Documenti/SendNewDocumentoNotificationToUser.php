<?php

namespace App\Listeners\Documenti;

use App\Enums\NotificationType;
use App\Events\Documenti\NotifyUserOfCreatedDocumento;
use App\Models\Anagrafica;
use App\Notifications\Documenti\NewDocumentoNotification;
use App\Traits\FilterByNotificationPreference;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendNewDocumentoNotificationToUser implements ShouldQueue
{
    use FilterByNotificationPreference;

    public function handle(NotifyUserOfCreatedDocumento $event): void
    {
        try {
            $query = !empty($event->validated['anagrafiche'])
                ? Anagrafica::whereIn('id', $event->validated['anagrafiche'])
                : Anagrafica::whereHas('condomini', fn ($q) =>
                    $q->whereIn('condominio_id', $event->validated['condomini_ids'])
                );

            $anagrafiche = $this->filterByNotificationPreference($query, NotificationType::NEW_ARCHIVE_DOCUMENT->value)
                ->get()
                ->unique('email')
                ->values();

            if ($anagrafiche->isNotEmpty()) {
                Notification::send($anagrafiche, new NewDocumentoNotification($event->documento));
            }

        } catch (\Exception $e) {
            Log::error("NotifyUsersOfNewDocumento error for documento ID {$event->documento->id}: " . $e->getMessage());
        }
    }
}
