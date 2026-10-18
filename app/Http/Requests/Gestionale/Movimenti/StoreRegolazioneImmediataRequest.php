<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use App\Actions\Gestionale\Movimenti\RegistraRegolazioneImmediataAction;
use App\Helpers\DateHelper;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
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
            // `integer` prima di `exists` su ogni id: `exists` accetta anche un elenco, che le letture dell'azione (`findOrFail`) trasformano in una
            // collezione e la richiesta finisce in un errore del server. Un elenco al posto dell'id è un errore sul campo.
            'gestione_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('gestioni', 'id')->where('condominio_id', $condominioId),
            ],
            'esercizio_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('esercizi', 'id')
                    ->where('condominio_id', $condominioId)
                    ->where('stato', 'aperto'),
            ],
            // Capitolo di spesa: deve appartenere a un piano dei conti del condominio.
            'conto_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('conti', 'id')->whereIn(
                    'piano_conto_id',
                    fn ($q) => $q->select('id')->from('piani_conti')->where('condominio_id', $condominioId)
                ),
            ],
            'cassa_id' => [
                'bail',
                'required',
                'integer',
                // ⚠️ **Stessa regola di StorePagamentoFornitoreRequest (beta.19), che qui
                // mancava.** Da un `fondo` non si paga: è una partizione del conto corrente, e
                // il denaro accantonato si libera prima con un giroconto verso la banca. Senza
                // questo vincolo un `cassa_id` di tipo fondo passava, l'azione scriveva AVERE
                // sul fondo, e il registro di contabilità — che per D15 esclude le righe sui
                // fondi — non mostrava un'uscita reale di denaro. Trovato dalla revisione
                // della beta.24.
                Rule::exists('casse', 'id')
                    ->where('condominio_id', $condominioId)
                    ->whereIn('tipo', ['banca', 'contanti', 'virtuale']),
            ],
            'fornitore_id' => ['bail', 'nullable', 'integer', 'exists:fornitori,id'],

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

    /**
     * La voce di entrata si rifiuta sul campo del capitolo, accanto al menu da cui si sceglie un'altra voce. Il testo è quello dell'Action (che
     * la rifiuta anche chiamata da sola). Il tipo si legge solo dentro il condominio: una voce altrui prende il rifiuto del perimetro.
     *
     * L'id si legge come lo legge la regola `integer`: «+5» e 5.0 valgono 5 come 5, e `exists` li accetta; se il controllo sul tipo li saltasse, la voce
     * di entrata arriverebbe all'Action e il rifiuto finirebbe nel riquadro delle operazioni non ammesse.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $id = filter_var($this->input('conto_id'), FILTER_VALIDATE_INT);
            if ($validator->errors()->has('conto_id') || $id === false) {
                return;
            }

            $voce = Conto::query()->whereKey($id)
                ->whereIn('piano_conto_id', PianoConto::query()->where('condominio_id', $this->route('condominio')?->id)->select('id'))
                ->first(['id', 'nome', 'tipo']);

            if ($voce !== null && Conto::segnoDelTipo((string) $voce->tipo) !== 1) {
                $validator->errors()->add('conto_id', RegistraRegolazioneImmediataAction::messaggioVoceDiEntrata($voce->nome));
            }
        });
    }

    public function messages(): array
    {
        return [
            'gestione_id.integer' => 'La gestione scelta non è valida: scegli una gestione dal menu.',
            'esercizio_id.integer' => "L'esercizio scelto non è valido: scegli un esercizio dal menu.",
            'conto_id.integer' => 'Il capitolo di spesa scelto non è valido: scegli una voce di spesa dal menu.',
            'cassa_id.integer' => 'La cassa scelta non è valida: scegli una cassa dal menu.',
            'fornitore_id.integer' => "Il fornitore scelto non è valido: scegli un fornitore dall'elenco.",
            'esercizio_id.exists' => "L'esercizio selezionato non esiste o non è aperto.",
            'conto_id.exists' => 'Il capitolo di spesa selezionato non appartiene a questo condominio.',
            'cassa_id.exists' => 'La cassa selezionata non appartiene a questo condominio, oppure è un fondo: da un fondo non si paga, si libera prima il denaro con un giroconto verso la banca.',
            'importo.gt' => "L'importo deve essere maggiore di zero.",
            'causale.required' => 'La causale è obbligatoria: è ciò che rende leggibile il libro giornale.',
            'data_operazione.before_or_equal' => 'Non è possibile registrare un movimento con data futura.',
            'data_operazione.required' => 'La data operazione è obbligatoria.',
        ];
    }
}
