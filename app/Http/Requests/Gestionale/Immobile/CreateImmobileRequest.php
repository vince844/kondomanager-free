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
class CreateImmobileRequest extends FormRequest
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
            'descrizione'         => 'nullable|string|max:255',
            'comune_catasto'      => 'sometimes|nullable|string|max:255',
            'codice_catasto'      => 'sometimes|nullable|string|max:255',
            'sezione_catasto'     => 'sometimes|nullable|string|max:255',
            'foglio_catasto'      => 'sometimes|nullable|string|max:255',
            'particella_catasto'  => 'sometimes|nullable|string|max:255',
            'subalterno_catasto'  => 'sometimes|nullable|string|max:255',
            // Facoltativo dal 18/08/2026, su segnalazione dal forum: «nel caso di un posto auto
            // esterno non collegato a un immobile non credo abbia senso riportare questo dato».
            // Non serve una migrazione: `prepareForValidation()` converte con `(string)`, quindi
            // un valore assente diventa stringa vuota e il vincolo NOT NULL della colonna regge.
            // L'unità resta identificabile perché `nome` è obbligatorio ed è ciò che le stampe
            // mettono in testa.
            'interno'             => 'nullable|string|max:255',
            'piano'               => 'sometimes|nullable|string|max:255',
            'superficie'          => 'sometimes|nullable|numeric',
            'numero_vani'         => 'sometimes|nullable|integer',
            'note'                => 'sometimes|nullable|string',
            'condominio_id'       => ['required', 'integer', Rule::exists('condomini', 'id')],
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
                    fn ($q) => $q->where('condominio_id', $this->route('condominio')?->id)
                        // ⚠️ **Due colonne, non una.** Una pertinenza lo è in entrambe le sue
                        // forme: un box già legato a un appartamento fuori dal condominio — il
                        // caso Tognoli — è una pertinenza tanto quanto uno legato qui dentro.
                        // Guardando la sola chiave esterna, sceglierlo come principale passava
                        // la validazione e costruiva la catena che la regola 3 vieta.
                        ->whereNull('pertinenza_di_immobile_id')
                        ->whereNull('pertinenza_di_esterna')
                ),

            ],
            'pertinenza_di_esterna' => [
                'sometimes', 'nullable', 'string', 'max:255',
                'prohibited_unless:pertinenza_di_immobile_id,null',
            ],
        ];
    }

    /**
     * Prepare data before validation.
     * Uppercases relevant string fields and merges condominio_id from route.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'condominio_id'  => $this->route('condominio')->id,
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
