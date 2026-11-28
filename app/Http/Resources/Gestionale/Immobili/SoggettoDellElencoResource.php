<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Resources\Gestionale\Immobili;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un soggetto di un'unità nell'elenco delle unità: solo ciò che la colonna «Soggetti» mostra (`components/gestionale/immobili/columns.ts`),
 * cioè il nome, l'indirizzo, il ruolo, la quota e le date della riga. Il codice fiscale e i saldi restano nella scheda dei titolari
 * dell'unità (`ImmobileAnagraficaResource`): l'elenco li mandava per ogni unità della pagina senza mostrarli.
 */
class SoggettoDellElencoResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'        => $this->id,
            'nome'      => $this->nome,
            'indirizzo' => $this->indirizzo,
            'pivot'     => [
                'id'          => $this->pivot->id,
                'tipologia'   => $this->pivot->tipologia,
                'quota'       => $this->pivot->quota,
                'data_inizio' => $this->pivot->data_inizio?->toDateString(),
                'data_fine'   => $this->pivot->data_fine?->toDateString(),
                'attivo'      => $this->pivot->attivo,
            ],
        ];
    }
}
