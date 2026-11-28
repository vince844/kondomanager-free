<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Resources\User;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * L'autore di una segnalazione, una comunicazione, un documento o un evento, come lo ricevono le pagine del pannello (Coda 411): l'id, che le pagine
 * confrontano con l'utente collegato, e il nome. Email, sospensione, ruoli e permessi dell'account restano a `UserResource`, nelle pagine degli utenti.
 * Il portale ha il suo: `Portale\AutorePortale`.
 */
class AutoreResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'   => $this->id,
            'name' => $this->name,
        ];
    }
}
