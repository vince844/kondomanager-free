<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

use Illuminate\Validation\Rule;

/** La tabella di proprietà generale di un perimetro (§3 P12): il condominio, una palazzina o una scala, e la tabella (vuota per toglierla). */
class TabellaDiProprietaRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'perimetro_tipo' => ['required', Rule::in(['condominio', 'palazzina', 'scala'])],
            'perimetro_id' => ['nullable', 'integer', 'required_unless:perimetro_tipo,condominio'],
            'tabella_id' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['perimetro_tipo' => 'per chi', 'perimetro_id' => 'la palazzina o la scala', 'tabella_id' => 'la tabella'];
    }
}
