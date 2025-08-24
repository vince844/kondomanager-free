<?php

namespace App\Http\Resources\Gestionale\Immobili;

use App\Http\Resources\Gestionale\Palazzine\PalazzinaResource;
use App\Http\Resources\Gestionale\Scale\ScalaResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ImmobileResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'nome'               => $this->nome,
            'descrizione'        => $this->descrizione,
            'interno'            => $this->interno,
            'piano'              => $this->piano,
            'superficie'         => $this->superficie,
            'numero_vani'        => $this->numero_vani,
            'codice_immobile'    => $this->codice_immobile,
            'comune_catasto'     => $this->comune_catasto,
            'sezione_catasto'    => $this->sezione_catasto,
            'foglio_catasto'     => $this->foglio_catasto,
            'particella_catasto' => $this->particella_catasto,
            'subalterno_catasto' => $this->subalterno_catasto,
            'codice_catasto'     => $this->codice_catasto,
            'attivo'             => $this->attivo,
            'note'               => $this->note,
            'tipologia'          => new TipologiaImmobileResource($this->whenLoaded('tipologiaImmobile')),
            'palazzina'          => new PalazzinaResource($this->whenLoaded('palazzina')),
            'scala'              => new ScalaResource($this->whenLoaded('scala')),
        ];
    }
}
