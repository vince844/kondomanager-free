<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use App\Actions\Gestionale\Movimenti\RegistraIncassoDaTerziAction;
use App\Helpers\DateHelper;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * L'incasso da terzi: la voce di entrata, la cassa reale, la data, l'importo, chi ha versato e la causale.
 *
 * Gli `exists` sono dentro il condominio della rotta, come per la regolazione immediata. Le regole di dominio (la voce di entrata, la cassa
 * reale) stanno anche nell'azione; qui si dicono sul campo, con lo stesso testo.
 */
class StoreIncassoDaTerziRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $condominioId = $this->route('condominio')?->id;

        return [
            // `integer` prima di `exists`: un elenco al posto dell'id è un errore sul campo, non del server.
            'esercizio_id' => [
                'bail', 'required', 'integer',
                Rule::exists('esercizi', 'id')->where('condominio_id', $condominioId)->where('stato', 'aperto'),
            ],
            'conto_id' => [
                'bail', 'required', 'integer',
                Rule::exists('conti', 'id')->whereIn(
                    'piano_conto_id',
                    fn ($q) => $q->select('id')->from('piani_conti')->where('condominio_id', $condominioId)
                ),
            ],
            'cassa_id' => [
                'bail', 'required', 'integer',
                Rule::exists('casse', 'id')->where('condominio_id', $condominioId),
            ],
            'anagrafica_id' => ['bail', 'nullable', 'integer', 'exists:anagrafiche,id'],
            // NB: non `data`, che lato Inertia collide con il metodo `form.data()`.
            'data_operazione' => ['required', 'date', 'before_or_equal:'.DateHelper::oggiUtente()],
            'causale' => ['required', 'string', 'min:3', 'max:255'],
            'importo' => ['required', 'numeric', 'gt:0'],
            'crea_altro' => ['nullable', 'boolean'],
            // Dove tornare dopo la registrazione: un elenco chiuso (`IncassoDaTerziController::PROVENIENZE`).
            'da' => ['nullable', Rule::in(\App\Http\Controllers\Gestionale\Movimenti\IncassoDaTerziController::PROVENIENZE)],
        ];
    }

    /** La voce di spesa e la cassa che non è reale si rifiutano sul loro campo, con il testo dell'azione. */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $condominioId = $this->route('condominio')?->id;

            $idVoce = filter_var($this->input('conto_id'), FILTER_VALIDATE_INT);
            if (! $validator->errors()->has('conto_id') && $idVoce !== false) {
                $voce = Conto::query()->whereKey($idVoce)
                    ->whereIn('piano_conto_id', PianoConto::query()->where('condominio_id', $condominioId)->select('id'))
                    ->first(['id', 'nome', 'tipo']);
                if ($voce !== null && Conto::segnoDelTipo((string) $voce->tipo) === 1) {
                    $validator->errors()->add('conto_id', RegistraIncassoDaTerziAction::messaggioVoceDiSpesa((string) $voce->nome));
                }
            }

            $idCassa = filter_var($this->input('cassa_id'), FILTER_VALIDATE_INT);
            if (! $validator->errors()->has('cassa_id') && $idCassa !== false) {
                $cassa = Cassa::query()->whereKey($idCassa)->where('condominio_id', $condominioId)->first(['id', 'nome', 'tipo']);
                if ($cassa !== null && ! in_array((string) $cassa->tipo, RegistraIncassoDaTerziAction::CASSE_REALI, true)) {
                    $validator->errors()->add('cassa_id', RegistraIncassoDaTerziAction::messaggioCassaNonReale((string) $cassa->nome));
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'esercizio_id.integer' => "L'esercizio scelto non è valido: scegli un esercizio dal menu.",
            'esercizio_id.exists' => "L'esercizio scelto non esiste o non è aperto.",
            'conto_id.required' => 'Scegli la voce di entrata su cui va l\'incasso.',
            'conto_id.integer' => 'La voce scelta non è valida: scegli una voce di entrata dal menu.',
            'conto_id.exists' => 'La voce scelta non appartiene a questo condominio.',
            'cassa_id.required' => 'Scegli la banca o la cassa in cui è entrato il denaro.',
            'cassa_id.integer' => 'La cassa scelta non è valida: scegli una cassa dal menu.',
            'cassa_id.exists' => 'La cassa scelta non appartiene a questo condominio.',
            'anagrafica_id.integer' => 'Chi ha versato non è valido: scegli una persona o una società dalla rubrica, o lascia vuoto.',
            'anagrafica_id.exists' => 'Chi ha versato non è nella rubrica: scegli una persona o una società dall\'elenco, o lascia vuoto.',
            'importo.gt' => "L'importo deve essere maggiore di zero.",
            'causale.required' => 'La causale è obbligatoria: è ciò che il registro di contabilità mostra.',
            'data_operazione.required' => 'La data dell\'incasso è obbligatoria.',
            'data_operazione.before_or_equal' => 'Non si registra un incasso con una data futura.',
        ];
    }
}
