<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class CreateImpostazioniGeneraliRequest extends FormRequest
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
            'user_frontend_registration' => 'required|boolean',
            'language'                   => 'required|in:it,en,pt',
            'open_condominio_on_login'   => 'required|boolean',
            'default_condominio_id'      => [
                'required_if:open_condominio_on_login,true',
                'nullable',
                'exists:condomini,id',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'default_condominio_id.required_if' => 'Devi selezionare un condominio.',
            'default_condominio_id.exists' => 'Il condominio selezionato non è valido.',
        ];
    }
}