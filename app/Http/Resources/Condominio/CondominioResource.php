<?php

namespace App\Http\Resources\Condominio;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CondominioResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'nome'                  => $this->nome,
            'indirizzo'             => $this->indirizzo,
            'email'                 => $this->email,
            'note'                  => $this->note,
            'codice_fiscale'        => $this->codice_fiscale,
            'comune_catasto'        => $this->comune_catasto,
            'codice_catasto'        => $this->codice_catasto,
            'sezione_catasto'       => $this->sezione_catasto,
            'foglio_catasto'        => $this->foglio_catasto,
            'particella_catasto'    => $this->particella_catasto,
            'codice_identificativo' => $this->codice_identificativo
        ];
    }
}
