<?php

namespace App\Http\Resources\User;

use App\Http\Resources\PermissionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IndexUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
         return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'email'              => $this->email,
            'suspended_at'       => $this->suspended_at,
            'email_verified_at'  => $this->email_verified_at,
            'roles'              => $this->getRoleNames(),
            'permissions'        => PermissionResource::collection($this->getAllPermissions())
        ];
    }
}
