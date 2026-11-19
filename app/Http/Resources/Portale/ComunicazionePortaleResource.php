<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Resources\Portale;

use App\Http\Resources\Comunicazioni\ComunicazioneResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * La comunicazione come la riceve il portale del condòmino (Coda 260): un elenco di campi ammessi,
 * l'autore con id e nome, i condomìni con id e nome (e le opzioni del modulo di modifica). Le
 * persone a cui è indirizzata non si mostrano: nessuna riceve i dati delle altre, nemmeno il
 * nome.
 */
class ComunicazionePortaleResource extends ComunicazioneResource
{
    private const CAMPI = [
        'id', 'subject', 'description', 'priority', 'is_featured', 'is_published', 'is_approved', 'is_private',
        'can_comment', 'commenti_moderati', 'created_at', 'pubblicata_il', 'contatori',
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
