<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Impostazioni;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Gate;

class ImpostazioniController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        Gate::allowIf(
            fn (User $user) => $user->hasPermissionTo(Permission::MANAGE_GENERAL_SETTINGS->value),
            __('errors.403_message')
        );

        $backupsEnabled = (bool) config('backup.enabled', true);

        try {
            $backupRunning = $backupsEnabled && \App\Models\Backup::running()->exists();
        } catch (\Throwable) {
            // La hub non deve mai rompersi per lo stato dei backup
            $backupRunning = false;
        }

        return Inertia::render('impostazioni/impostazioni', [
            'backups_enabled' => $backupsEnabled,
            'backup_running'  => $backupRunning,
        ]);
    }
}
