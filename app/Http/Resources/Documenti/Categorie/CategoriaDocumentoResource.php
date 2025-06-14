<?php

namespace App\Http\Resources\Documenti\Categorie;

use App\Http\Resources\Documenti\DocumentoResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoriaDocumentoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'description' => $this->description,
            'documenti'   => DocumentoResource::collection($this->whenLoaded('documenti')),
        ];
    }
}
