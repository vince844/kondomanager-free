<?php

namespace App\Http\Requests\Gestionale\Immobile\Anagrafica;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @method bool merge(string $key)
 * @method bool filled(string $key)
 * @method \Illuminate\Routing\Route|null route(string|null $param = null, mixed $default = null)
 * @method string|null input(string $key, mixed $default = null)
 */
class CreateImmobileAnagraficaRequest extends FormRequest
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
            'quota'               => 'required|numeric', 
            'note'                => 'sometimes|nullable|string',
            'anagrafica_id'       => ['required', 'integer', Rule::exists('anagrafiche', 'id')],
            'condominio_id'       => ['required', 'integer', Rule::exists('condomini', 'id')],
            'immobile_id'         => ['required', 'integer', Rule::exists('immobili', 'id')],
            'tipologia'           => 'required|in:proprietario,inquilino,usufruttuario',
            'data_inizio'         => 'required|date',
            'data_fine'           => 'sometimes|nullable|date',
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
            'immobile_id'  => $this->route('immobile')->id,
        ]);

        if ($this->filled('data_inizio')) {
            $this->merge([
                'data_inizio' => Carbon::parse($this->input('data_inizio'))->toDateString(),
            ]);
        }

        $this->merge([
            'data_fine' => $this->filled('data_fine')
                ? Carbon::parse($this->input('data_fine'))->toDateString()
                : null,
        ]);
    }
}
