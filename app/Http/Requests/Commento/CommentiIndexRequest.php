<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Commento;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * I filtri della pagina «Commenti» del pannello (Coda 657): gli stessi di quella degli allegati, con lo stato «nascosto».
 */
class CommentiIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Chi apre la pagina lo dice `RegoleCommenti::puoAprirePagina`, nel controller.
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'stato'         => ['sometimes', 'nullable', 'string', 'in:in_attesa,pubblicato,nascosto'],
            // Con «elemento» il tipo è obbligatorio: una segnalazione e una comunicazione possono avere lo stesso numero.
            'tipo'          => ['nullable', 'required_with:elemento', 'string', 'in:segnalazioni,comunicazioni'],
            'condominio_id' => ['sometimes', 'nullable', 'integer', 'exists:condomini,id'],
            // Un elemento solo (la segnalazione o la comunicazione, col suo tipo): da lì arriva «Modera i commenti» dell'Action inbox.
            'elemento'      => ['sometimes', 'nullable', 'integer', 'min:1'],
            'autore'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'dal'           => ['sometimes', 'nullable', 'date'],
            'al'            => ['sometimes', 'nullable', 'date'],
            'per_page'      => ['sometimes', 'nullable', 'integer', Rule::in(config('pagination.consentite', [10, 15, 25, 50, 100]))],
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->redirector->getUrlGenerator()->route('admin.commenti.index');
    }
}
