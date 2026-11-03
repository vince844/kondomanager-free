<?php

namespace App\Http\Requests\Comunicazione;

use App\Models\Comunicazione;
use App\Rules\CondominioDellUtente;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Gate;

/**
 * @method bool merge(string $key)
 */
class UpdateUserComunicazioneRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Prima delle regole (giro di sicurezza della 1.11.0-beta.39): `condomini_ids.*` ammette i
        // condomìni in cui il record è già, e validando per primi chiunque saprebbe in quali palazzi
        // sta un record che non può toccare (403 contro errore di validazione). Il Gate::authorize
        // nel controller resta: è ridondante ma innocuo, e lo cerca RotteSoloLoginTest.
        Gate::authorize('update', $this->route('comunicazione'));

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
            'subject'       => 'required|string|max:255',
            'description'   => 'required|string',
            'priority'      => 'required|string',
            'is_featured'   => 'required|boolean',
            // Coda 303: niente `created_by`, `is_approved`, `is_published`. Sono dati della
            // creazione (l'autore, e il calcolo dal permesso di chi crea): chi modifica la
            // comunicazione di un altro con un ruolo su misura non ne diventa l'autore, e la modifica
            // non la approva né la ritira (decisione 127.2). Senza regola non entrano in
            // `validated()` neanche se il corpo le porta.
            'is_private'    => 'sometimes|boolean',
            'condomini_ids' => ['required', 'array'],
            // Non basta che il condominio esista (giro di sicurezza della 1.11.0-beta.39, Coda 184):
            // deve essere uno dei condomìni dell'utente, altrimenti la comunicazione finisce nella
            // bacheca di un palazzo a cui non appartiene.
            // `integer` prima di tutto: un elemento-array arriverebbe a sync() come «chiave = id da
            // collegare», e la chiave non la controlla nessuno. I condomìni in cui la comunicazione
            // è già restano ammessi anche se l'autore non ne è più membro.
            'condomini_ids.*' => [
                'bail', 'integer', Rule::exists('condomini', 'id'),
                new CondominioDellUtente($this->condominiGiaCollegati()),
            ],
        ];
    }

    /**
     * @return array<int, int>
     */
    private function condominiGiaCollegati(): array
    {
        $comunicazione = $this->route('comunicazione');

        return $comunicazione instanceof Comunicazione
            ? $comunicazione->condomini()->pluck('condomini.id')->map(fn ($id) => (int) $id)->all()
            : [];
    }

    public function attributes()
    {
        return [
            'subject'       => __('validation.attributes.comunicazioni.subject'),
            'description'   => __('validation.attributes.comunicazioni.description'),
            'is_published'  => __('validation.attributes.comunicazioni.is_published'),
            'priority'      => __('validation.attributes.comunicazioni.priority'),
            'condomini_ids' => __('validation.attributes.comunicazioni.condomini_ids'),
        ];
    }
}
