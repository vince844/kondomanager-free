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

                // Coda 683 (3): un tipo può valere per più permessi (gli allegati di segnalazioni e comunicazioni): basta averne uno. Un permesso
                // che a database non c'è vale «no», non un errore.
                return collect(self::permessi($meta['permission']))
                    ->contains(fn (string $permesso) => $user->checkPermissionTo($permesso));
            })
            ->toArray();
    }

    /**
     * I nomi dei permessi di un tipo di notifica: uno solo o un elenco, di cui basta averne uno.
     *
     * @return list<string>
     */
    public static function permessi(mixed $permesso): array
    {
        return array_values(array_map(fn ($p) => $p instanceof \BackedEnum ? (string) $p->value : (string) $p, is_array($permesso) ? $permesso : [$permesso]));
    }
}
