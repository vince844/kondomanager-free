<?php

namespace App\Http\Resources\Segnalazioni;

use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\User\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'subject'       => $this->subject,
            'description'   => $this->description,
            'priority'      => $this->priority,
            'stato'         => $this->stato,
            'is_reolved'    => $this->is_resolved,
            'is_locked'     => $this->is_locked,
            'is_featured'   => $this->is_featured,
            'is_private'    => $this->is_private,
            'is_published'  => $this->is_published,
            'is_approved'   => $this->is_approved,
            'can_comment'   => $this->can_comment,
            'created_by' => [
                'user_id' => $this->createdBy->id,
                'name' => $this->createdBy->name, // From User model
                'email' => $this->createdBy->email,
                'anagrafica' => $this->whenLoaded('createdBy.anagrafica', function () {
                    return new AnagraficaResource($this->createdBy->anagrafica);
                }),
            ],
            /* 'created_by'    => new UserResource($this->whenLoaded('createdBy')), */
            'assigned_to'   => new UserResource($this->whenLoaded('assignedTo')),
            'condominio'    => new CondominioResource($this->whenLoaded('condominio')),
        ];
    }
}

