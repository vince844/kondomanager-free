<?php

namespace App\Http\Resources\Fornitore;

use App\Http\Resources\Anagrafica\AnagraficaResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FornitoreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'ragione_sociale'  => $this->ragione_sociale,
            'indirizzo'        => $this->indirizzo,
            'comune'           => $this->comune,
            'provincia'        => $this->provincia,
            'cap'              => $this->cap,
            'partita_iva'      => $this->partita_iva,
            'codice_fiscale'   => $this->codice_fiscale,
            'referenti'        =>  AnagraficaResource::collection($this->whenLoaded('referenti'))
        ];
    }
}
