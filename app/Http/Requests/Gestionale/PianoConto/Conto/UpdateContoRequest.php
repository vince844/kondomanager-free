<?php

namespace App\Http\Requests\Gestionale\PianoConto\Conto;

use App\Models\Gestionale\Conto;
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

    public function authorize(): bool
    {
        return true;
    }

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
            // Oltre a livello e cicli, il padre va vincolato al piano dei conti:
            // era l'unico controllo mancante e lasciava passare capitoli altrui.
            'parent_id'              => [
                'nullable',
                $this->regolaPadreNelPiano(),
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

        if (!$this->boolean('isCapitolo')) {
            $rules['importo'] = 'required|string';
            $rules['tabella_millesimale_id'] = 'required|exists:tabelle,id';
            $rules['percentuale_proprietario'] = 'required|numeric|min:0|max:100';
            $rules['percentuale_inquilino'] = 'required|numeric|min:0|max:100';
            $rules['percentuale_usufruttuario'] = 'required|numeric|min:0|max:100';
        } else {
            $rules['importo'] = 'nullable';
        }

        if ($this->boolean('isSottoConto')) {
            $rules['parent_id'] = ['required', $this->regolaPadreNelPiano()];
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
            $conto = $this->route('conto');

            if ($conto && $this->filled('parent_id') && (int) $this->parent_id === (int) $conto->id) {
                $validator->errors()->add(
                    'parent_id',
                    'Un conto non può essere il padre di sé stesso'
                );
            }

            // Il capitolo padre deve essere una voce di PRIMO LIVELLO: un sotto-conto
            // non può fare da padre (anche se lasciato a importo 0).
            if ($this->boolean('isSottoConto') && $this->filled('parent_id')) {
                $parent = Conto::find($this->parent_id);

                if ($parent && $parent->parent_id !== null) {
                    $validator->errors()->add(
                        'parent_id',
                        'Il padre selezionato è un sotto-conto: come capitolo padre puoi scegliere solo una voce di primo livello'
                    );
                }

                // Anti-ciclo: il padre non può essere un discendente del conto in modifica.
                if ($conto && $parent && in_array((int) $this->parent_id, $conto->getAllChildrenIds(), true)) {
                    $validator->errors()->add(
                        'parent_id',
                        'Non puoi impostare come padre un discendente di questo conto: creerebbe un ciclo'
                    );
                }
            }

            if ($conto && $conto->sottoconti && $conto->sottoconti->count() > 0 && !$this->boolean('isCapitolo')) {
                $validator->errors()->add(
                    'isCapitolo',
                    'Non è possibile trasformare un capitolo con sottoconti in una voce di spesa normale'
                );
            }

            if (!$this->boolean('isCapitolo')) {
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

    /**
     * Il conto padre deve appartenere allo stesso piano dei conti della rotta.
     * È il vincolo di appartenenza che mancava: `exists:conti,id` accettava
     * qualunque conto del database, compresi quelli di altri condomìni.
     */
    private function regolaPadreNelPiano(): \Illuminate\Validation\Rules\Exists
    {
        $pianoContoId = $this->route('pianoConto')?->id;

        return \Illuminate\Validation\Rule::exists('conti', 'id')
            ->where('piano_conto_id', $pianoContoId);
    }
}