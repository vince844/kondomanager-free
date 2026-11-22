<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

/** Il nuovo ordine dei punti, dal primo all'ultimo (gli id). */
class RiordinaRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['ordine' => ['required', 'array', 'min:1'], 'ordine.*' => ['integer']];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['ordine' => 'l\'ordine dei punti', 'ordine.*' => 'un punto'];
    }
}
