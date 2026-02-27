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
            // --- Informazioni Base e Identità ---
            'id'                    => $this->id,
            'ragione_sociale'       => $this->ragione_sociale,
            'stato'                 => $this->stato ?? 'attivo', // Fondamentale per i badge colorati
            'categoria'             => new CategoriaFornitoreResource($this->whenLoaded('categoria')),
            
            // --- Contatti ---
            'email'                 => $this->email,
            'pec'                   => $this->pec,
            'telefono'              => $this->telefono,
            'cellulare'             => $this->cellulare,
            'sito_web'              => $this->sito_web,
            
            // --- Sede ---
            'indirizzo'             => $this->indirizzo,
            'comune'                => $this->comune,
            'provincia'             => $this->provincia,
            'cap'                   => $this->cap,
            'nazione'               => $this->nazione,
            
            // --- Dati Fiscali e Societari ---
            'partita_iva'           => $this->partita_iva,
            'codice_fiscale'        => $this->codice_fiscale,
            'capitale_sociale'      => MoneyHelper::format($this->capitale_sociale ?? 0),
            'iscrizione_cciaa'      => $this->iscrizione_cciaa,
            'data_iscrizione_cciaa' => $this->data_iscrizione_cciaa?->format('d/m/Y'),
            'certificazione_iso'    => (bool) $this->certificazione_iso,
            'codice_ateco'          => $this->codice_ateco,
            'numero_iscrizione_ordine' => $this->numero_iscrizione_ordine, // Nome corretto del DB
            'note'                  => $this->note,

            // --- NUOVI CAMPI: Fatturazione e Tesoreria (V 1.9) ---
            'soggetto_ritenuta'          => (bool) $this->soggetto_ritenuta,
            'perc_ritenuta'              => $this->perc_ritenuta,
            'perc_imponibile_ritenuta'   => $this->perc_imponibile_ritenuta ?? '100',
            'codice_tributo'             => $this->codice_tributo,
            'giorni_scadenza'            => $this->giorni_scadenza ?? 0,
            'modalita_pagamento_default' => $this->modalita_pagamento_default ?? 'bonifico',
            
            // Logica IBAN: prende la colonna flat o cerca nel modulo conti correnti
            'iban_principale'            => $this->iban_principale ?? $this->contiCorrenti()->where('tipo', 'ordinario')->value('iban'),

            // --- Relazioni ---
            'referenti'             => AnagraficaResource::collection($this->whenLoaded('referenti')),
        ];
    }
}