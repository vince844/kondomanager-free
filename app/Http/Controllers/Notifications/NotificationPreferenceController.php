<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use App\Services\NotificationPreferenceService;
use App\Traits\HandleFlashMessages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferenceController extends Controller
{
    use HandleFlashMessages;

    /**
     * Displays the notification preferences settings page for the authenticated user.
     *
     * This method retrieves the available notification types for the user, considering their roles and permissions.
     * It fetches the configuration for both common and admin notification types and merges them if the user is an admin.
     * The saved notification preferences are loaded and then mapped to include the type, label, description, and the 
     * current enabled status for each notification. The result is passed to the view for rendering.
     *
     * @return \Inertia\Response The response object for rendering the notification preferences page.
     * 
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If there is an issue with loading user notification preferences.
     */
    public function index(NotificationPreferenceService $service): Response
    {

        $user = Auth::user();

        $types = $service->getVisibleNotificationTypes($user);

        $saved = $user->notificationPreferences()
            ->pluck('enabled', 'type')
            ->toArray();

        $preferences = collect($types)->map(function ($meta, $type) use ($saved) {
            return [
                'type' => $type,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'enabled' => $saved[$type] ?? false,
            ];
        })->values();

        return Inertia::render('settings/Notifications', [
            'preferences' => $preferences,
        ]);

    }

    /**
     * Update the notification preferences for the authenticated user.
     *
     * This method accepts a request containing the user's notification preferences,
     * validates the data, and updates or creates the preference records for the user.
     *
     * @param \Illuminate\Http\Request $request The incoming request containing the user's preferences.
     * 
     * @return \Illuminate\Http\RedirectResponse A redirect response to the previous page.
     */
    public function update(Request $request): RedirectResponse
    {
        try {
            $user = Auth::user();

            // Validate the incoming request data
            $data = $request->validate([
                'preferences' => 'required|array',
                'preferences.*.type' => 'required|string',
                'preferences.*.enabled' => 'required|boolean',
            ]);

            // Bulk update or create notification preferences for the user
            $preferences = collect($data['preferences'])->map(function ($pref) use ($user) {
                return [
                    'user_id' => $user->id,  
                    'type' => $pref['type'],
                    'enabled' => $pref['enabled']
                ];
            });

            NotificationPreference::upsert(
                $preferences->toArray(),
                ['user_id', 'type'],
                ['enabled']
            );

            return back()->with([
                'message' => [
                    'type'    => 'success',
                    'message' => "Le preferenze sono state aggiornate con successo"
                ]
            ]);

        } catch (\Exception $e) {

            // Log any unexpected errors for debugging
            Log::error('Error updating notification preferences for user ' . $user->id . ': ' . $e->getMessage());

            return back()->with([
                'message' => [
                    'type'    => 'error',
                    'message' => "Si è verificato un errore durante l'aggiornamento delle preferenze!"
                ]
            ]);

        }
    }
}
