<?php

namespace App\Http\Requests\Gestionale\Immobile\Anagrafica;

use App\Traits\ValidatesImmobileAnagraficaPivot;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * @method bool merge(string $key)
 * @method bool filled(string $key)
 * @method \Illuminate\Routing\Route|null route(string|null $param = null, mixed $default = null)
 * @method string|null input(string $key, mixed $default = null)
 */
class UpdateImmobileAnagraficaRequest extends FormRequest
{

    use ValidatesImmobileAnagraficaPivot;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quota'           => 'required|numeric', 
            'note'            => 'sometimes|nullable|string',
            'anagrafica_id'   => ['required','integer', Rule::exists('anagrafiche', 'id')],
            'tipologia'       => 'required|in:proprietario,inquilino,usufruttuario',
            'saldo_iniziale'  => 'nullable|string',
            'data_inizio'     => 'required|date',
            'data_fine'       => 'sometimes|nullable|date',
        ];
    }

   public function withValidator($validator)
    {
        $this->withPivotValidator($validator);
    }

    protected function prepareForValidation()
    {
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
