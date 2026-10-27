<?php

namespace App\Http\Requests\Documento;

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
            // Solo in modifica: la casella «avvisa i destinatari». In creazione l'avviso parte
            // sempre, quindi il campo non arriva e `nullable` lo lascia passare.
            'avvisa_destinatari' => ['nullable', 'boolean'],
            'name'            => ['sometimes', 'required', 'string'],
            'description'     => ['sometimes', 'nullable', 'string'],
            // Coda 303: niente `created_by` né `is_approved`. L'autore resta chi ha caricato il
            // documento (chi modifica sta in `updated_by`, che mette il controller), e
            // l'approvazione cambia solo dall'interruttore, che avvisa il creatore (decisione 127.2).
            'is_published'    => ['sometimes', 'required', 'boolean'],
            // ⚠️ **Almeno una, dalla 1.11.0-beta.10.** Un documento d'archivio senza categoria non
            // compare in nessuna vista per categoria e si trova solo cercandolo per nome: è il modo
            // in cui i documenti si perdono. L'obbligo vive **qui**, nei moduli dell'archivio, e non
            // a livello di schema: i documenti caricati su un soggetto — fornitore, unità,
            // anagrafica — non hanno categoria di proposito, e un vincolo sul database romperebbe
            // quei caricamenti.
            'categorie'       => ['sometimes', 'required', 'array', 'min:1'],
            'categorie.*'     => ['integer', 'exists:categorie_documento,id'],
            'condomini_ids'   => ['sometimes', 'required', 'array', 'min:1'],
            'condomini_ids.*' => ['integer', Rule::exists('condomini', 'id')],
            'anagrafiche'     => ['sometimes', 'nullable', 'array'],
            'anagrafiche.*'   => ['integer', Rule::exists('anagrafiche', 'id')],
            // Coda 307: il segno che la richiesta viene dal modulo, con i suoi tre elenchi (vedi
            // `prepareForValidation()` qui sotto).
            'anagrafiche_nel_modulo' => ['sometimes', 'boolean'],
            // Il segno della pagina del portale, che ha solo le categorie: arriva qui quando quella
            // pagina la apre chi ha il pannello (vedi `prepareForValidation()`).
            'categorie_nel_modulo' => ['sometimes', 'boolean'],
            'file'            => ['nullable', 'file', 'mimes:pdf', 'max:'.LimiteCaricamento::regolaMax()],
        ];
    }

    /**
     * Gli elenchi del modulo che con un file nuovo non arrivano (Coda 307).
     *
     * Con un file la pagina di modifica manda FormData, e lì un elenco vuoto **non si scrive affatto**:
     * «tolti tutti» arriva uguale a «campo non mandato», che per questa richiesta vuol dire «non
     * toccare». Il modulo però i tre elenchi li ha sempre (`documenti/DocumentiEdit.vue`), e lo dice con
     * il segno `anagrafiche_nel_modulo`: con il segno a vero, un elenco assente è un elenco vuoto, come
     * nel corpo JSON che la stessa pagina manda senza file. Così le regole «almeno uno» di palazzi e
     * categorie scattano con o senza file, e i destinatari tolti si tolgono.
     *
     * Senza il segno, o con il segno a falso, niente cambia: una richiesta che non viene dalla pagina
     * e non porta un elenco non lo tocca.
     *
     * La pagina di modifica del portale (`documenti/user/DocumentiEdit.vue`), aperta da chi ha il
     * pannello, salva qui (sceglie la rotta dal ruolo) con il suo segno, `categorie_nel_modulo`: quella
     * pagina ha solo le categorie, e con il suo segno vale vuoto solo l'elenco delle categorie, come fa
     * la sua richiesta (`Documento\Utenti\UpdateDocumentoRequest`). Palazzi e destinatari, che quella
     * pagina non manda, non si toccano.
     */
    protected function prepareForValidation(): void
    {
        $vero = fn (string $segno): bool => in_array($this->input($segno), [true, 1, '1'], true);

        $elenchi = match (true) {
            $vero('anagrafiche_nel_modulo') => ['condomini_ids', 'categorie', 'anagrafiche'],
            $vero('categorie_nel_modulo')   => ['categorie'],
            default                         => [],
        };

        foreach ($elenchi as $elenco) {
            if (! $this->has($elenco)) {
                $this->merge([$elenco => []]);
            }
        }
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes()
    {
        return [
            'name'          => __('validation.attributes.documenti.name'),
            'description'   => __('validation.attributes.documenti.description'),
            'is_published'  => __('validation.attributes.documenti.is_published'),
            'condomini_ids' => __('validation.attributes.documenti.condomini_ids'),
            'categorie'     => __('validation.attributes.documenti.categorie'),
        ];
    }
}
