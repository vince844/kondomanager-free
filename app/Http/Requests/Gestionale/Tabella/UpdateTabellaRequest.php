<?php

namespace App\Http\Requests\Gestionale\Tabella;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Auth;

/**
 * @method bool merge(string $key)
 * @method \Illuminate\Routing\Route|null route(string|null $param = null, mixed $default = null)
 */
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
            'nome'            => 'required|string|max:255',
            'tipo'            => 'required|string|in:standard,ascensore,riscaldamento,acqua,lastrico,speciale,altro',
            'quota'           => 'required|string|in:millesimi,persone,kwatt,mtcubi,quote',
            // `max:5` e non 6: `quote_tabella.valore` è un `decimal(12,5)`, quindi un sesto
            // decimale il database non lo può conservare — verrebbe troncato in silenzio.
            // In creazione il limite era già 5 (`CreateTabellaRequest:34`): erano due numeri
            // diversi per lo stesso vincolo, e quello sbagliato era qui.
            'numero_decimali' => 'required|integer|min:0|max:5',
            'updated_by'      => 'required|exists:users,id',
            'descrizione'     => 'nullable|string',
            'note'            => 'nullable|string',
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
            'updated_by'     => $user->id,
        ]);
    }
}
