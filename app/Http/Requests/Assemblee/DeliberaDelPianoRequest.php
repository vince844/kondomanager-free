<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

use Illuminate\Validation\Rule;

/** La delibera di un piano rate (R1): `delibera` per il piano straordinario, `riferimento` per il piano d'urgenza riferito all'assemblea. */
class DeliberaDelPianoRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'delibera_id' => ['nullable', 'integer'],
            'ruolo' => ['required', Rule::in(['delibera', 'riferimento'])],
            'nota' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['delibera_id' => 'la delibera', 'ruolo' => 'il legame con l\'assemblea', 'nota' => 'la nota'];
    }
}
