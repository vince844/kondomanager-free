<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Models\NotificationPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class NotificationPreferenceController extends Controller
{
    public function index(): Response
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

        return Inertia::render('settings/Notifications', [
            'preferences' => $preferences,
        ]);
    }

    public function update(Request $request)
    {
    
        $user = Auth::user();

        $data = $request->validate([
            'preferences' => 'required|array',
            'preferences.*.type' => 'required|string',
            'preferences.*.enabled' => 'required|boolean',
        ]);

        foreach ($data['preferences'] as $pref) {
            NotificationPreference::updateOrCreate(
                ['user_id' => $user->id, 'type' => $pref['type']],
                ['enabled' => $pref['enabled']]
            );
        }

        return redirect()->back();
    }

}
