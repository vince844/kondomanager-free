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
        return [
            'nome'                => 'required|string|max:255|'.Rule::unique('buildings', 'nome')->ignore($this->id), 
            'codice_fiscale'      => 'required|string|max:255|'.Rule::unique('buildings', 'codice_fiscale')->ignore($this->id),
            'email'               => 'sometimes|nullable|string|lowercase|email|max:255|'.Rule::unique('buildings', 'email')->ignore($this->id),
            'indirizzo'           => 'sometimes|nullable',
            'note'                => 'sometimes|nullable',
            'comune_catasto'      => 'sometimes|nullable',
            'codice_catasto'      => 'sometimes|nullable',
            'sezione_catasto'     => 'sometimes|nullable',
            'foglio_catasto'      => 'sometimes|nullable',
            'particella_catasto'  => 'sometimes|nullable',
        ];
    }
}
