<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

/** Il segno della tabella di un gruppo (Coda 734): la tabella del condominio, e il segno acceso o spento. */
class TabellaDiUnGruppoRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tabella_id' => ['required', 'integer'],
            'di_un_gruppo' => ['required', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['tabella_id' => 'la tabella', 'di_un_gruppo' => 'il segno della tabella di un gruppo'];
    }
}
