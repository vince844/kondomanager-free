<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\User;
use App\Notifications\Communications\ApproveComunicazioneNotification;
use App\Notifications\Communications\NewComunicazioneNotification;
use App\Notifications\Communications\ApprovedComunicazioneNotification;
use Illuminate\Support\Collection;
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
     * This method sends notifications to users related to a new communication (comunicazione),
     * specifically filtering by their notification preferences, and the 'new_communication' type.
     *
     * @param array $validated The validated request data containing 'anagrafiche' and 'condomini_ids'.
     * @param \App\Models\Comunicazione $comunicazione The comunicazione model instance, representing the communication to be notified about.
     * 
     * @return void
     * 
     * Any errors that occur during the process of filtering or sending notifications are caught and logged.
     */
    public function sendUserComunicazioneCreatedNotification(array $validated, Comunicazione $comunicazione): void
    {
        try {
            $anagrafiche = $this->getAnagraficheForNotification($validated);

            if ($anagrafiche->isNotEmpty()) {
                Notification::send($anagrafiche, new NewComunicazioneNotification($comunicazione));
            }

        } catch (\Exception $e) {
            Log::error("Error sending user notifications for created comunicazione ID: {$comunicazione->id} - " . $e->getMessage());
        }
    }

    /**
     * Sends admin notifications when a new comunicazione is created.
     *
     * This method sends notifications to admins when a new communication is created. 
     * The notifications are filtered based on the approval status of the comunicazione.
     * If approved and private, only admins are notified. If public, both admins and related anagrafiche are notified.
     * 
     * @param array $validated The validated input data containing 'anagrafiche' and 'condomini_ids'.
     * @param \App\Models\Comunicazione $comunicazione The comunicazione instance that triggered the notification.
     * 
     * @return void
     * 
     * Any errors that occur during the notification process are caught and logged.
     */
    public function sendAdminComunicazioneCreatedNotification(array $validated, Comunicazione $comunicazione): void
    {
        try {
            $adminQuery = $comunicazione->is_approved
                ? User::permission('Accesso pannello amministratore')
                : User::permission('Pubblica comunicazioni');

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
            Log::error("Error sending notifications for created comunicazione ID: {$comunicazione->id} - " . $e->getMessage());
        }
    }

    /**
     * Sends notifications to users when a comunicazione is approved.
     *
     * This method sends notifications to the creator of the comunicazione and all other related users 
     * (anagrafiche) if the comunicazione is public and approved. The creator will receive an approved notification, 
     * and the rest will receive a new communication notification.
     * 
     * @param \App\Models\Comunicazione $comunicazione The comunicazione that has been approved.
     * 
     * @return void
     * 
     * Any errors that occur during the notification process are caught and logged.
     */
    public function sendUserComunicazioneApproved(Comunicazione $comunicazione): void
    {
        try {
            $creator = $comunicazione->createdBy;

            // Only send notification to the creator if they are eligible and the notification type is approved communication
            if ($creator && $this->isUserEligibleForNotification($creator, NotificationType::APPROVED_COMMUNICATION->value)) {
                Notification::send($creator, new ApprovedComunicazioneNotification($comunicazione));
            }

            // Continue only if the comunicazione is public and approved
            if (!$comunicazione->is_private && $comunicazione->is_approved) {
                
                $condominioIds = $comunicazione->condomini->pluck('id')->toArray();
                
                $validated = ['condomini_ids' => $condominioIds];

                // Extract creator null check outside the closure
                $anagrafiche = $this->getAnagraficheForNotification($validated);

                // If creator is not null, filter out the creator from the anagrafiche collection
                if ($creator !== null) {
                    $anagrafiche = $anagrafiche->reject(fn ($a) => $a->user_id === $creator->id);
                }

                $anagrafiche = $anagrafiche->values();

                // Send notification to the remaining anagrafiche
                if ($anagrafiche->isNotEmpty()) {
                    Notification::send($anagrafiche, new NewComunicazioneNotification($comunicazione));
                }
            }

        } catch (\Exception $e) {
            Log::error("Error sending user notifications for approved comunicazione ID: {$comunicazione->id} - " . $e->getMessage());
        }
    }

    /**
     * Sends notifications to both admins and related anagrafiche.
     *
     * This private method merges the collection of admins and anagrafiche based on their email addresses
     * and sends the communication notification to the combined list of recipients.
     * 
     * @param array $validated The validated request data containing 'anagrafiche' and 'condomini_ids'.
     * @param \App\Models\Comunicazione $comunicazione The comunicazione model instance, representing the communication to be notified about.
     * @param \Illuminate\Support\Collection $admins A collection of admins who should receive notifications.
     * 
     * @return void
     */
    private function sendToAdminsAndAnagrafiche(array $validated, Comunicazione $comunicazione, Collection $admins): void
    {
        $anagrafiche = $this->getAnagraficheForNotification($validated);

        $recipients = $admins->keyBy('email')
            ->union($anagrafiche)
            ->values();

        Notification::send($recipients, new NewComunicazioneNotification($comunicazione));
    }

    /**
     * Retrieves anagrafiche to notify based on preferences.
     *
     * This method filters the anagrafiche based on the provided validated data
     * (either specific anagrafiche or by condominio ids) and checks their notification preferences
     * before returning a collection of unique anagrafiche.
     * 
     * @param array $validated The validated request data containing 'anagrafiche' and 'condomini_ids'.
     * @return \Illuminate\Support\Collection A collection of anagrafiche who should be notified.
     */
    private function getAnagraficheForNotification(array $validated): Collection
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

    /**
     * Checks if a user is eligible to receive a notification.
     *
     * This method checks if a user has preferences set to receive notifications of a specific type
     * (e.g., 'approved_communication') and returns whether or not they are eligible.
     *
     * @param \App\Models\User $user The user to check.
     * @param string $notificationType The type of notification to check eligibility for.
     * @return bool True if the user is eligible for the notification, false otherwise.
     */
    private function isUserEligibleForNotification(User $user, string $notificationType): bool
    {
        return $this->filterByNotificationPreference(
            User::where('id', $user->id),
            $notificationType
        )->exists();
    }
}
