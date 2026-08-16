<?php

namespace App\Http\Requests\Gestionale\Immobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @method bool merge(string $key)
 * @method \Illuminate\Routing\Route|null route(string|null $param = null, mixed $default = null)
 * @property-read string|null $codice_catasto
 * @property-read string $interno
 * @property-read string $sezione_catasto
 * @property-read string $foglio_catasto
 * @property-read string $particella_catasto
 * @property-read string $subalterno_catasto
 * @method string|null input(string $key, mixed $default = null)
 */
class UpdateImmobileRequest extends FormRequest
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
            'nome'                => 'required|string|max:255', 
            'descrizione'         => 'required|string|max:255',
            'comune_catasto'      => 'sometimes|nullable|string|max:255',
            'codice_catasto'      => 'sometimes|nullable|string|max:255',
            'sezione_catasto'     => 'sometimes|nullable|string|max:255',
            'foglio_catasto'      => 'sometimes|nullable|string|max:255',
            'particella_catasto'  => 'sometimes|nullable|string|max:255',
            'subalterno_catasto'  => 'sometimes|nullable|string|max:255',
            'interno'             => 'required|string|max:255',
            'piano'               => 'sometimes|nullable|string|max:255',
            'superficie'          => 'sometimes|nullable|numeric',
            'numero_vani'         => 'sometimes|nullable|integer',
            'note'                => 'sometimes|nullable|string',
            'palazzina_id'        => ['sometimes', 'nullable', 'integer', Rule::exists('palazzine', 'id')],
            'scala_id'            => ['sometimes', 'nullable', 'integer', Rule::exists('scale', 'id')],
            'tipologia_id'        => ['required', 'integer', Rule::exists('tipologie_immobili', 'id')],

            /*
             * Il legame «Pertinenza di». Tre regole che lo schema **non** presidia di proposito:
             * si spiegano meglio con un messaggio che con un errore SQL, e una foreign key
             * composita irrigidirebbe la tabella per due vincoli applicativi.
             *
             * 1. Il principale sta **nello stesso condominio**. Il caso legittimo di un principale
             *    altrove — il parcheggio Tognoli, art. 9 co. 5 L. 122/1989 — si dichiara nel campo
             *    di testo libero, non qui.
             * 2. Non è **l'unità stessa**: una cosa non è pertinenza di se stessa.
             * 3. Non è a sua volta una pertinenza: il legame ha **profondità uno**. Le catene non
             *    hanno corrispettivo in diritto e renderebbero ambigua qualunque lettura.
             *
             * Le due forme sono **alternative**: o il principale è qui, o è fuori. Averle
             * entrambe significherebbe due principali, che è la cardinalità appena tolta.
             */
            'pertinenza_di_immobile_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('immobili', 'id')->where(
                    // ⚠️ **Il perimetro è quello dell'unità, non quello dell'indirizzo.**
                    //
                    // La rotta `immobili` non ha binding vincolato: `/gestionale/{condominio}/
                    // immobili/{immobile}` risolve l'unità per sola chiave, e il condominio nella
                    // URL può benissimo essere un altro. Ancorando la regola a quello, cambiare un
                    // numero nell'indirizzo bastava a dichiarare un box di un condominio pertinenza
                    // di un appartamento di un altro — e da lì in poi il legame è invisibile a
                    // entrambi gli elenchi, perché ciascuno filtra sul proprio perimetro.
                    fn ($q) => $q->where('condominio_id', $this->condominioDellUnita())
                        // ⚠️ **Due colonne, non una.** Una pertinenza lo è in entrambe le sue
                        // forme: un box già legato a un appartamento fuori dal condominio — il
                        // caso Tognoli — è una pertinenza tanto quanto uno legato qui dentro.
                        // Guardando la sola chiave esterna, sceglierlo come principale passava
                        // la validazione e costruiva la catena che la regola 3 vieta.
                        ->whereNull('pertinenza_di_immobile_id')
                        ->whereNull('pertinenza_di_esterna')
                ),
                Rule::notIn([$this->route('immobile')?->id]),
            ],
            'pertinenza_di_esterna' => [
                'sometimes', 'nullable', 'string', 'max:255',
                'prohibited_unless:pertinenza_di_immobile_id,null',
            ],
        ];
    }

    /**
     * Il condominio a cui l'unità appartiene davvero, con ripiego sull'indirizzo.
     *
     * Il ripiego non è un caso residuo: in creazione l'unità non esiste ancora, e il condominio
     * della URL è l'unica fonte che ci sia — lì è anche quella giusta, perché è il condominio in
     * cui l'unità sta per nascere.
     */
    private function condominioDellUnita(): ?int
    {
        $immobile = $this->route('immobile');

        return $immobile instanceof \App\Models\Immobile
            ? $immobile->condominio_id
            : $this->route('condominio')?->id;
    }

    /**
     * La profondità uno vista **dal lato del soggetto**.
     *
     * ⚠️ Le regole in `rules()` guardano tutte il **bersaglio**: che l'unità scelta come principale
     * non sia a sua volta una pertinenza. Nessuna guardava chi sta compilando. Restava perciò
     * raggiungibile la catena dall'altro capo: si apre l'Appartamento 1, che ha già Box 8 come
     * pertinenza, e lo si dichiara pertinenza del Negozio 2. Ogni singola regola passava — il
     * Negozio 2 non è una pertinenza, non è sé stesso, sta nel condominio — e ne usciva
     * `Negozio 2 → Appartamento 1 → Box 8`, cioè esattamente la catena che il progetto vieta.
     *
     * Non è una svista teorica: il campo è disponibile su **tutte** le unità per scelta, proprio
     * perché una classificazione sbagliata non deve rendere una funzione irraggiungibile. Il
     * prezzo di quella scelta è che l'ordine in cui si dichiarano i legami non deve contare.
     *
     * Vale per entrambe le forme: anche `pertinenza_di_esterna` rende l'unità una pertinenza.
     *
     * Sta solo qui e non in creazione: un'unità che non esiste ancora non può avere pertinenze.
     */
    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function ($validator) {
            $immobile = $this->route('immobile');

            if (! $immobile instanceof \App\Models\Immobile) {
                return;
            }

            $diventaPertinenza = filled($this->input('pertinenza_di_immobile_id'))
                || filled($this->input('pertinenza_di_esterna'));

            if (! $diventaPertinenza) {
                return;
            }

            $quante = $immobile->pertinenze()->count();

            if ($quante === 0) {
                return;
            }

            // Il messaggio dice **quante** e **cosa fare**: chi ci arriva ha in testa un legame
            // preciso, e «non consentito» da solo lo lascerebbe a indovinare quale.
            $messaggio = $quante === 1
                ? "«{$immobile->nome}» ha già 1 pertinenza collegata, quindi non può diventare a sua volta una pertinenza: il collegamento ha profondità uno. Scollega prima quella pertinenza, oppure collegala direttamente all'unità principale."
                : "«{$immobile->nome}» ha già {$quante} pertinenze collegate, quindi non può diventare a sua volta una pertinenza: il collegamento ha profondità uno. Scollega prima quelle pertinenze, oppure collegale direttamente all'unità principale.";

            $campo = filled($this->input('pertinenza_di_immobile_id'))
                ? 'pertinenza_di_immobile_id'
                : 'pertinenza_di_esterna';

            $validator->errors()->add($campo, $messaggio);
        });
    }

    /**
     * Prepare data before validation.
     * Uppercases relevant string fields.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'interno'        => strtoupper((string) $this->interno),
            'codice_catasto' => $this->codice_catasto
                ? strtoupper($this->codice_catasto)
                : null,
            'sezione_catasto' => $this->sezione_catasto
                ? strtoupper($this->sezione_catasto)
                : null,
            'foglio_catasto' => $this->foglio_catasto
                ? strtoupper($this->foglio_catasto)
                : null,
            'particella_catasto' => $this->particella_catasto
                ? strtoupper($this->particella_catasto)
                : null,
            'subalterno_catasto' => $this->subalterno_catasto
                ? strtoupper($this->subalterno_catasto)
                : null,

        ]);
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes()
    {
        return [
            'tipologia_id'  => __('validation.attributes.immobili.tipologia_id')
        ];
    }
}
