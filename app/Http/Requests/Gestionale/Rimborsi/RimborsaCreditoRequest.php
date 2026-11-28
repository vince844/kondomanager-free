<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Gestionale\Rimborsi;

use App\Helpers\MoneyHelper;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Il modulo «Rimborsa il credito» dell'estratto conto (B2, S6). Le regole di dominio — la quota è di questa
 * persona in questo condominio, il credito basta, la cassa ha capienza — stanno nell'action: qui la forma.
 * L'importo arriva come stringa italiana e diventa centesimi **una volta**, qui al confine (`importoCents()`).
 */
class RimborsaCreditoRequest extends FormRequest
{
    /**
     * La persona di un altro condominio risponde 404 prima che il modulo si legga: la rotta non lega la persona al condominio (chi è stato tolto dopo una
     * vendita ha ancora il suo credito), e la regola è quella del pagante (`Anagrafica::delCondominio`). Il controller la ripete.
     */
    public function authorize(): bool
    {
        $condominio = $this->route('condominio');
        $anagrafica = $this->route('anagrafica');
        abort_unless($condominio !== null && $anagrafica !== null && \App\Models\Anagrafica::delCondominio((int) (is_object($condominio) ? $condominio->id : $condominio))
            ->whereKey(is_object($anagrafica) ? $anagrafica->id : $anagrafica)->exists(), 404);

        return true;
    }

    public function rules(): array
    {
        return [
            'rata_quote_id' => ['required', 'integer', 'exists:rate_quote,id'],
            'cassa_id'      => ['required', 'integer', 'exists:casse,id'],
            // «Oggi» nel fuso dell'utente (DateHelper), non del server in UTC (verifica S6, R16).
            'data_rimborso' => ['required', 'date', 'before_or_equal:' . \App\Helpers\DateHelper::oggiUtente()],
            'importo'       => ['required', 'string'],
            'nota'          => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'rata_quote_id.required' => 'Scegli il credito da rimborsare.',
            'cassa_id.required'      => 'Scegli la cassa da cui esce il denaro.',
            'data_rimborso.required' => 'Indica la data del rimborso.',
            'data_rimborso.before_or_equal' => 'La data del rimborso non può essere futura.',
            'importo.required'       => 'Indica l\'importo rimborsato.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->input('importo') !== null && $this->importoCents() <= 0) {
                $validator->errors()->add('importo', 'L\'importo del rimborso deve essere maggiore di zero.');
            }
        });
    }

    public function importoCents(): int
    {
        return (int) MoneyHelper::toCents((string) $this->input('importo'));
    }
}
