<?php

namespace App\Http\Resources\Evento;

use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
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
        
        $occursAt = $this->occurs_at ?? $this->start_time;

        return [
            'id'              => $this->id,
            'title'           => Str::ucfirst($this->title),
            'description'     => $this->description,
            'recurrence_id'   => $this->recurrence_id,
            'occurs_at_human' => Carbon::parse($occursAt)->diffForHumans(),
            'occurs'          => $this->occurs_at,
            'occurs_at'       => Carbon::parse($occursAt)->format('d/m/Y \a\l\l\e H:i'),
            'categoria'       => new CategoriaEventoResource($this->whenLoaded('categoria')),
            'condomini'       => CondominioResource::collection($this->whenLoaded('condomini')),
            'anagrafiche'     => AnagraficaResource::collection($this->whenLoaded('anagrafiche')),
            'timezone'        => $this->timezone,
            'visibility'      => $this->visibility,
        ];
    }
}
