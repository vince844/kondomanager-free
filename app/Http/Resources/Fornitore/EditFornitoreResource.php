<?php

namespace App\Http\Resources\Fornitore;

use App\Helpers\MoneyHelper;
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
            // --- Dati Base ---
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
            'stato'                    => $this->stato ?? 'attivo', // <-- Aggiunto Stato
            
            // --- Dati Societari ---
            'iscrizione_cciaa'         => $this->iscrizione_cciaa,
            'codice_ateco'             => $this->codice_ateco,
            'data_iscrizione_cciaa'    => $this->data_iscrizione_cciaa,
            'capitale_sociale'         => MoneyHelper::format($this->capitale_sociale ?? 0),
            'certificazione_iso'       => (bool) $this->certificazione_iso,
            'numero_iscrizione_ordine' => $this->numero_iscrizione_ordine,

            // --- NUOVI CAMPI FISCALI E TESORERIA (V 1.9) ---
            'soggetto_ritenuta'          => (bool) $this->soggetto_ritenuta,
            'perc_ritenuta'              => $this->perc_ritenuta,
            'perc_imponibile_ritenuta'   => $this->perc_imponibile_ritenuta ?? '100',
            'codice_tributo'             => $this->codice_tributo,
            'giorni_scadenza'            => $this->giorni_scadenza ?? 30,
            'modalita_pagamento_default' => $this->modalita_pagamento_default ?? 'bonifico',

            // --- NUOVI CAMPI: Regime Fiscale Ritenuta (V 1.10, Fase 1) ---
            'tipo_ritenuta'                 => $this->tipo_ritenuta?->value,
            'natura_percipiente'            => $this->natura_percipiente?->value,
            'residente_fiscale'             => (bool) ($this->residente_fiscale ?? true),
            'regime_forfetario'             => (bool) $this->regime_forfetario,
            'forfetario_dichiarato_il'      => $this->forfetario_dichiarato_il,
            'forfetario_riferimento'        => $this->forfetario_riferimento,
            'provvigioni_base_ridotta'      => (bool) $this->provvigioni_base_ridotta,
            'provvigioni_dichiarazione_il'  => $this->provvigioni_dichiarazione_il,

            // Magia 1: Recupera l'IBAN principale (o dalla colonna o dal conto corrente polimorfico associato)
            'iban_principale'          => $this->iban_principale ?? $this->contiCorrenti()->where('tipo', 'ordinario')->value('iban'),
            
            // Magia 2: Recupera il primo referente associato per ripopolare la v-select in Vue
            'anagrafica_id'            => $this->referenti()->first()?->id,

            // --- Timestamps ---
            'created_at'               => $this->created_at,
            'updated_at'               => $this->updated_at,
        ];
    }
}