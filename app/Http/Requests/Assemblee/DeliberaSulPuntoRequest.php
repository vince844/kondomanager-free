<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

use App\Enums\Assemblee\EsitoDelibera;
use Illuminate\Validation\Rule;

/** «Registra la delibera» su un punto di un'assemblea tenuta fuori dal programma (R16): l'esito e che cosa ha deciso. */
class DeliberaSulPuntoRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['esito' => ['required', Rule::in(EsitoDelibera::valori())], 'testo' => ['nullable', 'string', 'max:2000']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['esito' => 'l\'esito', 'testo' => 'che cosa ha deciso'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + ['esito.required' => 'Scegli l\'esito della delibera.'];
    }
}
