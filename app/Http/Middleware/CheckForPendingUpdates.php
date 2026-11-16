<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Middleware;

use Closure;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckForPendingUpdates
{
    public function handle(Request $request, Closure $next): Response
    {
        // 1. BYPASS: Route di upgrade
        if ($request->is('system/upgrade*')) {
            return $next($request);
        }

        // 2. BYPASS: Solo amministratori
        if (!Auth::check() || !Auth::user()->hasRole('amministratore')) {
            return $next($request);
        }

        // 3. BYPASS: Se auto-update disabilitato, skip check
        if (!$this->isAutoUpdateEnabled()) {
            return $next($request);
        }

        // 4. Cache check
        $needsUpgrade = Cache::remember('system.needs_upgrade', 300, function () {
            return $this->checkIfUpgradeNeeded();
        });

        if ($needsUpgrade) {
            $this->clearConfigCache();
            
            Log::info('Pending upgrade detected - redirecting admin', [
                'file_version' => config('app.version'),
                'db_version' => $this->getDbVersion(),
                'user_id' => Auth::id()
            ]);

            return redirect()->route('system.upgrade.confirm');
        }

        return $next($request);
    }

    private function isAutoUpdateEnabled(): bool
    {
        return config('installer.run_installer', false) === true;
    }

    private function checkIfUpgradeNeeded(): bool
    {
        try {
            if (!Schema::hasTable('settings')) {
                Log::warning('Settings table missing - upgrade needed');
                return true;
            }

            $versionExists = DB::table('settings')
                ->where('group', 'general')
                ->where('name', 'version')
                ->exists();

            if (!$versionExists) {
                Log::warning('Version record missing - upgrade needed');
                return true;
            }

            try {
                $settings = app(GeneralSettings::class);
            } catch (\Exception $e) {
                Log::error('Failed to load GeneralSettings', [
                    'error' => $e->getMessage()
                ]);
                return true;
            }

            $dbVersion = $settings->version ?? '0.0.0';
            $fileVersion = config('app.version');

            if (empty($dbVersion)) {
                Log::warning('DB version empty - upgrade needed');
                return true;
            }

            if (version_compare($fileVersion, $dbVersion, '>')) {
                Log::info('Version mismatch detected', [
                    'file' => $fileVersion,
                    'db' => $dbVersion
                ]);
                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('Upgrade check failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return true;
        }
    }

    private function clearConfigCache(): void
    {
        $configCache = base_path('bootstrap/cache/config.php');

        if (file_exists($configCache)) {
            try {
                @unlink($configCache);
                Log::info('Config cache cleared');
            } catch (\Exception $e) {
                Log::warning('Failed to clear config cache', [
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    private function getDbVersion(): string
    {
        try {
            if (!Schema::hasTable('settings')) {
                return 'unknown (table missing)';
            }

            $version = DB::table('settings')
                ->where('group', 'general')
                ->where('name', 'version')
                ->value('payload');

            if (!$version) {
                return 'unknown (record missing)';
            }

            $decoded = json_decode($version, true);
            return $decoded ?? 'unknown (invalid json)';

        } catch (\Exception $e) {
            return 'unknown (error)';
        }
    }
}