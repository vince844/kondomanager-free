<?php

namespace App\Http\Requests\Gestionale\Tabella;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTabellaRequest extends FormRequest
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
            'nome'           => 'required|string|max:255',
            'tipologia'      => 'required|string|in:standard,ascensore,riscaldamento,acqua,lastrico,speciale,altro',
            'quota'          => 'required|string|in:millesimi,persone,kwatt,mtcubi,quote',
            'descrizione'    => 'nullable|string',
            'note'           => 'nullable|string',
            'palazzina_id'   => ['sometimes', 'nullable', 'integer', Rule::exists('palazzine', 'id')],
            'scala_id'       => ['sometimes', 'nullable', 'integer', Rule::exists('scale', 'id')],
        ];
    }
}
