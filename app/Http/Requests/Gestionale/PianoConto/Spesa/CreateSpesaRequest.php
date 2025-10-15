<?php

namespace App\Http\Requests\Gestionale\PianoConto\Spesa;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Cknow\Money\Money;

class CreateSpesaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'nome' => 'required|string|max:255',
            'descrizione' => 'nullable|string',
            'tipo' => 'required|in:spesa,entrata',
            'note' => 'nullable|string',
            'isCapitolo' => 'required|boolean',
            'isSottoConto' => 'required|boolean', 
            'tabella_millesimale_id' => 'nullable|exists:tabelle,id',
            'importo'      => 'required|numeric',
            'parent_id' => 'nullable|exists:conti,id',
        ];

        // Importo obbligatorio solo se non è un capitolo
        if (!$this->isCapitolo) {
            $rules['percentuale_proprietario'] = 'required|numeric|min:0|max:100';
            $rules['percentuale_inquilino'] = 'required|numeric|min:0|max:100';
            $rules['percentuale_usufruttuario'] = 'required|numeric|min:0|max:100';
        }

        // Parent_id obbligatorio solo se è un sottoconto
        if ($this->isSottoConto) {
             $rules['parent_id'] = 'required|exists:conti,id';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'Il nome è obbligatorio',
            'tipo.required' => 'Il tipo è obbligatorio',
            'importo.required' => 'L\'importo è obbligatorio per le voci di spesa',
            'parent_id.required' => 'Il conto padre è obbligatorio per i sottoconti',
            'percentuale_proprietario.required' => 'La percentuale proprietario è obbligatoria',
            'percentuale_inquilino.required' => 'La percentuale inquilino è obbligatoria',
            'percentuale_usufruttuario.required' => 'La percentuale usufruttuario è obbligatoria',
            'tabella_millesimale_id.exists' => 'La tabella millesimale selezionata non è valida',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Verifica che la somma delle percentuali sia 100 se non è un capitolo
            if (!$this->isCapitolo) {
                $somma = $this->percentuale_proprietario + 
                         $this->percentuale_inquilino + 
                         $this->percentuale_usufruttuario;
                
                if ($somma != 100) {
                    $validator->errors()->add(
                        'percentuale_proprietario', 
                        'La somma delle percentuali deve essere 100%'
                    );
                }
            }
        });
    }

     /**
     * Prepare data before validation.
     * Uppercases relevant string fields and merges condominio_id from route.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'importo' => $this->importo !== null
                ? (int) Money::EUR($this->importo)->getAmount() // ← converte in centesimi
                : 0,
        ]);

    }

}