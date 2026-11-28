<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Requests\Gestionale\Immobile\Documento;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method bool merge(string $key)
 */
class UpdateImmobileDocumentoRequest extends FormRequest
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
            'file'            => ['nullable', 'file', 'mimes:pdf', 'max:'.\App\Support\LimiteCaricamento::regolaMax()],
        ];
    }

    // Chi ha caricato il documento e se è approvato si scrivono alla creazione (`CreateImmobileDocumentoRequest`): la modifica non li riceve.

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
