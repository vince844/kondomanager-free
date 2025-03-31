<?php

namespace App\Http\Requests\Condominio;

use App\Models\Condominio;
use Illuminate\Foundation\Http\FormRequest;

class CreateCondominioRequest extends FormRequest
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
            'nome'                => 'required|string|max:255|unique:'.Condominio::class, 
            'codice_fiscale'      => 'required|string|max:255|unique:'.Condominio::class,
            'email'               => 'sometimes|nullable|string|lowercase|email|max:255|unique:'.Condominio::class,
            'indirizzo'           => 'sometimes|nullable',
            'note'                => 'sometimes|nullable',
            'comune_catasto'      => 'sometimes|nullable',
            'codice_catasto'      => 'sometimes|nullable',
            'sezione_catasto'     => 'sometimes|nullable',
            'foglio_catasto'      => 'sometimes|nullable',
            'particella_catasto'  => 'sometimes|nullable',
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

            'nome.required' => trans('validation.custom.building.required'),
            'nome.unique' => trans('validation.custom.building.unique'),
            'codice_fiscale.unique' => trans('validation.custom.building.codice_fiscale.unique'),
            'codice_fiscale.required' => trans('validation.custom.building.codice_fiscale.required'),

        ];

    }

    /**
    * Get custom attributes for validator errors.
    *
    * @return array<string, string>
    */
    public function attributes()
    {
        return [
            'nome' => __('validation.attributes.building.nome'),
        ];
    }
}
