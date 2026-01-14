<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;

class SystemUpgradeController extends Controller
{

    // Recupera i dati del changelog
    private function getChangelog() {
        // Recuperiamo la versione dinamicamente dalla config
        $version = config('app.version'); 

        return [
            'date' => date('d/m/Y'), 
            'version' => $version,   
            'features' => [
                // NOTA: Queste feature dovrai aggiornarle a mano nel codice 
                // prima di rilasciare la nuova versione, oppure leggerle da un file esterno.
                'Nuovo sistema di installazione e aggiornamento automatico (Universal Diamond)',
                'Migliorata la gestione dei permessi amministratore',
                'Ottimizzazione cache e performance database',
                'Fix: Risolto problema redirect su sottodomini',
            ]
        ];
    }

    // STEP 1: Pagina di Conferma
    public function confirm()
    {
        // Passiamo alla vista la versione letta da config/app.php
        return Inertia::render('system/upgrade/Confirm', [
            'version' => config('app.version') 
        ]);
    }

    // STEP 2: Esecuzione Comandi
    public function run()
    {
        try {
            // Esecuzione comandi reali
            Artisan::call('migrate', ['--force' => true]);
            
            // Pulisce la cache per assicurarsi che config('app.version') legga il nuovo valore
            // se per caso era rimasto in cache quello vecchio
            Artisan::call('optimize:clear'); 

            if (!file_exists(public_path('storage'))) {
                Artisan::call('storage:link');
            }

            // Redirect Inertia alla pagina changelog
            return Redirect::route('system.upgrade.changelog')
                ->with('success', 'Aggiornamento completato con successo!');

        } catch (\Exception $e) {
            return Redirect::back()->withErrors(['msg' => 'Errore critico: ' . $e->getMessage()]);
        }
    }

    // STEP 3: Pagina Changelog
    public function showChangelog()
    {
        return Inertia::render('system/upgrade/Changelog', [
            'log' => $this->getChangelog()
        ]);
    }
}