<?php

namespace App\Http\Requests\Documento;

use App\Traits\OrdinaElenco;
use Illuminate\Foundation\Http\FormRequest;

class DocumentoIndexRequest extends FormRequest
{
    use OrdinaElenco;

    /**
     * ⚠️ **«Categoria» non è più ordinabile, dalla 1.11.0-beta.10, ed è una scelta.**
     *
     * Da quando un documento può stare in **più** categorie, «ordina per categoria» non ha una
     * risposta: un documento che sta in «Bilanci» e in «Verbali» dove va? Le uniche vie sarebbero
     * inventare una regola — «la prima in ordine alfabetico» — che nessuno ha chiesto e che chi
     * clicca non capirebbe, oppure lasciare che la libreria ordini per qualcosa di arbitrario.
     * L'intestazione smette quindi di essere cliccabile.
     *
     * Restano fuori «Condomini» e «Anagrafiche» per la ragione di sempre: sono elenchi anche loro.
     */
    public static function colonneOrdinabili(): array
    {
        return [
            'name' => 'name',
            // ⚠️ **«Stato» non è più ordinabile, dalla 1.11.0-beta.10.** Ordinare per due soli
            // valori non serve a niente — mette in cima tutti i pubblici o tutti i privati, che è
            // quello che un **filtro** fa meglio e senza far scomparire il resto. Il filtro c'è,
            // nella barra, accanto a categoria e condomìni.
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
     * ⚠️ **Il filtro dello stato arriva come stringa, e Laravel non la accetta.**
     *
     * La barra dei filtri manda dei booleani veri (`is_published: [false]`), ma il viaggio è un
     * **indirizzo**: Inertia serializza i parametri nella query string, dove non esistono i tipi, e
     * quello che arriva qui è `['false']` — una stringa. La regola `boolean` di Laravel accetta
     * `true`, `false`, `0`, `1`, `'0'` e `'1'`, e **rifiuta `'true'` e `'false'`**.
     *
     * Il difetto che ne veniva non somigliava a un errore: la validazione falliva, la richiesta
     * tornava indietro con l'elenco **non filtrato**, e a schermo restava la pillola «Privato»
     * accesa sopra una tabella piena di documenti pubblici. Nessun messaggio, nessuna riga di log —
     * l'errore viveva nei `props` di Inertia e nessuno lo leggeva.
     *
     * *(Trovato in Fase 1-bis della 1.11.0-beta.10, filtrando per «Privato» su due documenti
     * pubblici: la prova che il filtro funziona è che l'elenco si svuota.)*
     *
     * Qui si normalizza **prima** della validazione, con `FILTER_NULL_ON_FAILURE`: ciò che non è
     * riconoscibile come vero o falso resta com'è e va a sbattere contro la regola `boolean`, che è
     * il posto giusto per rifiutarlo. Convertire di forza — `boolval('pippo')` è `true` — vorrebbe
     * dire trasformare un parametro sbagliato in un filtro che sembra funzionare.
     */
    protected function prepareForValidation(): void
    {
        $stato = $this->input('is_published');

        if (! is_array($stato)) {
            return;
        }

        $this->merge([
            'is_published' => array_map(
                fn ($valore) => filter_var($valore, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $valore,
                $stato
            ),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return array_merge([
            'page'              => ['sometimes', 'integer', 'min:1'],
            'per_page'          => ['sometimes', 'integer'],
            'name'              => ['sometimes', 'string', 'max:255'],
            // ⚠️ Un **array** come gli altri due filtri, anche se i valori possibili sono due:
            // così la barra si comporta allo stesso modo su tutti e tre, e chi vuole vedere
            // «pubblici e privati insieme» non deve togliere il filtro.
            'is_published'      => ['sometimes', 'nullable', 'array'],
            'is_published.*'    => ['boolean'],

            'category_id'       => ['sometimes', 'array'],
            'category_id.*'     => ['integer', 'exists:categorie_documento,id'],
            'condominio_id'     => ['nullable', 'array'], 
            'condominio_id.*'   => ['integer'],           
            'search'            => ['nullable', 'string'],

        ], self::regoleOrdinamento(array_keys(self::colonneOrdinabili())));
    }
}
