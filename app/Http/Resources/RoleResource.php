<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Resources;

use App\Traits\HasProtectedRoles;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            // Il nome com'è scritto: la pagina di modifica lo rimanda, e una maiuscola aggiunta qui rinominava il ruolo al salvataggio.
            // L'iniziale maiuscola la mettono le pagine, solo a video.
            'name'         => $this->name,
            'description'  => $this->description, 
            'users_count'  => $this->users_count ?? 0,
            'is_protected' => $this->isProtectedRole($this->name),
            // Il ruolo dei nuovi iscritti non si elimina (Coda 258): l'elenco lo dice prima dell'invio.
            'is_registration_role' => $this->isRuoloDeiNuoviIscritti($this->name),
            'permissions'  => PermissionResource::collection($this->whenLoaded('permissions'))
        ];
    }
}
