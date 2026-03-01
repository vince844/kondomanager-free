<?php

namespace App\Http\Requests\Condominio;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCondominioRequest extends FormRequest
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
        // Ottieni l'ID o il modello dalla route per ignorarlo nell'unique
        // Usa $this->route('condominio') se il parametro nella route si chiama {condominio}
        $condominioId = $this->condominio; 

        return [
            // Dati Generali
            'nome' => [
                'required',
                'string',
                'max:255',
                Rule::unique('condomini', 'nome')->ignore($condominioId),
            ],
            'codice_fiscale' => [
                'required',
                'string',
                'max:255',
                Rule::unique('condomini', 'codice_fiscale')->ignore($condominioId),
            ],
            'email' => [
                'nullable',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique('condomini', 'email')->ignore($condominioId),
            ],
            
            // Ubicazione
            'indirizzo'           => 'sometimes|nullable|string|max:255',
            'comune'              => 'sometimes|nullable|string|max:255',
            'provincia'           => 'sometimes|nullable|string|size:2',
            'cap'                 => 'sometimes|nullable|string|max:5',
            
            // Dati Strutturali
            'anno_costruzione'    => 'sometimes|nullable|integer|min:1000|max:' . date('Y'),
            'anno_acquisizione'   => 'sometimes|nullable|integer|min:1000|max:' . date('Y'),
            'numero_piani'        => 'sometimes|nullable|integer|min:1|max:255',
            
            // Note
            'note'                => 'sometimes|nullable|string',
            
            // Dati Catastali
            'comune_catasto'      => 'sometimes|nullable|string|max:255',
            'codice_catasto'      => 'sometimes|nullable|string|max:255',
            'sezione_catasto'     => 'sometimes|nullable|string|max:255',
            'foglio_catasto'      => 'sometimes|nullable|string|max:255',
            'particella_catasto'  => 'sometimes|nullable|string|max:255',
        ];
    }

    /**
    * Get the error messages for the defined validation rules.
    *
    * @return array<string, string>
    */
    public function messages(): array
    {
        return [
            'nome.required'           => trans('validation.custom.building.required'),
            'nome.unique'             => trans('validation.custom.building.unique'),
            'codice_fiscale.unique'   => trans('validation.custom.building.codice_fiscale.unique'),
            'codice_fiscale.required' => trans('validation.custom.building.codice_fiscale.required'),
            'provincia.size'          => 'La provincia deve essere composta da esattamente 2 caratteri.',
            'anno_costruzione.max'    => 'L\'anno di costruzione non può essere nel futuro.',
            'anno_acquisizione.max'   => 'L\'anno di acquisizione non può essere nel futuro.',
        ];
    }
}