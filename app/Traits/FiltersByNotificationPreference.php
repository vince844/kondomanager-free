<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait FiltersByNotificationPreference
{
    /**
     * Filter the query by users' notification preferences for a specific type.
     *
     * @param  Builder $query
     * @param  string  $type
     * @return Builder
     */
    public function filterByNotificationPreference(Builder $query, string $type): Builder
    {
        // Eager load the user and related notification preferences
        return $query->with('user.notificationPreferences')  // Eager load user and notification preferences
            ->whereHas('user.notificationPreferences', function ($subQuery) use ($type) {
                // Filter by the notification type and enabled status
                $subQuery->where('type', $type)
                         ->where('enabled', true);
            });
    }
}

