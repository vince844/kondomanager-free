<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee\Convocazione;

use Illuminate\Foundation\Http\FormRequest;

/** «Spedisco le raccomandate entro»: la data scelta dall'amministratore (decisione 565), o vuota per tornare alla proposta del programma. */
class RaccomandateEntroRequest extends FormRequest
{
    /** «Gestisce le assemblee» prima della validazione: chi non può non legge gli errori del modulo. */
    public function authorize(): bool
    {
        return \App\Services\Assemblee\AccessoAlleAssemblee::gestisce($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['giorno' => ['nullable', 'date_format:Y-m-d']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['giorno.date_format' => 'La data si scrive come 03/12/2026.'];
    }
}
