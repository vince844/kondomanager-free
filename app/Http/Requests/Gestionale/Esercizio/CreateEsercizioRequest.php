<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

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
     * Le pagine non lasciano aprire un secondo esercizio; l'importazione sì (l'esercizio importato
     * nasce aperto). Con due aperti la regola è una sola, il più recente (Coda 646, decisione 707 (4):
     * `Esercizio::apertoPiuRecente()`, che `HasEsercizio::getEsercizioCorrente()` chiama), per gli
     * incassi, le pagine e lo storno cross-esercizio (Variante B1). Fino alla 1.12.0-beta.14 qui c'era
     * `->first()` senza ordinamento, e l'esercizio "corrente" era quello che il database restituiva.
     * Ogni sigillo per-esercizio poggia su questa invariante.
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
