<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class NotificationPreferenceController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        $config = config('notifications.types');
        $types = $config['common'];

        if ($user->hasRole(['amministratore', 'collaboratore']) || $user->hasPermissionTo('Accesso pannello amministratore')) {
            $types = array_merge($types, $config['admin']);
        }

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

        return Inertia::render('notifications/NotificationPreferences', [
            'preferences' => $preferences,
        ]);
    }

}
