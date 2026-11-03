<?php

namespace App\Http\Requests\Gestionale\Movimenti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * I dati grezzi del modulo in compilazione, così come arrivano mentre si scrive.
 *
 * ⚠️ **Niente `exists:` su `esercizio_id` o `fornitore_id` legato al condominio**: il difetto
 * misurato in `FetchCapitoliContiController` è che `exists:piani_conti,id` da solo prova solo che
 * la riga c'è **da qualche parte**, non che appartenga a questo condominio. Qui lo scoping non è
 * demandato alla validazione: `RicercaFattureSimili::cerca()` prende `condominioId` dal binding di
 * rotta (`scopeBindings()`), non da un parametro che il client potrebbe far combaciare a caso.
 */
class FetchFattureSimiliRequest extends FormRequest
{
    public function authorize(): bool
    {
        // ⚠️ Nessuna policy propria, di proposito. Il controller delle fatture non ha un solo
        // `Gate::authorize` sulle sue azioni principali (index, store, update): il gestionale non
        // ha permessi granulari suoi — zero su 65, Coda 110 — ed è tutto protetto a monte da
        // `ACCESS_ADMIN_PANEL` più lo scoping al condominio. Inventare qui un'autorizzazione più
        // stretta per un endpoint di sola lettura sarebbe esattamente la scelta improvvisata che
        // la Coda 110 chiede di non fare beta per beta.
        return true;
    }

    public function rules(): array
    {
        return [
            'esercizio_id' => ['required', 'integer'],
            'fornitore_id' => ['required', 'integer'],
            'numero_documento' => ['nullable', 'string', 'max:255'],
            'totale_documento_cents' => ['nullable', 'integer', 'min:1'],
            'data_documento' => ['nullable', 'date'],
            'tipo_documento' => ['nullable', 'string', 'in:fattura,nota_credito'],
            'escludi_fattura_id' => ['nullable', 'integer'],
        ];
    }
}
