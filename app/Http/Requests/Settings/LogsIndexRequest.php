<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class LogsIndexRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'status'    => ['sometimes', 'nullable', 'string', 'in:tutti,sent,logged,failed'],
            'mailer'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to'   => ['sometimes', 'nullable', 'date'],
            'per_page'  => ['sometimes', 'nullable', 'integer', 'in:10,25,50,100'],
            // La scheda «Errori» (1.12.0-beta.14): i suoi filtri hanno nomi loro, per non mescolarsi con quelli delle email.
            'scheda'         => ['sometimes', 'nullable', 'string', 'in:email,errori'],
            'cerca'          => ['sometimes', 'nullable', 'string', 'max:255'],
            'stato_errori'   => ['sometimes', 'nullable', 'string', 'in:tutti,nuovo,avvisato,visto'],
            'errori_dal'     => ['sometimes', 'nullable', 'date'],
            'errori_al'      => ['sometimes', 'nullable', 'date'],
            'errori_per_page' => ['sometimes', 'nullable', 'integer', 'in:10,25,50,100'],
            'errore'         => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->redirector->getUrlGenerator()->route('logs.index');
    }
}
