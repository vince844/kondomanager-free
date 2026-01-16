<?php

namespace App\Http\Requests\Fornitore\Anagrafica;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateFornitoreAnagraficaRequest extends FormRequest
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
            'anagrafica_id'   => ['required', 'integer', Rule::exists('anagrafiche', 'id')],
            'ruolo'           => ['required', 'string', Rule::in(['titolare', 'amministrativo', 'commerciale', 'tecnico', 'referente', 'altro'])],
        ];
    }
}
