<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Assemblee\Convocazione;

use App\Services\Assemblee\AccessoAlleAssemblee;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Le stampe e gli invii della convocazione per un gruppo di persone: «Stampa le lettere» (in un PDF o uno .zip), «Manda le PEC», il foglio delle consegne,
 * l'anteprima. Senza persone, tutte quelle da spedire; ogni id passa da `SchedaDelCondominio::risolvi()` nel controller.
 */
class PersoneDellaConvocazioneRequest extends FormRequest
{
    /**
     * I permessi prima dei campi, come le altre richieste della convocazione: chi non può non legge gli errori del modulo. L'anteprima con «Vede le
     * assemblee» (non scrive niente); le lettere, le PEC e il foglio con «Gestisce le assemblee».
     */
    public function authorize(): bool
    {
        return $this->routeIs('admin.gestionale.assemblee.convocazione.anteprima')
            ? AccessoAlleAssemblee::vede($this->user())
            : AccessoAlleAssemblee::gestisce($this->user());
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'formato' => ['nullable', 'in:pdf,zip'],
            'anagrafiche' => ['nullable', 'array', 'max:200'],
            'anagrafiche.*' => ['integer'],
        ];
    }
}
