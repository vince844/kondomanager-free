<?php

namespace App\Http\Requests\Documento\Utenti;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Support\LimiteCaricamento;

/**
 * @method bool merge(string $key)
 */
class UpdateDocumentoRequest extends FormRequest
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
            'name'            => ['sometimes', 'required', 'string'],
            'description'     => ['sometimes', 'nullable', 'string'],
            // Coda 303: niente `created_by`, `is_approved`, `is_published`. Sono dati della
            // creazione: chi modifica il documento di un altro con un ruolo su misura non ne
            // diventa l'autore, e la modifica non lo approva né lo ritira (decisione 127.2).
            // ⚠️ **Almeno una, dalla 1.11.0-beta.10.** Un documento d'archivio senza categoria non
            // compare in nessuna vista per categoria e si trova solo cercandolo per nome: è il modo
            // in cui i documenti si perdono. L'obbligo vive **qui**, nei moduli dell'archivio, e non
            // a livello di schema: i documenti caricati su un soggetto — fornitore, unità,
            // anagrafica — non hanno categoria di proposito, e un vincolo sul database romperebbe
            // quei caricamenti.
            'categorie'       => ['sometimes', 'required', 'array', 'min:1'],
            'categorie.*'     => ['integer', 'exists:categorie_documento,id'],
            // Coda 307: il segno che la richiesta viene dal modulo, con il suo elenco delle categorie
            // (vedi `prepareForValidation()` qui sotto).
            'categorie_nel_modulo' => ['sometimes', 'boolean'],
            'file'            => ['nullable', 'file', 'mimes:pdf', 'max:'.LimiteCaricamento::regolaMax()],
        ];
    }

    /**
     * L'elenco delle categorie che con un file nuovo non arriva (Coda 307).
     *
     * Con un file la pagina di modifica del portale (`documenti/user/DocumentiEdit.vue`) manda FormData,
     * e lì un elenco vuoto **non si scrive affatto**: «tolte tutte» arriva uguale a «campo non mandato»,
     * che per questa richiesta vuol dire «non toccare». Il modulo però le categorie le ha sempre, e lo
     * dice con il segno `categorie_nel_modulo`: con il segno a vero, l'elenco assente è un elenco vuoto,
     * come nel corpo JSON che la stessa pagina manda senza file, e la regola «almeno una» scatta con o
     * senza file. È la stessa regola della modifica dal pannello
     * (`Documento\UpdateDocumentoRequest::prepareForValidation()`), con un segno suo: la pagina del
     * portale non ha palazzi né destinatari, e il segno del pannello li varrebbe vuoti.
     *
     * Senza il segno, o con il segno a falso, niente cambia: una richiesta che non viene dalla pagina
     * e non porta le categorie non le tocca.
     */
    protected function prepareForValidation(): void
    {
        if (! in_array($this->input('categorie_nel_modulo'), [true, 1, '1'], true)) {
            return;
        }

        if (! $this->has('categorie')) {
            $this->merge(['categorie' => []]);
        }
    }

    public function attributes()
    {
        return [
            'name'          => __('validation.attributes.documenti.name'),
            'description'   => __('validation.attributes.documenti.description'),
            'categorie'     => __('validation.attributes.documenti.categorie'),
        ];
    }
}
