<?php

namespace App\Http\Resources\Gestionale\PianiDeiConti;

use App\Http\Resources\Gestionale\Gestioni\GestioneResource;
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
        return [
            'id'                => $this->id,
            'nome'              => Str::ucfirst($this->nome),
            'descrizione'       => $this->descrizione,
            'note'              => $this->note,
            'gestione'          => new GestioneResource($this->whenLoaded('gestione')),
            // Dati Finanziari
            'importo_totale'    => $this->conti_sum_importo ?? $this->conti()->sum('importo'),
            // Quanti conti/capitoli ci sono in questo piano?
            'capitoli_count'    => $this->conti_count ?? $this->conti()->count(),
            // Data formattata
            'data_creazione'    => $this->created_at ? $this->created_at->format('d/m/Y') : 'N/D',
        ];
    }
}