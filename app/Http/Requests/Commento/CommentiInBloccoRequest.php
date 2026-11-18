<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Commento;

use Illuminate\Foundation\Http\FormRequest;

/**
 * I commenti scelti per un'azione in blocco dalla pagina «Commenti» del pannello. Quali si possono toccare lo dice la regola
 * della scheda, commento per commento, nel servizio.
 */
class CommentiInBloccoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Chi apre la pagina lo dice `RegoleCommenti::puoAprirePagina`, nel controller.
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'ids'   => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'min:1'],
        ];
    }
}
