<?php

namespace App\Http\Requests\Gestionale\Casse;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCassaRequest extends FormRequest
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
             // Dati Generali Cassa
            'nome'           => 'required|string|max:255',
            'descrizione'    => 'nullable|string|max:255',
            'tipo'           => 'required|in:contanti,banca,fondo,virtuale',
            'saldo_iniziale' => 'nullable|string',
            'note'           => 'nullable|string',
            
            // Dati Bancari (Richiesti SOLO se tipo == banca)
            'iban'         => 'nullable|required_if:tipo,banca|size:27', // IBAN italiano standard 27 caratteri
            'istituto'     => 'nullable|string|max:255',
            'bic'          => 'nullable|string|max:20',
            'intestatario' => 'nullable|string|max:255',
            
            // Validazione enum database per tipo_conto
            'tipo_conto' => [
                'nullable',
                'required_if:tipo,banca',
                Rule::in(['ordinario', 'dedicato', 'estero', 'postale', 'contabilita_speciale', 'altro'])
            ],
            
            'predefinito' => 'boolean',
            
            // Dati Indirizzo Filiale
            'indirizzo' => 'nullable|string|max:255',
            'comune'    => 'nullable|string|max:255',
            'cap'       => 'nullable|string|max:10',
            'provincia' => 'nullable|string|max:5',
        ];
    }
}
