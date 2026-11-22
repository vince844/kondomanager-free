<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee\Sala;

use App\Http\Requests\Assemblee\RichiestaDelleAssemblee;
use App\Services\Assemblee\Sala\GestiDellaSala;
use Illuminate\Validation\Validator;

/**
 * Il lotto dei gesti di un dispositivo (la tenuta senza rete): il dispositivo, al più 500 gesti, ognuno con il numero (un UUID scritto dal dispositivo), il
 * tipo, i dati (al più 20.000 caratteri), la versione da cui parte, l'ora del dispositivo e l'ora del gesto. La forma si controlla qui; ogni gesto lo
 * controlla poi il servizio, uno per uno, con la sua risposta. Chi non gestisce le assemblee riceve 403.
 */
class GestiDellaSalaRequest extends RichiestaDelleAssemblee
{
    public const DATI_MASSIMI = 20000;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'dispositivo' => ['required', 'string', 'max:64'],
            'gesti' => ['required', 'array', 'min:1', 'max:' . GestiDellaSala::TETTO],
            'gesti.*.gesto' => ['required', 'uuid'],
            'gesti.*.tipo' => ['required', 'string', 'max:40'],
            'gesti.*.dati' => ['nullable', 'array'],
            'gesti.*.base' => ['nullable', 'integer', 'min:0'],
            'gesti.*.orologio' => ['nullable', 'date'],
            'gesti.*.segnato_il' => ['required', 'date'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['dispositivo' => 'il dispositivo', 'gesti' => 'i gesti', 'gesti.*.gesto' => 'il numero del gesto', 'gesti.*.tipo' => 'il tipo del gesto',
            'gesti.*.dati' => 'i dati del gesto', 'gesti.*.base' => 'la versione del gesto', 'gesti.*.orologio' => 'l\'ora del dispositivo',
            'gesti.*.segnato_il' => 'l\'ora del gesto'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'gesti.max' => 'Al più ' . GestiDellaSala::TETTO . ' gesti per volta: gli altri partono subito dopo.',
            'gesti.*.gesto.uuid' => 'Il numero del gesto non è nella forma giusta.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            foreach ((array) $this->input('gesti', []) as $i => $g) {
                // La discussione di un punto (il testo del verbale) ha il tetto della struttura del verbale; gli altri gesti 20.000 caratteri.
                $tetto = ($g['tipo'] ?? null) === \App\Services\Assemblee\Verbale\GestiDelVerbale::TIPO
                    ? 2 * \App\Services\Assemblee\Verbale\StrutturaDelVerbale::BYTE_MASSIMI : self::DATI_MASSIMI;
                if (strlen((string) json_encode($g['dati'] ?? [])) > $tetto) {
                    $v->errors()->add('gesti.' . $i . '.dati', 'I dati del gesto sono troppi.');
                }
            }
        }];
    }
}
