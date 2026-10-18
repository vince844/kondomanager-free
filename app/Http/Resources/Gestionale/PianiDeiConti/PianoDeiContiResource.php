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
        // «Preventivo» è la somma delle sole voci di spesa; le voci di entrata hanno il loro totale, in valore assoluto.
        $segnaposto = implode(', ', array_fill(0, count(Conto::TIPI_DI_SPESA), '?'));
        $totali = $this->conti()->toBase()->selectRaw(
            "COALESCE(SUM(CASE WHEN tipo IN ({$segnaposto}) THEN importo ELSE 0 END), 0) AS spese, "
            . "COALESCE(SUM(CASE WHEN tipo IN ({$segnaposto}) THEN 0 ELSE ABS(importo) END), 0) AS entrate, "
            . "COALESCE(SUM(CASE WHEN tipo IN ({$segnaposto}) THEN 0 ELSE 1 END), 0) AS voci_di_entrata",
            array_merge(Conto::TIPI_DI_SPESA, Conto::TIPI_DI_SPESA, Conto::TIPI_DI_SPESA)
        )->first();

        return [
            'id'                => $this->id,
            'nome'              => Str::ucfirst($this->nome),
            'descrizione'       => $this->descrizione,
            'note'              => $this->note,
            'gestione'          => new GestioneResource($this->whenLoaded('gestione')),
            // Dati Finanziari
            'importo_totale'    => (int) $totali->spese,
            'importo_entrate'   => (int) $totali->entrate,
            'capitoli_entrate_count' => (int) $totali->voci_di_entrata,
            // Quanti conti/capitoli ci sono in questo piano?
            'capitoli_count'    => $this->conti_count ?? $this->conti()->count(),
            // Data formattata
            'data_creazione'    => $this->created_at ? $this->created_at->format('d/m/Y') : 'N/D',
        ];
    }
}