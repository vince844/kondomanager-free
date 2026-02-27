<?php

namespace App\Http\Requests\Fornitore;

use App\Helpers\MoneyHelper;
use App\Models\Fornitore;
use App\Rules\UniqueEmailAcrossTables;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Illuminate\Support\Str;

class CreateFornitoreRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // --- Dati Base ---
            'ragione_sociale'          => 'required|string|max:255|unique:'.Fornitore::class.',ragione_sociale',
            'partita_iva'              => 'nullable|string|max:20|unique:'.Fornitore::class.',partita_iva',
            'codice_fiscale'           => 'nullable|string|max:255|unique:'.Fornitore::class.',codice_fiscale',
            'nazione'                  => 'nullable|string|max:100',
            'indirizzo'                => 'nullable|string|max:255',
            'comune'                   => 'nullable|string|max:100', 
            'cap'                      => 'nullable|string|max:10',
            'provincia'                => 'nullable|string|max:10',
            
            // --- Contatti ---
            'telefono'                 => 'nullable|string|max:20',
            'cellulare'                => 'nullable|string|max:20',
            'fax'                      => 'nullable|string|max:20',
            'email'                    => ['nullable','email','max:255',new UniqueEmailAcrossTables()],
            'pec'                      => ['nullable','email','max:255','different:email',new UniqueEmailAcrossTables()],
            'sito_web'                 => 'nullable|string|max:255',
            'note'                     => 'nullable|string',
            
            // --- Dati Societari ---
            'iscrizione_cciaa'         => 'nullable|string|max:100',
            'data_iscrizione_cciaa'    => 'nullable|date',
            'capitale_sociale'         => 'nullable',
            'categoria_id'             => 'nullable|integer|exists:categorie_fornitore,id',
            'codice_ateco'             => 'nullable|string|max:20',
            'numero_iscrizione_ordine' => 'nullable|string|max:100',
            'certificazione_iso'       => 'boolean',
            'anagrafica_id'            => ['nullable','integer',Rule::exists('anagrafiche', 'id')],

            // --- NUOVI CAMPI: Fatturazione e Pagamenti ---
            'iban_principale'            => 'nullable|string|max:34',
            'modalita_pagamento_default' => 'required|string|in:bonifico,mav,ri.ba,contanti',
            'giorni_scadenza'            => 'required|integer|min:0',
            
            // --- NUOVI CAMPI: Ritenuta d'Acconto ---
            'soggetto_ritenuta'          => 'boolean',
            'perc_ritenuta'              => 'nullable|required_if:soggetto_ritenuta,true|numeric|min:0|max:100',
            'perc_imponibile_ritenuta'   => 'nullable|required_if:soggetto_ritenuta,true|numeric|min:0|max:100',
            'codice_tributo'             => 'nullable|required_if:soggetto_ritenuta,true|string|max:10',
        ];
    }

    /**
     * Prepare data before validation.
     * Uppercases relevant string fields, cleans up IBAN, and formats dates.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'capitale_sociale'      => MoneyHelper::toCents($this->capitale_sociale) ?? 0,
            'data_iscrizione_cciaa' => $this->data_iscrizione_cciaa ? Carbon::parse($this->input('data_iscrizione_cciaa'))->toDateString() : null,
            'email'                 => $this->email ? Str::lower($this->input('email')) : null,
            'pec'                   => $this->pec ? Str::lower($this->input('pec')) : null,
            
            // Assicuriamoci che i booleani siano strict (utile per il form)
            'soggetto_ritenuta'     => filter_var($this->soggetto_ritenuta, FILTER_VALIDATE_BOOLEAN),
            'certificazione_iso'    => filter_var($this->certificazione_iso, FILTER_VALIDATE_BOOLEAN),
            
            // Pulizia IBAN: rimuove gli spazi e mette tutto maiuscolo
            'iban_principale'       => $this->iban_principale ? Str::upper(str_replace(' ', '', $this->iban_principale)) : null,
            
            // Maiuscolo per i codici fiscali e tributo
            'codice_tributo'        => $this->codice_tributo ? Str::upper($this->codice_tributo) : null,
            'partita_iva'           => $this->partita_iva ? Str::upper($this->partita_iva) : null,
            'codice_fiscale'        => $this->codice_fiscale ? Str::upper($this->codice_fiscale) : null,
        ]);
    }
}