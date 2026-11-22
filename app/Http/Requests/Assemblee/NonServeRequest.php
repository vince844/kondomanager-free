<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

/** «Non serve più», con il motivo: resta nel registro delle modifiche. */
class NonServeRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['motivo' => ['required', 'string', 'min:3', 'max:255']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['motivo' => 'perché non serve più'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + ['motivo.required' => 'Scrivi perché non serve più.', 'motivo.min' => 'Scrivi perché non serve più.'];
    }
}
