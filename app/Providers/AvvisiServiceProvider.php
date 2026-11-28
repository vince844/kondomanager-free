<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Providers;

use App\Support\Notifiche\FermaGliAvvisiAChiESospeso;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Le regole comuni degli avvisi (Coda 694). L'ascoltatore sta fuori da `app/Listeners` perché non lo registri due volte la scoperta automatica.
 */
class AvvisiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(NotificationSending::class, FermaGliAvvisiAChiESospeso::class);
    }
}
