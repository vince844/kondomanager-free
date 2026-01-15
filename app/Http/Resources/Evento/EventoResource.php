<?php

namespace App\Http\Resources\Evento;

use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\Evento\Categorie\CategoriaEventoResource;
use App\Http\Resources\User\UserResource;
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
            
            // Date
            'start_time'      => $this->start_time, // <--- FONDAMENTALE per il Dialog
            'end_time'        => $this->end_time,
            'occurs_at_human' => Carbon::parse($occursAt)->diffForHumans(),
            'occurs'          => $this->occurs_at,
            'occurs_at'       => Carbon::parse($occursAt)->format('d/m/Y \a\l\l\e H:i'),
            
            // Relazioni
            'categoria'       => new CategoriaEventoResource($this->whenLoaded('categoria')),
            'condomini'       => CondominioResource::collection($this->whenLoaded('condomini')),
            'anagrafiche'     => AnagraficaResource::collection($this->whenLoaded('anagrafiche')),
            
            // Configurazione
            'timezone'        => $this->timezone,
            'visibility'      => $this->visibility,
            'is_approved'     => $this->is_approved,
            
            'meta'            => $this->meta, 

            'created_by'      => $this->whenLoaded('createdBy', function () {
                                    return [
                                        'user'       => new UserResource($this->createdBy),
                                        'anagrafica' => $this->createdBy->relationLoaded('anagrafica')
                                            ? new AnagraficaResource($this->createdBy->anagrafica)
                                            : null,
                                    ];
                                 }),
        ];
    }
}