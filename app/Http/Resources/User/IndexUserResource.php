<?php

namespace App\Http\Resources\User;

use App\Http\Resources\Anagrafica\AnagraficaConIndirizzoResource;
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
            'last_login_at'      => $this->last_login_at,
            'roles'              => $this->getRoleNames(),
            'permissions'        => PermissionResource::collection($this->getAllPermissions()),
            // La colonna «Anagrafica» mostra la persona collegata con il nome e, nel pannello, l'indirizzo (Coda 408).
            'anagrafica'         => new AnagraficaConIndirizzoResource($this->whenLoaded('anagrafica')),
            // Serve all'elenco per non offrire due comandi che il server rifiuterebbe:
            // sospendere o eliminare l'ultimo amministratore attivo, e sé stessi.
            // L'id arriva dal controller, risolto una volta per l'intera pagina.
            'is_ultimo_amministratore' => $request->attributes->get('unico_amministratore_id') === $this->id,
            'is_self'                  => $request->user()?->is($this->resource) ?? false,
            // Coda 258, decisione 134.1: l'account è nella portata di chi guarda l'elenco? Se no, il menù
            // non offre i comandi che il server rifiuterebbe (sospendere, riattivare, reinvitare,
            // eliminare, la verifica dell'email).
            'gestibile'                => (bool) $request->user()?->haNellaPortata($this->resource),
        ];
    }
}
