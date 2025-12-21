<?php

namespace App\Http\Resources\Fornitore;

use App\Helpers\MoneyHelper;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use App\Http\Resources\Fornitore\Categorie\CategoriaFornitoreResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FornitoreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'ragione_sociale'       => $this->ragione_sociale,
            'sito_web'              => $this->sito_web,
            'telefono'              => $this->telefono,
            'cellulare'             => $this->cellulare,
            'email'                 => $this->email,
            'pec'                   => $this->pec,
            'indirizzo'             => $this->indirizzo,
            'comune'                => $this->comune,
            'provincia'             => $this->provincia,
            'cap'                   => $this->cap,
            'partita_iva'           => $this->partita_iva,
            'codice_fiscale'        => $this->codice_fiscale,
            'capitale_sociale'      => MoneyHelper::format($this->capitale_sociale ?? 0),
            'iscrizione_cciaa'      => $this->iscrizione_cciaa,
            'data_iscrizione_cciaa' => $this->data_iscrizione_cciaa?->format('d/m/Y'),
            'certificazione_iso'    => $this->certificazione_iso,
            'codice_ateco'          => $this->codice_ateco,
            'numero_ordine'         => $this->numero_iscrizione_ordine,
            'note'                  => $this->note,
            'referenti'             => AnagraficaResource::collection($this->whenLoaded('referenti')),
            'categoria'             => new CategoriaFornitoreResource($this->whenLoaded('categoria'))
        ];
    }
}
