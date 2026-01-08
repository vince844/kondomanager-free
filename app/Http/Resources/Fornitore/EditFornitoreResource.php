<?php

namespace App\Http\Resources\Fornitore;

use App\Helpers\MoneyHelper;
use App\Http\Resources\Anagrafica\AnagraficaResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EditFornitoreResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
         return [
            'id'                       => $this->id,
            'ragione_sociale'          => $this->ragione_sociale,
            'indirizzo'                => $this->indirizzo,
            'comune'                   => $this->comune,
            'provincia'                => $this->provincia,
            'cap'                      => $this->cap,
            'nazione'                  => $this->nazione,
            'partita_iva'              => $this->partita_iva,
            'codice_fiscale'           => $this->codice_fiscale,
            'email'                    => $this->email,
            'pec'                      => $this->pec,
            'telefono'                 => $this->telefono,
            'cellulare'                => $this->cellulare,
            'fax'                      => $this->fax,
            'sito_web'                 => $this->sito_web,
            'categoria_id'             => $this->categoria_id,
            'note'                     => $this->note,
            'iscrizione_cciaa'         => $this->iscrizione_cciaa,
            'codice_ateco'             => $this->codice_ateco,
            'data_iscrizione_cciaa'    => $this->data_iscrizione_cciaa,
            'capitale_sociale'         => MoneyHelper::format($this->capitale_sociale ?? 0),
            'certificazione_iso'       => $this->certificazione_iso,
            'numero_iscrizione_ordine' => $this->numero_iscrizione_ordine,
            'created_at'               => $this->created_at,
            'updated_at'               => $this->updated_at,
        ];
    }
}
