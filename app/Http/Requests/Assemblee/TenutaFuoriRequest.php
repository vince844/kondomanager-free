<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

use App\Enums\Assemblee\EsitoDelibera;
use App\Helpers\DateHelper;
use Illuminate\Validation\Rule;

/**
 * «Tenuta su carta» (R16): il giorno della seduta tenuta (non dopo oggi) e, per i punti trattati, l'esito e che cosa ha deciso. Un punto senza
 * esito è «non trattato» e il suo memo torna in attesa.
 */
class TenutaFuoriRequest extends RichiestaDelleAssemblee
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'giorno' => ['required', 'date_format:Y-m-d', 'before_or_equal:' . DateHelper::oggiUtente()],
            'esiti' => ['sometimes', 'array'],
            'esiti.*.esito' => ['nullable', Rule::in(EsitoDelibera::valori())],
            'esiti.*.testo' => ['nullable', 'string', 'max:2000'],
            'numero_verbale' => ['nullable', 'integer', 'min:1', 'max:99999'],
        ];
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['giorno' => 'il giorno in cui l\'assemblea si è tenuta', 'esiti.*.esito' => 'l\'esito del punto', 'esiti.*.testo' => 'che cosa ha deciso',
            'numero_verbale' => 'il numero del verbale nel registro'];
    }

    /** Gli esiti per punto, solo dove c'è un esito. @return array<int, array{esito: string, testo: ?string}> */
    public function esiti(): array
    {
        $esiti = [];
        foreach ((array) $this->validated('esiti', []) as $punto => $e) {
            if (! empty($e['esito'])) {
                $esiti[(int) $punto] = ['esito' => (string) $e['esito'], 'testo' => $e['testo'] ?? null];
            }
        }

        return $esiti;
    }
}
