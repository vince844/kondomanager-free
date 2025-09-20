<?php

namespace App\Http\Resources\Gestionale\Scale;

use App\Http\Resources\Gestionale\Palazzine\PalazzinaResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource class that transforms a Scala model into an array
 * for JSON responses.
 *
 * A Scala represents a condominium staircase and may optionally
 * be linked to a Palazzina (building block).
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $note
 * @property \App\Models\Palazzina|null $palazzina
 */
class ScalaResource extends JsonResource
{
    /**
     * Transform the Scala resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'description'   => $this->description,
            'note'          => $this->note,
            'palazzina'     => new PalazzinaResource($this->whenLoaded('palazzina')),
        ];
    }
}
