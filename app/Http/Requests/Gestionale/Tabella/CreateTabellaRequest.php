<?php

namespace App\Http\Requests\Gestionale\Tabella;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

/**
 * @method bool merge(string $key)
 * @method \Illuminate\Routing\Route|null route(string|null $param = null, mixed $default = null)
 */
class CreateTabellaRequest extends FormRequest
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
            'nome'            => 'required|string|max:255',
            'tipologia'       => 'required|string|in:standard,ascensore,riscaldamento,acqua,lastrico,speciale,altro',
            'quota'           => 'required|string|in:millesimi,persone,kwatt,mtcubi,quote',
            'numero_decimali' => 'required|integer|min:0|max:5',
            'created_by'      => 'required|exists:users,id',
            'descrizione'     => 'nullable|string',
            'note'            => 'nullable|string',
            'all_flats'       => 'nullable|boolean',
            'condominio_id'   => ['required', 'integer', Rule::exists('condomini', 'id')],
            'palazzina_id'    => ['sometimes', 'nullable', 'integer', Rule::exists('palazzine', 'id')],
            'scala_id'        => ['sometimes', 'nullable', 'integer', Rule::exists('scale', 'id')],
        ];
    }

    /**
     * Prepare data before validation.
     * Uppercases relevant string fields and merges condominio_id from route.
     */
    protected function prepareForValidation()
    {
        $user = Auth::user();

        $this->merge([
            'created_by'     => $user->id,
            'condominio_id'  => $this->route('condominio')->id,
        ]);
    }
}
