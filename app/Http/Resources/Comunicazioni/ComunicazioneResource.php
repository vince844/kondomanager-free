<?php

namespace App\Http\Resources\Comunicazioni;

use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\User\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ComunicazioneResource extends JsonResource
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
            'is_featured'   => $this->is_featured,
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
            'condomini' => [
                'options' => CondominioOptionsResource::collection($this->whenLoaded('condomini')),
                'full'    => CondominioResource::collection($this->whenLoaded('condomini')),
            ],
            'anagrafiche' => AnagraficaResource::collection($this->whenLoaded('anagrafiche')),
        ];
    }
}
