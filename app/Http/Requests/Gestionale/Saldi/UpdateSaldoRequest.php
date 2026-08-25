<?php

namespace App\Http\Requests\Gestionale\Saldi;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Valida l'aggiornamento di un saldo iniziale.
 *
 * Controlli:
 *   - saldo_iniziale obbligatorio e intero (centesimi)
 *   - gestione_id obbligatorio e appartenente al condominio
 *   - il saldo non deve essere già applicato a un piano rate (muro contabile)
 *   - il saldo deve appartenere al condominio della route (ownership)
 */
class UpdateSaldoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'saldo_iniziale' => ['required', 'integer'],
            'gestione_id'    => ['required', 'integer', 'exists:gestioni,id'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $saldo      = $this->route('saldo');
                $condominio = $this->route('condominio');

                // Ownership: il saldo deve appartenere a questo condominio
                if ($saldo->condominio_id !== $condominio->id) {
                    $validator->errors()->add(
                        'saldo',
                        'Il saldo non appartiene a questo condominio.'
                    );
                    return;
                }

                // Muro contabile: la soglia è l'EMISSIONE, non la generazione.
                // Finché il piano non è emesso né incassato resta interamente
                // riscrivibile — «Ricalcola» lo dimostra — quindi il saldo che
                // lo alimenta si può ancora correggere.
                if ($saldo->eBloccato()) {
                    $piano = $saldo->pianoRate;

                    $validator->errors()->add(
                        'saldo',
                        $piano
                            ? "Non puoi modificare questo saldo: è incluso nel piano rate «{$piano->nome}», "
                                . 'che risulta già emesso in contabilità o con incassi registrati. '
                                . 'Per correggerlo annulla prima le emissioni o gli incassi di quel piano.'
                            : 'Non puoi modificare un saldo già incluso in un piano rate emesso.'
                    );
                    return;
                }

                // La gestione deve appartenere al condominio
                $gestioneAppartiene = $condominio->gestioni()
                    ->where('id', $this->input('gestione_id'))
                    ->exists();

                if (!$gestioneAppartiene) {
                    $validator->errors()->add(
                        'gestione_id',
                        'La gestione selezionata non appartiene a questo condominio.'
                    );
                }
            }
        ];
    }

    public function messages(): array
    {
        return [
            'saldo_iniziale.required' => 'Inserisci un importo.',
            'saldo_iniziale.integer'  => 'L\'importo deve essere un numero intero (centesimi).',
            'gestione_id.required'    => 'Seleziona una gestione.',
            'gestione_id.exists'      => 'La gestione selezionata non esiste.',
        ];
    }
}
