<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee\Convocazione;

use Illuminate\Foundation\Http\FormRequest;

/** «Annulla e riconvoca»: il motivo (obbligatorio) e le date nuove, prima e seconda convocazione. */
class RiconvocaRequest extends FormRequest
{
    /** «Gestisce le assemblee» prima della validazione: chi non può non legge gli errori del modulo. */
    public function authorize(): bool
    {
        return \App\Services\Assemblee\AccessoAlleAssemblee::gestisce($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:5', 'max:1000'],
            'sedute' => ['required', 'array', 'min:1', 'max:6'],
            'sedute.*.convocazione' => ['required', 'in:prima,seconda'],
            'sedute.*.numero' => ['nullable', 'integer', 'min:1', 'max:9'],
            'sedute.*.fissata_il' => ['required', 'date'],
            'sedute.*.luogo' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Scrivi perché l\'assemblea si annulla.',
            'motivo.min' => 'Scrivi perché l\'assemblea si annulla, in qualche parola.',
            'sedute.required' => 'Scrivi le date nuove.',
        ];
    }
}
