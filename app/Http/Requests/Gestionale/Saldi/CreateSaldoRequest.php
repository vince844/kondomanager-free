<?php

namespace App\Http\Requests\Gestionale\Saldi;

use App\Models\Saldo;
use App\Traits\HasEsercizio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Valida e blocca i duplicati per entrambi i tipi di saldo:
 *
 * Personale: una sola riga per (esercizio, gestione, immobile, anagrafica)
 * Solidale:  una sola riga per (esercizio, gestione, immobile) con anagrafica_id NULL
 */
class CreateSaldoRequest extends FormRequest
{
    use HasEsercizio; 

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'anagrafica_id'  => ['nullable', 'integer', 'exists:anagrafiche,id'],
            'immobile_id'    => ['required', 'integer', 'exists:immobili,id'],
            'gestione_id'    => ['required', 'integer', 'exists:gestioni,id'],
            'saldo_iniziale' => ['required', 'integer'],
        ];
    }

    /**
     * Validazione after: controlla unicità in base al tipo di saldo.
     * Chiamata dopo che le rules() di base sono passate.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return; // Non proseguire se ci sono già errori
                }

                $condominio = $this->route('condominio');
                
                // 2. Usiamo il metodo del Trait passandogli il condominio
                $esercizio = $this->getEsercizioCorrente($condominio);

                if (!$esercizio) {
                    $validator->errors()->add('error', 'Nessun esercizio aperto per questo condominio.');
                    return;
                }

                $anagraficaId = $this->input('anagrafica_id');
                $gestioneId   = $this->input('gestione_id');
                $immobileId   = $this->input('immobile_id');

                $query = Saldo::where('esercizio_id', $esercizio->id)
                    ->where('gestione_id', $gestioneId)
                    ->where('immobile_id', $immobileId);

                if (is_null($anagraficaId)) {
                    // Saldo solidale: max uno per (esercizio, gestione, immobile)
                    $esiste = (clone $query)->whereNull('anagrafica_id')->exists();

                    if ($esiste) {
                        $validator->errors()->add(
                            'anagrafica_id',
                            'Esiste già un saldo solidale per questo immobile in questa gestione. Modifica quello esistente.'
                        );
                    }
                } else {
                    // Saldo personale: max uno per (esercizio, gestione, immobile, anagrafica)
                    $esiste = (clone $query)->where('anagrafica_id', $anagraficaId)->exists();

                    if ($esiste) {
                        $validator->errors()->add(
                            'anagrafica_id',
                            'Esiste già un saldo per questa anagrafica in questa gestione.'
                        );
                    }
                }
            }
        ];
    }

    public function messages(): array
    {
        return [
            'immobile_id.required' => 'Seleziona un immobile.',
            'gestione_id.required' => 'Seleziona una gestione.',
            'saldo_iniziale.required' => 'Inserisci un importo.',
            'saldo_iniziale.integer'  => 'L\'importo deve essere un numero intero (centesimi).',
            'anagrafica_id.exists'    => 'L\'anagrafica selezionata non esiste.',
        ];
    }
}