<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use App\Helpers\DateHelper;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registrazione a regolazione immediata — costo → banca/cassa in scrittura unica.
 *
 * Tutti gli `exists` sono SCOPATI sul condominio della rotta: senza scoping si
 * potrebbe registrare un movimento sulla cassa o sul capitolo di un altro
 * condominio (varco già presente in altre request del progetto, qui non replicato).
 *
 * I guard rail di dominio (ritenuta d'acconto, scadenziario) vivono nell'Action,
 * non qui: sono regole contabili, non di forma, e devono valere anche quando
 * l'Action è invocata da un service o da un test senza passare dall'HTTP.
 */
class StoreRegolazioneImmediataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $condominioId = $this->route('condominio')?->id;

        return [
            'gestione_id' => [
                'required',
                Rule::exists('gestioni', 'id')->where('condominio_id', $condominioId),
            ],
            'esercizio_id' => [
                'required',
                Rule::exists('esercizi', 'id')
                    ->where('condominio_id', $condominioId)
                    ->where('stato', 'aperto'),
            ],
            // Capitolo di spesa: deve appartenere a un piano dei conti del condominio.
            'conto_id' => [
                'required',
                Rule::exists('conti', 'id')->whereIn(
                    'piano_conto_id',
                    fn ($q) => $q->select('id')->from('piani_conti')->where('condominio_id', $condominioId)
                ),
            ],
            'cassa_id' => [
                'required',
                Rule::exists('casse', 'id')->where('condominio_id', $condominioId),
            ],
            'fornitore_id' => ['nullable', 'exists:fornitori,id'],

            // NB: non chiamarlo 'data' — lato Inertia collide con il metodo form.data()
            // di useForm e il campo non si lega mai al v-model.
            'data_operazione' => ['required', 'date', 'before_or_equal:'.DateHelper::oggiUtente()],
            'causale' => ['required', 'string', 'min:3', 'max:255'],
            'importo' => ['required', 'numeric', 'gt:0'],

            // "Registra e nuova": dopo il salvataggio si torna al modulo vuoto
            // invece che al dettaglio della scrittura.
            'crea_altro' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'esercizio_id.exists' => "L'esercizio selezionato non esiste o non è aperto.",
            'conto_id.exists' => 'Il capitolo di spesa selezionato non appartiene a questo condominio.',
            'cassa_id.exists' => 'La cassa selezionata non appartiene a questo condominio.',
            'importo.gt' => "L'importo deve essere maggiore di zero.",
            'causale.required' => 'La causale è obbligatoria: è ciò che rende leggibile il libro giornale.',
            'data_operazione.before_or_equal' => 'Non è possibile registrare un movimento con data futura.',
            'data_operazione.required' => 'La data operazione è obbligatoria.',
        ];
    }
}
