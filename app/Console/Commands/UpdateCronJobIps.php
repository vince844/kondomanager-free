<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\Response; 

class UpdateCronJobIps extends Command
{
    protected $signature = 'cronjob:update-ips';
    protected $description = 'Scarica e aggiorna gli IP ufficiali di cron-job.org';

    public function handle()
    {
        $url = 'https://api.cron-job.org/executor-nodes.json';
        
        $fallbackIps = [
            '116.203.134.67', 
            '116.203.129.16', 
            '23.88.105.37', 
            '128.140.8.200', 
            '91.99.23.109'
        ];

        $this->info("Connessione a {$url}...");

        try {

            /** @var Response $response */
            $response = Http::timeout(10)->get($url);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['ipAddresses']) && is_array($data['ipAddresses'])) {
                    $liveIps = $data['ipAddresses'];

                    $finalIps = array_unique(array_merge($liveIps, $fallbackIps));

                    Cache::forever('cronjob_allowed_ips', $finalIps);

                    $count = count($finalIps);
                    $this->info("Successo! Lista aggiornata con {$count} indirizzi IP.");
                    Log::info("CronJob IPs aggiornati.", ['count' => $count]);
                    
                    return 0;
                } else {
                    $this->error("Formato JSON imprevisto: chiave 'ipAddresses' mancante.");
                    Log::error("CronJob API: Formato JSON cambiato.", ['response' => $data]);
                }
            } else {
                $this->error("Errore HTTP: " . $response->status());
            }

        } catch (\Exception $e) {
            $this->error("Eccezione durante l'aggiornamento: " . $e->getMessage());
            Log::error("CronJob Update Failed: " . $e->getMessage());
        }

        if (!Cache::has('cronjob_allowed_ips')) {
            Cache::forever('cronjob_allowed_ips', $fallbackIps);
            $this->warn("Usati IP di fallback statici.");
        }

        return 1;
    }
}