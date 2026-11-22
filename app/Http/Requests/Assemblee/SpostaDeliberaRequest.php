<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

use App\Enums\Assemblee\AmbitoAssemblea;
use App\Helpers\DateHelper;
use Illuminate\Validation\Rule;

/** «Sposta in un'altra riunione» (R2): una riunione a mano del condominio, o il giorno e il numero del verbale di quella che nasce se non c'è. */
class SpostaDeliberaRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'riunione_id' => ['nullable', 'integer', 'required_without:giorno'],
            'giorno' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . DateHelper::oggiUtente(), 'required_without:riunione_id'],
            'numero_verbale' => ['nullable', 'string', 'max:50'],
            'ambito' => ['nullable', Rule::in(AmbitoAssemblea::valori())],
            'palazzina_id' => ['nullable', 'integer'],
            'scala_id' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['riunione_id' => 'la riunione', 'giorno' => 'il giorno della riunione', 'numero_verbale' => 'il numero del verbale', 'ambito' => 'per chi'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'riunione_id.required_without' => 'Scegli la riunione, o scrivi il giorno di quella nuova.',
            'giorno.required_without' => 'Scegli la riunione, o scrivi il giorno di quella nuova.',
        ];
    }
}
