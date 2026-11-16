<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Commento;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCommentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // La policy viene verificata nel controller
    }

    public function rules(): array
    {
        return [
            'corpo' => ['required', 'string', 'min:1', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'corpo.required' => 'Il testo del commento è obbligatorio.',
            'corpo.min'      => 'Il commento non può essere vuoto.',
            'corpo.max'      => 'Il commento non può superare i 5000 caratteri.',
        ];
    }
}
