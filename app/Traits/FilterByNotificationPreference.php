<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;

trait FilterByNotificationPreference
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
        $model = $query->getModel();

        // If the model has a `user()` relationship (like Anagrafica), use nested whereHas
        if (method_exists($model, 'user')) {
            return $query
                ->with('user.notificationPreferences')
                ->whereHas('user.notificationPreferences', function ($subQuery) use ($type) {
                    $subQuery->where('type', $type)
                             ->where('enabled', true);
                });
        }

        // Otherwise, assume it's the User model itself
        return $query
            ->with('notificationPreferences')
            ->whereHas('notificationPreferences', function ($subQuery) use ($type) {
                $subQuery->where('type', $type)
                         ->where('enabled', true);
            });
    }
}


