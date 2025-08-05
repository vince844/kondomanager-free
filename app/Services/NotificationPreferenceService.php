<?php

namespace App\Services;

use App\Models\User;

class NotificationPreferenceService
{
    public function getVisibleNotificationTypes(User $user): array
    {
        $config = config('notifications.types');
        $merged = array_merge($config['common'], $config['admin'] ?? []);

        return collect($merged)
            ->filter(function ($meta) use ($user) {
                if (!isset($meta['permission'])) {
                    return true; 
                }

                return $user->hasPermissionTo($meta['permission']->value);
            })
            ->toArray();
    }
}
