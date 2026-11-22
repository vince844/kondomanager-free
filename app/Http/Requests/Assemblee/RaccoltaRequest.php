<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

/** «Raccogli N date» (R9): le schede spuntate dell'anteprima. */
class RaccoltaRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'spuntate' => ['required', 'array', 'min:1'],
            'spuntate.*.oggetto_tipo' => ['required', 'string', 'in:piano_rate,dispensa_sei_mesi,accordo_rientro,fattura_passiva'],
            'spuntate.*.oggetto_id' => ['required', 'integer'],
            // Per chi era ogni riunione proposta, con la sua chiave (giorno e numero del verbale, che può avere un punto: per questo un elenco e non le chiavi
            // dell'array): di serie tutto il condominio. Palazzina e scala le risolve il servizio nel condominio.
            'perimetri' => ['sometimes', 'array'],
            'perimetri.*.chiave' => ['required', 'string', 'max:120'],
            'perimetri.*.ambito' => ['required', 'string', 'in:condominio,palazzina,scala'],
            'perimetri.*.palazzina_id' => ['nullable', 'integer', 'required_if:perimetri.*.ambito,palazzina'],
            'perimetri.*.scala_id' => ['nullable', 'integer', 'required_if:perimetri.*.ambito,scala'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['spuntate' => 'le date da raccogliere', 'spuntate.*.oggetto_tipo' => 'la scheda', 'spuntate.*.oggetto_id' => 'la scheda',
            'perimetri.*.ambito' => 'per chi', 'perimetri.*.palazzina_id' => 'la palazzina', 'perimetri.*.scala_id' => 'la scala'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + ['spuntate.required' => 'Spunta almeno una data da raccogliere.', 'spuntate.min' => 'Spunta almeno una data da raccogliere.'];
    }
}
