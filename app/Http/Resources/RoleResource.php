<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class RoleResource extends JsonResource
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
            'name'        => Str::of($this->name)->ucfirst(), 
            'description' => $this->description, 
            'permissions' => PermissionResource::collection($this->whenLoaded('permissions'))
        ];
    }
}
