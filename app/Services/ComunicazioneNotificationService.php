<?php

namespace App\Services;

use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\User;
use App\Notifications\Communications\ApproveComunicazioneNotification;
use App\Notifications\Communications\NewComunicazioneNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Log;
use App\Traits\FilterByNotificationPreference;
use App\Enums\NotificationType;

class ComunicazioneNotificationService
{
    use FilterByNotificationPreference;

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
    public function sendUserComunicazioneCreatedNotification(array $validated, Comunicazione $comunicazione) 
    {
        try {
            
            $anagrafiche = $this->getAnagraficheForNotification($validated);

            if ($anagrafiche->isNotEmpty()) {
                Notification::send($anagrafiche, new NewComunicazioneNotification($comunicazione));
            }

        } catch (\Exception $e) {

            Log::error("Error sending user notifications for comunicazione ID: {$comunicazione->id} - " . $e->getMessage());
            
        }
    }

    /**
     * Sends admin notifications when a new comunicazione is created.
     *
     * This method determines the appropriate group of admin users to notify based on the 
     * approval and privacy status of the comunicazione:
     * 
     * - If the comunicazione is approved:
     *   - And private: notify admins with the standard new comunicazione notification.
     *   - And public: notify both admins and relevant anagrafiche using a helper method.
     * - If the comunicazione is not approved:
     *   - Notify only admins with the permission to publish comunicazioni, using a special approval request notification.
     *
     * The method filters admins based on their notification preferences and uses the 
     * appropriate notification class based on the context.
     *
     * @param array $validated An array of validated input data, typically containing 'anagrafiche' or 'condomini_ids'.
     * @param \App\Models\Comunicazione $comunicazione The comunicazione instance that triggered the notification.
     * 
     * @return void
     * 
     * @throws \Exception Any exceptions during the notification process are caught and logged.
     */
    public function sendAdminComunicazioneCreatedNotification(array $validated, Comunicazione $comunicazione)
    {
        try {
            if ($comunicazione->is_approved) {
                $adminQuery = User::permission('Accesso pannello amministratore');
            } else {
                $adminQuery = User::permission('Pubblica comunicazioni');
            }
    
            $admins = $this->filterByNotificationPreference(
                $adminQuery,
                NotificationType::NEW_COMMUNICATION->value
            )->get();
    
            if ($comunicazione->is_approved) {

                if ($comunicazione->is_private) {
                    Notification::send($admins, new NewComunicazioneNotification($comunicazione));
                } else {
                    $this->sendToAdminsAndAnagrafiche($validated, $comunicazione, $admins);
                }
                
            } else {
                Notification::send($admins, new ApproveComunicazioneNotification($comunicazione));
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
    private function sendToAdminsAndAnagrafiche($validated, $comunicazione, $admins)
    {

        $anagrafiche = $this->getAnagraficheForNotification($validated);

        $recipients = $admins->keyBy('email') // Key admins by email
            ->union($anagrafiche) // Keep first occurrence of each email
            ->values(); // Reset to sequential keys (optional)

        // Send the notifications
        Notification::send($recipients, new NewComunicazioneNotification($comunicazione));
    }

    /**
     * Retrieves anagrafiche to notify based on validated data and notification preferences.
     *
     * @param array $validated The validated input data, containing 'anagrafiche' or 'condomini_ids'.
     * @return \Illuminate\Support\Collection A collection of unique anagrafiche.
     */
    private function getAnagraficheForNotification(array $validated)
    {
        $query = !empty($validated['anagrafiche'])
            ? Anagrafica::whereIn('id', $validated['anagrafiche'])
            : Anagrafica::whereHas('condomini', function ($q) use ($validated) {
                $q->whereIn('condominio_id', $validated['condomini_ids']);
            });

        return $this->filterByNotificationPreference($query, NotificationType::NEW_COMMUNICATION->value)
            ->get()
            ->unique('email')
            ->values();
    }
}
