<?php

namespace App\Http\Resources\Gestionale\Palazzine;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource class that transforms a Palazzina model into an array
 * for JSON responses.
 *
 * A Palazzina represents a building block within a condominium.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string|null $note
 */
class PalazzinaResource extends JsonResource
{
    /**
     * Transform the Palazzina resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'note'        => $this->note,
        ];
    }
}
