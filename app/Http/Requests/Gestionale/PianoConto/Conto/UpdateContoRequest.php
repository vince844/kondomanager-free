<?php

namespace App\Http\Requests\Gestionale\PianoConto\Conto;

use Illuminate\Foundation\Http\FormRequest;

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
        $rules = [
            'nome'                   => 'required|string|max:255',
            'descrizione'            => 'nullable|string',
            'tipo'                   => 'required|in:spesa,entrata',
            'note'                   => 'nullable|string',
            'isCapitolo'             => 'required|boolean',
            'isSottoConto'           => 'required|boolean', 
            'importo'                => 'sometimes|required|string',
            'parent_id'              => 'nullable|exists:conti,id',
        ];

        // Importo obbligatorio solo se non è un capitolo
        if (!$this->isCapitolo) {
            $rules['importo'] = 'required|string';
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
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            // Verifica che non si stia cercando di rendere un capitolo con sottoconti un conto normale
            $conto = $this->route('conto');
            if ($conto && $conto->sottoconti && $conto->sottoconti->count() > 0 && !$this->isCapitolo) {
                $validator->errors()->add(
                    'isCapitolo',
                    'Non è possibile trasformare un capitolo con sottoconti in una voce di spesa normale'
                );
            }
        });
    }
}