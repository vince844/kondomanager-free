<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee\Convocazione;

use App\Helpers\DateHelper;
use App\Services\Gestionale\Lettere\RegistroLettere;
use App\Support\LimiteCaricamento;
use Illuminate\Foundation\Http\FormRequest;

/** Il foglio delle consegne a mano firmato: il file una volta, e per ogni riga l'invio e il giorno della consegna (non dopo oggi). */
class FoglioFirmatoRequest extends FormRequest
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
            'foglio' => ['required', 'file', 'extensions:' . implode(',', RegistroLettere::ESTENSIONI_PROVA), 'max:' . LimiteCaricamento::regolaMax(RegistroLettere::MB_PROVA),
                \App\Support\FileComeIlNome::regola()],
            'consegne' => ['required', 'array', 'min:1', 'max:200'],
            'consegne.*.invio_id' => ['required', 'integer'],
            'consegne.*.giorno' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . DateHelper::oggiUtente()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'foglio.required' => 'Carica la scansione del foglio firmato.',
            'consegne.required' => 'Scegli chi ha firmato il foglio.',
            'consegne.*.giorno.before_or_equal' => 'Il giorno della consegna non può essere dopo oggi.',
        ];
    }
}
