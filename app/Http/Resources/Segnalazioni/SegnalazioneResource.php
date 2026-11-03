<?php

namespace App\Http\Resources\Segnalazioni;

use App\Http\Resources\Anagrafica\AnagraficaNomeResource;
use App\Http\Resources\Commenti\CommentoResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\User\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class SegnalazioneResource extends JsonResource
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
            'subject'       => Str::ucfirst($this->subject),
            'description'   => $this->description,
            'priority'      => $this->priority,
            'stato'         => $this->stato,
            'is_resolved'   => $this->is_resolved,
            'is_locked'     => $this->is_locked,
            'is_featured'   => $this->is_featured,
            'is_private'    => $this->is_private,
            'is_published'  => $this->is_published,
            'is_approved'   => $this->is_approved,
            'can_comment'   => $this->can_comment,
            'created_at'    => $this->created_at->diffForHumans(),
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'user'       => new UserResource($this->createdBy),
                    // Della persona in rubrica dell'autore, id e nome (Coda 408): il resto sta nella sua scheda.
                    'anagrafica' => $this->createdBy->relationLoaded('anagrafica')
                        ? new AnagraficaNomeResource($this->createdBy->anagrafica)
                        : null,
                ];
            }),
            'assigned_to'   => new UserResource($this->whenLoaded('assignedTo')),
            'condominio' => [
                'option' => new CondominioOptionsResource($this->whenLoaded('condominio')),
                'full'   => new CondominioResource($this->whenLoaded('condominio')),
            ],
            // I destinatari: gli elenchi, la scheda, la modifica e la dashboard ne leggono id e nome (Coda 408).
            'anagrafiche'       => AnagraficaNomeResource::collection($this->whenLoaded('anagrafiche')),
            'commenti'          => CommentoResource::collection($this->whenLoaded('commenti')),
            'commentiInAttesa'  => CommentoResource::collection($this->whenLoaded('commentiInAttesa')),
        ];
    }
}

