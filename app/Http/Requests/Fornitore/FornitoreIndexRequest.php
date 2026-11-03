<?php

namespace App\Http\Requests\Fornitore;

use App\Traits\OrdinaElenco;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Foundation\Http\FormRequest;

class FornitoreIndexRequest extends FormRequest
{
    use OrdinaElenco;

    /** ⚠️ Fuori «Referenti», che è un elenco di persone. */
    public static function colonneOrdinabili(): array
    {
        return [
            // «Categoria» è una relazione: si ordina per il suo nome con una sottoquery
            // correlata, esattamente come l'elenco documenti ordina per la sua.
            'categoria'        => fn () => \App\Models\CategoriaFornitore::select('name')
                ->whereColumn('categorie_fornitore.id', 'fornitori.categoria_id'),
            'ragione_sociale' => 'ragione_sociale',
            'indirizzo'       => 'indirizzo',
            'codice_fiscale'  => 'codice_fiscale',
        ];
    }

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
        return array_merge([
            'page'             => ['sometimes', 'integer', 'min:1'],
            'per_page'         => ['sometimes', 'integer'],
            'ragione_sociale'  => ['sometimes', 'string', 'max:255'],

            // Un **array**, come `category_id` nell'elenco documenti: un amministratore che cerca
            // chi può salire su un tetto vuole vedere insieme muratori e lattonieri, non un
            // mestiere per volta. `exists` perché un id inventato nell'indirizzo non deve produrre
            // un elenco vuoto senza spiegazione.
            'categoria_id'     => ['sometimes', 'nullable', 'array'],
            'categoria_id.*'   => ['integer', 'exists:categorie_fornitore,id'],
        ], self::regoleOrdinamento(array_keys(self::colonneOrdinabili())));
    }

    /**
     * ⚠️ **Un filtro per categoria non più valido non deve mandare il browser in ciclo.**
     *
     * Il rifiuto ordinario di una `FormRequest` è un `redirect()->back()`, cioè verso il `Referer`.
     * Su un **elenco filtrato** il Referer è quasi sempre **la stessa pagina filtrata**: si ricarica,
     * la validazione fallisce di nuovo, si rimanda indietro di nuovo. Ciclo infinito, e l'utente non
     * ha fatto niente di strano — gli è bastato tenere aperta la scheda dei fornitori filtrata per
     * «Idraulico» mentre da un'altra scheda cancellava quella categoria, e poi ricaricare.
     *
     * Qui si esce dal ciclo mandando all'elenco **senza filtro**, e dicendo perché: un elenco
     * completo con una spiegazione è la cosa giusta quando il filtro chiesto non esiste più.
     *
     * Vale **solo** se gli errori riguardano tutti la categoria: qualunque altro errore di
     * validazione torna al comportamento normale, perché per quelli il `back()` è giusto.
     */
    protected function failedValidation(Validator $validator): void
    {
        $chiavi = array_keys($validator->errors()->toArray());

        $soloCategoria = $chiavi !== [] && collect($chiavi)->every(
            fn (string $chiave) => $chiave === 'categoria_id' || str_starts_with($chiave, 'categoria_id.')
        );

        if ($soloCategoria) {
            throw new HttpResponseException(
                redirect()->route('admin.fornitori.index')->with([
                    'message' => [
                        'type'    => 'info',
                        'message' => __('fornitori.categorie.filtro_non_valido'),
                    ],
                ])
            );
        }

        parent::failedValidation($validator);
    }
}
