<?php

namespace App\Http\Resources\Segnalazioni;

use App\Http\Resources\Anagrafica\AnagraficaResource;
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
            'created_by' => [
                'user_id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
                'email' => $this->createdBy->email,
                'anagrafica' => $this->relationLoaded('createdBy.anagrafica', function () {
                    return new AnagraficaResource($this->createdBy->anagrafica);
                }),
            ],
            'assigned_to'   => new UserResource($this->whenLoaded('assignedTo')),
            'condominio' => [
                'option' => new CondominioOptionsResource($this->whenLoaded('condominio')),
                'full'   => new CondominioResource($this->whenLoaded('condominio')),
            ],
            'anagrafiche' => AnagraficaResource::collection($this->whenLoaded('anagrafiche')),
        ];
    }
}

