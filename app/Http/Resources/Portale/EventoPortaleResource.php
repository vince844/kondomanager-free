<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Resources\Portale;

use App\Http\Resources\Evento\EventoResource;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * L'evento come lo riceve l'agenda del condòmino (Coda 260): un elenco di campi ammessi, i
 * condomìni con id e nome. Non arrivano le persone collegate all'evento (in un evento per più
 * persone nessuna riceve i dati delle altre) né l'autore completo. Il `meta` resta: porta
 * i dati della rata di chi la riceve, ed è ciò che l'agenda mostra.
 */
class EventoPortaleResource extends EventoResource
{
    private const CAMPI = [
        'id', 'title', 'description', 'recurrence_id', 'start_time', 'end_time', 'occurs_at_human', 'occurs',
        'occurs_at', 'updated_at', 'categoria', 'timezone', 'visibility', 'is_approved', 'meta',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return Arr::only(parent::toArray($request), self::CAMPI) + [
            // Coda 668: dei palazzi dell'evento solo quelli di chi guarda, tutti a chi l'ha scritto (`PalazziDelCondomino`), mai il nome di un altro palazzo dello studio.
            'condomini'  => CondominioPortaleResource::collection($this->whenLoaded('condomini', fn () => PalazziDelCondomino::fra($request, $this->condomini, $this->created_by))),
            'created_by' => $this->whenLoaded('createdBy', fn () => AutorePortale::di($this->createdBy)),
        ];
    }
}
