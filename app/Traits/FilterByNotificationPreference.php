<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Config;

trait FilterByNotificationPreference
{
    /**
     * Filter the query by users' notification preferences for a specific type,
     * AND ensure the user has the required permission via Spatie Permissions,
     * checking both direct permissions and permissions via roles.
     * Only return users who have the notification enabled AND have the required permission either directly or through any of their roles
     *
     * @param Builder $query
     * @param string $type
     * @return Builder
     */
    public function filterByNotificationPreference(Builder $query, string $type): Builder
    {
        $model = $query->getModel();
        $permissionName = $this->getRequiredPermissionForNotification($type);

        if (method_exists($model, 'user')) {
            return $query
                ->with(['user.notificationPreferences', 'user.roles.permissions', 'user.permissions'])
                ->whereHas('user', function ($userQuery) use ($type, $permissionName) {
                    // User has enabled the notification type
                    $userQuery->whereHas('notificationPreferences', function ($prefQuery) use ($type) {
                        $prefQuery->where('type', $type)->where('enabled', true);
                    });

                    // User has the required permission (direct or via roles)
                    if ($permissionName) {
                        $userQuery->where(function ($query) use ($permissionName) {
                            $query->whereHas('permissions', function ($permQuery) use ($permissionName) {
                                $permQuery->where('name', $permissionName);
                            })
                            ->orWhereHas('roles.permissions', function ($permQuery) use ($permissionName) {
                                $permQuery->where('name', $permissionName);
                            });
                        });
                    }
                });
        }

        // For User model itself
        return $query
            ->with(['notificationPreferences', 'roles.permissions', 'permissions'])
            ->whereHas('notificationPreferences', function ($prefQuery) use ($type) {
                $prefQuery->where('type', $type)->where('enabled', true);
            })
            ->when($permissionName, function ($query) use ($permissionName) {
                $query->where(function ($query) use ($permissionName) {
                    $query->whereHas('permissions', function ($permQuery) use ($permissionName) {
                        $permQuery->where('name', $permissionName);
                    })
                    ->orWhereHas('roles.permissions', function ($permQuery) use ($permissionName) {
                        $permQuery->where('name', $permissionName);
                    });
                });
            });
    }

    /**
     * Get the permission name required for the given notification type from config.
     *
     * @param string $type
     * @return string|null
     */
    protected function getRequiredPermissionForNotification(string $type): ?string
    {
        $types = collect(Config::get('notifications.types.common', []))
            ->merge(Config::get('notifications.types.admin', []));

        return $types[$type]['permission']->value ?? null; // Assuming permission is an Enum
    }
}

