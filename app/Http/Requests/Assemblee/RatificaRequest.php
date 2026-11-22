<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

/** La ratifica dello sforo con la delibera (R12, decisione 561): la delibera scelta e una nota facoltativa. */
class RatificaRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['delibera_id' => ['required', 'integer'], 'nota' => ['nullable', 'string', 'max:1000']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['delibera_id' => 'la delibera', 'nota' => 'la nota'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + ['delibera_id.required' => 'Scegli la delibera dell\'assemblea che ha ratificato la spesa.'];
    }
}
