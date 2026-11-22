<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee\Verbale;

use App\Http\Requests\Assemblee\RichiestaDelleAssemblee;
use App\Services\Assemblee\Verbale\GestiDelVerbale;
use App\Services\Assemblee\Verbale\StrutturaDelVerbale;
use Illuminate\Validation\Validator;

/**
 * I salvataggi del testo del verbale di un dispositivo (la tenuta senza rete): il dispositivo, al più 50 gesti, ognuno con il numero (un UUID scritto dal
 * dispositivo), il tipo `testo`, i dati (il verbale o la seduta, la parte, la struttura dell'editor; al più il tetto della struttura, 1 MB per parte), la
 * revisione da cui parte, l'ora del dispositivo e l'ora del gesto. La forma si controlla qui; la struttura e il resto li controlla il servizio, gesto per gesto.
 * Chi non gestisce le assemblee riceve 403.
 */
class GestiDelVerbaleRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dispositivo' => ['required', 'string', 'max:64'],
            'gesti' => ['required', 'array', 'min:1', 'max:' . GestiDelVerbale::TETTO],
            'gesti.*.gesto' => ['required', 'uuid'],
            'gesti.*.tipo' => ['required', 'string', 'max:40'],
            // I dati senza regole annidate: `validated()` li rende interi (la struttura del testo compresa); la parte si controlla qui sotto.
            'gesti.*.dati' => ['required', 'array'],
            'gesti.*.base' => ['nullable', 'integer', 'min:0'],
            'gesti.*.orologio' => ['nullable', 'date'],
            'gesti.*.segnato_il' => ['required', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['dispositivo' => 'il dispositivo', 'gesti' => 'i salvataggi', 'gesti.*.gesto' => 'il numero del salvataggio', 'gesti.*.tipo' => 'il tipo del salvataggio',
            'gesti.*.dati' => 'il testo', 'gesti.*.dati.parte' => 'la parte del verbale', 'gesti.*.base' => 'la revisione', 'gesti.*.orologio' => 'l\'ora del dispositivo',
            'gesti.*.segnato_il' => 'l\'ora del salvataggio'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'gesti.max' => 'Al più ' . GestiDelVerbale::TETTO . ' salvataggi per volta: gli altri partono subito dopo.',
            'gesti.*.gesto.uuid' => 'Il numero del salvataggio non è nella forma giusta.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            foreach ((array) $this->input('gesti', []) as $i => $g) {
                $parte = $g['dati']['parte'] ?? null;
                if (! is_string($parte) || preg_match('/^(intero|punto:\d+)$/', $parte) !== 1) {
                    $v->errors()->add('gesti.' . $i . '.dati', 'Il salvataggio non dice la parte del verbale.');
                }
                if (strlen((string) json_encode($g['dati'] ?? [])) > 2 * StrutturaDelVerbale::BYTE_MASSIMI) {
                    $v->errors()->add('gesti.' . $i . '.dati', 'Il testo è troppo lungo: al più 1 MB per parte.');
                }
            }
        }];
    }
}
