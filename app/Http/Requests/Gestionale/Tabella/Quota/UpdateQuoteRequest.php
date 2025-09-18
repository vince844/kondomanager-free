<?php

namespace App\Http\Requests\Gestionale\Tabella\Quota;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * @method bool merge(string $key)
 * @method \Illuminate\Routing\Route|null route(string|null $param = null, mixed $default = null)
 */
class UpdateQuoteRequest extends FormRequest
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
            'quote'                       => ['array'],
            'quote.*.immobile.id'         => ['required', 'exists:immobili,id'],
            'quote.*.valore'              => ['required', 'numeric'],
            'quote.*.has_contatore'       => ['boolean'],
            'quote.*.ultima_lettura'      => ['nullable', 'numeric'],
            'quote.*.coeff_dispersione'   => ['nullable', 'numeric'],
            'quote.*.quota_fissa'         => ['nullable', 'numeric'],
            'quote.*.quota_variabile'     => ['nullable', 'numeric'],
            'created_by'                  => 'required|exists:users,id',
            'updated_by'                  => 'required|exists:users,id',
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
            'updated_by'     => $user->id,
        ]);
    }

    public function messages(): array
    {
        return [
            'quote.*.immobile.id.required' => 'Specifica immobile da associare',
            'quote.*.immobile.id.exists'   => 'Uno degli immobili selezionati non esiste.',
            'quote.*.valore.required'      => 'Specifica millesimi',
            'quote.*.valore.numeric'       => 'Il valore millesimale deve essere numerico.',
        ];
    }

}
