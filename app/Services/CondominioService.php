<?php

namespace App\Services;

use App\Models\Condominio;
use App\Models\Esercizio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CondominioService
{
    /**
     * Crea un nuovo condominio con il suo esercizio per l'anno corrente
     */
    public function createCondominioWithEsercizio(array $condominioData): Condominio
    {
        return DB::transaction(function () use ($condominioData) {
            // Crea il condominio
            $condominio = Condominio::create($condominioData);
            
            // Crea l'esercizio per l'anno corrente
            $this->createEsercizioForCondominio($condominio);
            
            return $condominio;
        });
    }
    
    /**
     * Crea un esercizio per l'anno corrente per il condominio
     */
    protected function createEsercizioForCondominio(Condominio $condominio): Esercizio
    {
        $currentYear = now()->year;
        $nomeEsercizio = "Esercizio anno {$currentYear}";
        
        $esercizioData = [
            'condominio_id' => $condominio->id,
            'nome'          => $nomeEsercizio,
            'descrizione'   => "Esercizio anno {$currentYear}",
            'data_inizio'   => "{$currentYear}-01-01",
            'data_fine'     => "{$currentYear}-12-31",
            'stato'         => 'aperto',
            'note'          => 'Esercizio creato automaticamente alla creazione del condominio',
        ];
        
        $esercizio = Esercizio::create($esercizioData);
        
        return $esercizio;
    }
    
    /**
     * Verifica se esiste già un esercizio per l'anno corrente
     */
    public function esercizioCorrenteExists(Condominio $condominio): bool
    {
        $currentYear = now()->year;
        
        return $condominio->esercizi()
            ->whereYear('data_inizio', $currentYear)
            ->whereYear('data_fine', $currentYear)
            ->exists();
    }
}