<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee\Convocazione;

use App\Enums\Assemblee\MezzoConvocazione;
use App\Services\Assemblee\AccessoAlleAssemblee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Il mezzo di una persona per questa assemblea, dalla riga della tabella dei convocati (decisione 647): `mezzo` (vuoto: torna quello della scheda o la
 * proposta) e `ricorda` (scrivilo anche nella scheda). Solo chi gestisce le assemblee; la persona passa da `SchedaDelCondominio::risolvi()` nel controller.
 */
class MezzoDelConvocatoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return AccessoAlleAssemblee::gestisce($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'anagrafica_id' => ['required', 'integer'],
            'mezzo' => ['nullable', Rule::in(MezzoConvocazione::valori())],
            'ricorda' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mezzo.in' => 'Come si convoca: PEC, raccomandata, a mano o fax.',
        ];
    }
}
