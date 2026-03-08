<?php

namespace App\Http\Resources\Evento\Categorie;

use App\Enums\CategoriaEventoEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoriaEventoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $enum = CategoriaEventoEnum::tryFrom($this->name);

        return [
            'id'           => $this->id,
            'name'         => $this->name,
            'localized_name' => $enum ? __('eventi.categories.' . $enum->name) : $this->name,
            'description'  => $this->description
        ];
    }
}
