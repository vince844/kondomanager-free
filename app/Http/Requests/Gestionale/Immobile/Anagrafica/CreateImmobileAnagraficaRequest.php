<?php

namespace App\Http\Requests\Gestionale\Immobile\Anagrafica;

use App\Enums\RuoloAnagraficaImmobile;
use App\Traits\ValidatesImmobileAnagraficaPivot;
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
    use ValidatesImmobileAnagraficaPivot;

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
            // Vedi la nota gemella in `UpdateImmobileAnagraficaRequest`: il limite è sul valore
            // della singola riga, non sulla somma, che è presidiata da
            // `ValidatesImmobileAnagraficaPivot`.
            'quota'               => 'required|numeric|min:0|max:100',
            'note'                => 'sometimes|nullable|string',
            'anagrafica_id'       => ['required', 'integer', Rule::exists('anagrafiche', 'id')],
            'condominio_id'       => ['required', 'integer', Rule::exists('condomini', 'id')],
            'immobile_id'         => ['required', 'integer', Rule::exists('immobili', 'id')],
            // Il vocabolario dei ruoli sta in un posto solo. Prima era ridigitato qui e nella
            // Request di modifica, e nessuna delle due copie sapeva della colonna: aggiungere
            // `nuda_proprietario` allo schema senza toccarle avrebbe lasciato il server a
            // rifiutare un valore che il database accetta.
            'tipologia'           => ['required', Rule::in(RuoloAnagraficaImmobile::values())],
            'data_inizio'         => 'required|date',
            'data_fine'           => 'sometimes|nullable|date|after_or_equal:data_inizio',
        ];
    }

    public function withValidator($validator)
    {
        $this->withPivotValidator($validator);
    }

    /**
     * Prepare data before validation.
     * Uppercases relevant string fields and merges condominio_id from route.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'condominio_id'  => $this->route('condominio')->id,
            'immobile_id'    => $this->route('immobile')->id,
        ]);

    }
}
