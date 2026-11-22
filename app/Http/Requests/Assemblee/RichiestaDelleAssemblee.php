<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee;

use App\Services\Assemblee\AccessoAlleAssemblee;
use Illuminate\Foundation\Http\FormRequest;

/**
 * La base delle richieste delle assemblee: chi scrive deve avere «Gestisce le assemblee» (mai i ruoli del portale, `AccessoAlleAssemblee`), e lo si
 * controlla **prima** della validazione, così chi non può riceve 403 e non un elenco di errori. I messaggi sono in italiano, con il nome del campo come
 * lo dice la pagina, mai la chiave.
 */
abstract class RichiestaDelleAssemblee extends FormRequest
{
    public function authorize(): bool
    {
        return AccessoAlleAssemblee::gestisce($this->user());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'required' => 'Scrivi :attribute.',
            'required_if' => 'Scrivi :attribute.',
            'in' => 'Scegli :attribute fra quelli proposti.',
            'integer' => ':Attribute deve essere un numero intero.',
            'date' => ':Attribute deve essere un giorno, per esempio 15/12/2026.',
            'date_format' => ':Attribute deve essere un giorno, per esempio 15/12/2026.',
            'before_or_equal' => ':Attribute non può essere dopo oggi.',
            'after_or_equal' => ':Attribute non può essere un giorno già passato.',
            'max' => ':Attribute è troppo lungo.',
            'array' => ':Attribute non è nella forma giusta.',
            'boolean' => ':Attribute non è nella forma giusta.',
            'string' => ':Attribute deve essere un testo.',
            'numeric' => ':Attribute deve essere un importo, per esempio 1.800,00.',
            'regex' => ':Attribute non è nella forma giusta.',
        ];
    }
}
