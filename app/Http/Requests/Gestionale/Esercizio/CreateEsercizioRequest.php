<?php

namespace App\Http\Requests\Gestionale\Esercizio;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @method bool merge(string $key)
 * @method bool input(string $key)
 * @method \Illuminate\Routing\Route|null route(string|null $param = null, mixed $default = null)
 */
class CreateEsercizioRequest extends FormRequest
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
            'data_inizio'         => 'required|date',
            'data_fine'           => 'required|date|after_or_equal:data_inizio',
            'stato'               => 'required|string|in:aperto,chiuso',
            'note'                => 'sometimes|nullable|string',
            'condominio_id'       => ['required', 'integer', Rule::exists('condomini', 'id')],
        ];
    }

    /**
     * Invariante: al più un esercizio aperto per condominio.
     *
     * Senza questo vincolo `HasEsercizio::getEsercizioCorrente()` fa `->first()`
     * senza ordinamento e l'esercizio "corrente" diventa nondeterministico: incassi,
     * fetch della UI e soprattutto lo storno cross-esercizio (Variante B1, che cerca
     * "un" esercizio aperto in cui appoggiare la scrittura inversa) possono finire in
     * esercizi diversi nella stessa sessione. Ogni sigillo per-esercizio poggia su
     * questa invariante.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('stato') !== 'aperto') {
                return;
            }

            $condominioId = $this->route('condominio')?->id;

            $esistente = \App\Models\Esercizio::where('condominio_id', $condominioId)
                ->where('stato', 'aperto')
                ->first();

            if ($esistente) {
                $validator->errors()->add(
                    'stato',
                    "Esiste già un esercizio aperto per questo condominio («{$esistente->nome}»). "
                    .'Chiudilo prima di aprirne un altro: con due esercizi aperti il sistema non '
                    .'saprebbe in quale registrare i movimenti correnti.'
                );
            }
        });
    }

    /**
     * Prepare data before validation.
     * Uppercases relevant string fields and merges condominio_id from route.
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'condominio_id'  => $this->route('condominio')->id,
        ]);
    }
}
