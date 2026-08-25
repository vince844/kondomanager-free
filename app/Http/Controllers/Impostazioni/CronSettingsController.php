<?php

namespace App\Http\Controllers\Impostazioni;

use App\Http\Controllers\Controller;
use App\Settings\GeneralSettings; 
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

class CronSettingsController extends Controller
{
    /**
     * Mostra la pagina di configurazione.
     * Laravel inietta automaticamente l'istanza di GeneralSettings con i dati caricati.
     */
    public function edit(GeneralSettings $settings)
    {
        // Genera URL completo solo se abilitato e token esiste
        $webhookUrl = null;
        
        if ($settings->external_cron_enabled && !empty($settings->external_cron_token)) {
            $webhookUrl = url('/system/run-scheduler?token=' . $settings->external_cron_token);
        }

        // Recuperiamo gli IP dalla cache o usiamo i fallback
        $ips = Cache::get('cronjob_allowed_ips', [
            '116.203.134.67', 
            '116.203.129.16', 
            '23.88.105.37', 
            '128.140.8.200', 
            '91.99.23.109'
        ]);

        $lastHeartbeat = Cache::get('system_cron_heartbeat');
        $lastHeartbeatSource = Cache::get('system_cron_source');

        return Inertia::render('impostazioni/impostazioniCron', [
            'enabled' => (bool) $settings->external_cron_enabled,
            'webhookUrl' => $webhookUrl,
            'allowedIps' => $ips,
            'lastHeartbeat' => $lastHeartbeat,
            'lastHeartbeatSource' => $lastHeartbeatSource,
        ]);
    }

    /**
     * Aggiorna lo stato abilitato/disabilitato.
     */
    public function update(Request $request, GeneralSettings $settings)
    {
        $validated = $request->validate([
            'enabled' => 'required|boolean'
        ]);
        
        // 1. Aggiorna la proprietà nella classe settings
        $settings->external_cron_enabled = $validated['enabled'];
        
        // 2. Se abilitiamo per la prima volta e non c'è token, generiamolo
        if ($settings->external_cron_enabled && empty($settings->external_cron_token)) {
            $settings->external_cron_token = Str::uuid()->toString();
        }

        // 3. Salva le modifiche nel DB (metodo di Spatie)
        $settings->save();

        return back()->with('success', 'Impostazioni di automazione aggiornate.');
    }

    /**
     * Rigenera il token di sicurezza.
     */
    public function regenerateToken(GeneralSettings $settings)
    {
        $settings->external_cron_token = Str::uuid()->toString();
        $settings->save();

        return back()->with('success', 'Token di sicurezza rigenerato con successo.');
    }
}