<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
