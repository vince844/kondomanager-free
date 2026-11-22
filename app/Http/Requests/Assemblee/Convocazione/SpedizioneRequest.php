<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee\Convocazione;

use App\Helpers\DateHelper;
use Illuminate\Foundation\Http\FormRequest;

/** «Registra la spedizione» degli avvisi stampati: le righe, il canale (raccomandata, a mano, fax), la data non dopo oggi, il numero di ognuna, la nota. */
class SpedizioneRequest extends FormRequest
{
    /** «Gestisce le assemblee» prima della validazione: chi non può non legge gli errori del modulo. */
    public function authorize(): bool
    {
        return \App\Services\Assemblee\AccessoAlleAssemblee::gestisce($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'invii' => ['required', 'array', 'min:1', 'max:200'],
            'invii.*' => ['integer'],
            'canale' => ['required', 'in:raccomandata,a_mano,fax'],
            'data' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . DateHelper::oggiUtente()],
            'numeri' => ['nullable', 'array'],
            'numeri.*' => ['nullable', 'string', 'max:100'],
            'nota' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'invii.required' => 'Scegli le persone di cui registri la spedizione.',
            'canale.in' => 'Il canale è la raccomandata, la consegna a mano o il fax.',
            'data.before_or_equal' => 'La spedizione non può essere dopo oggi.',
        ];
    }
}
