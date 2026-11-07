<?php

namespace App\Http\Requests\Gestionale\Saldi;

use App\Services\Gestionale\Debiti\OriginePregresso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * «Dichiara l'origine» di uno o più saldi: la voce (un esercizio, «dovuto con questo piano», «prima del primo esercizio», o di nuovo «non
 * dichiarata») e, per la prima, l'esercizio del condominio. Nessuna voce è già scelta: senza la voce la richiesta si rifiuta.
 *
 * Non tocca l'importo, quindi vale anche per un saldo fermo in un piano emesso, per le righe del conguaglio di un passaggio e per quelle da
 * cui una successione ha calcolato l'arretrato: le loro guardie sono sull'importo. Che i saldi siano di questo condominio lo controlla il
 * controller (404 per tutta la richiesta).
 */
class DichiaraOrigineSaldiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'saldi'                => ['required', 'array', 'min:1'],
            'saldi.*'              => ['integer'],
            'origine'              => ['required', 'string', Rule::in([...OriginePregresso::VOCI, OriginePregresso::NON_DICHIARATA])],
            'esercizio_origine_id' => ['nullable', 'integer'],
        ];
    }

    public function messages(): array
    {
        return [
            'saldi.required'   => 'Scegli almeno un saldo.',
            'origine.required' => 'Scegli che cosa dichiarare: un esercizio, «dovuto con questo piano» o «prima del primo esercizio».',
            'origine.in'       => 'Questa voce non c\'è: scegli un esercizio, «dovuto con questo piano» o «prima del primo esercizio».',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty() || $this->input('origine') !== OriginePregresso::ESERCIZIO) {
                    return;
                }
                $condominio = $this->route('condominio');
                $esercizio = (int) $this->input('esercizio_origine_id');
                if ($esercizio <= 0 || ! $condominio->esercizi()->whereKey($esercizio)->exists()) {
                    $validator->errors()->add('esercizio_origine_id', 'Scegli l\'esercizio d\'origine fra quelli del condominio.');
                }
            },
        ];
    }
}
