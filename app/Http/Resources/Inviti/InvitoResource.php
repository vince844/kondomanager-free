<?php

namespace App\Http\Resources\Inviti;

use App\Http\Resources\Condominio\CondominioResource;
use App\Models\Condominio;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvitoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'email'        => $this->email,
            'expires_at'   => $this->expires_at,
            'accepted_at'  => $this->accepted_at,
            'created_at'   => $this->created_at,
            'condomini'    => CondominioResource::collection(Condominio::whereIn('codice_identificativo', $this->building_codes)->get())
        ];
    }
}
