<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Fornitore\Documento;

use Illuminate\Foundation\Http\FormRequest;
use App\Support\LimiteCaricamento;

/**
 * La modifica di un documento di un fornitore. L'autore (`created_by`) e l'approvazione (`is_approved`) si scrivono solo alla creazione (Coda 396): qui
 * non hanno una regola e non entrano in `validated()`; l'approvazione cambia solo dall'interruttore.
 */
class UpdateFornitoreDocumentoRequest extends FormRequest
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
            'name'            => ['sometimes', 'required', 'string'],
            'description'     => ['sometimes', 'nullable', 'string'],
            'is_published'    => ['sometimes', 'required', 'boolean'],
            'file'            => ['nullable', 'file', 'mimes:pdf', 'max:'.LimiteCaricamento::regolaMax()],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes()
    {
        return [
            'name'          => __('validation.attributes.documenti.name'),
            'description'   => __('validation.attributes.documenti.description'),
            'is_published'  => __('validation.attributes.documenti.is_published'),
        ];
    }
}
