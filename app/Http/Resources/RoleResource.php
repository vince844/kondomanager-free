<?php

namespace App\Http\Resources;

use App\Traits\HasProtectedRoles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class RoleResource extends JsonResource
{
    use HasProtectedRoles;

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'name'         => Str::of($this->name)->ucfirst(), 
            'description'  => $this->description, 
            'users_count'  => $this->users_count ?? 0,
            'is_protected' => $this->isProtectedRole($this->name),
            'permissions'  => PermissionResource::collection($this->whenLoaded('permissions'))
        ];
    }
}
