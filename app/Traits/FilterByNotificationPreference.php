<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
        $permissionNames = $this->getRequiredPermissionsForNotification($type);

        if (method_exists($model, 'user')) {
            return $query
                ->with(['user.notificationPreferences', 'user.roles.permissions', 'user.permissions'])
                ->whereHas('user', function ($userQuery) use ($type, $permissionNames) {
                    // User has enabled the notification type
                    $userQuery->whereHas('notificationPreferences', function ($prefQuery) use ($type) {
                        $prefQuery->where('type', $type)->where('enabled', true);
                    });

                    // User has the required permission (direct or via roles)
                    if ($permissionNames !== []) {
                        $userQuery->where(function ($query) use ($permissionNames) {
                            $query->whereHas('permissions', function ($permQuery) use ($permissionNames) {
                                $permQuery->whereIn('name', $permissionNames);
                            })
                            ->orWhereHas('roles.permissions', function ($permQuery) use ($permissionNames) {
                                $permQuery->whereIn('name', $permissionNames);
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
            ->when($permissionNames !== [], function ($query) use ($permissionNames) {
                $query->where(function ($query) use ($permissionNames) {
                    $query->whereHas('permissions', function ($permQuery) use ($permissionNames) {
                        $permQuery->whereIn('name', $permissionNames);
                    })
                    ->orWhereHas('roles.permissions', function ($permQuery) use ($permissionNames) {
                        $permQuery->whereIn('name', $permissionNames);
                    });
                });
            });
    }

    /**
     * The permission names required for the given notification type from config: one, or a list of which any is enough (Coda 683 (3)).
     *
     * @param string $type
     * @return list<string>
     */
    protected function getRequiredPermissionsForNotification(string $type): array
    {
        $types = collect(Config::get('notifications.types.common', []))
            ->merge(Config::get('notifications.types.admin', []));

        $permesso = $types[$type]['permission'] ?? null;

        return $permesso === null ? [] : \App\Services\NotificationPreferenceService::permessi($permesso);
    }
}
