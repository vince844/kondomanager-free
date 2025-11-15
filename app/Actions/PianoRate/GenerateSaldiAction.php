<?php

namespace App\Actions\PianoRate;

use App\Models\Gestionale\PianoRate;
use App\Models\Saldo;
use Illuminate\Support\Facades\Log;

class GenerateSaldiAction
{
    /**
     * Retrieve saldi mapped as:
     * [anagrafica_id][immobile_id] => saldo_iniziale (cents)
     */
    public function execute(PianoRate $pianoRate, $gestione, $esercizio): array
    {
        $gestioneOrdinaria = $gestione->tipo === 'ordinaria'
            ? $gestione
            : $esercizio->gestioni()->where('tipo', 'ordinaria')->first();

        if (!$gestioneOrdinaria) {
            Log::warning("No ordinary gestione found for saldi", [
                'esercizio_id' => $esercizio->id,
                'gestione_id'  => $gestione->id,
            ]);

            return [];
        }

        $saldi = Saldo::where('condominio_id', $pianoRate->condominio_id)
            ->where('esercizio_id', $esercizio->id)
            ->where('origine', 'manuale')
            ->select('anagrafica_id', 'immobile_id', 'saldo_iniziale')
            ->get();

        $result = [];

        foreach ($saldi as $s) {
            $result[$s->anagrafica_id][$s->immobile_id] = (int) $s->saldo_iniziale;
        }

        return $result;
    }
}
