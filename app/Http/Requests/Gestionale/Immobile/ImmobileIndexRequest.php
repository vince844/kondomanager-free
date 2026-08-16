<?php

namespace App\Http\Requests\Gestionale\Immobile;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;

class ImmobileIndexRequest extends FormRequest
{
    use OrdinaElenco;

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
            'page'       => ['sometimes', 'integer', 'min:1'],
            'per_page'   => ['sometimes', 'integer'],
            'nome'       => ['sometimes', 'string', 'max:255'],
            // Il filtro sulle pertinenze. `da_collegare` è quello che serve davvero: apri
            // l'elenco, filtri, e vedi solo ciò che manca — la bonifica quando si ha voglia,
            // invece di un allarme che insegue riga per riga.
            'pertinenze' => ['sometimes', 'string', 'in:principali,collegate,da_collegare'],
            'search'     => ['nullable', 'string'],
        ], self::regoleOrdinamento(array_keys(self::colonneOrdinabili())));
    }

    /**
     * Le colonne dell'elenco unità che si possono ordinare, e su quale campo.
     *
     * ⚠️ **Non ci sono «Soggetti» né «Dati tecnici», e non è una dimenticanza.**
     *
     * «Soggetti» contiene un **elenco** di persone: un'unità con «Esposito + Russo» non ha una
     * posizione in un ordinamento alfabetico finché qualcuno non decide *quale dei due* faccia
     * da chiave. Finché quella decisione non è presa, l'intestazione non è cliccabile —
     * `enableSorting: false` in `columns.ts` — perché una colonna che non si ordina è meglio di
     * una che ordina per un criterio che nessuno ha scelto.
     *
     * «Dati tecnici» monta superficie e vani in una cella sola: ordinarla vorrebbe dire scegliere
     * per quale dei due, ed è la stessa domanda.
     *
     * Nemmeno «Ubicazione»: monta palazzina, scala e piano, e la chiave più difendibile — il nome
     * della palazzina — richiede una join che oggi non c'è. Si potrà aggiungere, ma è una
     * decisione da prendere, non un default.
     *
     * ⚠️ **Questa lista e le intestazioni cliccabili devono coincidere esattamente.** Una colonna
     * ammessa qui e non offerta a video è configurazione morta; una offerta a video e non ammessa
     * qui manda l'amministratore in un errore di validazione al primo clic. Lo fissa
     * `OrdinamentoElenchiTest`.
     */
    /** @return array<string, string|\Closure> */
    public static function colonneOrdinabili(): array
    {
        return [
        'nome'    => 'nome',
        'catasto' => 'codice_catasto',
        ];
    }
}
