<?php

namespace App\Http\Requests\Gestionale\Esercizio;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @method bool merge(string $key)
 * @method bool input(string $key)
 * @method \Illuminate\Routing\Route|null route(string|null $param = null, mixed $default = null)
 */
class CreateEsercizioRequest extends FormRequest
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
            'nome'                => 'required|string|max:255', 
            'descrizione'         => 'required|string|max:255',
            'data_inizio'         => 'required|date',
            'data_fine'           => 'required|date|after_or_equal:data_inizio',
            'stato'               => 'required|string|in:aperto,chiuso',
            'note'                => 'sometimes|nullable|string',
            'condominio_id'       => ['required', 'integer', Rule::exists('condomini', 'id')],
        ];
    }

    /**
     * Prepare data before validation.
     * Uppercases relevant string fields and merges condominio_id from route.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'condominio_id'  => $this->route('condominio')->id,
        ]);
    }
}
