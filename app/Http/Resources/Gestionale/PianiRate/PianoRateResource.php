<?php

namespace App\Http\Resources\Gestionale\PianiRate;

use App\Http\Resources\Gestionale\Gestioni\GestioneResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PianoRateResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // 1. Calcolo Totale Piano (Somma delle rate collegate)
        // Se la relazione 'rate' è caricata, sommiamo 'importo_totale' (che è in centesimi) e dividiamo.
        $totalePiano = 0;
        if ($this->relationLoaded('rate')) {
            $totalePiano = $this->rate->sum('importo_totale') / 100;
        }

        // 2. Gestione Stato (Enum o Stringa)
        $statoValue = $this->stato;
        if ($this->stato instanceof \UnitEnum) {
            $statoValue = $this->stato->value;
        }

        return [
            'id'           => $this->id,
            'nome'         => $this->nome,
            'descrizione'  => $this->descrizione,
            'numero_rate'  => $this->numero_rate,
            
            // Nuovi campi per l'emissione
            'totale_piano' => $totalePiano,
            'stato'        => $statoValue,
            
            // Fallback per data_inizio se null (usa created_at o oggi)
            'data_inizio'  => $this->data_inizio?->format('Y-m-d') ?? $this->created_at?->format('Y-m-d'),
            
            'gestione'     => new GestioneResource($this->whenLoaded('gestione')),
        ];
    }
}
