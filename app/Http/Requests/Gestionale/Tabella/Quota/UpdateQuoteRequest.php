<?php

namespace App\Http\Requests\Gestionale\Tabella\Quota;

use App\Models\Condominio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * @method bool merge(string $key)
 * @method \Illuminate\Routing\Route|null route(string|null $param = null, mixed $default = null)
 */
class UpdateQuoteRequest extends FormRequest
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
        return [
            // ⚠️ `present` e non `required`: la chiave **deve esserci**, ma l'elenco può essere
            // vuoto — togliere tutte le righe e salvare è un'operazione legittima. Senza `present`
            // una richiesta priva della chiave `quote` svuotava la tabella e rispondeva con un
            // messaggio di successo.
            'quote'                       => ['present', 'array'],

            // ⚠️ **Senza questa regola l'id non arrivava al controller, e ogni salvataggio
            // cancellava e ricreava tutte le righe.** `Validator::$excludeUnvalidatedArrayKeys`
            // vale `true` per default: `validated()` restituisce solo le chiavi che una regola
            // nomina, quindi `id` veniva scartato in silenzio. A valle `$idsPresenti` risultava
            // sempre vuoto e `whereNotIn('id', [])` compila in `where 1 = 1`, cioè «cancella
            // tutto»; il ramo che aggiorna la riga esistente era codice morto e non veniva mai
            // preso. Effetti misurati: gli id delle quote cambiavano a ogni salvataggio, e
            // `created_by` veniva riscritto con l'ultimo che ha premuto «Salva» — il che
            // annullava, al primo salvataggio, la firma che questa stessa beta ha aggiunto alle
            // quote create da «associa tutti gli immobili esistenti».
            'quote.*.id'                  => ['nullable', 'integer'],
            // ⚠️ `exists:immobili,id` **senza perimetro** lasciava associare a questa tabella
            // l'unità di un altro condominio: l'id arriva dal **corpo** della richiesta, quindi
            // non lo chiude né il binding di rotta né `scopeBindings()`. La regola è ambitata al
            // condominio dell'indirizzo. (Misurato aprendo la beta.61: un immobile del condominio
            // 52 passava la validazione su una richiesta indirizzata al condominio 28.)
            'quote.*.immobile.id'         => ['required', $this->regolaImmobileDelCondominio()],
            // ⚠️ `nullable` e non `required`, dalla beta.61. Il `required` sembrava una difesa e
            // non lo era: **spingeva verso il difetto**. Chi spuntava «associa tutti gli immobili
            // esistenti» si ritrovava una tabella che non si poteva più salvare finché ogni riga
            // non era compilata, e la via d'uscita più rapida era scrivere `0` — che il motore di
            // riparto legge come «non partecipa», escludendo quel condòmino e facendo pagare la
            // sua quota agli altri, in silenzio.
            //
            // Con il valore facoltativo si può associare oggi e compilare domani, e soprattutto
            // «non ancora compilato» (NULL) smette di essere indistinguibile da «non partecipa»
            // (zero, o riga assente). È quella distinzione a rendere possibile l'avviso alla
            // generazione del piano rate — vedi `CalcoloQuoteService::getMillesimiNonCompilati()`.
            // ⚠️ `min:0` chiude il **terzo stato** che la dottrina di questa beta non copriva.
            // Un valore negativo non è NULL — quindi non avvisa — ed è ≤ 0, quindi il motore lo
            // salta come uno zero; ma a differenza dello zero **entra nel divisore**
            // (`CalcoloQuoteService`, `$quote->sum('valore')`), così la tabella pesa più del suo
            // coefficiente e la spesa si sposta fra tabelle. Misurato dalla revisione avversariale
            // della beta.61: due tabelle al 50/50 su un capitolo da € 1.000,00, con un `-900` in
            // una, spostano **€ 193,78** a carico di un'unità sola. In più il totale a video
            // ignora i negativi, quindi la pagina mostrava un divisore diverso da quello che il
            // motore usava davvero. Dalla schermata il meno non si può battere; da qui sì.
            'quote.*.valore'              => ['nullable', 'numeric', 'min:0'],
            // I cinque coefficienti di acqua e riscaldamento sono stati tolti nella beta.50:
            // venivano validati e salvati, e nessun calcolo li leggeva. Vedi la nota in
            // `TabellaQuotaController`. Il modulo contatori è previsto per la v1.15.
            'created_by'                  => 'required|exists:users,id',
            'updated_by'                  => 'required|exists:users,id',
        ];
    }

    /**
     * L'unità deve esistere **e appartenere al condominio dell'indirizzo**.
     *
     * Il condominio si legge dalla rotta, non dal corpo: è l'unico dato di questa richiesta che
     * un client non può scegliere. Se il parametro manca — non accade su questa rotta, ma la
     * regola deve reggere da sola — si ricade sul solo `exists`, che è la difesa di prima.
     */
    protected function regolaImmobileDelCondominio(): \Illuminate\Validation\Rules\Exists
    {
        $condominio = $this->route('condominio');
        $id = $condominio instanceof Condominio ? $condominio->id : $condominio;

        $regola = Rule::exists('immobili', 'id');

        return $id === null ? $regola : $regola->where('condominio_id', $id);
    }

    /**
     * Prepare data before validation.
     * Uppercases relevant string fields and merges condominio_id from route.
     */
    protected function prepareForValidation()
    {
        $user = Auth::user();

        $this->merge([
            'created_by'     => $user->id,
            'updated_by'     => $user->id,
        ]);
    }

    public function messages(): array
    {
        return [
            'quote.*.immobile.id.required' => 'Specifica immobile da associare',
            'quote.*.immobile.id.exists'   => 'Uno degli immobili selezionati non esiste in questo condominio.',
            'quote.*.valore.numeric'       => 'Il valore millesimale deve essere numerico.',
            'quote.*.valore.min'           => 'Il valore millesimale non può essere negativo.',
        ];
    }

}
