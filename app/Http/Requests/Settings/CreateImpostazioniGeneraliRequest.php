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
            'language'                   => 'required|in:it,en',
            'open_condominio_on_login'   => 'required|boolean',
            'default_condominio_id'      => 'nullable|exists:condomini,id',
        ];
    }
}
