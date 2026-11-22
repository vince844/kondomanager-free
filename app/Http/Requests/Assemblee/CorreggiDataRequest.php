<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

use App\Helpers\DateHelper;

/** «Correggi la data» di una riunione registrata a mano (R2): il giorno giusto, non dopo oggi. */
class CorreggiDataRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['giorno' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . DateHelper::oggiUtente()]];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['giorno' => 'il giorno della riunione'];
    }
}
