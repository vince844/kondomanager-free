<?php

namespace App\Http\Resources\Anagrafica;

use App\Http\Resources\Condominio\CondominioResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AnagraficaResource extends JsonResource
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
            'nome'             => $this->nome,
            'indirizzo'        => $this->indirizzo,
            'codice_fiscale'   => $this->codice_fiscale ? Str::upper($this->codice_fiscale) : null,
            'email'            => $this->email,
            'email_secondaria' => $this->email_secondaria,
            'pec'              => $this->pec,
            'telefono'         => $this->telefono,
            'cellulare'        => $this->cellulare,
            'condomini'        => CondominioResource::collection($this->whenLoaded('condomini'))
        ];
    }
}