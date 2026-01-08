<?php

namespace App\Services;

use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestionale\ContoContabile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CondominioService
{
    public function createCondominioWithEsercizio(array $condominioData): Condominio
    {
        return DB::transaction(function () use ($condominioData) {
            $condominio = Condominio::create($condominioData);
            $this->createEsercizioForCondominio($condominio);
            
            // Creazione conti allineata alla migration
            $this->ensureDefaultConti($condominio); 
            
            return $condominio;
        });
    }

    public function createEsercizioForCondominio(Condominio $condominio): Esercizio
    {
        $currentYear = now()->year;
        $existing = $condominio->esercizi()
            ->whereYear('data_inizio', $currentYear)
            ->whereYear('data_fine', $currentYear)
            ->first();

        if ($existing) return $existing;

        return Esercizio::create([
            'condominio_id' => $condominio->id,
            'nome'          => "Esercizio anno {$currentYear}",
            'descrizione'   => "Esercizio anno {$currentYear}",
            'data_inizio'   => now()->startOfYear(),
            'data_fine'     => now()->endOfYear(),
            'stato'         => 'aperto',
            'note'          => 'Esercizio creato automaticamente.',
        ]);
    }

    /**
     * Crea i conti di sistema rispettando gli ENUM 'tipo' e 'categoria'.
     */
    public function ensureDefaultConti(Condominio $condominio): void
    {
        // Controllo se esiste già il conto "Crediti v/Condomini"
        if (ContoContabile::where('condominio_id', $condominio->id)->where('ruolo', 'crediti_condomini')->exists()) {
            return;
        }

        Log::info("Inizializzazione Piano Conti (Schema Strict) per '{$condominio->nome}'...");

        // 1. RADICE: ATTIVO (parent_id = null)
        // Usiamo una categoria generica 'liquidita' per la radice, tanto è un contenitore
        $attivoRoot = ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'codice' => '1000'],
            [
                'parent_id'   => null,
                'nome'        => 'ATTIVO',
                'tipo'        => 'attivo',      // ENUM OK
                'categoria'   => 'liquidita',   // ENUM OK (Valore placeholder per la radice)
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 0
            ]
        );

        // 2. RADICE: PASSIVO (parent_id = null)
        $passivoRoot = ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'codice' => '2000'],
            [
                'parent_id'   => null,
                'nome'        => 'PASSIVO',
                'tipo'        => 'passivo',     // ENUM OK
                'categoria'   => 'fondi',       // ENUM OK (Valore placeholder)
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 0
            ]
        );

        // --- SOTTOCONTI DI SISTEMA ---

        // A. Crediti verso Condomini (Attività > Crediti)
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'crediti_condomini'],
            [
                'parent_id'   => $attivoRoot->id,
                'codice'      => '1101',
                'nome'        => 'Crediti verso Condomini',
                'tipo'        => 'attivo',
                'categoria'   => 'crediti',     // ENUM OK: Questo è un credito!
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1
            ]
        );

        // B. Cassa Contanti (Attività > Liquidità)
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'cassa'],
            [
                'parent_id'   => $attivoRoot->id,
                'codice'      => '1001',
                'nome'        => 'Cassa Contanti',
                'tipo'        => 'attivo',
                'categoria'   => 'liquidita',   // ENUM OK
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1
            ]
        );

        // C. Anticipi da Condomini (Passività > Debiti)
        // Quando un condomino paga troppo, il condominio ha un debito verso di lui
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'anticipi_condomini'],
            [
                'parent_id'   => $passivoRoot->id,
                'codice'      => '2101',
                'nome'        => 'Anticipi da Condomini',
                'tipo'        => 'passivo',
                'categoria'   => 'debiti',      // ENUM OK
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1
            ]
        );
        
        // D. Debiti v/Fornitori (Passività > Debiti)
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'debiti_fornitori'],
            [
                'parent_id'   => $passivoRoot->id,
                'codice'      => '2201',
                'nome'        => 'Debiti v/Fornitori',
                'tipo'        => 'passivo',
                'categoria'   => 'debiti',      // ENUM OK
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1
            ]
        );

        // E. Gestione Rate (Passività > Fondi/Ricavi) - NECESSARIO PER EMISSIONE RATE
        // Rappresenta la contropartita dell'emissione (Crediti vs Condomini @ Gestione Rate)
        ContoContabile::firstOrCreate(
            ['condominio_id' => $condominio->id, 'ruolo' => 'gestione_rate'],
            [
                'parent_id'   => $passivoRoot->id, // Mettilo sotto la radice PASSIVO (ID 8 nel tuo caso)
                'codice'      => '3001',
                'nome'        => 'Gestione Rate',
                'descrizione' => 'Contropartita per emissione rate',
                'tipo'        => 'passivo',     // OK: Presente nell'enum
                'categoria'   => 'fondi',       // OK: Presente nell'enum
                'di_sistema'  => true,
                'attivo'      => true,
                'livello'     => 1
            ]
        );

        Log::info("Piano Conti creato correttamente per '{$condominio->nome}'");
    }
}