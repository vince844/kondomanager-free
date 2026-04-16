<?php

namespace App\Http\Requests\Gestionale\Casse;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCassaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $cassa = $this->route('cassa'); 
        $condominio = $this->route('condominio');

        return [
            'nome' => [
                'required', 'string', 'max:255',
                Rule::unique('casse')->where(function ($query) use ($condominio) {
                    return $query->where('condominio_id', $condominio->id);
                })->ignore($cassa->id),
            ],
            'descrizione' => 'nullable|string|max:255',
            'note'        => 'nullable|string',
            'saldo_iniziale' => 'nullable|string',
            
            'iban'         => 'nullable|required_if:tipo,banca|size:27',
            'istituto'     => 'nullable|string|max:255',
            'bic'          => 'nullable|string|max:20',
            'intestatario' => 'nullable|string|max:255',
            'tipo_conto'   => ['nullable', Rule::in(['ordinario', 'dedicato', 'estero', 'postale', 'contabilita_speciale', 'altro'])],
            'tipo'         => ['required', Rule::in(['contanti', 'banca', 'fondo', 'virtuale'])],
            
            'predefinito'  => 'boolean',
            
            'indirizzo' => 'nullable|string|max:255',
            'comune'    => 'nullable|string|max:255',
            'cap'       => 'nullable|string|max:10',
            'provincia' => 'nullable|string|max:5',

            // --- NUOVE REGOLE GOVERNANCE FONDI (Uguali al Create) ---
            'sottotipo_fondo' => [
                'nullable',
                Rule::requiredIf(fn () => $this->input('tipo') === 'fondo'),
                Rule::in(['generico', 'vincolato_lavori', 'tfr', 'morosita'])
            ],
            
            'vincolo_descrizione'   => 'nullable|string|max:255',
            'is_override_assemblea' => 'boolean',
            
            'motivazione_override'  => [
                'nullable',
                'string',
                Rule::requiredIf(fn () => 
                    $this->input('tipo') === 'fondo' && 
                    $this->input('sottotipo_fondo') !== 'generico' && 
                    $this->input('is_override_assemblea') === true
                )
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'sottotipo_fondo.required' => 'Devi specificare la destinazione d\'uso del fondo di riserva.',
            'motivazione_override.required' => 'Inserisci gli estremi della delibera o la motivazione per sbloccare questo fondo vincolato.',
        ];
    }
}