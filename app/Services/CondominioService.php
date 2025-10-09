<?php

namespace App\Services;

use App\Models\Condominio;
use App\Models\Esercizio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CondominioService
{
    /**
     * Crea un nuovo condominio con un esercizio per l'anno corrente.
     */
    public function createCondominioWithEsercizio(array $condominioData): Condominio
    {
        return DB::transaction(function () use ($condominioData) {
            $condominio = Condominio::create($condominioData);

            // Crea automaticamente l'esercizio per l'anno corrente
            $this->createEsercizioForCondominio($condominio);

            return $condominio;
        });
    }

    /**
     * Crea un esercizio per l'anno corrente se non esiste già per il condominio.
     */
    public function createEsercizioForCondominio(Condominio $condominio): Esercizio
    {
        $currentYear = now()->year;
        $nomeEsercizio = "Esercizio anno {$currentYear}";

        // Se esiste già un esercizio per l'anno corrente, lo restituisce
        $existing = $condominio->esercizi()
            ->whereYear('data_inizio', $currentYear)
            ->whereYear('data_fine', $currentYear)
            ->first();

        if ($existing) {
            Log::info("Esercizio {$currentYear} già esistente per '{$condominio->nome}' (ID: {$existing->id})");
            return $existing;
        }

        // Crea il nuovo esercizio
        return DB::transaction(function () use ($condominio, $currentYear, $nomeEsercizio) {
            $esercizio = Esercizio::create([
                'condominio_id' => $condominio->id,
                'nome'          => $nomeEsercizio,
                'descrizione'   => "Esercizio anno {$currentYear}",
                'data_inizio'   => now()->startOfYear(),
                'data_fine'     => now()->endOfYear(),
                'stato'         => 'aperto',
                'note'          => 'Esercizio creato automaticamente.',
            ]);

            Log::info("Creato esercizio '{$esercizio->nome}' per condominio '{$condominio->nome}' (ID: {$condominio->id})");

            return $esercizio;
        });
    }

    /**
     * Verifica se esiste già un esercizio per l'anno corrente.
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
