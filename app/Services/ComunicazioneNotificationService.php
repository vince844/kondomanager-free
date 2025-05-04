<?php

namespace App\Services;

use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\User;
use App\Notifications\NewAdminComunicazioneNotification;
use App\Notifications\NewComunicazioneNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;
use App\Traits\FiltersByNotificationPreference;
use App\Enums\NotificationType;

class ComunicazioneNotificationService
{
    use FiltersByNotificationPreference;

    /**
     * Sends notifications to users (anagrafiche) based on their preferences.
     *
     * This method filters the users based on their notification preferences for the 
     * 'new_communication' type and sends them notifications regarding the new 
     * communication. The users are filtered by the provided 'anagrafiche' or 
     * related 'condomini' IDs, and notifications are only sent if there are valid users.
     *
     * @param array $validated The validated request data containing 'anagrafiche' and 'condomini_ids'.
     * @param \App\Models\Comunicazione $comunicazione The comunicazione model instance, representing the communication to be notified about.
     * 
     * @return void
     * 
     * @throws \Exception If an error occurs during the process of filtering or sending notifications, it will be logged.
     */
    public function sendUserNotifications(array $validated, Comunicazione $comunicazione) 
    {
        try {

            // Prepare base query for anagrafiche based on given data
            $baseQuery = !empty($validated['anagrafiche'])
                ? Anagrafica::whereIn('id', $validated['anagrafiche'])
                : Anagrafica::whereHas('condomini', function ($query) use ($validated) {
                    $query->where('condominio_id', $validated['condomini_ids']);
                });

            // Apply the notification preference filter
            $anagrafiche = $this->filterByNotificationPreference($baseQuery, NotificationType::NEW_COMMUNICATION->value)
                ->get()
                ->unique('email')
                ->values();

            if ($anagrafiche->isNotEmpty()) {
                Notification::send($anagrafiche, new NewComunicazioneNotification($comunicazione));
            }

        } catch (\Exception $e) {

            Log::error("Error sending user notifications for comunicazione ID: {$comunicazione->id} - " . $e->getMessage());
            
        }
    }

    /**
     * Sends notifications to admins based on the communication's approval and privacy status.
     *
     * This method retrieves all admins who are subscribed to the 'new-communication' 
     * notification type. It then sends notifications depending on whether the 
     * communication is approved, private, or public. Admins are notified differently 
     * based on these conditions.
     *
     * - If the communication is approved and private, a general notification is sent to admins.
     * - If the communication is approved and public, both admins and related anagrafiche are notified.
     * - If the communication is not approved and private, a notification intended for admins only is sent.
     *
     * @param \App\Models\Comunicazione $comunicazione The comunicazione model instance, representing the communication to be notified about.
     * 
     * @return void
     * 
     * @throws \Exception If an error occurs while sending notifications, it will be logged, but not rethrown.
     */
    public function sendAdminNotifications(Comunicazione $comunicazione)
    {
        try {
            // Get admins who are subscribed to 'new-communication' notification type
            $adminQuery = User::role(['amministratore', 'collaboratore'])->with('roles');
            $admins = $this->filterByNotificationPreference($adminQuery, NotificationType::NEW_COMMUNICATION->value)->get();

            // Refactored condition checks for approved and private flags
            if ($comunicazione->is_approved) {
                if ($comunicazione->is_private) {
                    Notification::send($admins, new NewComunicazioneNotification($comunicazione));
                } else {
                    // If approved and public, notify both admins and related anagrafiche
                    $this->sendToAdminsAndAnagrafiche($comunicazione, $admins);
                }
            } else {
                if ($comunicazione->is_private) {
                    Notification::send($admins, new NewAdminComunicazioneNotification($comunicazione));
                }
            }

        } catch (\Exception $e) {
            Log::error("Error sending notifications for comunicazione ID: {$comunicazione->id} - " . $e->getMessage());
        }
    }

    /**
     * Sends notifications to both admins and related anagrafiche for a specific communication.
     *
     * This method retrieves all anagrafiche related to the communication's condominio, 
     * filters them based on their notification preferences, and merges them with the 
     * admins who are already subscribed to the 'new-communication' notification type. 
     * After filtering and merging the recipients, it sends the communication notification 
     * to the unique recipients (admins and anagrafiche).
     *
     * @param \App\Models\Comunicazione $comunicazione The comunicazione model instance, representing the communication to be notified about.
     * @param \Illuminate\Database\Eloquent\Collection $admins A collection of admins who should receive notifications.
     * 
     * @return void
     * 
     * @throws \Exception If an error occurs during the process of filtering, merging, or sending notifications, it will be logged, but not rethrown.
     */
    private function sendToAdminsAndAnagrafiche(Comunicazione $comunicazione, $admins)
    {
        // Eager load related condomini for anagrafiche
        $anagraficaQuery = Anagrafica::with('condomini')
            ->whereHas('condomini', function ($query) use ($comunicazione) {
                $query->where('condominio_id', $comunicazione->condominio_id);
            });

        $anagrafiche = $this->filterByNotificationPreference($anagraficaQuery, NotificationType::NEW_COMMUNICATION->value)
            ->get()
            ->unique('email')
            ->values();

        // Merge and send notification to unique recipients
        $recipients = $admins->merge($anagrafiche)->unique('email')->values();
        Notification::send($recipients, new NewComunicazioneNotification($comunicazione));
    }
}
