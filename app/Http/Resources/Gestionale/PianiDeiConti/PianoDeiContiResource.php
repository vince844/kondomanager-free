<?php

namespace App\Http\Resources\Gestionale\PianiDeiConti;

use App\Http\Resources\Gestionale\Gestioni\GestioneResource;
use App\Models\Gestionale\Conto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class PianoDeiContiResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // «Preventivo» è la somma delle sole voci di spesa; le voci di entrata hanno il loro totale. Ogni voce conta come la conta il
        // calcolo delle quote, per il valore assoluto dal lato del suo tipo (`Conto::partiDelTipo`): anche una spesa scritta con il meno
        // da una versione vecchia, che il motore addebita in positivo. La somma si fa qui e non in SQL, perché la regola stia in un punto.
        $totali = ['spese' => 0, 'entrate' => 0, 'voci_di_entrata' => 0];
        foreach ($this->conti()->toBase()->get(['tipo', 'importo']) as $voce) {
            $parti = Conto::partiDelTipo((string) $voce->tipo, (int) $voce->importo);
            $totali['spese'] += $parti['spese'];
            $totali['entrate'] += $parti['entrate'];
            $totali['voci_di_entrata'] += Conto::segnoDelTipo((string) $voce->tipo) < 0 ? 1 : 0;
        }

        return [
            'id'                => $this->id,
            'nome'              => Str::ucfirst($this->nome),
            'descrizione'       => $this->descrizione,
            'note'              => $this->note,
            'gestione'          => new GestioneResource($this->whenLoaded('gestione')),
            // Dati Finanziari
            'importo_totale'    => $totali['spese'],
            'importo_entrate'   => $totali['entrate'],
            'capitoli_entrate_count' => $totali['voci_di_entrata'],
            // Quanti conti/capitoli ci sono in questo piano?
            'capitoli_count'    => $this->conti_count ?? $this->conti()->count(),
            // Data formattata
            'data_creazione'    => $this->created_at ? $this->created_at->format('d/m/Y') : 'N/D',
        ];
    }
}