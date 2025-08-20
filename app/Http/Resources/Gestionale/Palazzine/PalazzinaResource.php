<?php

namespace App\Http\Resources\Gestionale\Palazzine;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PalazzinaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
          return [
            'id'            => $this->id,
            'name'          => $this->name,
            'description'   => $this->description,
            'note'          => $this->note
        ];
    }
}
