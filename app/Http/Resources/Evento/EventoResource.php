<?php

namespace App\Http\Resources\Evento;

use App\Http\Resources\Evento\Categorie\CategoriaEventoResource;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class EventoResource extends JsonResource
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
            'title'         => Str::ucfirst($this->title),
            'description'   => $this->description,
            'occurs_at'     => Carbon::parse($this->occurs_at)->format('d/m/Y H:i'),
            'categoria'     => new CategoriaEventoResource($this->whenLoaded('categoria')),
        ];
    }
}
