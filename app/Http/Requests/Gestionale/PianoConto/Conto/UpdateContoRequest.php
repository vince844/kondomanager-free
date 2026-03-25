<?php

namespace App\Http\Requests\Gestionale\PianoConto\Conto;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @method bool merge(string $key)
 * @property-read string $isCapitolo
 * @property-read string $isSottoConto
 * @property-read string $importo
 * @property-read string $parent_id
 * @property-read string $percentuale_proprietario
 * @property-read string $percentuale_inquilino
 * @property-read string $percentuale_usufruttuario
 */
class UpdateContoRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'isCapitolo' => $this->boolean('isCapitolo'),
            'isSottoConto' => $this->boolean('isSottoConto'),
        ]);
    }

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
        $contoId = optional($this->route('conto'))->id;

        $rules = [
            'nome'                   => 'required|string|max:255',
            'descrizione'            => 'nullable|string',
            'tipo'                   => 'required|in:spesa,entrata',
            'note'                   => 'nullable|string',
            'isCapitolo'             => 'required|boolean',
            'isSottoConto'           => 'required|boolean', 
            'importo'                => 'sometimes|required|string',
            'parent_id'              => [
                'nullable',
                'exists:conti,id',
                Rule::notIn(array_filter([$contoId])),
            ],
            'codice'                 => ['nullable', 'string', 'max:20'],
            'default_fornitore_id'   => ['nullable', 'exists:fornitori,id'],
            'tipo_spesa'             => ['nullable', 'string', 'in:standard,professionista,lavori,utenza'],
            'tabella_millesimale_id' => 'nullable|exists:tabelle,id',
            'percentuale_proprietario' => 'nullable|numeric|min:0|max:100',
            'percentuale_inquilino' => 'nullable|numeric|min:0|max:100',
            'percentuale_usufruttuario' => 'nullable|numeric|min:0|max:100',
        ];

        // Importo obbligatorio solo se non è un capitolo
        if (!$this->isCapitolo) {
            $rules['importo'] = 'required|string';
            $rules['tabella_millesimale_id'] = 'required|exists:tabelle,id';
            $rules['percentuale_proprietario'] = 'required|numeric|min:0|max:100';
            $rules['percentuale_inquilino'] = 'required|numeric|min:0|max:100';
            $rules['percentuale_usufruttuario'] = 'required|numeric|min:0|max:100';
        }

        // Parent_id obbligatorio solo se è un sottoconto
        if ($this->isSottoConto) {
            $rules['parent_id'] = 'required|exists:conti,id';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'nome.required' => 'Il nome è obbligatorio',
            'tipo.required' => 'Il tipo è obbligatorio',
            'importo.required' => 'L\'importo è obbligatorio per le voci di spesa',
            'parent_id.required' => 'Il conto padre è obbligatorio per i sottoconti',
            'parent_id.not_in' => 'Un conto non può essere il padre di sé stesso',
            'tabella_millesimale_id.required' => 'La tabella millesimale è obbligatoria per le voci di spesa.',
            'tabella_millesimale_id.exists' => 'La tabella millesimale selezionata non è valida',
            'percentuale_proprietario.required' => 'La percentuale proprietario è obbligatoria',
            'percentuale_inquilino.required' => 'La percentuale inquilino è obbligatoria',
            'percentuale_usufruttuario.required' => 'La percentuale usufruttuario è obbligatoria',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Verifica che non si stia cercando di rendere un capitolo con sottoconti un conto normale
            $conto = $this->route('conto');

            if ($conto && $this->filled('parent_id') && (int) $this->parent_id === (int) $conto->id) {
                $validator->errors()->add(
                    'parent_id',
                    'Un conto non può essere il padre di sé stesso'
                );
            }

            if ($conto && $conto->sottoconti && $conto->sottoconti->count() > 0 && !$this->isCapitolo) {
                $validator->errors()->add(
                    'isCapitolo',
                    'Non è possibile trasformare un capitolo con sottoconti in una voce di spesa normale'
                );
            }

            if (!$this->isCapitolo) {
                $somma = $this->percentuale_proprietario +
                    $this->percentuale_inquilino +
                    $this->percentuale_usufruttuario;

                if ($somma != 100) {
                    $validator->errors()->add(
                        'percentuale_proprietario',
                        'La somma delle percentuali deve essere 100%'
                    );
                }
            }
        });
    }
}
