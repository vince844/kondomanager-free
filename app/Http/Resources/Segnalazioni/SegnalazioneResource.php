<?php

namespace App\Http\Resources\Segnalazioni;

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
            'is_published'  => $this->is_publisched,
            'is_approved'   => $this->is_approved,
            'can_comment'   => $this->can_comment,
            'created_by'    => UserResource::collection($this->created_by),
            'assigned_to'   => UserResource::collection($this->assogned_to),
            'condominio_id' => CondominioResource::collection($this->condominio_id),
        ];
    }
}

