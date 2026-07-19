<?php

namespace App\Http\Requests\Gestionale\PianoConto\Conto;

use App\Models\Gestionale\Conto;
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
class CreateContoRequest extends FormRequest
{

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'nome'                   => 'required|string|max:255',
            'descrizione'            => 'nullable|string',
            'tipo'                   => 'required|in:spesa,entrata',
            'note'                   => 'nullable|string',
            'isCapitolo'             => 'required|boolean',
            'isSottoConto'           => 'required|boolean', 
            'tabella_millesimale_id' => 'nullable|exists:tabelle,id',
            // Il padre deve vivere nello STESSO piano dei conti: senza questo
            // vincolo un sottoconto poteva essere agganciato a un capitolo di un
            // altro condominio (o di un altro piano dello stesso), producendo un
            // albero contabile che attraversa i confini dello stabile.
            'parent_id'              => ['nullable', $this->regolaPadreNelPiano()],
            'codice'                 => ['nullable', 'string', 'max:20'],
            'default_fornitore_id'   => ['nullable', 'exists:fornitori,id'],
            'tipo_spesa'             => ['nullable', 'string', 'in:standard,professionista,lavori,utenza'],
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
            'nome.required'                         => 'Il nome è obbligatorio',
            'tipo.required'                         => 'Il tipo è obbligatorio',
            'importo.required'                      => 'L\'importo è obbligatorio per le voci di spesa',
            'parent_id.required'                    => 'Il conto padre è obbligatorio per i sottoconti',
            'percentuale_proprietario.required'     => 'La percentuale proprietario è obbligatoria',
            'percentuale_inquilino.required'        => 'La percentuale inquilino è obbligatoria',
            'percentuale_usufruttuario.required'    => 'La percentuale usufruttuario è obbligatoria',
            'tabella_millesimale_id.exists'         => 'La tabella millesimale selezionata non è valida',
            'tabella_millesimale_id.required'       => 'La tabella millesimale è obbligatoria per le voci di spesa.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'isCapitolo' => $this->boolean('isCapitolo'),
            'isSottoConto' => $this->boolean('isSottoConto'),
        ]);
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
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