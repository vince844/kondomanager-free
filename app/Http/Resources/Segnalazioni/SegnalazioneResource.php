<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Resources\Segnalazioni;

use App\Http\Resources\Anagrafica\AnagraficaNomeResource;
use App\Http\Resources\Commenti\CommentoResource;
use App\Http\Resources\Condominio\CondominioOptionsResource;
use App\Http\Resources\Condominio\CondominioResource;
use App\Http\Resources\User\AutoreResource;
use App\Support\Segnalazioni\AssegnazioneSegnalazioni;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class SegnalazioneResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $assegnataA = null;
        if ($this->resource->relationLoaded('assignedTo') && $this->assignedTo) {
            $assegnataA = [
                'id'     => (int) $this->assignedTo->id,
                'name'   => (string) $this->assignedTo->name,
                'attivo' => (bool) AssegnazioneSegnalazioni::puoRicevere($this->assignedTo),
            ];

            if ($this->resource->relationLoaded('assegnataDa')) {
                $assegnataA['da'] = $this->assegnataDa?->name;
                $assegnataA['il'] = $this->assegnata_il?->timezone((string) config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y');
                $assegnataA['alle'] = $this->assegnata_il?->timezone((string) config('app.user_timezone', 'Europe/Rome'))->format('H:i');
            }
        }

        return [
            'id'            => $this->id,
            'subject'       => Str::ucfirst($this->subject),
            'description'   => $this->description,
            'priority'      => $this->priority,
            'stato'         => $this->stato,
            'is_resolved'   => $this->is_resolved,
            'is_locked'     => $this->is_locked,
            'is_featured'   => $this->is_featured,
            'is_private'    => $this->is_private,
            'is_published'  => $this->is_published,
            'is_approved'   => $this->is_approved,
            'can_comment'   => $this->can_comment,
            'commenti_moderati' => (bool) $this->commenti_moderati,
            'created_at'    => $this->created_at->diffForHumans(),
            'creata_il'     => $this->created_at?->timezone((string) config('app.user_timezone', 'Europe/Rome'))->format('d/m/Y H:i'),
            'created_by' => $this->whenLoaded('createdBy', function () {
                return [
                    'user'       => new AutoreResource($this->createdBy),
                    // Della persona in rubrica dell'autore, id e nome (Coda 408): il resto sta nella sua scheda.
                    'anagrafica' => $this->createdBy->relationLoaded('anagrafica')
                        ? new AnagraficaNomeResource($this->createdBy->anagrafica)
                        : null,
                ];
            }),
            'assegnata_a' => $assegnataA,
            'condominio' => [
                'option' => new CondominioOptionsResource($this->whenLoaded('condominio')),
                'full'   => new CondominioResource($this->whenLoaded('condominio')),
            ],
            // I destinatari: gli elenchi, la scheda, la modifica e la dashboard ne leggono id e nome (Coda 408).
            'anagrafiche'       => AnagraficaNomeResource::collection($this->whenLoaded('anagrafiche')),
            'commenti'          => CommentoResource::collection($this->whenLoaded('commenti')),
            'commentiInAttesa'  => CommentoResource::collection($this->whenLoaded('commentiInAttesa')),
            'commentiNascosti'  => CommentoResource::collection($this->whenLoaded('commentiNascosti')),
            // Quanti commenti e allegati (decisione 453): li calcola l'elenco, per tutte le righe insieme, e li mette sul
            // modello. Dove nessuno li ha calcolati il campo non c'è.
            'contatori'         => $this->when(
                $this->resource->getAttribute('contatori_elenco') !== null,
                fn () => $this->resource->getAttribute('contatori_elenco')
            ),
        ];
    }
}

