<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

use App\Enums\Assemblee\Maggioranza;
use Illuminate\Validation\Rule;

/** I punti proposti da aggiungere all'ordine del giorno, come le loro chiavi (il server rilegge le proposte), con la maggioranza scelta. */
class DalleProposteRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'proposte' => ['required', 'array', 'min:1'],
            'proposte.*' => ['string', 'max:191'],
            'maggioranze' => ['sometimes', 'array'],
            'maggioranze.*' => ['nullable', Rule::in(Maggioranza::valori())],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['proposte' => 'i punti da aggiungere', 'maggioranze.*' => 'la maggioranza'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + ['proposte.required' => 'Spunta almeno un punto da aggiungere.', 'proposte.min' => 'Spunta almeno un punto da aggiungere.'];
    }
}
