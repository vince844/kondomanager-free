<?php

namespace App\Http\Requests\Fornitore;

use App\Enums\Fiscale\NaturaPercipiente;
use App\Enums\RuoloRappresentanteFornitore;
use App\Enums\Fiscale\TipoRitenuta;
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
            // Le lunghezze seguono le colonne, non un numero comodo — vedi UpdateFornitoreRequest.
            'codice_fiscale'           => 'nullable|string|max:20|unique:'.Fornitore::class.',codice_fiscale',
            'nazione'                  => 'nullable|string|max:50',
            'indirizzo'                => 'nullable|string|max:255',
            'comune'                   => 'nullable|string|max:100',
            'cap'                      => 'nullable|string|max:10',
            'provincia'                => 'nullable|string|max:5',

            // --- Contatti ---
            'telefono'                 => 'nullable|string|max:50',
            'cellulare'                => 'nullable|string|max:50',
            'fax'                      => 'nullable|string|max:50',
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
            // Il rappresentante scelto qui nasce **con il suo ruolo**, come pretende la scheda
            // «Rappresentanti»: fino alla beta.6 questa strada faceva `attach()` senza ruolo e
            // creava una riga che la porta gemella avrebbe rifiutato — due strade per la stessa
            // cosa che davano esiti diversi.
            'anagrafica_id'            => ['nullable','integer',Rule::exists('anagrafiche', 'id')],
            'ruolo'                    => ['nullable','required_with:anagrafica_id','string',Rule::in(RuoloRappresentanteFornitore::valori())],

            // --- NUOVI CAMPI: Fatturazione e Pagamenti ---
            'iban_principale'            => 'nullable|string|max:34',
            'modalita_pagamento_default' => 'required|string|in:bonifico,mav,ri.ba,contanti',
            'giorni_scadenza'            => 'required|integer|min:0',
            
            // --- NUOVI CAMPI: Ritenuta d'Acconto ---
            // Stessa regola di UpdateFornitoreRequest, e per la stessa ragione: il riquadro si
            // dichiara «Override manuale (facoltativo)» e questi campi non possono essere
            // obbligatori. L'unico davvero necessario è `perc_ritenuta`, e solo quando non c'è un
            // `tipo_ritenuta` — perché è l'unico caso in cui `RitenutaService` non saprebbe che
            // aliquota applicare.
            'soggetto_ritenuta'          => 'boolean',
            'perc_ritenuta'              => ['nullable', Rule::requiredIf(
                fn () => $this->boolean('soggetto_ritenuta') && blank($this->input('tipo_ritenuta'))
            ), 'numeric', 'min:0', 'max:100'],
            'perc_imponibile_ritenuta'   => 'nullable|numeric|min:0|max:100',
            'codice_tributo'             => 'nullable|string|max:10',

            // --- NUOVI CAMPI: Regime Fiscale Ritenuta (v1.10, Fase 1) ---
            // Additivi ai campi legacy sopra: codice_tributo resta un override
            // motivato, la fonte di verità diventa tipo_ritenuta + natura_percipiente.
            'tipo_ritenuta'                => ['nullable', Rule::in(array_column(TipoRitenuta::cases(), 'value'))],
            'natura_percipiente'           => ['nullable', Rule::in(array_column(NaturaPercipiente::cases(), 'value'))],
            'residente_fiscale'            => 'boolean',
            'regime_forfetario'            => 'boolean',
            'forfetario_dichiarato_il'     => 'nullable|date|required_if:regime_forfetario,true',
            'forfetario_riferimento'       => 'nullable|string|max:255',
            'provvigioni_base_ridotta'     => 'boolean',
            'provvigioni_dichiarazione_il' => 'nullable|date|required_if:provvigioni_base_ridotta,true',
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
            
            // `perc_imponibile_ritenuta` è NOT NULL con default 100.00 — vedi UpdateFornitoreRequest.
            'perc_imponibile_ritenuta' => blank($this->input('perc_imponibile_ritenuta'))
                ? 100
                : $this->input('perc_imponibile_ritenuta'),

            // Assicuriamoci che i booleani siano strict (utile per il form)
            'soggetto_ritenuta'     => filter_var($this->soggetto_ritenuta, FILTER_VALIDATE_BOOLEAN),
            'certificazione_iso'    => filter_var($this->certificazione_iso, FILTER_VALIDATE_BOOLEAN),
            'residente_fiscale'         => filter_var($this->input('residente_fiscale', true), FILTER_VALIDATE_BOOLEAN),
            'regime_forfetario'         => filter_var($this->regime_forfetario, FILTER_VALIDATE_BOOLEAN),
            'provvigioni_base_ridotta'  => filter_var($this->provvigioni_base_ridotta, FILTER_VALIDATE_BOOLEAN),
            
            // Pulizia IBAN: rimuove gli spazi e mette tutto maiuscolo
            'iban_principale'       => $this->iban_principale ? Str::upper(str_replace(' ', '', $this->iban_principale)) : null,
            
            // Maiuscolo per i codici fiscali e tributo
            'codice_tributo'        => $this->codice_tributo ? Str::upper($this->codice_tributo) : null,
            'partita_iva'           => $this->partita_iva ? Str::upper($this->partita_iva) : null,
            'codice_fiscale'        => $this->codice_fiscale ? Str::upper($this->codice_fiscale) : null,
            // Il campo ha `class="uppercase"`, che è solo CSS: scrivendo «rm» a schermo si legge RM
            // ma a database finiva «rm», e la scheda di sola lettura lo ristampa grezzo.
            'provincia'             => $this->provincia ? Str::upper($this->provincia) : null,
        ]);
    }
}