<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Resources\Anagrafica;

use App\Http\Resources\Condominio\CondominioResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AnagraficaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'nome'             => $this->nome,
            'indirizzo'        => $this->indirizzo,
            'codice_fiscale'   => $this->codice_fiscale ? Str::upper($this->codice_fiscale) : null,
            'email'            => $this->email,
            'email_secondaria' => $this->email_secondaria,
            'pec'              => $this->pec,
            // Come si convoca alle assemblee e il giorno in cui ha comunicato la PEC (la scheda della persona le mostra).
            'mezzo_convocazione' => $this->mezzo_convocazione,
            'pec_comunicata_il' => $this->pec_comunicata_il === null ? null : substr((string) $this->pec_comunicata_il, 0, 10),
            'telefono'         => $this->telefono,
            'cellulare'        => $this->cellulare,
            'condomini'        => CondominioResource::collection($this->whenLoaded('condomini'))
        ];
    }
}