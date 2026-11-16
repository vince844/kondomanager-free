<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\UpdateService;

class CheckSystemUpdates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'system:check-updates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for system updates and update cache';

    /**
     * Execute the console command.
     */
    public function handle(UpdateService $updateService)
    {
        // 1. Controlla se gli aggiornamenti sono abilitati da config
        if (!$updateService->isAutoUpdateEnabled()) {
            $this->info('Auto-updates disabled - skipping check');
            return 0;
        }

        $this->info('Checking for updates...');
        
        // 2. Chiama il servizio (che contatta GitHub e scrive in CACHE)
        $release = $updateService->checkRemoteVersion();
        
        // 3. Output a terminale (utile per debug manuale)
        if ($release) {
            $this->info("✓ Update available: {$release['version']}");
            // Qui la cache 'system.update_available' è già stata settata a TRUE dal Service
            return 0;
        }
        
        $this->info('✓ System is up to date');
        // Qui la cache è stata pulita o settata a FALSE dal Service
        return 0;
    }
}