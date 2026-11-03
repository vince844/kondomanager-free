<?php

namespace App\Http\Resources\Portale;

use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Documenti\DocumentoResource;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Il documento come lo riceve il portale del condòmino (Coda 260): un elenco di campi ammessi,
 * l'autore con id e nome, i condomìni con id e nome. Non arrivano le persone a cui è indirizzato né
 * il percorso del file sul disco (`patyh`): il file si scarica dalla sua rotta, che controlla chi lo
 * chiede.
 */
class DocumentoPortaleResource extends DocumentoResource
{
    private const CAMPI = [
        'id', 'name', 'description', 'is_published', 'is_approved', 'mime_type', 'file_size',
        'created_at', 'created_at_data', 'categorie',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return Arr::only(parent::toArray($request), self::CAMPI) + [
            'created_by' => $this->whenLoaded('createdBy', fn () => AutorePortale::di($this->createdBy)),
            'condomini'  => [
                'options' => CondominioOptionsResource::collection($this->whenLoaded('condomini')),
                'full'    => CondominioPortaleResource::collection($this->whenLoaded('condomini')),
            ],
        ];
    }
}
