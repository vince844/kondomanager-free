<?php

namespace App\Http\Resources\Condominio;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CondominioOptionsResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'label' => $this->nome,
            'value' => $this->id
        ];
    }
}
