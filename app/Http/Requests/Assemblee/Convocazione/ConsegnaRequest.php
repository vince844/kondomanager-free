<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee\Convocazione;

use App\Enums\Assemblee\EsitoInvio;
use App\Helpers\DateHelper;
use App\Services\Gestionale\Lettere\RegistroLettere;
use App\Support\LimiteCaricamento;
use Illuminate\Foundation\Http\FormRequest;

/**
 * «Registra la consegna» di un invio: l'esito, il giorno (non dopo oggi), l'ora facoltativa, la prova (PDF, immagine, .eml, .p7m, come le prove delle
 * lettere), e il perché quando si corregge un esito già registrato.
 */
class ConsegnaRequest extends FormRequest
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
            // «ritirata»: la raccomandata in giacenza ritirata dopo l'avviso (un fatto nuovo, non una correzione).
            'esito' => ['required', 'in:' . implode(',', [...EsitoInvio::valori(), \App\Services\Assemblee\Convocazione\ConsegneDellaConvocazione::RITIRATA])],
            'giorno' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . DateHelper::oggiUtente()],
            'ora' => ['nullable', 'regex:/^([01]?\d|2[0-3]):[0-5]\d$/'],
            'prova' => ['nullable', 'file', 'extensions:' . implode(',', RegistroLettere::ESTENSIONI_PROVA), 'max:' . LimiteCaricamento::regolaMax(RegistroLettere::MB_PROVA),
                \App\Support\FileComeIlNome::regola()],
            'perche' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'esito.required' => 'Scegli l\'esito.',
            'giorno.required' => 'Scrivi il giorno.',
            'giorno.before_or_equal' => 'Il giorno non può essere dopo oggi.',
            'ora.regex' => 'L\'ora si scrive come 10:14.',
            'prova.extensions' => 'La prova è un PDF, un\'immagine (JPG o PNG), un messaggio .eml o un file firmato .p7m.',
        ];
    }
}
