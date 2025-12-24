<?php

namespace App\Http\Resources\Gestionale\Casse;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CassaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Carichiamo la relazione se non è già stata caricata nel controller
        $cc = $this->relationLoaded('contoCorrente') ? $this->contoCorrente : null;

        return [
            'id'          => $this->id,
            'nome'        => $this->nome,
            'tipo'        => $this->tipo, 
            'tipo_label'  => ucfirst($this->tipo), 
            'descrizione' => $this->descrizione,
            'attiva'      => (bool) $this->attiva,
            
            // DATI BANCA
            'banca_istituto'    => $cc ? $cc->istituto : null,
            'banca_iban'        => $cc ? $cc->iban : null,
            'banca_predefinito' => $cc ? (bool) $cc->predefinito : false,
            'banca_tipo_conto'  => $cc ? $cc->tipo : null, 

            'saldo_attuale' => 0.00, 
            'note'          => $this->note,
        ];
    }
}